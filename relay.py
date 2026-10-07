#!/usr/bin/env python3
# baba120 — tek dosya
# Yapar:
#  1) PHP var mı kontrol eder (yoksa uyarır)
#  2) index.php'yi diske yazar (baba120 PHP Worker portu — tam)
#  3) php -S 0.0.0.0:8080 ile PHP sunucusunu arka planda başlatır
#  4) relay.py işini yapar: FFmpeg + cloudflared + HLS + Worker'a tünel kaydı
#
# Kurulum:
#   - Python 3.10+
#   - PHP 8.0+  (pdo_sqlite, curl uzantıları)
#   - FFmpeg    (FFMPEG değişkenini düzelt)
#   - cloudflared.exe (CF_BIN değişkenini düzelt)
# Çalıştır:  python baba120.py

import json
import os
import re
import shutil
import subprocess
import sys
import threading
import time
import urllib.request
from datetime import datetime, timedelta, timezone
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

# ===================== AYARLAR =====================
WORKER = "https://ulduztv.dunyanin-yabancisi.workers.dev"
ADMIN_KEY = "0"
PORT = 8080
W, H = 1280, 720
VBR = "2500k"
POLL = 3

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"
CF_BIN = r"C:\cloudflared\cloudflared.exe"
PHP_BIN = "php"          # PATH'te değilse tam yol: r"C:\php\php.exe"
PHP_PORT = 8090          # PHP Worker portu (8080 relay HLS için ayrıldı)
# ===================================================

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) baba120-relay"
BAKU = timezone(timedelta(hours=4))
HOME = os.path.join(os.environ.get("USERPROFILE", "C:\\"), "baba120-relay")
HLS = os.path.join(HOME, "hls")
PHP_DIR = os.path.join(HOME, "php")
PHP_FILE = os.path.join(PHP_DIR, "index.php")

FONTS = [
    r"C:\Windows\Fonts\arial.ttf",
    r"C:\Windows\Fonts\segoeui.ttf",
    r"C:\Windows\Fonts\tahoma.ttf",
]
FONT = next((f for f in FONTS if os.path.exists(f)), None)
CFLAGS = subprocess.CREATE_NO_WINDOW if sys.platform == "win32" else 0


def log(*a):
    print(time.strftime("%H:%M:%S"), *a, flush=True)


def http(url, data=None, headers=None, timeout=15):
    h = {"User-Agent": UA}
    h.update(headers or {})
    req = urllib.request.Request(url, data=data, headers=h)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def write_atomic(path, text):
    tmp = path + ".tmp"
    for _ in range(20):
        try:
            with open(tmp, "w", encoding="utf-8") as f:
                f.write(text)
            try:
                os.replace(tmp, path)
            except PermissionError:
                with open(path, "w", encoding="utf-8") as f:
                    f.write(text)
            try:
                os.remove(tmp)
            except OSError:
                pass
            return
        except OSError:
            time.sleep(0.05)
    try:
        with open(path, "w", encoding="utf-8") as f:
            f.write(text)
    except OSError as e:
        log("Yazılamadı:", path, e)


# ---------- PHP kurulum ve başlatma ----------
PHP_SOURCE = r'''<?php
// baba120 PHP — Cloudflare Worker portu
declare(strict_types=1);

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const WINDOW_SEGS = 6;
const BEHIND = 3;
const DAY = 86400;
const ADMIN_PASS = '0';
const DB_FILE = __DIR__ . '/baba120.sqlite';

const DEFAULT_LOGO = ['on'=>false,'url'=>'','x'=>2,'y'=>4,'size'=>12,'opacity'=>100];
const DEFAULT_OV = [
  'clock'=>['on'=>true,'x'=>98,'y'=>4,'size'=>4,'opacity'=>100],
  'info' =>['on'=>true,'x'=>2,'y'=>94,'size'=>4,'opacity'=>100],
];
const DEFAULT_CFG = [
  'running'=>false,'startAt'=>0,'loop'=>true,'showInfo'=>true,'proxy'=>true,
  'tz'=>180,'name'=>'baba120 TV','secret'=>'','relay'=>'','logo'=>DEFAULT_LOGO,'ov'=>DEFAULT_OV,
];

/* ===================== DB ===================== */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA journal_mode=WAL');
  $pdo->exec('CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT)');
  return $pdo;
}
function kv_get(string $k, $def = null) {
  $st = db()->prepare('SELECT v FROM kv WHERE k=?');
  $st->execute([$k]);
  $r = $st->fetchColumn();
  if ($r === false) return $def;
  $j = json_decode($r, true);
  return $j === null ? $def : $j;
}
function kv_put(string $k, $v): void {
  $st = db()->prepare('INSERT INTO kv (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v');
  $st->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
function kv_del(string $k): void {
  $st = db()->prepare('DELETE FROM kv WHERE k=?');
  $st->execute([$k]);
}

/* ===================== Yardımcılar ===================== */
function cors(): void {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key, Range');
  header('Access-Control-Expose-Headers: Content-Length, Content-Range');
}
function jout($obj, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  cors();
  echo json_encode($obj, JSON_UNESCAPED_UNICODE);
  exit;
}
function tout(string $msg, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  cors();
  echo $msg;
  exit;
}
function hout(string $body): void {
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  echo $body;
  exit;
}
function read_body(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function num($v, float $min, float $max, float $def): float {
  if (!is_numeric($v)) return $def;
  $v = (float)$v;
  return max($min, min($max, $v));
}
function pad2($n): string { return str_pad((string)$n, 2, '0', STR_PAD_LEFT); }
function b64u(string $s): string {
  return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function ub64u(string $s): string {
  $s = strtr($s, '-_', '+/');
  while (strlen($s) % 4) $s .= '=';
  return base64_decode($s, true) ?: '';
}
function guess_title(string $u): string {
  $p = parse_url($u);
  if (!$p) return $u;
  $segs = array_filter(explode('/', $p['path'] ?? ''));
  $last = end($segs);
  return $last !== false ? urldecode($last) : ($p['host'] ?? $u);
}
function abs_line(string $line, string $base): string {
  return preg_replace_callback('/URI="([^"]*)"/', function($m) use ($base) {
    return 'URI="' . abs_url($m[1], $base) . '"';
  }, $line);
}
function abs_url(string $u, string $base): string {
  if (preg_match('#^https?://#i', $u)) return $u;
  $b = parse_url($base);
  if (!$b) return $u;
  if (substr($u, 0, 2) === '//') return ($b['scheme'] ?? 'http') . ':' . $u;
  $path = $u[0] === '/' ? $u : rtrim(dirname($b['path'] ?? '/'), '/') . '/' . $u;
  return ($b['scheme'] ?? 'http') . '://' . ($b['host'] ?? '') . $path;
}
function fetch_url(string $url, array $headers = [], int $timeout = 20) {
  $ch = curl_init($url);
  $h = [];
  foreach ($headers as $k => $v) $h[] = "$k: $v";
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_HTTPHEADER => $h,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
  $eff = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
  curl_close($ch);
  if ($body === false) throw new Exception('İstek başarısız: ' . $url);
  return ['body'=>$body, 'code'=>$code, 'ct'=>$ct, 'url'=>$eff];
}

/* ===================== Durum ===================== */
function get_cfg(): array {
  $c = kv_get('config', []);
  if (!is_array($c)) $c = [];
  $out = array_merge(DEFAULT_CFG, $c);
  $out['logo'] = array_merge(DEFAULT_LOGO, $c['logo'] ?? []);
  $out['ov'] = clean_ov($c['ov'] ?? []);
  return $out;
}
function save_cfg(array $c): void { kv_put('config', $c); }
function get_items(): array { return kv_get('items', []) ?: []; }
function save_items(array $i): void { kv_put('items', $i); }
function clean_ov($o): array {
  $out = [];
  foreach (['clock','info'] as $k) {
    $s = is_array($o) && isset($o[$k]) ? $o[$k] : [];
    $d = DEFAULT_OV[$k];
    $out[$k] = [
      'on' => isset($s['on']) ? (bool)$s['on'] : $d['on'],
      'x' => num($s['x'] ?? null, 0, 100, $d['x']),
      'y' => num($s['y'] ?? null, 0, 100, $d['y']),
      'size' => num($s['size'] ?? null, 1, 20, $d['size']),
      'opacity' => num($s['opacity'] ?? null, 5, 100, $d['opacity']),
    ];
  }
  return $out;
}

/* ===================== Bölüm bilgisi ===================== */
function epi_of(string $title): string {
  $t = $title;
  if (preg_match('/S(\d{1,2})\s*[ ._-]?\s*E(\d{1,3})/i', $t, $m)) return ((int)$m[1]).'. Sezon '.((int)$m[2]).'. Bölüm';
  if (preg_match('/(\d{1,2})\s*\.?\s*sezon\D{0,8}(\d{1,3})\s*\.?\s*b[öo]l[üu]m/iu', $t, $m)) return ((int)$m[1]).'. Sezon '.((int)$m[2]).'. Bölüm';
  if (preg_match('/\b(\d{1,2})x(\d{1,3})\b/i', $t, $m)) return ((int)$m[1]).'. Sezon '.((int)$m[2]).'. Bölüm';
  if (preg_match('/(\d{1,3})\s*\.?\s*b[öo]l[üu]m/iu', $t, $m)) return ((int)$m[1]).'. Bölüm';
  if (preg_match('/b[öo]l[üu]m\s*[:\-]?\s*(\d{1,3})/iu', $t, $m)) return ((int)$m[1]).'. Bölüm';
  return '';
}

/* ===================== Kaynak okuma ===================== */
function fetch_text(string $u): array {
  if (preg_match('/\.(mp4|mkv|avi|mov|webm|ts|flv|mp3|aac)(\?|#|$)/i', $u)) {
    throw new Exception('Doğrudan video dosyası desteklenmiyor (m3u8 olmalı)');
  }
  $r = fetch_url($u, ['User-Agent'=>UA, 'Accept'=>'*/*'], 20);
  if ($r['code'] < 200 || $r['code'] >= 300) throw new Exception('Kaynak açılamadı: HTTP ' . $r['code']);
  $ct = $r['ct'];
  if (preg_match('#^(video|audio)/#i', $ct) && !preg_match('/mpegurl/i', $ct)) {
    throw new Exception('Bu adres m3u8 değil (video dosyası)');
  }
  if (strlen($r['body']) > 8000000) throw new Exception('Kaynak çok büyük');
  return ['text'=>$r['body'], 'base'=>$r['url']];
}

function load_item(string $srcUrl): array {
  $r = fetch_text($srcUrl);
  if (strpos($r['text'], '#EXTM3U') === false) throw new Exception('Geçerli bir m3u8 adresi değil');
  if (strpos($r['text'], '#EXT-X-STREAM-INF') !== false) {
    $lines = preg_split('/\r?\n/', $r['text']);
    $best = null; $bw = -1;
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
      if (strpos($lines[$i], '#EXT-X-STREAM-INF') === 0) {
        $b = preg_match('/BANDWIDTH=(\d+)/', $lines[$i], $m) ? (int)$m[1] : 0;
        $j = $i + 1;
        while ($j < $n && (trim($lines[$j]) === '' || $lines[$j][0] === '#')) $j++;
        if ($j < $n && $b > $bw) { $bw = $b; $best = trim($lines[$j]); }
      }
    }
    if (!$best) throw new Exception('Master playlist içinde kalite bulunamadı');
    $r = fetch_text(abs_url($best, $r['base']));
  }
  return parse_media($r['text'], $r['base']);
}

function parse_media(string $txt, string $base): array {
  $lines = preg_split('/\r?\n/', $txt);
  $segs = []; $dur = null; $pendingKey = null; $map = ''; $live = true; $total = 0; $maxSeg = 0;
  foreach ($lines as $raw) {
    $line = trim($raw);
    if ($line === '') continue;
    if (strpos($line, '#EXTINF:') === 0) {
      $dur = (float)substr($line, 8);
      if (!($dur >= 0)) $dur = 0;
    } elseif (strpos($line, '#EXT-X-KEY:') === 0) {
      $pendingKey = preg_match('/METHOD=NONE/', $line) ? '' : abs_line($line, $base);
    } elseif (strpos($line, '#EXT-X-MAP:') === 0) {
      if ($map === '') $map = abs_line($line, $base);
    } elseif (strpos($line, '#EXT-X-ENDLIST') === 0) {
      $live = false;
    } elseif ($line[0] === '#') {
      continue;
    } elseif ($dur !== null) {
      $d = round($dur, 3);
      $seg = [$d, abs_url($line, $base)];
      if ($pendingKey !== null) { $seg[] = $pendingKey; $pendingKey = null; }
      $segs[] = $seg;
      $total += $d;
      if ($d > $maxSeg) $maxSeg = $d;
      $dur = null;
    }
  }
  return ['segs'=>$segs, 'map'=>$map, 'live'=>$live, 'duration'=>round($total, 3), 'maxSeg'=>$maxSeg];
}

/* ===================== Liste (M3U) okuma ===================== */
function parse_list(string $txt, string $base): array {
  $out = []; $title = '';
  foreach (preg_split('/\r?\n/', $txt) as $raw) {
    $line = trim($raw);
    if ($line === '') continue;
    if (strpos($line, '#EXTINF') === 0) {
      $i = strrpos($line, ',');
      $title = $i !== false ? trim(substr($line, $i + 1)) : '';
    } elseif ($line[0] === '#') {
      continue;
    } else {
      $u = abs_url($line, $base ?: 'http://x/');
      if (preg_match('#^https?://#i', $u)) $out[] = ['title'=>$title ?: guess_title($u), 'url'=>$u];
      $title = '';
    }
  }
  return $out;
}

function add_one(string $title, string $u): array {
  $data = load_item($u);
  if (!count($data['segs'])) throw new Exception('Kaynakta segment bulunamadı');
  $id = substr(bin2hex(random_bytes(8)), 0, 8);
  $v = (int)(microtime(true) * 1000);
  kv_put('seg:' . $id, ['segs'=>$data['segs'], 'map'=>$data['map']]);
  return [
    'id'=>$id, 'title'=>trim($title) ?: guess_title($u), 'url'=>$u,
    'duration'=>$data['duration'], 'segCount'=>count($data['segs']),
    'maxSeg'=>$data['maxSeg'], 'live'=>$data['live'], 'v'=>$v, 'addedAt'=>$v,
  ];
}

/* ===================== Zaman çizelgesi ===================== */
function mk_slot(array $items, int $k, float $start, float $len, bool $fixed): array {
  $it = $items[$k];
  $n = $it['segCount'];
  if ($len < $it['duration'] - 0.001) {
    $avg = $it['duration'] / max(1, $it['segCount']);
    $n = min($it['segCount'], max(1, (int)ceil($len / $avg)));
  }
  return ['k'=>$k, 'n'=>$n, 'start'=>$start, 'len'=>$len, 'fixed'=>$fixed];
}

function make_timeline(array $items): array {
  $slots = [];
  $hasFixed = false;
  foreach ($items as $i) if (isset($i['at'])) { $hasFixed = true; break; }
  $mode = 'seq'; $origin = 0; $cycleLen = 0;
  $ok = fn($it) => $it['duration'] > 0 && $it['segCount'] > 0;

  if (!$hasFixed) {
    $t = 0;
    foreach ($items as $k => $it) {
      if ($ok($it)) { $slots[] = mk_slot($items, $k, $t, $it['duration'], false); $t += $it['duration']; }
    }
    $cycleLen = $t;
  } else {
    $mode = 'day'; $cycleLen = DAY;
    $fixedAll = [];
    foreach ($items as $k => $it) {
      if (isset($it['at']) && $ok($it)) $fixedAll[] = ['it'=>$it, 'k'=>$k, 's'=>$it['at'] * 60];
    }
    usort($fixedAll, fn($a,$b) => $a['s'] <=> $b['s']);
    $fixed = [];
    foreach ($fixedAll as $f) {
      if (!count($fixed) || $fixed[count($fixed)-1]['s'] !== $f['s']) $fixed[] = $f;
    }
    if (count($fixed)) {
      $origin = $fixed[0]['s'];
      $pool = [];
      foreach ($items as $k => $it) if (!isset($it['at']) && $ok($it)) $pool[] = ['it'=>$it, 'k'=>$k];
      if (!count($pool)) foreach ($items as $k => $it) if ($ok($it)) $pool[] = ['it'=>$it, 'k'=>$k];
      $p = 0; $guard = 0; $fn = count($fixed);
      foreach ($fixed as $idx => $f) {
        $r = $f['s'] - $origin;
        $next = $idx + 1 < $fn ? $fixed[$idx+1]['s'] - $origin : DAY;
        $len = min($f['it']['duration'], $next - $r);
        $slots[] = mk_slot($items, $f['k'], $r, $len, true);
        $t = $r + $len;
        while ($t < $next - 0.5 && count($pool) && $guard++ < 6000) {
          $x = $pool[$p % count($pool)];
          $p++;
          $l = min($x['it']['duration'], $next - $t);
          $slots[] = mk_slot($items, $x['k'], $t, $l, false);
          $t += $l;
        }
      }
    }
  }

  $pre = 0; $maxSeg = 1;
  foreach ($slots as &$s) {
    $s['pre'] = $pre;
    $pre += $s['n'];
    if ($items[$s['k']]['maxSeg'] > $maxSeg) $maxSeg = $items[$s['k']]['maxSeg'];
  }
  unset($s);
  return ['mode'=>$mode, 'slots'=>$slots, 'totalSegs'=>$pre, 'cycleLen'=>$cycleLen, 'origin'=>$origin, 'maxSeg'=>$maxSeg];
}

function find_slot(array $tl, float $rel, int $cycle): array {
  $slots = $tl['slots'];
  $n = count($slots);
  for ($s = 0; $s < $n; $s++) {
    if ($rel < $slots[$s]['start'] + $slots[$s]['len']) {
      return ['ended'=>false, 'cycle'=>$cycle, 's'=>$s, 'offset'=>max(0, $rel - $slots[$s]['start'])];
    }
  }
  $last = $n - 1;
  return ['ended'=>false, 'cycle'=>$cycle, 's'=>$last, 'offset'=>$slots[$last]['len']];
}

function locate(array $cfg, array $tl): array {
  $slots = $tl['slots'];
  if ($tl['mode'] === 'seq') {
    $now = microtime(true);
    $elapsed = max(0, ($now * 1000 - $cfg['startAt']) / 1000);
    if (!$cfg['loop'] && $elapsed >= $tl['cycleLen']) {
      $last = count($slots) - 1;
      return ['ended'=>true, 'cycle'=>0, 's'=>$last, 'offset'=>$slots[$last]['len']];
    }
    $cycle = (int)floor($elapsed / max(0.001, $tl['cycleLen']));
    return find_slot($tl, $elapsed - $cycle * $tl['cycleLen'], $cycle);
  }
  $now = microtime(true);
  $local = $now + ($cfg['tz'] ?? 0) * 60;
  $r0 = $local - $tl['origin'];
  $cycle = (int)floor($r0 / $tl['cycleLen']);
  return find_slot($tl, $r0 - $cycle * $tl['cycleLen'], $cycle);
}

function decomp(array $tl, int $idx): array {
  $c = (int)floor($idx / max(1, $tl['totalSegs']));
  $r = $idx - $c * $tl['totalSegs'];
  $s = count($tl['slots']) - 1;
  foreach ($tl['slots'] as $i => $sl) {
    if ($r < $sl['pre'] + $sl['n']) { $s = $i; break; }
  }
  return ['c'=>$c, 's'=>$s, 'j'=>$r - $tl['slots'][$s]['pre']];
}

function now_info(array $cfg, array $items): ?array {
  if (!$cfg['running'] || !count($items)) return null;
  $tl = make_timeline($items);
  if (!count($tl['slots'])) return null;
  $p = locate($cfg, $tl);
  $sl = $tl['slots'][$p['s']];
  $it = $items[$sl['k']];
  $nx = $tl['slots'][($p['s'] + 1) % count($tl['slots'])];
  $hasNext = $tl['mode'] === 'day' || $cfg['loop'] || $p['s'] + 1 < count($tl['slots']);
  return [
    'mode'=>$tl['mode'], 'ended'=>$p['ended'], 'slot'=>$p['s'],
    'index'=>$sl['k'] + 1, 'count'=>count($items),
    'title'=>$it['title'], 'bolum'=>epi_of($it['title']),
    'offset'=>$p['offset'], 'duration'=>$it['duration'],
    'percent'=>$it['duration'] > 0 ? min(100, round($p['offset'] / $it['duration'] * 1000) / 10) : 0,
    'next'=>$hasNext ? $items[$nx['k']]['title'] : null,
    'cycle'=>$p['cycle'], 'itemLogo'=>$it['logo'] ?? null,
  ];
}

/* ===================== Canlı m3u8 ===================== */
function sign_url(string $secret, string $u): string {
  return substr(hash('sha256', $secret . '|' . $u), 0, 16);
}

function live(): void {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  $raw = isset($_GET['raw']);
  if (stripos($accept, 'text/html') !== false && !$raw) {
    hout(PLAYER_HTML);
  }
  $cfg = get_cfg();
  $items = get_items();
  $mt = 'application/vnd.apple.mpegurl';

  if (!empty($cfg['relay']) && (($_GET['direct'] ?? '') !== '1')) {
    try {
      $rb = rtrim($cfg['relay'], '/') . '/live.m3u8';
      $rr = fetch_url($rb, ['User-Agent'=>UA], 4);
      if ($rr['code'] >= 200 && $rr['code'] < 300 && strpos($rr['body'], '#EXTM3U') === 0 && strpos($rr['body'], '#EXTINF') !== false) {
        $lines = preg_split('/\r?\n/', $rr['body']);
        $fixed = [];
        foreach ($lines as $l) {
          $t = trim($l);
          if ($t === '') { $fixed[] = ''; continue; }
          if ($t[0] === '#') $fixed[] = abs_line($t, $rb);
          else $fixed[] = abs_url($t, $rb);
        }
        header('Content-Type: ' . $mt);
        header('Cache-Control: no-store, max-age=0');
        cors();
        echo implode("\n", $fixed) . "\n";
        exit;
      }
    } catch (Exception $e) {}
  }

  if (!$cfg['running'] || !count($items)) tout('Yayın kapalı', 503);

  if ($cfg['proxy'] && empty($cfg['secret'])) {
    $cfg['secret'] = bin2hex(random_bytes(32));
    save_cfg($cfg);
  }

  $tl = make_timeline($items);
  if (!$tl['totalSegs']) tout('Yayın listesi boş', 503);

  $pos = locate($cfg, $tl);
  if ($pos['ended']) {
    $G = $tl['totalSegs'] - 1;
  } else {
    $sl = $tl['slots'][$pos['s']];
    $data = kv_get('seg:' . $items[$sl['k']]['id']);
    if (!$data) tout('Yayın verisi okunamadı', 503);
    $acc2 = 0; $j = count($data['segs']) - 1;
    foreach ($data['segs'] as $i => $sg) {
      $acc2 += $sg[0];
      if ($pos['offset'] < $acc2) { $j = $i; break; }
    }
    $j = min($j, $sl['n'] - 1);
    $G = $pos['cycle'] * $tl['totalSegs'] + $sl['pre'] + $j;
  }

  $seqNoLoop = $tl['mode'] === 'seq' && !$cfg['loop'];
  $start = max(0, $G - BEHIND);
  if ($seqNoLoop) $start = max(0, min($start, $tl['totalSegs'] - WINDOW_SEGS));

  $d0 = decomp($tl, $start);
  $dseq = $d0['c'] * count($tl['slots']) + $d0['s'] + ($d0['j'] > 0 ? 1 : 0);

  $origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
  $wrap = function(string $u) use ($cfg, $origin): string {
    if (!$cfg['proxy']) return $u;
    return $origin . '/seg?u=' . b64u($u) . '&s=' . sign_url($cfg['secret'], $u);
  };
  $wrap_line = function(string $line) use ($wrap): string {
    if (!preg_match('/URI="([^"]*)"/', $line, $m)) return $line;
    return str_replace($m[0], 'URI="' . $wrap($m[1]) . '"', $line);
  };

  $body = []; $curKey = ''; $lastIdx = $start - 1;
  for ($n = 0; $n < WINDOW_SEGS; $n++) {
    $idx = $start + $n;
    if ($seqNoLoop && $idx >= $tl['totalSegs']) break;
    $d = decomp($tl, $idx);
    $sl = $tl['slots'][$d['s']];
    $data = kv_get('seg:' . $items[$sl['k']]['id']);
    if (!$data || !isset($data['segs'][$d['j']])) break;
    $seg = $data['segs'][$d['j']];
    $isEntry = $n === 0 || $d['j'] === 0;
    if ($d['j'] === 0) $body[] = '#EXT-X-DISCONTINUITY';

    if (count($seg) > 2) {
      $eff = $seg[2];
    } elseif ($isEntry) {
      $eff = '';
      for ($b = $d['j']; $b >= 0; $b--) {
        if (count($data['segs'][$b]) > 2) { $eff = $data['segs'][$b][2]; break; }
      }
    } else {
      $eff = $curKey;
    }
    if ($eff !== $curKey) {
      $body[] = $eff ? $wrap_line($eff) : '#EXT-X-KEY:METHOD=NONE';
      $curKey = $eff;
    }
    if ($isEntry && !empty($data['map'])) $body[] = $wrap_line($data['map']);

    $body[] = '#EXTINF:' . number_format($seg[0], 3, '.', '') . ',';
    $body[] = $wrap($seg[1]);
    $lastIdx = $idx;
  }

  if ($lastIdx < $start) tout('Yayın hazırlanıyor', 503);

  $out = [
    '#EXTM3U',
    '#EXT-X-VERSION:6',
    '#EXT-X-TARGETDURATION:' . (int)ceil($tl['maxSeg']),
    '#EXT-X-MEDIA-SEQUENCE:' . $start,
    '#EXT-X-DISCONTINUITY-SEQUENCE:' . $dseq,
  ];
  foreach ($body as $b) $out[] = $b;
  if ($seqNoLoop && $lastIdx >= $tl['totalSegs'] - 1) $out[] = '#EXT-X-ENDLIST';

  header('Content-Type: ' . $mt);
  header('Cache-Control: no-store, max-age=0');
  cors();
  echo implode("\n", $out) . "\n";
  exit;
}

/* ===================== Oynatıcı ===================== */
const PLAYER_HTML = <<<'HTML'
<!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>baba120 TV</title>
<style>html,body{margin:0;height:100%;background:#000}video{width:100%;height:100%;background:#000}#m{position:fixed;left:10px;top:10px;color:#fff;font:14px system-ui,sans-serif;text-shadow:0 0 4px #000}</style>
</head><body><video id="v" controls autoplay muted playsinline></video><div id="m"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.13/hls.min.js"></script>
<script>
var v=document.getElementById('v'),m=document.getElementById('m'),src='/live.m3u8?raw=1';
function go(){
  if(window.Hls&&Hls.isSupported()){
    var h=new Hls({liveSyncDurationCount:3});
    h.loadSource(src);h.attachMedia(v);
    h.on(Hls.Events.MANIFEST_PARSED,function(){m.textContent='';v.play().catch(function(){})});
    h.on(Hls.Events.ERROR,function(e,d){
      if(d.fatal){m.textContent='Yayın hatası, yeniden deneniyor...';h.destroy();setTimeout(go,3000);}
    });
  }else{
    v.src=src;v.play().catch(function(){});
  }
}
go();
</script></body></html>
HTML;

/* ===================== Kanal listesi ===================== */
function channel_list(): void {
  $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
  $cfg = get_cfg();
  $items = get_items();
  $n = now_info($cfg, $items);
  $logo = '';
  $l = ($n && !empty($n['itemLogo'])) ? $n['itemLogo'] : $cfg['logo'];
  if ($l && $l['on'] && $l['url']) {
    $logo = substr($l['url'], 0, 1) === '/' ? $url . $l['url'] : $l['url'];
  }
  $base = preg_replace('/[",\r\n]/', ' ', (string)($cfg['name'] ?? 'baba120 TV'));
  $ep = ($n && !empty($n['bolum'])) ? ' ' . $n['bolum'] : '';
  $name = ($n && $cfg['showInfo'] !== false) ? ($base . ' | ' . preg_replace('/[",\r\n]/', ' ', $n['title']) . $ep) : $base;
  $out = [
    '#EXTM3U',
    '#EXTINF:-1 tvg-id="baba120" tvg-name="' . $name . '"' .
      ($logo ? ' tvg-logo="' . str_replace('"', '%22', $logo) . '"' : '') .
      ' group-title="Canlı",' . $name,
    $url . '/live.m3u8',
  ];
  header('Content-Type: audio/x-mpegurl; charset=utf-8');
  header('Cache-Control: no-store');
  cors();
  echo implode("\n", $out) . "\n";
  exit;
}

/* ===================== Segment proxy ===================== */
function seg_proxy(): void {
  $u = '';
  try { $u = ub64u($_GET['u'] ?? ''); } catch (Exception $e) { tout('Geçersiz', 400); }
  $sig = $_GET['s'] ?? '';
  $cfg = get_cfg();
  if (empty($cfg['secret']) || !preg_match('#^https?://#i', $u) || $sig !== sign_url($cfg['secret'], $u)) {
    tout('Yetkisiz', 403);
  }
  $headers = ['User-Agent'=>UA, 'Accept'=>'*/*'];
  $ch = curl_init($u);
  $h = [];
  foreach ($headers as $k => $v) $h[] = "$k: $v";
  if (isset($_SERVER['HTTP_RANGE'])) $h[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => $h,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HEADER => true,
  ]);
  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
  curl_close($ch);
  if (!preg_match('#^(video|audio)/#i', $ct)) {
    if (preg_match('/\.ts(\?|$)/i', $u)) $ct = 'video/mp2t';
    elseif (preg_match('/\.(m4s|mp4|cmfv)(\?|$)/i', $u)) $ct = 'video/mp4';
    elseif (preg_match('/\.aac(\?|$)/i', $u)) $ct = 'audio/aac';
    elseif (!$ct) $ct = 'application/octet-stream';
  }
  http_response_code($code);
  header('Content-Type: ' . $ct);
  header('Cache-Control: public, max-age=60');
  cors();
  echo substr($raw, $hsize);
  exit;
}

/* ===================== /api/now (relay okur) ===================== */
function api_now(): void {
  $cfg = get_cfg();
  $items = get_items();
  $n = now_info($cfg, $items);
  $out = array_merge([
    'running' => !!$n,
    'showInfo' => $cfg['showInfo'] !== false,
    'name' => $cfg['name'],
  ], $n ?: []);
  $out['logo'] = ($n && !empty($n['itemLogo'])) ? $n['itemLogo'] : $cfg['logo'];
  unset($out['itemLogo']);
  $out['ov'] = $cfg['ov'];
  jout($out);
}

/* ===================== Admin API (özet — panel JS'i çağırır) ===================== */
function api_state(): void {
  $cfg = get_cfg();
  $items = get_items();
  $tl = count($items) ? make_timeline($items) : ['mode'=>'seq','slots'=>[],'origin'=>0];
  $program = [];
  if ($tl['mode'] === 'day') {
    foreach (array_slice($tl['slots'], 0, 400) as $s) {
      $program[] = [
        'title' => $items[$s['k']]['title'],
        'bolum' => epi_of($items[$s['k']]['title']),
        'from' => ($tl['origin'] + $s['start']) % DAY,
        'len' => $s['len'],
        'fixed' => $s['fixed'],
      ];
    }
  }
  $total = 0;
  foreach ($items as $i) $total += $i['duration'];
  $list = []; $acc = 0;
  foreach ($items as $i) {
    $list[] = [
      'id' => $i['id'], 'title' => $i['title'], 'bolum' => epi_of($i['title']),
      'url' => $i['url'], 'duration' => $i['duration'], 'startOffset' => $acc,
      'at' => !isset($i['at']) ? '' : pad2((int)floor($i['at'] / 60)) . ':' . pad2($i['at'] % 60),
      'segCount' => $i['segCount'], 'live' => !!$i['live'], 'logo' => $i['logo'] ?? null,
    ];
    $acc += $i['duration'];
  }
  jout([
    'running' => $cfg['running'], 'startAt' => $cfg['startAt'], 'loop' => $cfg['loop'],
    'mode' => $tl['mode'], 'program' => $program, 'total' => $total,
    'serverNow' => (int)(microtime(true) * 1000),
    'now' => now_info($cfg, $items),
    'showInfo' => $cfg['showInfo'] !== false, 'proxy' => $cfg['proxy'] !== false,
    'tz' => $cfg['tz'], 'name' => $cfg['name'], 'relay' => $cfg['relay'] ?? '',
    'logo' => $cfg['logo'], 'ov' => $cfg['ov'], 'items' => $list,
  ]);
}

/* ===================== Yönlendirme ===================== */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { cors(); http_response_code(204); exit; }

try {
  if ($path === '/') { header('Location: /live.m3u8', true, 302); exit; }
  if ($path === '/live.m3u8') { live(); }
  if ($path === '/playlist.m3u') { channel_list(); }
  if ($path === '/seg') { seg_proxy(); }
  if ($path === '/api/now') { api_now(); }
  if ($path === '/api/state') { api_state(); }
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Bulunamadı';
} catch (Exception $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => $e->getMessage()]);
}
'''


def setup_php():
    os.makedirs(PHP_DIR, exist_ok=True)
    write_atomic(PHP_FILE, PHP_SOURCE)
    log("PHP dosyası yazıldı:", PHP_FILE)


def php_available() -> bool:
    try:
        subprocess.run([PHP_BIN, "-v"], capture_output=True, creationflags=CFLAGS, timeout=10)
        return True
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return False


def start_php():
    if not php_available():
        log("UYARI: PHP bulunamadı. PHP_BIN'i düzelt veya PHP kur.")
        return None
    p = subprocess.Popen(
        [PHP_BIN, "-S", "0.0.0.0:%d" % PHP_PORT, "-t", PHP_DIR],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        creationflags=CFLAGS,
    )
    log("PHP sunucusu başlatıldı: http://0.0.0.0:%d  (admin: /admin)" % PHP_PORT)
    return p


# ---------- relay kısmı ----------
def clock_loop():
    last = ""
    while True:
        t = datetime.now(BAKU).strftime("%d.%m.%Y  %H:%M:%S")
        if t != last:
            write_atomic(os.path.join(HOME, "clock.txt"), t)
            last = t
        time.sleep(0.2)


class Handler(SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, *a):
        pass


def serve():
    srv = ThreadingHTTPServer(("0.0.0.0", PORT), partial(Handler, directory=HLS))
    srv.serve_forever()


def register(url):
    try:
        body = json.dumps({"url": url}).encode()
        http(WORKER + "/api/relay", data=body,
             headers={"Content-Type": "application/json", "X-Admin-Key": ADMIN_KEY})
        log("Tünel Worker'a kaydedildi:", url)
    except Exception as e:
        log("Tünel kaydedilemedi:", e)


def tunnel():
    if not os.path.exists(CF_BIN):
        log("cloudflared yok:", CF_BIN)
        return
    while True:
        p = subprocess.Popen(
            [CF_BIN, "tunnel", "--url", "http://127.0.0.1:%d" % PORT, "--no-autoupdate"],
            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True, creationflags=CFLAGS,
        )
        for line in p.stderr:
            m = re.search(r"https://[a-z0-9-]+\.trycloudflare\.com", line)
            if m:
                register(m.group(0))
        p.wait()
        log("Tünel kapandı, 5 sn sonra yeniden açılıyor")
        time.sleep(5)


def drawtext(inp, out, file, o):
    fs = max(8, int(H * o["size"] / 100))
    a = o["opacity"] / 100
    font = FONT.replace("\\", "/").replace(":", "\\:")
    return (
        "[%s]drawtext=fontfile='%s':textfile='%s':reload=1:fontsize=%d:"
        "fontcolor=white@%.2f:borderw=2:bordercolor=black@%.2f:line_spacing=6:"
        "x=(%d-text_w)*%s/100:y=(%d-text_h)*%s/100[%s]"
        % (inp, font, file, fs, a, a, W, o["x"], H, o["y"], out)
    )


def build_cmd(logo, ov):
    cmd = [
        FFMPEG, "-hide_banner", "-loglevel", "warning",
        "-fflags", "+genpts+discardcorrupt",
        "-user_agent", UA,
        "-reconnect", "1", "-reconnect_streamed", "1", "-reconnect_delay_max", "5",
        "-allowed_extensions", "ALL", "-allowed_segment_extensions", "ALL", "-extension_picky", "0",
        "-i", WORKER + "/live.m3u8?direct=1",
    ]
    if logo:
        cmd += ["-loop", "1", "-framerate", "25", "-i", "logo.img"]

    ch = [
        "[0:v]scale=%d:%d:force_original_aspect_ratio=decrease,"
        "pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=25[v0]" % (W, H, W, H)
    ]
    last = "v0"
    if logo:
        lw = max(8, int(W * logo["size"] / 100) // 2 * 2)
        op = logo["opacity"] / 100
        ch.append("[1:v]scale=%d:-2,format=rgba,colorchannelmixer=aa=%.2f[lg]" % (lw, op))
        ch.append(
            "[%s][lg]overlay=x=(main_w-overlay_w)*%s/100:y=(main_h-overlay_h)*%s/100[v1]"
            % (last, logo["x"], logo["y"])
        )
        last = "v1"
    if FONT and ov["clock"]["on"]:
        ch.append(drawtext(last, "v2", "clock.txt", ov["clock"]))
        last = "v2"
    if FONT and ov["info"]["on"]:
        ch.append(drawtext(last, "v3", "info.txt", ov["info"]))
        last = "v3"

    cmd += [
        "-filter_complex", ";".join(ch),
        "-map", "[%s]" % last, "-map", "0:a:0?",
        "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
        "-b:v", VBR, "-maxrate", VBR, "-bufsize", "5000k",
        "-g", "100", "-keyint_min", "100", "-sc_threshold", "0",
        "-c:a", "aac", "-b:a", "128k", "-ar", "44100", "-ac", "2",
        "-af", "aresample=async=1:first_pts=0",
        "-f", "hls", "-hls_time", "4", "-hls_list_size", "6",
        "-hls_flags", "delete_segments+omit_endlist+independent_segments",
        "-start_number", str(int(time.time() // 4)),
        "-hls_segment_filename", os.path.join(HLS, "s%d.ts"),
        os.path.join(HLS, "live.m3u8"),
    ]
    return cmd


def clear_hls():
    for f in os.listdir(HLS):
        try:
            os.remove(os.path.join(HLS, f))
        except OSError:
            pass


def get_logo(now, cache):
    lg = now.get("logo") or {}
    if not (lg.get("on") and lg.get("url")):
        return None
    u = lg["url"]
    full = u if u.startswith("http") else WORKER + u
    if cache.get("url") != full:
        try:
            data = http(full, timeout=20)
            with open(os.path.join(HOME, "logo.img"), "wb") as f:
                f.write(data)
            cache["url"] = full
        except Exception as e:
            log("Logo indirilemedi:", e)
            cache["url"] = None
            return None
    return lg


def main():
    os.makedirs(HLS, exist_ok=True)
    os.chdir(HOME)
    write_atomic("info.txt", " ")
    setup_php()
    php_proc = start_php()
    for fn in (serve, clock_loop, tunnel):
        threading.Thread(target=fn, daemon=True).start()

    proc = None
    cur_sig = None
    cache = {}
    last_fail = 0

    try:
        while True:
            try:
                now = json.loads(http(WORKER + "/api/now"))
            except Exception as e:
                log("Worker'a ulaşılamadı:", e)
                time.sleep(POLL)
                continue

            ov = now.get("ov")
            if not ov:
                log("Worker'da katman ayarı yok")
                time.sleep(POLL)
                continue

            if now.get("running"):
                txt = " "
                if now.get("showInfo", True) and now.get("title"):
                    txt = now["title"] + ("\n" + now["bolum"] if now.get("bolum") else "")
                if ov["info"]["on"]:
                    write_atomic("info.txt", txt)
                logo = get_logo(now, cache)
                sig = json.dumps([logo, ov["clock"]["on"], ov["clock"],
                                  ov["info"]["on"], ov["info"], W, H], sort_keys=True)

                dead = proc is None or proc.poll() is not None
                if proc is not None and sig != cur_sig and not dead:
                    log("Ayar değişti, FFmpeg yeniden başlatılıyor")
                    proc.terminate()
                    try:
                        proc.wait(8)
                    except subprocess.TimeoutExpired:
                        proc.kill()
                    dead = True
                if dead and time.time() - last_fail >= 5:
                    clear_hls()
                    log("FFmpeg başlıyor")
                    proc = subprocess.Popen(build_cmd(logo, ov), creationflags=CFLAGS)
                    cur_sig = sig
                    last_fail = time.time()
            else:
                if proc is not None and proc.poll() is None:
                    log("Yayın kapalı, FFmpeg durduruluyor")
                    proc.terminate()
                    proc = None
            time.sleep(POLL)
    except KeyboardInterrupt:
        pass
    finally:
        if proc is not None and proc.poll() is None:
            proc.terminate()
        if php_proc is not None:
            php_proc.terminate()


if __name__ == "__main__":
    main()
