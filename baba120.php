<?php
/**
 * baba120 — 7/24 Sanal Canlı Yayın (Tek Dosya PHP)
 *
 * Kullanım:
 *   1) Bu dosyayı baba120.php olarak kaydet
 *   2) Aynı dizine "data" klasörü aç (yazılabilir: chmod 755 veya 777)
 *   3) Tarayıcıda /baba120.php/admin aç
 *
 * Adresler (baba120.php üzerinden):
 *   /baba120.php                -> /baba120.php/live.m3u8'e yönlendirir
 *   /baba120.php/live.m3u8      -> Canlı yayın (tarayıcıda logolu oynatıcı)
 *   /baba120.php/admin          -> Admin panel
 *   /baba120.php/seg            -> Segment proxy
 *   /baba120.php/logo           -> Logo
 *   /baba120.php/manifest.webmanifest -> PWA manifest
 *   /baba120.php/api/now        -> Şu an oynayan bilgisi
 *   /baba120.php/api/*          -> Admin API
 *
 * NOT: RTMP/YouTube gönderici bu sürümde YOKTUR.
 *      Onun için sunucuda ffmpeg ile gönderim yap (panel ffmpeg komutunu gösterir).
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

/* ============ Sabitler ============ */
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const WINDOW_SIZE = 6;
const BEHIND = 3;
const DAY = 86400;

const DEFAULT_LOGO = ['on' => false, 'url' => '', 'x' => 2, 'y' => 4, 'size' => 12, 'opacity' => 100];
const DEFAULT_CFG = [
    'adminHash' => '',
    'running'   => false,
    'startAt'   => 0,
    'loop'      => true,
    'showInfo'  => true,
    'proxy'     => true,
    'tz'        => 180,
    'name'      => 'baba120 TV',
    'secret'    => '',
];

/* ============ Yardımcılar ============ */
function cors_headers(): array {
    return [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, X-Admin-Key, Range',
        'Access-Control-Expose-Headers'=> 'Content-Length, Content-Range',
    ];
}
function send_json($obj, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach (cors_headers() as $k => $v) header("$k: $v");
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function send_html(string $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $body;
}
function send_text(string $msg, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    foreach (cors_headers() as $k => $v) header("$k: $v");
    echo $msg;
}
function read_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function ub64u(string $s): string {
    $s = strtr($s, '-_', '+/');
    while (strlen($s) % 4) $s .= '=';
    return base64_decode($s) ?: '';
}
function num($v, float $min, float $max, float $def): float {
    if (!is_numeric($v)) return $def;
    $v = (float)$v;
    return min($max, max($min, $v));
}
function pad2(int $n): string { return str_pad((string)$n, 2, '0', STR_PAD_LEFT); }
function guess_title(string $u): string {
    $p = parse_url($u);
    if (!$p || empty($p['path'])) return $p['host'] ?? $u;
    $parts = array_values(array_filter(explode('/', $p['path'])));
    return urldecode(end($parts) ?: ($p['host'] ?? $u));
}
function http_get(string $url, array $extraHeaders = [], int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: */*'], $extraHeaders),
        CURLOPT_HEADER         => true,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch); curl_close($ch);
        throw new RuntimeException('Bağlantı hatası: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $rawHeaders = substr($resp, 0, $hsize);
    $body = substr($resp, $hsize);
    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $code, 'body' => $body, 'headers' => $headers, 'url' => $effUrl];
}
function abs_url(string $u, string $base): string {
    if (preg_match('#^https?://#i', $u)) return $u;
    $p = parse_url($base);
    if (!$p || empty($p['scheme'])) return $u;
    if (str_starts_with($u, '//')) return $p['scheme'] . ':' . $u;
    if (str_starts_with($u, '/')) {
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $u;
    }
    $dir = dirname($p['path'] ?? '/');
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($dir, '/') . '/' . $u;
}
function abs_line(string $line, string $base): string {
    return preg_replace_callback('/URI="([^"]*)"/', fn($m) => 'URI="' . abs_url($m[1], $base) . '"', $line);
}
function epi_of(string $title): string {
    $t = $title;
    if (preg_match('/S(\d{1,2})\s*[ ._-]?\s*E(\d{1,3})/i', $t, $m)) return (int)$m[1] . '. Sezon ' . (int)$m[2] . '. Bölüm';
    if (preg_match('/(\d{1,2})\s*\.?\s*sezon\D{0,8}(\d{1,3})\s*\.?\s*b[öo]l[üu]m/iu', $t, $m)) return (int)$m[1] . '. Sezon ' . (int)$m[2] . '. Bölüm';
    if (preg_match('/\b(\d{1,2})x(\d{1,3})\b/i', $t, $m)) return (int)$m[1] . '. Sezon ' . (int)$m[2] . '. Bölüm';
    if (preg_match('/(\d{1,3})\s*\.?\s*b[öo]l[üu]m/iu', $t, $m)) return (int)$m[1] . '. Bölüm';
    if (preg_match('/b[öo]l[üu]m\s*[:\-]?\s*(\d{1,3})/iu', $t, $m)) return (int)$m[1] . '. Bölüm';
    return '';
}
function set_at_val(array &$it, $v): void {
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim((string)$v), $m)) {
        if ((int)$m[1] < 24 && (int)$m[2] < 60) {
            $it['at'] = (int)$m[1] * 60 + (int)$m[2];
            return;
        }
    }
    unset($it['at']);
}
function asset_of($logo): string {
    if (!is_array($logo) || empty($logo['url'])) return '';
    if (preg_match('#^/logo\?i=([a-z0-9]+)#', $logo['url'], $m)) return $m[1];
    return '';
}
function rand_id(int $len = 8): string { return substr(bin2hex(random_bytes(16)), 0, $len); }
function sign_url(string $secret, string $u): string { return substr(hash('sha256', $secret . '|' . $u), 0, 16); }

/* ============ KV (SQLite) ============ */
class KV {
    private static ?PDO $db = null;
    private static array $segMemo = [];
    private static ?array $stateCache = null;
    private static int $stateCacheAt = 0;

    public static function db(): PDO {
        if (self::$db === null) {
            $dir = __DIR__ . '/data';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $file = $dir . '/baba120.sqlite';
            self::$db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            self::$db->exec('PRAGMA journal_mode = WAL');
            self::$db->exec('CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL, t INTEGER NOT NULL)');
        }
        return self::$db;
    }
    public static function put(string $key, string $value): void {
        $stmt = self::db()->prepare('INSERT INTO kv (k,v,t) VALUES (?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, t=excluded.t');
        $stmt->execute([$key, $value, time()]);
        self::invalidate($key);
    }
    public static function delete(string $key): void {
        $stmt = self::db()->prepare('DELETE FROM kv WHERE k=?');
        $stmt->execute([$key]);
        self::invalidate($key);
    }
    public static function get_raw(string $key): ?string {
        $stmt = self::db()->prepare('SELECT v FROM kv WHERE k=?');
        $stmt->execute([$key]);
        $r = $stmt->fetchColumn();
        return $r === false ? null : (string)$r;
    }
    public static function get_json(string $key) {
        $raw = self::get_raw($key);
        if ($raw === null) return null;
        return json_decode($raw, true);
    }
    public static function put_json(string $key, $value): void {
        self::put($key, json_encode($value, JSON_UNESCAPED_UNICODE));
    }
    private static function invalidate(string $key): void {
        if ($key === 'config' || $key === 'items') {
            self::$stateCache = null; self::$stateCacheAt = 0;
        }
        if (str_starts_with($key, 'seg:')) self::$segMemo = [];
    }
    public static function state(bool $fresh = false, int $maxAgeMs = 5000): array {
        $now = (int)(microtime(true) * 1000);
        if (!$fresh && self::$stateCache !== null && ($now - self::$stateCacheAt) < $maxAgeMs) return self::$stateCache;
        $cfg = self::get_json('config') ?: [];
        $items = self::get_json('items') ?: [];
        $c = array_merge(DEFAULT_CFG, $cfg);
        $c['logo'] = array_merge(DEFAULT_LOGO, $cfg['logo'] ?? []);
        $o = $cfg['out'] ?? [];
        $c['out'] = [
            'yt' => array_merge(['on' => false, 'key' => '', 'sid' => 0, 'mode' => 'rtmp'], $o['yt'] ?? []),
            'cu' => array_merge(['on' => false, 'url' => '', 'key' => '', 'sid' => 0], $o['cu'] ?? []),
        ];
        self::$stateCache = ['cfg' => $c, 'items' => $items];
        self::$stateCacheAt = $now;
        return self::$stateCache;
    }
    public static function save_cfg(array $cfg): void { self::put_json('config', $cfg); self::$stateCache = null; }
    public static function save_items(array $items): void { self::put_json('items', $items); self::$stateCache = null; }
    public static function get_segs(string $id, int $v): ?array {
        $key = $id . ':' . $v;
        if (isset(self::$segMemo[$key])) return self::$segMemo[$key];
        $data = self::get_json('seg:' . $id);
        if ($data) {
            if (count(self::$segMemo) > 40) self::$segMemo = [];
            self::$segMemo[$key] = $data;
        }
        return $data;
    }
    public static function put_segs(string $id, array $data): void { self::put_json('seg:' . $id, $data); }
}

/* ============ m3u8 oku / parse ============ */
function fetch_text(string $u): array {
    if (preg_match('/\.(mp4|mkv|avi|mov|webm|ts|flv|mp3|aac)(\?|#|$)/i', $u)) {
        throw new RuntimeException('Doğrudan video dosyası desteklenmiyor (m3u8 olmalı)');
    }
    $r = http_get($u, [], 20);
    if ($r['status'] < 200 || $r['status'] >= 300) throw new RuntimeException('Kaynak açılamadı: HTTP ' . $r['status']);
    $ct = $r['headers']['content-type'] ?? '';
    $cl = (int)($r['headers']['content-length'] ?? 0);
    if ((preg_match('#^(video|audio)/#i', $ct) && !preg_match('/mpegurl/i', $ct)) || $cl > 8000000) {
        throw new RuntimeException('Bu adres m3u8 değil (video dosyası)');
    }
    return ['body' => $r['body'], 'url' => $r['url']];
}
function load_item(string $srcUrl): array {
    $r = fetch_text($srcUrl);
    if (!str_contains($r['body'], '#EXTM3U')) throw new RuntimeException('Geçerli bir m3u8 adresi değil');
    $text = $r['body']; $base = $r['url'];
    if (str_contains($text, '#EXT-X-STREAM-INF')) {
        $lines = preg_split('/\r?\n/', $text);
        $best = null; $bw = -1; $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (str_starts_with($lines[$i], '#EXT-X-STREAM-INF')) {
                $b = 0;
                if (preg_match('/BANDWIDTH=(\d+)/', $lines[$i], $m)) $b = (int)$m[1];
                $j = $i + 1;
                while ($j < $n && (!trim($lines[$j]) || str_starts_with($lines[$j], '#'))) $j++;
                if ($j < $n && $b > $bw) { $bw = $b; $best = trim($lines[$j]); }
            }
        }
        if (!$best) throw new RuntimeException('Master playlist içinde kalite bulunamadı');
        $r2 = fetch_text(abs_url($best, $base));
        $text = $r2['body']; $base = $r2['url'];
    }
    return parse_media($text, $base);
}
function parse_media(string $txt, string $base): array {
    $lines = preg_split('/\r?\n/', $txt);
    $segs = []; $dur = null; $pendingKey = null; $map = ''; $live = true; $total = 0.0; $maxSeg = 0.0;
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;
        if (str_starts_with($line, '#EXTINF:')) {
            $dur = (float)substr($line, 8);
            if (!($dur >= 0)) $dur = 0.0;
        } elseif (str_starts_with($line, '#EXT-X-KEY:')) {
            $pendingKey = preg_match('/METHOD=NONE/', $line) ? '' : abs_line($line, $base);
        } elseif (str_starts_with($line, '#EXT-X-MAP:')) {
            if (!$map) $map = abs_line($line, $base);
        } elseif (str_starts_with($line, '#EXT-X-ENDLIST')) {
            $live = false;
        } elseif (str_starts_with($line, '#')) {
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
    return ['segs' => $segs, 'map' => $map, 'live' => $live, 'duration' => round($total, 3), 'maxSeg' => $maxSeg];
}
function parse_list(string $txt, string $base): array {
    $out = []; $title = '';
    foreach (preg_split('/\r?\n/', $txt) as $raw) {
        $line = trim($raw);
        if ($line === '') continue;
        if (str_starts_with($line, '#EXTINF')) {
            $i = strrpos($line, ',');
            $title = $i >= 0 ? trim(substr($line, $i + 1)) : '';
        } elseif (str_starts_with($line, '#')) {
            continue;
        } else {
            $u = abs_url($line, $base ?: 'http://x/');
            if (preg_match('#^https?://#i', $u)) $out[] = ['title' => $title ?: guess_title($u), 'url' => $u];
            $title = '';
        }
    }
    return $out;
}
function add_one(string $title, string $u): array {
    $data = load_item($u);
    if (!count($data['segs'])) throw new RuntimeException('Kaynakta segment bulunamadı');
    $id = rand_id(8);
    $v = (int)(microtime(true) * 1000);
    KV::put_segs($id, ['segs' => $data['segs'], 'map' => $data['map']]);
    return [
        'id' => $id,
        'title' => trim($title) !== '' ? trim($title) : guess_title($u),
        'url' => $u,
        'duration' => $data['duration'],
        'segCount' => count($data['segs']),
        'maxSeg' => $data['maxSeg'],
        'live' => $data['live'],
        'v' => $v,
        'addedAt' => $v,
    ];
}

/* ============ Timeline ============ */
function mk_slot(array $items, int $k, float $start, float $len, bool $fixed): array {
    $it = $items[$k];
    $n = $it['segCount'];
    if ($len < $it['duration'] - 0.001) {
        $avg = $it['duration'] / max(1, $it['segCount']);
        $n = min($it['segCount'], max(1, (int)ceil($len / $avg)));
    }
    return ['k' => $k, 'n' => $n, 'start' => $start, 'len' => $len, 'fixed' => $fixed];
}
function make_timeline(array $items): array {
    $slots = []; $hasFixed = false;
    foreach ($items as $i) if (isset($i['at'])) { $hasFixed = true; break; }
    $mode = 'seq'; $origin = 0.0; $cycleLen = 0.0;
    $ok = fn($it) => ($it['duration'] ?? 0) > 0 && ($it['segCount'] ?? 0) > 0;
    if (!$hasFixed) {
        $t = 0.0;
        foreach ($items as $k => $it) {
            if ($ok($it)) { $slots[] = mk_slot($items, $k, $t, (float)$it['duration'], false); $t += (float)$it['duration']; }
        }
        $cycleLen = $t;
    } else {
        $mode = 'day'; $cycleLen = (float)DAY;
        $fixedAll = [];
        foreach ($items as $k => $it) if (isset($it['at']) && $ok($it)) $fixedAll[] = ['k' => $k, 's' => (int)$it['at'] * 60];
        usort($fixedAll, fn($a, $b) => $a['s'] <=> $b['s']);
        $fixed = [];
        foreach ($fixedAll as $f) if (!$fixed || end($fixed)['s'] !== $f['s']) $fixed[] = $f;
        if ($fixed) {
            $origin = $fixed[0]['s'];
            $pool = [];
            foreach ($items as $k => $it) if (!isset($it['at']) && $ok($it)) $pool[] = ['k' => $k];
            if (!$pool) foreach ($items as $k => $it) if ($ok($it)) $pool[] = ['k' => $k];
            $p = 0; $guard = 0;
            foreach ($fixed as $idx => $f) {
                $r = $f['s'] - $origin;
                $next = ($idx + 1 < count($fixed)) ? $fixed[$idx + 1]['s'] - $origin : DAY;
                $len = min((float)$items[$f['k']]['duration'], $next - $r);
                $slots[] = mk_slot($items, $f['k'], $r, $len, true);
                $t = $r + $len;
                while ($t < $next - 0.5 && $pool && $guard++ < 6000) {
                    $x = $pool[$p % count($pool)]; $p++;
                    $l = min((float)$items[$x['k']]['duration'], $next - $t);
                    $slots[] = mk_slot($items, $x['k'], $t, $l, false);
                    $t += $l;
                }
            }
        }
    }
    $pre = 0; $maxSeg = 1.0;
    foreach ($slots as &$s) {
        $s['pre'] = $pre;
        $pre += $s['n'];
        if (($items[$s['k']]['maxSeg'] ?? 0) > $maxSeg) $maxSeg = (float)$items[$s['k']]['maxSeg'];
    }
    unset($s);
    return ['mode' => $mode, 'slots' => $slots, 'totalSegs' => $pre, 'cycleLen' => $cycleLen, 'origin' => $origin, 'maxSeg' => $maxSeg];
}
function build_timeline(array $items): array {
    static $sig = ''; static $tl = null;
    $s = '';
    foreach ($items as $i) $s .= $i['id'] . ':' . ($i['v'] ?? 0) . ':' . (isset($i['at']) ? $i['at'] : '') . '|';
    if ($sig === $s && $tl !== null) return $tl;
    $sig = $s; $tl = make_timeline($items);
    return $tl;
}
function locate(array $cfg, array $tl): array {
    $slots = $tl['slots'];
    if ($tl['mode'] === 'seq') {
        $elapsed = max(0.0, (microtime(true) * 1000 - $cfg['startAt']) / 1000);
        if (!$cfg['loop'] && $elapsed >= $tl['cycleLen']) {
            $last = count($slots) - 1;
            return ['ended' => true, 'cycle' => 0, 's' => $last, 'offset' => $slots[$last]['len']];
        }
        $cycle = (int)floor($elapsed / max(0.001, $tl['cycleLen']));
        return find_slot($tl, $elapsed - $cycle * $tl['cycleLen'], $cycle);
    }
    $local = microtime(true) + ($cfg['tz'] ?? 0) * 60;
    $r0 = $local - $tl['origin'];
    $cycle = (int)floor($r0 / $tl['cycleLen']);
    return find_slot($tl, $r0 - $cycle * $tl['cycleLen'], $cycle);
}
function find_slot(array $tl, float $rel, int $cycle): array {
    $slots = $tl['slots'];
    foreach ($slots as $s => $slot) {
        if ($rel < $slot['start'] + $slot['len']) return ['ended' => false, 'cycle' => $cycle, 's' => $s, 'offset' => max(0.0, $rel - $slot['start'])];
    }
    $last = count($slots) - 1;
    return ['ended' => false, 'cycle' => $cycle, 's' => $last, 'offset' => $slots[$last]['len']];
}
function decomp(array $tl, int $idx): array {
    $c = intdiv($idx, $tl['totalSegs']);
    $r = $idx - $c * $tl['totalSegs'];
    $s = count($tl['slots']) - 1;
    foreach ($tl['slots'] as $i => $slot) if ($r < $slot['pre'] + $slot['n']) { $s = $i; break; }
    return ['c' => $c, 's' => $s, 'j' => $r - $tl['slots'][$s]['pre']];
}
function now_info(array $cfg, array $items): ?array {
    if (!$cfg['running'] || !$items) return null;
    $tl = build_timeline($items);
    if (!$tl['slots']) return null;
    $p = locate($cfg, $tl);
    $sl = $tl['slots'][$p['s']];
    $it = $items[$sl['k']];
    $nx = $tl['slots'][($p['s'] + 1) % count($tl['slots'])];
    $hasNext = $tl['mode'] === 'day' || $cfg['loop'] || ($p['s'] + 1 < count($tl['slots']));
    $dur = (float)$it['duration'];
    return [
        'mode' => $tl['mode'], 'ended' => $p['ended'], 'slot' => $p['s'],
        'index' => $sl['k'] + 1, 'count' => count($items),
        'title' => $it['title'], 'bolum' => epi_of($it['title']),
        'offset' => $p['offset'], 'duration' => $dur,
        'percent' => $dur > 0 ? min(100, round($p['offset'] / $dur * 1000) / 10) : 0,
        'next' => $hasNext ? $items[$nx['k']]['title'] : null,
        'cycle' => $p['cycle'], 'itemLogo' => $it['logo'] ?? null,
    ];
}

/* ============ /live.m3u8 ============ */
function handle_live(): void {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?: '', $q);
    if (($q['raw'] ?? '') !== '1' && stripos($accept, 'text/html') !== false) {
        send_html(PLAYER_HTML); return;
    }
    $st = KV::state(false); $cfg = $st['cfg']; $items = $st['items'];
    if (!$cfg['running'] || !$items) { send_text('Yayın kapalı', 503); return; }

    if ($cfg['proxy'] && !$cfg['secret']) {
        $st2 = KV::state(true);
        if (!$st2['cfg']['secret']) {
            $st2['cfg']['secret'] = bin2hex(random_bytes(32));
            KV::save_cfg($st2['cfg']);
        }
        $cfg = $st2['cfg']; $items = $st2['items'];
    }
    $tl = build_timeline($items);
    if (!$tl['totalSegs']) { send_text('Yayın listesi boş', 503); return; }

    $pos = locate($cfg, $tl);
    if ($pos['ended']) {
        $G = $tl['totalSegs'] - 1;
    } else {
        $sl = $tl['slots'][$pos['s']];
        $data = KV::get_segs($items[$sl['k']]['id'], $items[$sl['k']]['v'] ?? 0);
        if (!$data) { send_text('Yayın verisi okunamadı', 503); return; }
        $acc = 0.0; $j = count($data['segs']) - 1;
        foreach ($data['segs'] as $i => $seg) {
            $acc += $seg[0];
            if ($pos['offset'] < $acc) { $j = $i; break; }
        }
        $j = min($j, $sl['n'] - 1);
        $G = $pos['cycle'] * $tl['totalSegs'] + $sl['pre'] + $j;
    }
    $seqNoLoop = ($tl['mode'] === 'seq' && !$cfg['loop']);
    $start = max(0, $G - BEHIND);
    if ($seqNoLoop) $start = max(0, min($start, $tl['totalSegs'] - WINDOW_SIZE));
    $d0 = decomp($tl, $start);
    $dseq = $d0['c'] * count($tl['slots']) + $d0['s'] + ($d0['j'] > 0 ? 1 : 0);

    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $wrap = function (string $u) use ($cfg, $baseUrl): string {
        if (!$cfg['proxy']) return $u;
        return $baseUrl . '?u=' . b64u($u) . '&s=' . sign_url($cfg['secret'], $u);
    };
    $wrapLine = function (string $line) use ($wrap): string {
        if (!preg_match('/URI="([^"]*)"/', $line, $m)) return $line;
        $w = $wrap($m[1]);
        return str_replace($m[0], 'URI="' . $w . '"', $line);
    };

    // /seg yolu bu dosyaya göre
    $segPath = strtok($_SERVER['REQUEST_URI'], '?');
    $segPath = rtrim(str_replace('/live.m3u8', '/seg', $segPath), '/');
    if (!str_ends_with($segPath, '/seg')) $segPath .= '/seg';

    $wrap = function (string $u) use ($cfg, $segPath): string {
        if (!$cfg['proxy']) return $u;
        return $segPath . '?u=' . b64u($u) . '&s=' . sign_url($cfg['secret'], $u);
    };
    $wrapLine = function (string $line) use ($wrap): string {
        if (!preg_match('/URI="([^"]*)"/', $line, $m)) return $line;
        $w = $wrap($m[1]);
        return str_replace($m[0], 'URI="' . $w . '"', $line);
    };

    $body = []; $curKey = ''; $lastIdx = $start - 1;
    for ($n = 0; $n < WINDOW_SIZE; $n++) {
        $idx = $start + $n;
        if ($seqNoLoop && $idx >= $tl['totalSegs']) break;
        $d = decomp($tl, $idx);
        $sl = $tl['slots'][$d['s']];
        $data = KV::get_segs($items[$sl['k']]['id'], $items[$sl['k']]['v'] ?? 0);
        if (!$data || !isset($data['segs'][$d['j']])) break;
        $seg = $data['segs'][$d['j']];
        $isEntry = ($n === 0 || $d['j'] === 0);
        if ($d['j'] === 0) $body[] = '#EXT-X-DISCONTINUITY';

        $eff = null;
        if (count($seg) > 2) $eff = $seg[2];
        elseif ($isEntry) {
            $eff = '';
            for ($b = $d['j']; $b >= 0; $b--) {
                if (count($data['segs'][$b]) > 2) { $eff = $data['segs'][$b][2]; break; }
            }
        } else $eff = $curKey;

        if ($eff !== $curKey) {
            $body[] = $eff ? $wrapLine($eff) : '#EXT-X-KEY:METHOD=NONE';
            $curKey = $eff;
        }
        if ($isEntry && !empty($data['map'])) $body[] = $wrapLine($data['map']);
        $body[] = '#EXTINF:' . number_format((float)$seg[0], 3, '.', '') . ',';
        $body[] = $wrap($seg[1]);
        $lastIdx = $idx;
    }
    if ($lastIdx < $start) { send_text('Yayın hazırlanıyor', 503); return; }

    $out = [
        '#EXTM3U', '#EXT-X-VERSION:6',
        '#EXT-X-TARGETDURATION:' . (int)ceil($tl['maxSeg']),
        '#EXT-X-MEDIA-SEQUENCE:' . $start,
        '#EXT-X-DISCONTINUITY-SEQUENCE:' . $dseq,
        ...$body,
    ];
    if ($seqNoLoop && $lastIdx >= $tl['totalSegs'] - 1) $out[] = '#EXT-X-ENDLIST';

    http_response_code(200);
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store, max-age=0');
    foreach (cors_headers() as $k => $v) header("$k: $v");
    echo implode("\n", $out) . "\n";
}

/* ============ /seg proxy ============ */
function handle_seg_proxy(): void {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?: '', $q);
    $u = ub64u($q['u'] ?? '');
    $sig = $q['s'] ?? '';
    $st = KV::state(false);
    $cfg = $st['cfg'];
    if (!$cfg['secret'] || !preg_match('#^https?://#i', $u) || $sig !== sign_url($cfg['secret'], $u)) {
        send_text('Yetkisiz', 403); return;
    }
    $headers = ['Accept: */*'];
    if (!empty($_SERVER['HTTP_RANGE'])) $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];

    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) {
            if (preg_match('#^Content-Type:\s*(.+)$#i', $h, $m)) header('Content-Type: ' . trim($m[1]));
            if (preg_match('#^Content-Length:\s*(.+)$#i', $h, $m)) header('Content-Length: ' . trim($m[1]));
            if (preg_match('#^Content-Range:\s*(.+)$#i', $h, $m)) header('Content-Range: ' . trim($m[1]));
            if (preg_match('#^Accept-Ranges:\s*(.+)$#i', $h, $m)) header('Accept-Ranges: ' . trim($m[1]));
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) http_response_code((int)$m[1]);
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $data) { echo $data; return strlen($data); },
    ]);
    foreach (cors_headers() as $k => $v) header("$k: $v");
    header('Cache-Control: public, max-age=60');
    curl_exec($ch);
    curl_close($ch);
}

/* ============ /logo ============ */
function handle_logo(): void {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?: '', $q);
    $iid = $q['i'] ?? '';
    if ($iid) {
        if (!preg_match('/^[a-z0-9]{4,32}$/', $iid)) { http_response_code(404); echo 'Logo yok'; return; }
        $d = KV::get_json('ilogo:' . $iid);
    } else {
        $key = isset($q['orig']) ? 'logo:orig' : 'logo:data';
        $d = KV::get_json($key);
        if (!$d && $key === 'logo:orig') $d = KV::get_json('logo:data');
    }
    if (!$d || empty($d['b64'])) { http_response_code(404); foreach (cors_headers() as $k => $v) header("$k: $v"); echo 'Logo yok'; return; }
    $bin = base64_decode($d['b64']);
    http_response_code(200);
    header('Content-Type: ' . $d['mime']);
    header('Cache-Control: public, max-age=3600');
    foreach (cors_headers() as $k => $v) header("$k: $v");
    echo $bin;
}

/* ============ /manifest.webmanifest ============ */
function handle_manifest(): void {
    $st = KV::state(false);
    $cfg = $st['cfg'];
    $name = $cfg['name'] ?: 'baba120 TV';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $origin = $scheme . '://' . $host . $base;
    $icon = '';
    if (!empty($cfg['logo']['url'])) {
        $icon = str_starts_with($cfg['logo']['url'], '/') ? $origin . $cfg['logo']['url'] : $cfg['logo']['url'];
    }
    $m = [
        'name' => $name,
        'short_name' => mb_substr($name, 0, 12),
        'start_url' => $base . '/live.m3u8',
        'scope' => $base . '/',
        'display' => 'fullscreen',
        'display_override' => ['fullscreen', 'standalone'],
        'orientation' => 'landscape',
        'background_color' => '#000000',
        'theme_color' => '#000000',
        'icons' => $icon ? [
            ['src' => $icon, 'sizes' => '192x192', 'purpose' => 'any'],
            ['src' => $icon, 'sizes' => '512x512', 'purpose' => 'any'],
        ] : [],
    ];
    http_response_code(200);
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach (cors_headers() as $k => $v) header("$k: $v");
    echo json_encode($m, JSON_UNESCAPED_UNICODE);
}

/* ============ /api/now ============ */
function handle_api_now(): void {
    $st = KV::state(false);
    $cfg = $st['cfg']; $items = $st['items'];
    $n = now_info($cfg, $items);
    $out = array_merge([
        'running' => (bool)$n,
        'showInfo' => $cfg['showInfo'] !== false,
        'name' => $cfg['name'],
    ], $n ?: []);
    $out['logo'] = ($n && $n['itemLogo']) ? $n['itemLogo'] : $cfg['logo'];
    unset($out['itemLogo']);
    send_json($out);
}

/* ============ /api/* ============ */
function handle_api(string $path): void {
    $st = KV::state(true); $cfg = $st['cfg']; $items = $st['items'];

    if ($path === '/api/status') {
        send_json(['hasKey' => !empty($cfg['adminHash'])]);
        return;
    }
    if ($path === '/api/setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!empty($cfg['adminHash'])) { send_json(['error' => 'Anahtar zaten belirlenmiş'], 403); return; }
        $b = read_body();
        $k = (string)($b['key'] ?? '');
        if (strlen($k) < 4) { send_json(['error' => 'Anahtar en az 4 karakter olmalı'], 400); return; }
        $cfg['adminHash'] = hash('sha256', $k);
        KV::save_cfg($cfg);
        send_json(['ok' => true]);
        return;
    }

    $expected = $cfg['adminHash'] ?? '';
    $given = hash('sha256', $_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
    if (!$expected || !hash_equals($expected, $given)) { send_json(['error' => 'Yetkisiz'], 401); return; }

    $b = $_SERVER['REQUEST_METHOD'] === 'POST' ? read_body() : [];

    switch ($path) {
        case '/api/state':
            send_json(array_merge(state_out($cfg, $items), ['out' => out_info($cfg)]));
            return;

        case '/api/import': {
            if (!empty($b['text'])) {
                $entries = parse_list((string)$b['text'], '');
            } else {
                $u = trim((string)($b['url'] ?? ''));
                if (!preg_match('#^https?://#i', $u)) { send_json(['error' => 'Geçerli bir liste adresi girin'], 400); return; }
                $r = http_get($u, [], 25);
                if ($r['status'] < 200 || $r['status'] >= 300) { send_json(['error' => 'Liste açılamadı: HTTP ' . $r['status']], 400); return; }
                $t = $r['body'];
                if (str_contains($t, '#EXT-X-TARGETDURATION') || str_contains($t, '#EXT-X-STREAM-INF')) {
                    send_json(['error' => 'Bu bir liste değil, tek yayın. Tek yayınları alttaki kutudan ekleyin.'], 400); return;
                }
                $entries = parse_list($t, $r['url'] ?: $u);
            }
            if (!$entries) { send_json(['error' => 'Listede yayın bulunamadı'], 400); return; }
            $have = []; foreach ($items as $x) $have[$x['url']] = 1;
            $seen = []; $fresh = []; $dup = 0;
            foreach ($entries as $e) {
                if (isset($have[$e['url']]) || isset($seen[$e['url']])) { $dup++; continue; }
                $seen[$e['url']] = 1;
                $fresh[] = $e;
            }
            send_json([
                'entries' => array_map(fn($e) => ['title' => $e['title'], 'url' => $e['url'], 'bolum' => epi_of($e['title'])], $fresh),
                'duplicate' => $dup,
                'total' => count($entries),
            ]);
            return;
        }

        case '/api/add-batch': {
            $ents = array_slice(is_array($b['entries'] ?? null) ? $b['entries'] : [], 0, 3);
            $have = []; foreach ($items as $x) $have[$x['url']] = 1;
            $results = [];
            $added = 0;
            foreach ($ents as $e) {
                $u = trim((string)($e['url'] ?? ''));
                try {
                    if (!preg_match('#^https?://#i', $u)) throw new RuntimeException('Geçersiz adres');
                    if (isset($have[$u])) throw new RuntimeException('Zaten listede');
                    $have[$u] = 1;
                    $item = add_one((string)($e['title'] ?? ''), $u);
                    $items[] = $item;
                    $added++;
                    $results[] = ['ok' => true];
                } catch (Throwable $err) {
                    $results[] = ['ok' => false, 'url' => $u, 'error' => $err->getMessage()];
                }
            }
            if ($added) KV::save_items($items);
            send_json(['results' => $results]);
            return;
        }

        case '/api/at': {
            $id = (string)($b['id'] ?? '');
            $found = false;
            foreach ($items as &$it) {
                if ($it['id'] === $id) { set_at_val($it, $b['at'] ?? ''); $found = true; break; }
            }
            unset($it);
            if (!$found) { send_json(['error' => 'Bulunamadı'], 404); return; }
            KV::save_items($items);
            send_json(['ok' => true]);
            return;
        }

        case '/api/edit': {
            $id = (string)($b['id'] ?? '');
            $found = false;
            foreach ($items as &$it) {
                if ($it['id'] !== $id) continue;
                $found = true;
                if (!empty($b['title']) && trim((string)$b['title']) !== '') $it['title'] = mb_substr(trim((string)$b['title']), 0, 200);
                if (!empty($b['url']) && trim((string)$b['url']) !== $it['url']) {
                    $u = trim((string)$b['url']);
                    if (!preg_match('#^https?://#i', $u)) { send_json(['error' => 'Geçersiz adres'], 400); return; }
                    foreach ($items as $x) if ($x !== $it && $x['url'] === $u) { send_json(['error' => 'Bu adres zaten listede'], 400); return; }
                    $data = load_item($u);
                    if (!$data['segs']) { send_json(['error' => 'Kaynakta segment bulunamadı'], 400); return; }
                    $it['url'] = $u;
                    $it['v'] = (int)(microtime(true) * 1000);
                    $it['duration'] = $data['duration'];
                    $it['segCount'] = count($data['segs']);
                    $it['maxSeg'] = $data['maxSeg'];
                    $it['live'] = $data['live'];
                    KV::put_segs($it['id'], ['segs' => $data['segs'], 'map' => $data['map']]);
                }
                if (array_key_exists('at', $b)) set_at_val($it, $b['at']);
                break;
            }
            unset($it);
            if (!$found) { send_json(['error' => 'Bulunamadı'], 404); return; }
            KV::save_items($items);
            send_json(['ok' => true]);
            return;
        }

        case '/api/item-logo': {
            $ids = is_array($b['ids'] ?? null) ? array_map('strval', $b['ids']) : [];
            $targets = [];
            foreach ($items as &$it) if (in_array($it['id'], $ids, true)) { $targets[] = &$it; }
            unset($it);
            if (!$targets) { send_json(['error' => 'Yayın seçilmedi'], 404); return; }
            $old = [];
            foreach ($targets as $t) { $a = asset_of($t['logo'] ?? null); if ($a) $old[] = $a; }

            if (!isset($b['logo']) || $b['logo'] === null) {
                foreach ($targets as &$t) { unset($t['logo']); }
                unset($t);
            } else {
                $l = $b['logo'] ?: [];
                $lu = mb_substr(trim((string)($l['url'] ?? '')), 0, 500);
                if (!empty($b['data'])) {
                    if (!preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,([A-Za-z0-9+/=]+)$#', (string)$b['data'], $m)) {
                        send_json(['error' => 'Geçersiz logo verisi'], 400); return;
                    }
                    if (strlen($m[2]) > 2500000) { send_json(['error' => 'Logo çok büyük'], 400); return; }
                    $aid = rand_id(10);
                    KV::put_json('ilogo:' . $aid, ['mime' => $m[1], 'b64' => $m[2]]);
                    $lu = '/logo?i=' . $aid;
                }
                if (!$lu || (!str_starts_with($lu, '/logo') && !preg_match('#^https?://#i', $lu))) {
                    send_json(['error' => 'Logo adresi girin veya dosya yükleyin'], 400); return;
                }
                $lg = [
                    'on' => !empty($l['on']),
                    'url' => $lu,
                    'x' => num($l['x'] ?? 2, 0, 100, 2),
                    'y' => num($l['y'] ?? 4, 0, 100, 4),
                    'size' => num($l['size'] ?? 12, 1, 100, 12),
                    'opacity' => num($l['opacity'] ?? 100, 5, 100, 100),
                ];
                foreach ($targets as &$t) { $t['logo'] = $lg; }
                unset($t);
            }
            KV::save_items($items);
            // Artık kullanılmayan logoları sil
            $used = [];
            foreach ($items as $i) { $a = asset_of($i['logo'] ?? null); if ($a) $used[$a] = 1; }
            foreach (array_unique($old) as $id) if (!isset($used[$id])) KV::delete('ilogo:' . $id);
            send_json(['ok' => true, 'count' => count($targets)]);
            return;
        }

        case '/api/refresh': {
            $id = (string)($b['id'] ?? '');
            $found = false;
            foreach ($items as &$it) {
                if ($it['id'] !== $id) continue;
                $found = true;
                $data = load_item($it['url']);
                if (!$data['segs']) { send_json(['error' => 'Kaynakta segment bulunamadı'], 400); return; }
                $it['v'] = (int)(microtime(true) * 1000);
                $it['duration'] = $data['duration'];
                $it['segCount'] = count($data['segs']);
                $it['maxSeg'] = $data['maxSeg'];
                $it['live'] = $data['live'];
                KV::put_segs($it['id'], ['segs' => $data['segs'], 'map' => $data['map']]);
                break;
            }
            unset($it);
            if (!$found) { send_json(['error' => 'Bulunamadı'], 404); return; }
            KV::save_items($items);
            send_json(['ok' => true]);
            return;
        }

        case '/api/remove': {
            $id = (string)($b['id'] ?? '');
            $gone = null; $left = [];
            foreach ($items as $x) { if ($x['id'] === $id) $gone = $x; else $left[] = $x; }
            if (!$gone) { send_json(['error' => 'Bulunamadı'], 404); return; }
            KV::delete('seg:' . $id);
            KV::save_items($left);
            $a = asset_of($gone['logo'] ?? null);
            if ($a) {
                $used = false;
                foreach ($left as $x) if (asset_of($x['logo'] ?? null) === $a) { $used = true; break; }
                if (!$used) KV::delete('ilogo:' . $a);
            }
            send_json(['ok' => true]);
            return;
        }

        case '/api/remove-many': {
            $ids = is_array($b['ids'] ?? null) ? array_map('strval', $b['ids']) : [];
            $set = array_flip($ids);
            if (!$set) { send_json(['error' => 'Yayın seçilmedi'], 400); return; }
            $gone = []; $left = [];
            foreach ($items as $x) { if (isset($set[$x['id']])) $gone[] = $x; else $left[] = $x; }
            if (!$gone) { send_json(['error' => 'Bulunamadı'], 404); return; }
            foreach ($gone as $g) KV::delete('seg:' . $g['id']);
            KV::save_items($left);
            $used = [];
            foreach ($left as $x) { $a = asset_of($x['logo'] ?? null); if ($a) $used[$a] = 1; }
            foreach ($gone as $g) { $a = asset_of($g['logo'] ?? null); if ($a && !isset($used[$a])) KV::delete('ilogo:' . $a); }
            send_json(['ok' => true, 'removed' => count($gone)]);
            return;
        }

        case '/api/clear': {
            foreach ($items as $it) KV::delete('seg:' . $it['id']);
            $cfg['running'] = false;
            KV::save_items([]);
            KV::save_cfg($cfg);
            send_json(['ok' => true]);
            return;
        }

        case '/api/move': {
            $id = (string)($b['id'] ?? '');
            $dir = (string)($b['dir'] ?? '');
            $i = -1;
            foreach ($items as $k => $x) if ($x['id'] === $id) { $i = $k; break; }
            if ($i < 0) { send_json(['error' => 'Bulunamadı'], 404); return; }
            $j = $dir === 'up' ? $i - 1 : $i + 1;
            if ($j >= 0 && $j < count($items)) {
                $t = $items[$i]; $items[$i] = $items[$j]; $items[$j] = $t;
                KV::save_items($items);
            }
            send_json(['ok' => true]);
            return;
        }

        case '/api/start': {
            if (!$items) { send_json(['error' => 'Önce listeye yayın ekleyin'], 400); return; }
            $cfg['running'] = true;
            $cfg['startAt'] = (int)(microtime(true) * 1000);
            KV::save_cfg($cfg);
            send_json(['ok' => true, 'startAt' => $cfg['startAt']]);
            return;
        }
        case '/api/stop': {
            $cfg['running'] = false;
            KV::save_cfg($cfg);
            send_json(['ok' => true]);
            return;
        }
        case '/api/loop': {
            $cfg['loop'] = !empty($b['loop']);
            KV::save_cfg($cfg);
            send_json(['ok' => true]);
            return;
        }

        case '/api/settings': {
            if (isset($b['name'])) $cfg['name'] = mb_substr(trim((string)$b['name']), 0, 60) ?: DEFAULT_CFG['name'];
            if (isset($b['showInfo'])) $cfg['showInfo'] = (bool)$b['showInfo'];
            if (isset($b['proxy'])) $cfg['proxy'] = (bool)$b['proxy'];
            if (isset($b['tz'])) $cfg['tz'] = (int)round(num($b['tz'], -720, 840, $cfg['tz']));
            if (isset($b['logo']) && is_array($b['logo'])) {
                $lu = mb_substr(trim((string)($b['logo']['url'] ?? '')), 0, 500);
                $okUrl = $lu === '' || str_starts_with($lu, '/logo') || preg_match('#^https?://#i', $lu);
                $cfg['logo'] = [
                    'on' => !empty($b['logo']['on']),
                    'url' => $okUrl ? $lu : '',
                    'x' => num($b['logo']['x'] ?? 2, 0, 100, 2),
                    'y' => num($b['logo']['y'] ?? 4, 0, 100, 4),
                    'size' => num($b['logo']['size'] ?? 12, 1, 100, 12),
                    'opacity' => num($b['logo']['opacity'] ?? 100, 5, 100, 100),
                ];
            }
            KV::save_cfg($cfg);
            send_json(['ok' => true, 'logo' => $cfg['logo']]);
            return;
        }

        case '/api/logo-upload': {
            if (!preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,([A-Za-z0-9+/=]+)$#', (string)($b['data'] ?? ''), $m)) {
                send_json(['error' => 'Geçersiz logo verisi'], 400); return;
            }
            if (strlen($m[2]) > 2500000) { send_json(['error' => 'Logo çok büyük'], 400); return; }
            $rec = json_encode(['mime' => $m[1], 'b64' => $m[2]]);
            KV::put('logo:data', $rec);
            if (($b['orig'] ?? true) !== false) KV::put('logo:orig', $rec);
            $cfg['logo']['url'] = '/logo?v=' . time();
            $cfg['logo']['on'] = true;
            KV::save_cfg($cfg);
            send_json(['ok' => true, 'url' => $cfg['logo']['url']]);
            return;
        }

        case '/api/logo-import': {
            $u = trim((string)($b['url'] ?? ''));
            if (!preg_match('#^https?://#i', $u)) { send_json(['error' => 'Geçerli bir logo adresi girin'], 400); return; }
            $r = http_get($u, [], 20);
            if ($r['status'] < 200 || $r['status'] >= 300) { send_json(['error' => 'Logo açılamadı: HTTP ' . $r['status']], 400); return; }
            $ct = strtolower(trim(explode(';', $r['headers']['content-type'] ?? '')[0]));
            if (!preg_match('#^image/(png|jpeg|webp|gif)$#', $ct)) { send_json(['error' => 'Bu adres bir resim değil'], 400); return; }
            if (strlen($r['body']) > 1800000) { send_json(['error' => 'Logo çok büyük (en fazla ~1.8 MB)'], 400); return; }
            $rec = json_encode(['mime' => $ct, 'b64' => base64_encode($r['body'])]);
            KV::put('logo:data', $rec);
            KV::put('logo:orig', $rec);
            $cfg['logo']['url'] = '/logo?v=' . time();
            $cfg['logo']['on'] = true;
            KV::save_cfg($cfg);
            send_json(['ok' => true, 'url' => $cfg['logo']['url']]);
            return;
        }

        case '/api/logo-restore': {
            $d = KV::get_json('logo:orig');
            if (!$d) { send_json(['error' => 'Orijinal logo yok'], 404); return; }
            KV::put('logo:data', json_encode($d));
            $cfg['logo']['url'] = '/logo?v=' . time();
            KV::save_cfg($cfg);
            send_json(['ok' => true, 'url' => $cfg['logo']['url']]);
            return;
        }

        // out-save / out-start / out-stop / pump: bu sürümde RTMP gönderici yok
        case '/api/out-save':
        case '/api/out-start':
        case '/api/out-stop':
            send_json(['error' => 'Bu PHP sürümünde RTMP/YouTube gönderici yok. Sunucuda ffmpeg ile gönderin.'], 501);
            return;
        case '/api/pump':
            // Boş yanıt: panel bunu bekliyor, hata vermesin
            send_text('');
            return;
    }
    send_json(['error' => 'Bilinmeyen istek'], 404);
}

function state_out(array $cfg, array $items): array {
    $tl = $items ? build_timeline($items) : ['mode' => 'seq', 'slots' => [], 'origin' => 0];
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
    $total = 0; foreach ($items as $i) $total += $i['duration'];
    $acc = 0;
    $list = [];
    foreach ($items as $i) {
        $list[] = [
            'id' => $i['id'],
            'title' => $i['title'],
            'bolum' => epi_of($i['title']),
            'url' => $i['url'],
            'duration' => $i['duration'],
            'startOffset' => $acc,
            'at' => isset($i['at']) ? pad2(intdiv($i['at'], 60)) . ':' . pad2($i['at'] % 60) : '',
            'segCount' => $i['segCount'],
            'live' => !empty($i['live']),
            'logo' => $i['logo'] ?? null,
        ];
        $acc += $i['duration'];
    }
    return [
        'running' => $cfg['running'],
        'startAt' => $cfg['startAt'],
        'loop' => $cfg['loop'],
        'mode' => $tl['mode'],
        'program' => $program,
        'total' => $total,
        'serverNow' => (int)(microtime(true) * 1000),
        'now' => now_info($cfg, $items),
        'showInfo' => $cfg['showInfo'] !== false,
        'proxy' => $cfg['proxy'] !== false,
        'tz' => $cfg['tz'],
        'name' => $cfg['name'],
        'logo' => $cfg['logo'],
        'items' => $list,
    ];
}

function out_info(array $cfg): array {
    return [
        'cron' => 0,
        'yt' => ['on' => false, 'hasKey' => false, 'tail' => '', 'mode' => 'rtmp', 'alive' => false, 'st' => null, 'log' => []],
        'cu' => ['on' => false, 'hasKey' => false, 'url' => '', 'mode' => 'hls', 'alive' => false, 'st' => null, 'log' => []],
    ];
}

/* ============ Oynatıcı HTML ============ */
const PLAYER_HTML = <<<'HTML'
<!doctype html>
<html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#000000">
<link rel="manifest" href="manifest.webmanifest">
<title>Canlı Yayın</title>
<style>
html,body{margin:0;height:100%;background:#000;color:#fff;font-family:system-ui,sans-serif;overflow:hidden}
#w{position:relative;width:100%;height:100%;background:#000}
video{width:100%;height:100%;background:#000;object-fit:cover}
#lg{position:absolute;pointer-events:none;display:none;z-index:5}
#t{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,.6);padding:8px 14px;border-radius:8px;font-size:15px;display:none;text-align:center;max-width:90%}
#info{position:absolute;left:10px;bottom:56px;background:rgba(0,0,0,.55);padding:.25em .55em;border-radius:.45em;font-size:11px;display:none;line-height:1.35;pointer-events:none;z-index:6;box-sizing:border-box;overflow:hidden}
#info b{display:block;font-size:1.05em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#info span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:.9}
#er{position:absolute;left:10px;top:10px;background:rgba(127,29,29,.8);padding:5px 9px;border-radius:8px;font-size:12px;display:none;max-width:70%}
#hint{position:absolute;left:50%;top:12px;transform:translateX(-50%);background:rgba(0,0,0,.6);color:#fff;padding:6px 12px;border-radius:999px;font-size:12px;z-index:7;pointer-events:none;display:none;white-space:nowrap}
#fs{position:absolute;right:8px;top:8px;background:rgba(0,0,0,.45);color:#fff;border:0;border-radius:8px;padding:6px 10px;font-size:16px;cursor:pointer;opacity:.7}
@media (orientation:portrait) and (pointer:coarse){
#w{position:fixed;left:0;top:0;width:100vh;width:100dvh;height:100vw;transform-origin:0 0;transform:translateX(100vw) rotate(90deg)}
}
#w:fullscreen{transform:none!important;width:100%!important;height:100%!important}
#w:-webkit-full-screen{transform:none!important;width:100%!important;height:100%!important}
</style></head><body>
<div id="w">
<video id="v" controls controlsList="nofullscreen" autoplay playsinline></video>
<img id="lg" alt="">
<div id="t">Yükleniyor...</div>
<div id="info"></div>
<div id="er"></div>
<div id="hint">Tam ekran ve ses için ekrana dokun</div>
<button id="fs" title="Tam ekran">&#x26F6;</button>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.13/hls.min.js"></script>
<script>
var v=document.getElementById('v'),w=document.getElementById('w'),lg=document.getElementById('lg'),tt=document.getElementById('t'),info=document.getElementById('info'),er=document.getElementById('er');
var src='live.m3u8?raw=1';
var h=null,lastLogo='',N=null,t0=0;
function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function errMsg(m){er.textContent=m;er.style.display=m?'block':'none'}
function start(){
  if(h){try{h.destroy()}catch(e){}h=null;}
  if(window.Hls&&Hls.isSupported()){
    h=new Hls({liveSyncDurationCount:3,manifestLoadingMaxRetry:2,levelLoadingMaxRetry:2,fragLoadingMaxRetry:4});
    h.loadSource(src);h.attachMedia(v);
    h.on(Hls.Events.FRAG_LOADED,function(){errMsg('')});
    h.on(Hls.Events.MANIFEST_PARSED,function(){tryPlay()});
    h.on(Hls.Events.ERROR,function(e,d){
      var det=(d.details||'')+(d.response&&d.response.code?(' '+d.response.code):'');
      if(!d.fatal){if(d.details==='fragLoadError')errMsg('Segment alınamadı: '+det);return;}
      errMsg('Hata: '+det);
      if(d.type===Hls.ErrorTypes.MEDIA_ERROR){h.recoverMediaError();}
      else{try{h.destroy()}catch(x){}h=null;setTimeout(start,3000);}
    });
  }else if(v.canPlayType('application/vnd.apple.mpegurl')){
    v.src=src;
  }else{errMsg('Bu tarayıcı HLS oynatmayı desteklemiyor');}
  tryPlay();
}
function goFull(){
  if(document.fullscreenElement||document.webkitFullscreenElement)return;
  var f=w.requestFullscreen||w.webkitRequestFullscreen;
  if(!f)return;
  var lk=function(){try{screen.orientation.lock('landscape').catch(function(){})}catch(e){}};
  try{
    var pr=f.call(w);
    if(pr&&pr.then)pr.then(lk).catch(function(){});else lk();
  }catch(e){}
}
var hintEl=document.getElementById('hint');
function hideHint(){hintEl.style.display='none'}
(function(){
  var ev=['pointerdown','mousedown','pointerup','click','touchend','keydown'];
  function go(){
    var act=navigator.userActivation?navigator.userActivation.isActive:true;
    if(!act)return;
    v.muted=false;v.volume=1;
    var p=v.play();if(p&&p.catch)p.catch(function(){});
    goFull();hideHint();
    ev.forEach(function(e){document.removeEventListener(e,go,true)});
  }
  ev.forEach(function(e){document.addEventListener(e,go,true)});
  hintEl.style.display='block';
  setTimeout(hideHint,12000);
  try{goFull()}catch(e){}
})();
var armed=false;
function armUnmute(){
  if(armed)return;armed=true;
  var ev=['click','touchstart','pointerdown','keydown'];
  function go(){
    v.muted=false;v.volume=1;
    var p=v.play();if(p&&p.catch)p.catch(function(){});
    ev.forEach(function(e){document.removeEventListener(e,go,true)});
    armed=false;
  }
  ev.forEach(function(e){document.addEventListener(e,go,true)});
}
function tryPlay(){
  v.muted=false;
  var p=v.play();
  if(p&&p.catch)p.catch(function(){
    v.muted=true;
    var q=v.play();if(q&&q.catch)q.catch(function(){});
    armUnmute();
  });
}
function ready(fn){
  if((window.Hls&&Hls.isSupported())||v.canPlayType('application/vnd.apple.mpegurl')){fn();return;}
  var s=document.createElement('script');
  s.src='https://cdn.jsdelivr.net/npm/hls.js@1.5.13/dist/hls.min.js';
  s.onload=fn;
  s.onerror=function(){errMsg('Oynatıcı kütüphanesi yüklenemedi (internet / reklam engelleyici?)')};
  document.head.appendChild(s);
}
var lgS=null;
function applyLogo(l){
  var s=JSON.stringify(l||{});
  if(s===lastLogo)return;lastLogo=s;
  lgS=l;
  if(l&&l.on&&l.url){
    lg.src=(l.url.startsWith('/')?l.url.slice(1):l.url);
  }
  placeLogo();
}
var FIT='cover';
v.style.objectFit=FIT;
function vrect(){
  var cw=w.clientWidth,ch=w.clientHeight,vw=v.videoWidth,vh=v.videoHeight;
  var rx=0,ry=0,rw=cw,rh=ch;
  if(vw&&vh&&FIT!=='cover'){var k=Math.min(cw/vw,ch/vh);rw=vw*k;rh=vh*k;rx=(cw-rw)/2;ry=(ch-rh)/2;}
  return {x:rx,y:ry,w:rw,h:rh,cw:cw,ch:ch};
}
function placeInfo(r){
  if(info.style.display==='none')return;
  var f=Math.max(9,Math.min(15,r.w/75));
  info.style.fontSize=f+'px';
  info.style.left=(r.x+r.w*0.02)+'px';
  info.style.bottom=(r.ch-(r.y+r.h)+Math.max(40,f*3))+'px';
  info.style.maxWidth=(r.w*0.7)+'px';
}
function placeLogo(){
  var r=vrect(); placeInfo(r);
  var l=lgS;
  if(!l||!l.on||!l.url){lg.style.display='none';return;}
  var lw=r.w*l.size/100;
  var lh=lg.naturalWidth?lw*lg.naturalHeight/lg.naturalWidth:lw;
  lg.style.display='block';
  lg.style.width=lw+'px';
  lg.style.left=(r.x+(r.w-lw)*l.x/100)+'px';
  lg.style.top=(r.y+(r.h-lh)*l.y/100)+'px';
  lg.style.opacity=(l.opacity/100);
}
lg.addEventListener('load',placeLogo);
v.addEventListener('canplay',function(){if(v.paused)tryPlay()});
v.addEventListener('loadedmetadata',placeLogo);
v.addEventListener('resize',placeLogo);
window.addEventListener('resize',placeLogo);
window.addEventListener('orientationchange',function(){setTimeout(placeLogo,300)});
document.addEventListener('fullscreenchange',function(){
  if(!document.fullscreenElement){try{screen.orientation.unlock()}catch(e){}}
  setTimeout(placeLogo,100);
});
function drawInfo(){
  if(!N)return;
  if(!N.running){tt.style.display='block';tt.textContent='Yayın kapalı';info.style.display='none';return;}
  tt.style.display='none';
  if(N.showInfo===false||!N.title){info.style.display='none';return;}
  info.innerHTML='<b>'+esc(N.title)+'</b>'+(N.bolum?'<span>'+esc(N.bolum)+'</span>':'');
  info.style.display='block';
  placeLogo();
}
function now(){
  fetch('api/now').then(function(r){return r.json()}).then(function(j){
    N=j;t0=Date.now();
    if(j.name)document.title=j.name;
    applyLogo(j.logo);drawInfo();
  }).catch(function(){});
}
document.getElementById('fs').onclick=function(){
  if(document.fullscreenElement){document.exitFullscreen();}
  else if(w.requestFullscreen||w.webkitRequestFullscreen){goFull();}
  else if(v.webkitEnterFullscreen){v.webkitEnterFullscreen();}
};
window.addEventListener('load',function(){ready(start)});
now();setInterval(now,5000);
</script></body></html>
HTML;

/* ============ Admin HTML ============ */
const ADMIN_HTML = <<<'HTML'
<!doctype html>
<html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>baba120 Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0f1115;color:#e8eaf0;font-family:system-ui,sans-serif;padding:14px;max-width:900px;margin:auto}
h1{font-size:20px;margin:0}h2{font-size:16px;margin:0 0 10px}
label{display:block;margin:10px 0 4px;font-size:13px;color:#aab2c5}
.bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px;flex-wrap:wrap}
.card{background:#181b22;border:1px solid #262b36;border-radius:12px;padding:14px;margin-bottom:14px}
input[type=text],input[type=password],textarea{width:100%;background:#0f1115;color:#fff;border:1px solid #333a49;border-radius:8px;padding:10px;font-size:14px}
input[type=time]{background:#0f1115;color:#fff;border:1px solid #333a49;border-radius:8px;padding:6px;font-size:13px;width:104px}
input[type=range]{width:100%}
textarea{min-height:90px;margin:8px 0}
button{background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:10px 14px;font-size:14px;cursor:pointer;margin:2px}
button.b2{background:#2a303d}button.red{background:#dc2626}button.green{background:#16a34a}
button.big{font-size:16px;padding:14px 20px}
a{color:#7db3ff}
.on{color:#4ade80}.off{color:#f87171}
.dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e;margin-right:6px;animation:p 1.2s infinite}
@keyframes p{0%{opacity:1}50%{opacity:.25}100%{opacity:1}}
.banner{padding:12px;border-radius:10px;margin-bottom:10px;font-weight:600}
.banner.live{background:#052e16;border:1px solid #16a34a;color:#86efac}
.banner.stop{background:#2a1215;border:1px solid #7f1d1d;color:#fca5a5}
.row{display:flex;gap:6px;align-items:center;padding:8px 0;border-bottom:1px solid #262b36;flex-wrap:wrap}
.row:last-child{border:0}
.row .n{width:30px;color:#8b93a7}.row .t{flex:1 1 180px;min-width:0}
.row .t div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row .s,.s{font-size:12px;color:#8b93a7}
.row button{padding:6px 10px}
.row input[type=checkbox]{width:20px;height:20px;flex:none}
.row.cur{background:#0b2a1a;border-radius:8px;padding-left:6px}
.row.sel{background:#14213d;border-radius:8px;padding-left:6px}
.bulk{position:sticky;top:0;background:#181b22;padding:8px 0;z-index:3;border-bottom:1px solid #262b36;margin-bottom:4px}
#msg{margin-top:8px;font-size:13px;color:#fbbf24;white-space:pre-wrap;word-break:break-all}
code{background:#0f1115;padding:2px 6px;border-radius:6px;word-break:break-all}
#toast{position:fixed;left:50%;top:14px;transform:translateX(-50%);background:#16a34a;color:#fff;padding:10px 18px;border-radius:10px;font-weight:600;display:none;z-index:30}
#pv,#mpv{position:relative;width:100%;aspect-ratio:16/9;background:conic-gradient(#2a303d 25%,#1a1e27 0 50%,#2a303d 0 75%,#1a1e27 0) 0 0/22px 22px;border-radius:10px;overflow:hidden;margin:10px 0}
#pvl,#mpl{position:absolute;display:none}
#pvt,#mpt{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);color:#64748b;font-size:13px}
.grid3{display:grid;grid-template-columns:repeat(3,48px);gap:4px;margin:8px 0}
.grid3 button{padding:10px 0;margin:0}
.ctl{display:flex;gap:16px;flex-wrap:wrap;align-items:center}
.prow{display:flex;gap:8px;align-items:center;padding:7px 0;border-bottom:1px solid #262b36;margin:0;font-size:14px;color:#e8eaf0;cursor:pointer}
.prow input{flex:none}.prow span{min-width:0}
.pb{height:8px;background:#2a303d;border-radius:6px;overflow:hidden;margin-top:6px}
.pb i{display:block;height:100%;background:#22c55e}
.tm{color:#7db3ff;font-size:12px}
.mback{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,.72);z-index:20;display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:14px}
.mbox{background:#181b22;border:1px solid #262b36;border-radius:12px;padding:14px;width:100%;max-width:520px;margin:auto}
.osec{border-top:1px solid #262b36;margin-top:14px;padding-top:12px}
</style></head><body>
<div id="toast"></div>
<div id="app"></div>
<div id="modal"></div>
<script>
var KEY=localStorage.getItem('b120key')||'';
var S=null,filled=false,V='';
var L={on:false,url:'',x:2,y:4,size:12,opacity:100};
var OR=null,PVSRC='',BGC=null;
var SEL={};
function $(id){return document.getElementById(id)}
function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function pad(n){return String(n).padStart(2,'0')}
function fmt(s){s=Math.max(0,Math.round(s));var h=Math.floor(s/3600),m=Math.floor((s%3600)/60),x=s%60;return h+':'+pad(m)+':'+pad(x)}
function clamp(v,a,b){return Math.min(b,Math.max(a,v))}
function hm(ms){return new Date(ms).toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'})}
function hm2(sec){sec=((Math.round(sec)%86400)+86400)%86400;return pad(Math.floor(sec/3600))+':'+pad(Math.floor((sec%3600)/60))}
function toast(t){var e=$('toast');if(!e)return;e.textContent=t;e.style.display='block';clearTimeout(window.__to);window.__to=setTimeout(function(){e.style.display='none'},3500)}
function setMsg(t){var m=$('msg');if(m)m.textContent=t||''}
function api(path,method,body){
  return fetch(path,{method:method||'GET',headers:{'Content-Type':'application/json','X-Admin-Key':KEY},body:body?JSON.stringify(body):undefined})
  .then(function(r){return r.json().then(function(j){
    if(r.status===401){KEY='';localStorage.removeItem('b120key');init();throw new Error('Yetkisiz');}
    if(!r.ok)throw new Error(j.error||('HTTP '+r.status));
    return j;
  })});
}
function init(){
  clearInterval(window.__t);clearInterval(window.__p);
  fetch('api/status').then(function(r){return r.json()}).then(function(s){
    if(s.error){$('app').innerHTML='<div class="card">'+esc(s.error)+'</div>';return;}
    if(!s.hasKey)setup();else if(!KEY)login();else{
      api('api/state').then(function(){panel()}).catch(function(){});
    }
  }).catch(function(e){$('app').innerHTML='<div class="card">Hata: '+esc(e.message)+'</div>'});
}
function setup(){
  $('app').innerHTML='<div class="card"><h2>Admin anahtarını belirle</h2><input type="password" id="k" placeholder="Anahtar (en az 4 karakter)"><br><br><button onclick="doSetup()">Kaydet</button><div id="msg"></div></div>';
}
function doSetup(){
  var k=$('k').value;
  fetch('api/setup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({key:k})})
  .then(function(r){return r.json()}).then(function(j){
    if(j.error){setMsg(j.error);return;}
    KEY=k;localStorage.setItem('b120key',k);panel();
  });
}
function login(){
  $('app').innerHTML='<div class="card"><h2>Admin girişi</h2><input type="password" id="k" placeholder="Anahtar"><br><br><button onclick="doLogin()">Giriş</button><div id="msg"></div></div>';
}
function doLogin(){
  KEY=$('k').value;
  api('api/state').then(function(){localStorage.setItem('b120key',KEY);panel()}).catch(function(){setMsg('Anahtar hatalı')});
}
function logout(){KEY='';localStorage.removeItem('b120key');init()}

function gridBtns(fn){
  var g='',ys=[4,50,96],xs=[2,50,98],ar=['&#8598;','&#8593;','&#8599;','&#8592;','&#9679;','&#8594;','&#8601;','&#8595;','&#8600;'],n=0;
  for(var r=0;r<3;r++)for(var c=0;c<3;c++){g+='<button class="b2" onclick="'+fn+'('+xs[c]+','+ys[r]+')">'+ar[n++]+'</button>';}
  return g;
}

function panel(){
  filled=false;
  $('app').innerHTML=
  '<div class="bar"><h1>baba120 Yayın Paneli</h1><span><a href="live.m3u8" target="_blank">Yayını Aç</a> <button class="b2" onclick="logout()">Çıkış</button></span></div>'
  +'<div class="card" id="st"></div>'

  +'<div class="card"><h2>Film / Dizi Listesi Ekle</h2>'
  +'<input type="text" id="lu" placeholder="Liste adresi (.m3u) — içindeki yayınlar listelenir">'
  +'<div style="margin-top:8px"><button onclick="importList()">Listeyi Oku</button></div>'
  +'<label>Veya tek tek / yapıştır (her satıra bir adres, ya da liste içeriği)</label>'
  +'<textarea id="ta" placeholder="Film Adı | https://site.com/film.m3u8"></textarea>'
  +'<button class="b2" onclick="addItems()">Satırları Ekle</button>'
  +'<div id="msg"></div></div>'

  +'<div class="card" id="pvc" style="display:none"><h2>Liste Önizleme <span id="pcnt" class="s"></span></h2>'
  +'<input type="text" id="pf" placeholder="Ara..." oninput="drawPend()">'
  +'<div style="margin:8px 0"><button class="green" onclick="addPendAll()">Tümünü Ekle</button><button onclick="addPendSel()">Seçilenleri Ekle</button>'
  +'<button class="b2" onclick="pendSelect(true)">Tümünü Seç</button><button class="b2" onclick="pendSelect(false)">Seçimi Kaldır</button><button class="b2" onclick="pendClose()">Kapat</button></div>'
  +'<div id="pl" style="max-height:380px;overflow:auto"></div></div>'

  +'<div class="card"><h2>Yayın Sırası ve Saatleri</h2>'
  +'<div class="s" style="margin-bottom:6px">Bir yayının yanındaki saat kutusunu doldurursan o yayın her gün o saatte oynar. Saati boş olanlar aradaki boşlukları sırayla doldurur. Her bölüme ayrı logo ekleyebilirsin; logosu olmayan bölümlerde genel logo görünür.</div>'
  +'<div class="bulk"><span class="s" id="scnt"></span><div>'
  +'<button class="b2" onclick="selAll(true)">Tümünü Seç</button>'
  +'<button class="b2" onclick="selAll(false)">Seçimi Kaldır</button>'
  +'<button onclick="logoSel()">Seçililere Logo Ekle</button>'
  +'<button class="b2" onclick="logoClearSel()">Seçililerin Logosunu Kaldır</button>'
  +'<button class="red" onclick="delSel()">Seçilenleri Sil</button></div></div>'
  +'<div id="lst"></div>'
  +'<div style="margin-top:10px"><button class="red" onclick="clearAll()">Listeyi Temizle</button></div></div>'

  +'<div class="card" id="prgc" style="display:none"><h2>Günlük Program (her gün tekrar)</h2><div id="prg"></div></div>'

  +'<div class="card"><h2>Genel Logo</h2><div class="s">Konum, boyut ve saydamlık otomatik kaydedilir; tarayıcıda filmin üzerinde aynı yerde görünür. Özel logosu olmayan tüm bölümlerde bu logo kullanılır.</div>'
  +'<label style="display:inline-block"><input type="checkbox" id="lon" onchange="lchg()"> Logoyu göster</label>'
  +'<div id="pv"><img id="pvl" alt=""><span id="pvt">Logo yok</span></div>'
  +'<input type="text" id="lurl" placeholder="Logo adresi (https://...png)" oninput="lchg()">'
  +'<div style="margin-top:6px"><button class="b2" onclick="limport()">Adresten Al</button></div>'
  +'<label>Veya dosyadan yükle</label><input type="file" id="lf" accept="image/*" onchange="lup(this)">'

  +'<label>Arka planı temizle</label>'
  +'<div class="ctl"><label style="display:inline-block;margin:0"><input type="checkbox" id="bga" checked onchange="bgchg()"> Otomatik (köşe rengi)</label>'
  +'<span>Renk: <input type="color" id="bgc" value="#ffffff" onchange="bgchg()"></span></div>'
  +'<label>Tolerans: <span id="bgtv">15</span></label><input type="range" id="bgt" min="1" max="100" value="15" oninput="bgchg()">'
  +'<label style="display:inline-block"><input type="checkbox" id="bge" checked onchange="bgchg()"> Sadece kenardan sil (logonun içindeki renkler kalsın)</label>'
  +'<div><button class="b2" onclick="bgchg()">Önizle</button><button class="green" onclick="bgSave()">Temizlenmiş Logoyu Kaydet</button><button class="b2" onclick="bgReset()">Orijinali Geri Yükle</button></div>'

  +'<label>Konum (hazır)</label><div class="grid3" id="lgrid"></div>'
  +'<label>İnce ayar</label><div class="ctl"><span>'
  +'<button class="b2" onclick="lnudge(-1,0)">&larr;</button><button class="b2" onclick="lnudge(0,-1)">&uarr;</button>'
  +'<button class="b2" onclick="lnudge(0,1)">&darr;</button><button class="b2" onclick="lnudge(1,0)">&rarr;</button></span>'
  +'<span class="s" id="lxy"></span></div>'
  +'<label>Boyut: <span id="lszv"></span></label><input type="range" id="lsz" min="3" max="60" oninput="lchg()">'
  +'<label>Saydamlık: <span id="lopv"></span></label><input type="range" id="lop" min="10" max="100" oninput="lchg()">'
  +'<div style="margin-top:10px"><button class="green" onclick="saveLogo()">Logoyu Kaydet</button></div></div>'

  +'<div class="card"><h2>Gerçekten sınırsız yayın (PC / VPS)</h2>'
  +'<div class="s">Bu PHP sürümünde RTMP gönderici yoktur. Sunucuda ffmpeg ile gönderim yapabilirsin. Aşağıdaki komut YouTube anahtarını yazınca güncellenir (RTMP gerekir).</div>'
  +'<label>YouTube yayın anahtarı</label>'
  +'<input type="text" id="ytk" placeholder="YouTube yayın anahtarı" autocomplete="off" oninput="drawFf()">'
  +'<textarea id="ffc" readonly style="min-height:110px"></textarea>'
  +'<button class="b2" onclick="copyTxt($(\'ffc\').value)">Komutu Kopyala</button></div>'

  +'<div class="card"><h2>Ayarlar</h2>'
  +'<label>Kanal adı</label><input type="text" id="cn" placeholder="baba120 TV">'
  +'<label style="display:inline-block;margin-top:12px"><input type="checkbox" id="spx"> Segmentleri worker üzerinden ver</label><br>'
  +'<label style="display:inline-block"><input type="checkbox" id="sinf"> Tarayıcıda film/dizi adı ve bölümü göster</label>'
  +'<div style="margin-top:10px"><button class="green" onclick="saveKeys()">Ayarları Kaydet</button></div></div>';

  $('lgrid').innerHTML=gridBtns('lpos');
  load();
  clearInterval(window.__t);
  window.__t=setInterval(load,5000);
}
function load(){
  api('api/state').then(function(s){
    S=s;
    if(!filled){filled=true;fillSettings();}
    drawStatus();drawList();drawProgram();
  }).catch(function(){});
}
function fillSettings(){
  L=S.logo||L;
  fillLogoInputs();
  $('cn').value=S.name||'';
  $('spx').checked=S.proxy!==false;
  $('sinf').checked=S.showInfo!==false;
  drawFf();
  var tz=-new Date().getTimezoneOffset();
  if(S.tz!==tz){api('api/settings','POST',{tz:tz}).catch(function(){});}
  drawLogo();
}
function liveUrl(){return new URL('live.m3u8',location.href).href}
function drawStatus(){
  var h='',n=S.now;
  if(S.running){
    h+='<div class="banner live"><span class="dot"></span>YAYIN BAŞLADI — YAYINDA<div class="s" style="color:#86efac;font-weight:400">'
    +(S.mode==='day'?'Saatli günlük program':'Sıralı döngü')+' &bull; Başlatıldı: '+esc(new Date(S.startAt).toLocaleString('tr-TR'))+'</div>'
    +(V?'<div style="font-weight:400;margin-top:4px">'+esc(V)+'</div>':'')+'</div>';
  }else{
    h+='<div class="banner stop">YAYIN KAPALI</div>';
  }
  h+='<div>'+S.items.length+' yayın &bull; Toplam süre: '+fmt(S.total)+'</div>';
  if(n){
    h+='<div style="margin-top:8px"><b>'+esc(n.title)+'</b></div>'
    +'<div class="s">'+(n.bolum?esc(n.bolum)+' &bull; ':'')+'Sıra '+n.index+'/'+n.count+' &bull; '+fmt(n.offset)+' / '+fmt(n.duration)+' &bull; <b>%'+n.percent+'</b></div>'
    +'<div class="pb"><i style="width:'+n.percent+'%"></i></div>';
    if(n.next)h+='<div class="s" style="margin-top:6px">Sıradaki: '+esc(n.next)+'</div>';
    if(n.ended)h+='<div class="off">Liste bitti.</div>';
  }
  h+='<div style="margin-top:10px">';
  if(S.running){
    h+='<button class="red big" onclick="toggleLive()">&#9632; Yayını Durdur</button>';
    if(S.mode!=='day')h+='<button class="b2" onclick="restartLive()">&#8634; Baştan Başlat</button>';
  }else{
    h+='<button class="green big" onclick="toggleLive()">&#9654; Yayını Başlat</button>';
  }
  h+='</div>';
  if(S.mode!=='day')h+='<div style="margin-top:8px"><label style="display:inline-block;margin:0"><input type="checkbox" '+(S.loop?'checked':'')+' onchange="setLoop(this.checked)"> Liste bitince başa dön</label></div>';
  h+='<div style="margin-top:10px">Yayın adresi: <code>'+esc(liveUrl())+'</code> <button class="b2" onclick="copyTxt(liveUrl())">Kopyala</button></div>';
  $('st').innerHTML=h;
}
function toggleLive(){
  if(S.running){
    if(!confirm('Yayın durdurulsun mu?'))return;
    api('api/stop','POST').then(function(){V='';toast('Yayın durduruldu');load()}).catch(function(e){setMsg(e.message)});
  }else{
    api('api/start','POST').then(function(){toast('Yayın başladı ✓');V='Kontrol ediliyor...';load();verify()}).catch(function(e){toast(e.message)});
  }
}
function restartLive(){
  if(!confirm('Yayın ilk yayından yeniden başlasın mı?'))return;
  api('api/start','POST').then(function(){toast('Yayın baştan başladı ✓');V='Kontrol ediliyor...';load();verify()}).catch(function(e){toast(e.message)});
}
function verify(){
  setTimeout(function(){
    fetch(liveUrl()+'?raw=1',{cache:'no-store'}).then(function(r){return r.text().then(function(t){
      V=(r.ok&&t.indexOf('#EXTM3U')===0)?'✓ Yayın çalışıyor (playlist hazır)':('⚠ Yayın hatası: '+t.slice(0,80));
      drawStatus();
    })}).catch(function(){V='⚠ Yayın adresine ulaşılamadı';drawStatus();});
  },1200);
}
function setLoop(v){api('api/loop','POST',{loop:v}).then(load)}
function copyTxt(t){navigator.clipboard.writeText(t);toast('Kopyalandı')}
function ffcmd(){
  var k=($('ytk')&&$('ytk').value.trim())||'YAYIN_ANAHTARI';
  return 'while true; do ffmpeg -re -i "'+liveUrl()+'?raw=1" -c:v libx264 -preset veryfast -b:v 3000k -maxrate 3000k -bufsize 6000k -g 60 -keyint_min 60 -vf scale=1280:720 -r 30 -c:a aac -b:a 128k -ar 44100 -ac 2 -f flv "rtmp://a.rtmp.youtube.com/live2/'+k+'"; sleep 3; done';
}
function drawFf(){var e=$('ffc');if(e)e.value=ffcmd()}

function selIds(){var out=[];if(S)S.items.forEach(function(i){if(SEL[i.id])out.push(i.id)});return out;}
function updSel(){
  var ids={},k;
  S.items.forEach(function(i){ids[i.id]=1});
  for(k in SEL){if(!ids[k])delete SEL[k];}
  var el=$('scnt'); if(el)el.textContent=selIds().length+' / '+S.items.length+' yayın seçili';
}
function selTog(id,v){SEL[id]=v;drawList()}
function selAll(flag){S.items.forEach(function(i){SEL[i.id]=flag});drawList();}
function delSel(){
  var ids=selIds();
  if(!ids.length){toast('Hiç yayın seçilmedi');return;}
  if(!confirm(ids.length+' yayın silinsin mi?'))return;
  api('api/remove-many','POST',{ids:ids}).then(function(r){SEL={};toast(r.removed+' yayın silindi');load()}).catch(function(e){toast(e.message)});
}
function logoSel(){openLogo(selIds())}
function logoOne(id){openLogo([id])}
function logoClearSel(){
  var ids=selIds();
  if(!ids.length){toast('Hiç yayın seçilmedi');return;}
  if(!confirm(ids.length+' yayının özel logosu kaldırılsın mı?'))return;
  api('api/item-logo','POST',{ids:ids,logo:null}).then(function(){toast('Logolar kaldırıldı');load()}).catch(function(e){toast(e.message)});
}
function drawList(){
  if(!S.items.length){$('lst').innerHTML='<div class="s">Liste boş.</div>';updSel();return;}
  updSel();
  var n=S.now,cur=(n&&n.index)?n.index-1:-1,base=0,h='';
  if(S.running&&S.mode==='seq'){base=S.startAt+((n&&n.cycle)||0)*S.total*1000;}
  S.items.forEach(function(it,i){
    var tm='';
    if(S.running&&S.mode==='seq')tm=hm(base+it.startOffset*1000)+' – '+hm(base+(it.startOffset+it.duration)*1000);
    var lgt=it.logo?(it.logo.on?' &bull; <b class="tm">özel logo</b>':' &bull; <b class="tm">logo gizli</b>'):'';
    h+='<div class="row'+(i===cur?' cur':(SEL[it.id]?' sel':''))+'">'
    +'<input type="checkbox" '+(SEL[it.id]?'checked':'')+' onchange="selTog(\''+it.id+'\',this.checked)">'
    +'<div class="n">'+(i+1)+'</div><div class="t"><div>'+esc(it.title)+'</div>'
    +'<div class="s">'+(it.bolum?esc(it.bolum)+' &bull; ':'')+fmt(it.duration)+(tm?' &bull; <span class="tm">'+tm+'</span>':'')+lgt+(i===cur?' &bull; <b class="on">ŞU AN %'+n.percent+'</b>':'')+'</div>'
    +(i===cur?'<div class="pb"><i style="width:'+n.percent+'%"></i></div>':'')
    +'</div>'
    +'<input type="time" value="'+esc(it.at)+'" onchange="setAt(\''+it.id+'\',this.value)">'
    +(it.at?'<button class="b2" onclick="setAt(\''+it.id+'\',\'\')">&#10005;</button>':'')
    +'<button class="b2" onclick="openEdit(\''+it.id+'\')">Düzenle</button>'
    +'<button class="b2" onclick="logoOne(\''+it.id+'\')">Logo</button>'
    +'<button class="b2" onclick="mv(\''+it.id+'\',\'up\')">&uarr;</button>'
    +'<button class="b2" onclick="mv(\''+it.id+'\',\'down\')">&darr;</button>'
    +'<button class="b2" onclick="rf(\''+it.id+'\')">&#8635;</button>'
    +'<button class="red" onclick="rm(\''+it.id+'\')">Sil</button></div>';
  });
  $('lst').innerHTML=h;
}
function drawProgram(){
  var c=$('prgc');
  if(S.mode!=='day'){c.style.display='none';return;}
  c.style.display='block';
  var cur=(S.now&&S.now.slot!=null)?S.now.slot:-1,h='';
  S.program.forEach(function(p,i){
    h+='<div class="row'+(i===cur?' cur':'')+'"><div class="n tm" style="width:52px">'+hm2(p.from)+'</div><div class="t"><div>'+esc(p.title)+'</div>'
    +'<div class="s">'+(p.bolum?esc(p.bolum)+' &bull; ':'')+hm2(p.from)+' – '+hm2(p.from+p.len)+' &bull; '+(p.fixed?'<b class="tm">sabit saat</b>':'dolgu')+(i===cur?' &bull; <b class="on">ŞU AN</b>':'')+'</div></div></div>';
  });
  $('prg').innerHTML=h||'<div class="s">Program yok</div>';
}
function setAt(id,val){api('api/at','POST',{id:id,at:val}).then(function(){toast(val?'Saat kaydedildi ✓':'Saat kaldırıldı');load()}).catch(function(e){toast(e.message)})}
function mv(id,dir){api('api/move','POST',{id:id,dir:dir}).then(load)}
function rm(id){if(confirm('Silinsin mi?'))api('api/remove','POST',{id:id}).then(function(){delete SEL[id];load()})}
function rf(id){setMsg('Yenileniyor...');api('api/refresh','POST',{id:id}).then(function(){setMsg('Süre yenilendi');load()}).catch(function(e){setMsg(e.message)})}
function clearAll(){if(confirm('TÜM liste silinsin mi? Yayın da durur.'))api('api/clear','POST').then(function(){SEL={};toast('Liste temizlendi');load()})}

var EID='';
function closeModal(){$('modal').innerHTML=''}
function openEdit(id){
  var it=null;S.items.forEach(function(i){if(i.id===id)it=i});
  if(!it)return;
  EID=id;
  $('modal').innerHTML='<div class="mback"><div class="mbox"><h2>Yayını Düzenle</h2>'
  +'<label>Ad</label><input type="text" id="etitle">'
  +'<label>Kaynak adresi (m3u8)</label><input type="text" id="eurl">'
  +'<label>Oynama saati (boş = sıralı akış)</label><input type="time" id="eat">'
  +'<div style="margin-top:12px"><button class="green" onclick="saveEdit()">Kaydet</button><button class="b2" onclick="closeModal()">İptal</button></div>'
  +'<div id="emsg" class="s" style="margin-top:8px;color:#fbbf24"></div></div></div>';
  $('etitle').value=it.title;
  $('eurl').value=it.url;
  $('eat').value=it.at||'';
}
function saveEdit(){
  $('emsg').textContent='Kaydediliyor...';
  api('api/edit','POST',{id:EID,title:$('etitle').value,url:$('eurl').value,at:$('eat').value})
  .then(function(){closeModal();toast('Kaydedildi ✓');load()})
  .catch(function(e){$('emsg').textContent=e.message});
}

var ML=null,MDATA=null,MIDS=[];
function openLogo(ids){
  if(!ids.length){toast('Hiç yayın seçilmedi');return;}
  MIDS=ids;MDATA=null;
  var first=null;
  S.items.forEach(function(i){if(!first&&i.id===ids[0])first=i});
  var il=first&&first.logo?first.logo:null;
  var base=il||L;
  ML={on:il?!!il.on:true,url:il?il.url:'',x:base.x,y:base.y,size:base.size,opacity:base.opacity};
  $('modal').innerHTML='<div class="mback"><div class="mbox"><h2>Logo ekle ('+ids.length+' yayın)</h2>'
  +'<label style="display:inline-block"><input type="checkbox" id="mon" onchange="mchg()"> Logoyu göster</label>'
  +'<div id="mpv"><img id="mpl" alt=""><span id="mpt">Logo yok</span></div>'
  +'<input type="text" id="murl" placeholder="Logo adresi (https://...png)" oninput="MDATA=null;mchg()">'
  +'<label>Veya dosyadan yükle</label><input type="file" id="mf" accept="image/*" onchange="mup(this)">'
  +'<label>Konum (hazır)</label><div class="grid3">'+gridBtns('mpos')+'</div>'
  +'<div class="s" id="mxy"></div>'
  +'<label>Boyut: <span id="mszv"></span></label><input type="range" id="msz" min="3" max="60" oninput="mchg()">'
  +'<label>Saydamlık: <span id="mopv"></span></label><input type="range" id="mop" min="10" max="100" oninput="mchg()">'
  +'<div style="margin-top:12px"><button class="green" onclick="msave()">Kaydet</button>'
  +'<button class="b2" onclick="mrem()">Özel Logoyu Kaldır</button><button class="b2" onclick="closeModal()">İptal</button></div>'
  +'<div id="mmsg" class="s" style="margin-top:8px;color:#fbbf24"></div></div></div>';
  $('mon').checked=ML.on;
  $('murl').value=ML.url;
  $('msz').value=ML.size;
  $('mop').value=ML.opacity;
  mdraw();
}
function mchg(){
  ML.on=$('mon').checked;
  ML.url=$('murl').value.trim();
  ML.size=parseFloat($('msz').value);
  ML.opacity=parseFloat($('mop').value);
  mdraw();
}
function mpos(x,y){ML.x=x;ML.y=y;mdraw()}
function mdraw(){
  var im=$('mpl'),src=MDATA||ML.url;
  if(src){
    if(im.getAttribute('data-s')!==src){im.setAttribute('data-s',src);im.src=src;}
    im.style.display='block';
    im.style.left=ML.x+'%';im.style.top=ML.y+'%';
    im.style.transform='translate(-'+ML.x+'%,-'+ML.y+'%)';
    im.style.width=ML.size+'%';
    im.style.opacity=ML.opacity/100;
    $('mpt').style.display='none';
  }else{
    im.style.display='none';$('mpt').style.display='block';
  }
  $('mszv').textContent=ML.size+'%';
  $('mopv').textContent=ML.opacity+'%';
  $('mxy').textContent='Yatay: '+ML.x+'%  Dikey: '+ML.y+'%';
}
function mup(inp){
  var f=inp.files[0];if(!f)return;
  var fr=new FileReader();
  fr.onload=function(){
    var im=new Image();
    im.onload=function(){
      var m=600,k=Math.min(1,m/Math.max(im.width,im.height));
      var c=document.createElement('canvas');
      c.width=Math.round(im.width*k);c.height=Math.round(im.height*k);
      c.getContext('2d').drawImage(im,0,0,c.width,c.height);
      MDATA=c.toDataURL('image/png');
      ML.url='';$('murl').value='';
      ML.on=true;$('mon').checked=true;
      mdraw();
    };
    im.src=fr.result;
  };
  fr.readAsDataURL(f);
}
function msave(){
  if(!MDATA&&!ML.url){$('mmsg').textContent='Logo adresi girin veya dosya yükleyin';return;}
  $('mmsg').textContent='Kaydediliyor...';
  api('api/item-logo','POST',{ids:MIDS,logo:ML,data:MDATA}).then(function(){
    closeModal();toast('Logo kaydedildi ✓');load();
  }).catch(function(e){$('mmsg').textContent=e.message});
}
function mrem(){
  api('api/item-logo','POST',{ids:MIDS,logo:null}).then(function(){
    closeModal();toast('Özel logo kaldırıldı');load();
  }).catch(function(e){$('mmsg').textContent=e.message});
}

function importList(){
  var u=$('lu').value.trim();
  if(!u){setMsg('Liste adresini girin');return;}
  setMsg('Liste okunuyor...');
  api('api/import','POST',{url:u}).then(function(r){showPend(r.entries,r.duplicate||0)}).catch(function(e){setMsg(e.message)});
}
function addItems(){
  var nl=String.fromCharCode(10);
  var raw=$('ta').value;
  if(!raw.trim()){setMsg('Adres veya liste girin');return;}
  if(raw.indexOf('#EXTINF')>=0){
    setMsg('Liste okunuyor...');
    api('api/import','POST',{text:raw}).then(function(r){showPend(r.entries,r.duplicate||0);$('ta').value=''}).catch(function(e){setMsg(e.message)});
    return;
  }
  var entries=[];
  raw.split(nl).map(function(x){return x.trim()}).filter(Boolean).forEach(function(l){
    var title='',url=l,p=l.indexOf('|');
    if(p>0&&!/^https?:/i.test(l.slice(0,p).trim())){title=l.slice(0,p).trim();url=l.slice(p+1).trim();}
    entries.push({title:title,url:url});
  });
  $('ta').value='';
  runBatch(entries,0);
}
var PEND=[],PSEL={};
function showPend(entries,dup){
  PEND=entries;PSEL={};
  $('pvc').style.display='block';
  $('pf').value='';
  setMsg(entries.length+' yayın bulundu'+(dup?(', '+dup+' zaten listede'):'')+'. Aşağıdan seçip ekleyin.');
  drawPend();
  $('pvc').scrollIntoView({behavior:'smooth'});
}
function pendVisible(){
  var q=$('pf').value.trim().toLowerCase(),out=[];
  PEND.forEach(function(e,i){if(!q||e.title.toLowerCase().indexOf(q)>=0)out.push(i)});
  return out;
}
function updPendCount(){
  var n=0,k;
  for(k in PSEL){if(PSEL[k])n++;}
  $('pcnt').textContent='('+pendVisible().length+'/'+PEND.length+' gösteriliyor, '+n+' seçili)';
}
function drawPend(){
  var vis=pendVisible(),h='';
  vis.forEach(function(i){
    var e=PEND[i];
    h+='<label class="prow"><input type="checkbox" '+(PSEL[i]?'checked':'')+' onchange="pendTog('+i+',this.checked)"><span>'+(i+1)+'. '+esc(e.title)+(e.bolum?' <span class="tm">'+esc(e.bolum)+'</span>':'')+'</span></label>';
  });
  $('pl').innerHTML=h||'<div class="s">Sonuç yok</div>';
  updPendCount();
}
function pendTog(i,v){PSEL[i]=v;updPendCount()}
function pendSelect(flag){pendVisible().forEach(function(i){PSEL[i]=flag});drawPend()}
function pendClose(){PEND=[];PSEL={};$('pvc').style.display='none'}
function addPendAll(){
  var l=PEND.map(function(e){return {title:e.title,url:e.url}});
  if(!l.length){toast('Liste boş');return;}
  pendClose();runBatch(l,0);
}
function addPendSel(){
  var l=[];
  PEND.forEach(function(e,i){if(PSEL[i])l.push({title:e.title,url:e.url})});
  if(!l.length){toast('Hiç yayın seçilmedi');return;}
  pendClose();runBatch(l,0);
}
function runBatch(entries,dup){
  var nl=String.fromCharCode(10),ok=0,bad=[],i=0,total=entries.length;
  if(!total){setMsg('Eklenecek yeni yayın yok'+(dup?(' ('+dup+' zaten listede)'):''));return;}
  function step(){
    if(i>=total){
      setMsg('Bitti: '+ok+' eklendi, '+bad.length+' atlandı'+(dup?(', '+dup+' zaten vardı'):'')+(bad.length?(nl+bad.slice(0,15).join(nl)):''));
      toast(ok+' yayın eklendi');load();return;
    }
    var chunk=entries.slice(i,i+3);i+=3;
    setMsg('Ekleniyor '+Math.min(i,total)+'/'+total+' — eklenen: '+ok+', atlanan: '+bad.length+nl+'(Bu sayfayı kapatmayın)');
    api('api/add-batch','POST',{entries:chunk}).then(function(r){
      r.results.forEach(function(x){if(x.ok)ok++;else bad.push(x.error+' -> '+x.url)});
      step();
    }).catch(function(e){
      chunk.forEach(function(c){bad.push(e.message+' -> '+c.url)});
      step();
    });
  }
  step();
}

function fillLogoInputs(){
  $('lon').checked=!!L.on;
  $('lurl').value=L.url||'';
  $('lsz').value=L.size;
  $('lop').value=L.opacity;
}
function autoSave(){
  clearTimeout(window.__as);
  window.__as=setTimeout(function(){api('api/settings','POST',{logo:L}).then(function(){toast('Logo kaydedildi ✓')}).catch(function(){})},800);
}
function lchg(){
  L.on=$('lon').checked;
  L.url=$('lurl').value.trim();
  L.size=parseFloat($('lsz').value);
  L.opacity=parseFloat($('lop').value);
  drawLogo();autoSave();
}
function lpos(x,y){L.x=x;L.y=y;drawLogo();autoSave()}
function lnudge(dx,dy){L.x=clamp(L.x+dx,0,100);L.y=clamp(L.y+dy,0,100);drawLogo();autoSave()}
function drawLogo(){
  var im=$('pvl'),src=PVSRC||L.url;
  if(src){
    if(im.getAttribute('data-s')!==src){im.setAttribute('data-s',src);im.src=src;}
    im.style.display='block';
    im.style.left=L.x+'%';im.style.top=L.y+'%';
    im.style.transform='translate(-'+L.x+'%,-'+L.y+'%)';
    im.style.width=L.size+'%';
    im.style.opacity=L.opacity/100;
    $('pvt').style.display='none';
  }else{
    im.style.display='none';$('pvt').style.display='block';
  }
  $('lszv').textContent=L.size+'%';
  $('lopv').textContent=L.opacity+'%';
  $('lxy').textContent='Yatay: '+L.x+'%  Dikey: '+L.y+'%';
}
function saveLogo(){
  api('api/settings','POST',{logo:L}).then(function(){toast('Logo kaydedildi ✓')}).catch(function(e){toast(e.message)});
}
function logoNew(r){
  L.url=r.url;L.on=true;OR=null;PVSRC='';BGC=null;
  fillLogoInputs();drawLogo();
  api('api/settings','POST',{logo:L});
}
function limport(){
  var u=$('lurl').value.trim();
  if(!/^https?:/i.test(u)){toast('Önce logo adresini yazın');return;}
  api('api/logo-import','POST',{url:u}).then(function(r){logoNew(r);toast('Logo alındı ✓')}).catch(function(e){toast(e.message)});
}
function lup(inp){
  var f=inp.files[0];if(!f)return;
  var fr=new FileReader();
  fr.onload=function(){
    var im=new Image();
    im.onload=function(){
      var m=600,k=Math.min(1,m/Math.max(im.width,im.height));
      var c=document.createElement('canvas');
      c.width=Math.round(im.width*k);c.height=Math.round(im.height*k);
      c.getContext('2d').drawImage(im,0,0,c.width,c.height);
      api('api/logo-upload','POST',{data:c.toDataURL('image/png'),orig:true}).then(function(r){logoNew(r);toast('Logo yüklendi ✓')}).catch(function(e){toast(e.message)});
    };
    im.src=fr.result;
  };
  fr.readAsDataURL(f);
}

function loadOrig(cb){
  if(OR){cb();return;}
  var im=new Image();
  im.onload=function(){OR=im;cb()};
  im.onerror=function(){toast('Önce logoyu yükleyin veya adresten alın')};
  im.src='logo?orig=1&t='+Date.now();
}
function cleanBg(){
  var W=OR.naturalWidth,H=OR.naturalHeight;
  var c=document.createElement('canvas');c.width=W;c.height=H;
  var x=c.getContext('2d');x.drawImage(OR,0,0);
  var im=x.getImageData(0,0,W,H),d=im.data;
  var rgb;
  if($('bga').checked){rgb=[d[0],d[1],d[2]];}
  else{var hx=$('bgc').value;rgb=[parseInt(hx.substr(1,2),16),parseInt(hx.substr(3,2),16),parseInt(hx.substr(5,2),16)];}
  var T=parseFloat($('bgt').value)*4.41;
  function match(p){
    if(d[p+3]===0)return true;
    var a=d[p]-rgb[0],b=d[p+1]-rgb[1],e=d[p+2]-rgb[2];
    return Math.sqrt(a*a+b*b+e*e)<=T;
  }
  if(!$('bge').checked){
    for(var i=0;i<d.length;i+=4){if(match(i))d[i+3]=0;}
  }else{
    var seen=new Uint8Array(W*H),stack=new Int32Array(W*H),sp=0,q;
    function push(px,py){
      if(px<0||py<0||px>=W||py>=H)return;
      q=py*W+px;
      if(seen[q])return;
      if(!match(q*4))return;
      seen[q]=1;stack[sp++]=q;
    }
    for(var a1=0;a1<W;a1++){push(a1,0);push(a1,H-1);}
    for(var b1=0;b1<H;b1++){push(0,b1);push(W-1,b1);}
    while(sp>0){
      q=stack[--sp];
      d[q*4+3]=0;
      var px=q%W,py=(q-px)/W;
      push(px+1,py);push(px-1,py);push(px,py+1);push(px,py-1);
    }
  }
  x.putImageData(im,0,0);
  return c;
}
function bgchg(){
  $('bgtv').textContent=$('bgt').value;
  loadOrig(function(){
    BGC=cleanBg();
    PVSRC=BGC.toDataURL('image/png');
    drawLogo();
  });
}
function bgSave(){
  if(!BGC){toast('Önce "Önizle" ile temizleyin');return;}
  api('api/logo-upload','POST',{data:BGC.toDataURL('image/png'),orig:false}).then(function(r){
    L.url=r.url;L.on=true;PVSRC='';BGC=null;fillLogoInputs();drawLogo();
    api('api/settings','POST',{logo:L});
    toast('Temizlenmiş logo kaydedildi ✓');
  }).catch(function(e){toast(e.message)});
}
function bgReset(){
  api('api/logo-restore','POST').then(function(r){
    L.url=r.url;PVSRC='';BGC=null;fillLogoInputs();drawLogo();
    api('api/settings','POST',{logo:L});
    toast('Orijinal logo geri yüklendi');
  }).catch(function(e){toast(e.message)});
}

function saveKeys(){
  api('api/settings','POST',{
    name:$('cn').value,proxy:$('spx').checked,showInfo:$('sinf').checked
  }).then(function(){
    toast('Ayarlar kaydedildi ✓');load();
  }).catch(function(e){toast(e.message)});
}
init();
</script></body></html>
HTML;

/* ============ Router ============ */
$__path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
$__scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$__scriptBase = basename($_SERVER['SCRIPT_NAME']);
// İstek yolundan script adını ve dizinini çıkar
$__rel = $__path;
if ($__scriptDir && str_starts_with($__rel, $__scriptDir)) $__rel = substr($__rel, strlen($__scriptDir));
$__rel = '/' . ltrim($__rel, '/');
if (str_starts_with($__rel, '/' . $__scriptBase)) $__rel = substr($__rel, strlen($__scriptBase));
if ($__rel === '' || $__rel === '/') $__rel = '/';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    foreach (cors_headers() as $k => $v) header("$k: $v");
    http_response_code(204);
    exit;
}

try {
    if ($__rel === '/') { header('Location: ' . $__scriptBase . '/live.m3u8', true, 302); exit; }
    if ($__rel === '/admin') { send_html(ADMIN_HTML); exit; }
    if ($__rel === '/live.m3u8') { handle_live(); exit; }
    if ($__rel === '/seg') { handle_seg_proxy(); exit; }
    if ($__rel === '/logo') { handle_logo(); exit; }
    if ($__rel === '/manifest.webmanifest') { handle_manifest(); exit; }
    if ($__rel === '/api/now') { handle_api_now(); exit; }
    if (str_starts_with($__rel, '/api/')) { handle_api($__rel); exit; }
    http_response_code(404);
    foreach (cors_headers() as $k => $v) header("$k: $v");
    echo 'Bulunamadı';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    foreach (cors_headers() as $k => $v) header("$k: $v");
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
