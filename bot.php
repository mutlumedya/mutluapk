<?php
const SECRET = 'BURAYA-UZUN-RASTGELE-BIR-ANAHTAR'; // değiştir
const PROXY  = '';            // gerekirse 'http://kullanici:sifre@host:port'
const REFRESH_SECONDS = 5;    // playlist yenileme süresi
const SEGMENT_CACHE_SECONDS = 60;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

$cacheDir = sys_get_temp_dir() . '/ctv_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

// Eski önbellek dosyalarını ara sıra temizle
if (mt_rand(1, 100) === 1) {
    foreach (glob($cacheDir . '/*') ?: [] as $f) {
        if (is_file($f) && time() - filemtime($f) > 180) @unlink($f);
    }
}

function hashCode($str) {
    $h = 0;
    for ($i = 0, $n = strlen($str); $i < $n; $i++) {
        $h = (($h << 5) - $h) + ord($str[$i]);
        $h &= 0xFFFFFFFF;
        if ($h >= 0x80000000) $h -= 0x100000000;
    }
    return $h;
}

function guessUrl($c, $file = null) {
    $slot = intdiv(time(), 300);
    $t = (abs(hashCode($c['stream'] . $slot)) % 900000000) + 100000000;
    $base = $c['customPath'] ?? 'live';
    $file = $file ?: "chunklist_w{$t}.m3u8";
    return "https://{$c['domain']}/{$base}/{$c['stream']}/{$file}?hash=9520d7940ddaf87a835f52f01f2206be";
}

function hdrs($referer) {
    return [
        'User-Agent: ' . UA,
        'Referer: ' . $referer,
        'Origin: https://canlitv.com',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept: */*',
    ];
}

function fetchUrl($url, $headers, &$code = null, &$err = null) {
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '',
    ];
    if (PROXY !== '') $opt[CURLOPT_PROXY] = PROXY;
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $GLOBALS['lastCtype'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return $body;
}

function absUrl($base, $rel) {
    if (preg_match('#^https?://#i', $rel)) return $rel;
    $p = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if ($rel[0] === '/') return $origin . $rel;
    $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    return $origin . $dir . $rel;
}

function proxyUrl($abs) {
    $u = rtrim(strtr(base64_encode($abs), '+/', '-_'), '=');
    $s = substr(hash_hmac('sha256', $abs, SECRET), 0, 24);
    return '/_p?u=' . $u . '&s=' . $s;
}

function isM3u8($u) { return (bool) preg_match('#\.m3u8(\?|$)#i', $u); }

function rewritePlaylist($body, $baseUrl) {
    $out = [];
    foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
        $t = trim($line);
        if ($t === '') { $out[] = $line; continue; }
        if ($t[0] === '#') {
            $out[] = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($baseUrl) {
                return 'URI="' . proxyUrl(absUrl($baseUrl, $m[1])) . '"';
            }, $line);
        } else {
            $out[] = proxyUrl(absUrl($baseUrl, $t));
        }
    }
    return implode("\n", $out);
}

function sendPlaylist($body, $baseUrl) {
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');
    echo rewritePlaylist($body, $baseUrl);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// ---------- Alt playlist / parça proxy (hepsi VPS IP'si ile) ----------
if ($path === '/_p') {
    $abs = base64_decode(strtr($_GET['u'] ?? '', '-_', '+/'));
    $sig = $_GET['s'] ?? '';
    if (!$abs || !hash_equals(substr(hash_hmac('sha256', $abs, SECRET), 0, 24), $sig)) {
        http_response_code(403); exit('geçersiz');
    }

    $isList = isM3u8($abs);
    $ttl = $isList ? REFRESH_SECONDS : SEGMENT_CACHE_SECONDS;
    $f = $cacheDir . '/' . md5($abs);

    if (is_file($f) && time() - filemtime($f) < $ttl) {
        $c = json_decode(file_get_contents($f), true);
        $body = base64_decode($c['b']); $ctype = $c['t'];
    } else {
        $body = fetchUrl($abs, hdrs('https://canlitv.com/'), $code, $err);
        if ($body === false || $code >= 400) { http_response_code($code ?: 502); exit; }
        $ctype = $GLOBALS['lastCtype'];
        @file_put_contents($f, json_encode(['b' => base64_encode($body), 't' => $ctype]));
    }

    if ($isList || strpos($body, '#EXTM3U') === 0) sendPlaylist($body, $abs);
    header('Content-Type: ' . ($ctype ?: 'video/mp2t'));
    header('Access-Control-Allow-Origin: *');
    echo $body;
    exit;
}

// ---------- Kanal ----------
$raw = trim(mb_strtolower(ltrim($path, '/'), 'UTF-8'));
$channelPath = preg_replace('/\s+/', '-', rawurldecode($raw));
if ($channelPath === '' || $channelPath === 'index.php') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kullanım için kanal adı belirtin. Örnek: /az-tv, /cankiri-tv, /arb-gunes-tv, /xezer-tv');
}

$db = [
    "az-tv" => ["id" => "32", "domain" => "yayin2.canlitv.fun", "stream" => "aztv.stream"],
    "aztv" => ["id" => "32", "domain" => "yayin2.canlitv.fun", "stream" => "aztv.stream"],
    "arb-gunes-tv" => ["id" => "12988", "domain" => "yayin2.canlitv.fun", "stream" => "arbgunes.stream"],
    "arbgunes" => ["id" => "12988", "domain" => "yayin2.canlitv.fun", "stream" => "arbgunes.stream"],
    "xezer-tv" => ["id" => "11827", "domain" => "yayin2.canlitv.fun", "stream" => "xezertv.stream"],
    "xezertv" => ["id" => "11827", "domain" => "yayin2.canlitv.fun", "stream" => "xezertv.stream"],
    "arb-tv" => ["id" => "12985", "domain" => "yayin2.canlitv.fun", "stream" => "arbtv.stream"],
    "arbtv" => ["id" => "12985", "domain" => "yayin2.canlitv.fun", "stream" => "arbtv.stream"],
    "cbc-sport" => ["id" => "12732", "domain" => "yayin2.canlitv.fun", "stream" => "cbcsport.stream"],
    "cbcsport" => ["id" => "12732", "domain" => "yayin2.canlitv.fun", "stream" => "cbcsport.stream"],
    "ictimai-tv" => ["id" => "903", "domain" => "yayin2.canlitv.fun", "stream" => "ictimaitv.stream"],
    "space-tv" => ["id" => "12312", "domain" => "yayin2.canlitv.fun", "stream" => "spacetv.stream"],
    "atv" => ["id" => "11229", "domain" => "yayin2.canlitv.fun", "stream" => "atv.stream"],
    "cankiri-tv" => ["id" => "9999", "domain" => "yayin1.canlitv.fun", "stream" => "cankiritv.stream", "customPath" => "canlitv"],
    "cankiritv" => ["id" => "9999", "domain" => "yayin1.canlitv.fun", "stream" => "cankiritv.stream", "customPath" => "canlitv"],
];
$channel = $db[$channelPath] ?? [
    "id" => "32", "domain" => "yayin2.canlitv.fun",
    "stream" => str_replace('-', '', $channelPath) . ".stream",
];

$debug = isset($_GET['debug']);

// Kanal başına ortak 5 sn önbellek
$chFile = $cacheDir . '/ch_' . md5($channelPath);
if (!$debug && is_file($chFile) && time() - filemtime($chFile) < REFRESH_SECONDS) {
    $c = json_decode(file_get_contents($chFile), true);
    if ($c && isset($c['body'], $c['url'])) sendPlaylist($c['body'], $c['url']);
}

$log = [];
$H = hdrs('https://canlitv.com/' . $channelPath);

if ($channel['id'] !== "9999") {
    @fetchUrl("https://canlitv.com/online/online.php?sayfa={$channel['id']}&tur=1&ref=0&onay=1", $H, $c1, $e1);
    $log[] = "online tetikleyici: HTTP $c1 $e1";
}

$candidates = [];
$html = fetchUrl("https://canlitv.com/player/index.php?id={$channel['id']}&mobile=1", $H, $c2, $e2);
$log[] = "player sayfası: HTTP $c2 $e2";
if ($html !== false) {
    $re = '#https?://[^"\'\s]+\.m3u8(\?[^"\'\s]*)?#i';
    if (preg_match($re, $html, $m) || preg_match($re, str_replace('\\', '', $html), $m)) {
        $candidates[] = $m[0];
        $log[] = "yakalanan: " . $m[0];
    }
}
$candidates[] = guessUrl($channel);
$candidates[] = guessUrl($channel, 'playlist.m3u8');

foreach ($candidates as $u) {
    $body = fetchUrl($u, hdrs('https://canlitv.com/'), $code, $err);
    $log[] = "deneme: $u => HTTP $code $err";
    if ($body !== false && $code === 200 && strpos($body, '#EXTM3U') !== false) {
        if ($debug) break;
        @file_put_contents($chFile, json_encode(['url' => $u, 'body' => $body]));
        sendPlaylist($body, $u);
    }
}

http_response_code($debug ? 200 : 502);
header('Content-Type: text/plain; charset=utf-8');
echo "m3u8 alınamadı\n\n" . implode("\n", $log);
