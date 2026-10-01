<?php
// Cloudflare Worker -> PHP (proxy versiyonu)

error_reporting(E_ALL);
ini_set('display_errors', 0); // Canlıda 0, hata ayıklarken 1 yapın

function hashCode($str) {
    $hash = 0;
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $hash = (($hash << 5) - $hash) + ord($str[$i]);
        $hash &= 0xFFFFFFFF;
        if ($hash >= 0x80000000) $hash -= 0x100000000;
    }
    return $hash;
}

function buildChunklistUrl($channel) {
    $timeSlot = intdiv(time(), 300);
    $tRand = (abs(hashCode($channel['stream'] . $timeSlot)) % 900000000) + 100000000;
    $basePath = isset($channel['customPath']) ? $channel['customPath'] : 'live';
    return "https://{$channel['domain']}/{$basePath}/{$channel['stream']}/chunklist_w{$tRand}.m3u8?hash=9520d7940ddaf87a835f52f01f2206be";
}

function httpGet($url, $headers, $isBinary = false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ]);
    if ($isBinary) {
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    }
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['body' => $body, 'code' => $httpCode, 'type' => $contentType];
}

function browserHeaders($referer = 'https://canlitv.com/') {
    return [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Referer: ' . $referer,
        'Origin: https://canlitv.com',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept: */*',
    ];
}

// --- Proxy modu: .ts parça istekleri ---
if (isset($_GET['ts'])) {
    $tsUrl = base64_decode($_GET['ts']);
    if (!$tsUrl || !preg_match('#^https?://#i', $tsUrl)) {
        http_response_code(400);
        exit('Geçersiz ts URL');
    }
    // ts'nin geldiği domaini referer yap
    $parsed = parse_url($tsUrl);
    $ref = $parsed['scheme'] . '://' . $parsed['host'] . '/';
    $res = httpGet($tsUrl, browserHeaders($ref), true);
    if ($res['code'] !== 200 || $res['body'] === false) {
        http_response_code($res['code'] ?: 502);
        exit('ts alınamadı: ' . $res['code']);
    }
    header('Content-Type: video/mp2t');
    header('Content-Length: ' . strlen($res['body']));
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache');
    echo $res['body'];
    exit;
}

// --- Yol çözümleme ---
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$scriptName = basename($_SERVER['SCRIPT_NAME']);
$pos = strpos($path, $scriptName);
if ($pos !== false) {
    $channelPath = substr($path, $pos + strlen($scriptName));
} else {
    $channelPath = $path;
}
$channelPath = trim(mb_strtolower(ltrim($channelPath, '/'), 'UTF-8'));
$channelPath = preg_replace('/\s+/', '-', rawurldecode($channelPath));

if ($channelPath === '' || $channelPath === 'index.php') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kullanım: /bot.php/aztv , /bot.php/cankiri-tv , /bot.php/xezer-tv';
    exit;
}

// --- Kanal tablosu ---
$channelDatabase = [
    "az-tv"        => ["id" => "32",    "domain" => "yayin2.canlitv.fun", "stream" => "aztv.stream"],
    "aztv"         => ["id" => "32",    "domain" => "yayin2.canlitv.fun", "stream" => "aztv.stream"],
    "arb-gunes-tv" => ["id" => "12988", "domain" => "yayin2.canlitv.fun", "stream" => "arbgunes.stream"],
    "arbgunes"     => ["id" => "12988", "domain" => "yayin2.canlitv.fun", "stream" => "arbgunes.stream"],
    "xezer-tv"     => ["id" => "11827", "domain" => "yayin2.canlitv.fun", "stream" => "xezertv.stream"],
    "xezertv"      => ["id" => "11827", "domain" => "yayin2.canlitv.fun", "stream" => "xezertv.stream"],
    "arb-tv"       => ["id" => "12985", "domain" => "yayin2.canlitv.fun", "stream" => "arbtv.stream"],
    "arbtv"        => ["id" => "12985", "domain" => "yayin2.canlitv.fun", "stream" => "arbtv.stream"],
    "cbc-sport"    => ["id" => "12732", "domain" => "yayin2.canlitv.fun", "stream" => "cbcsport.stream"],
    "cbcsport"     => ["id" => "12732", "domain" => "yayin2.canlitv.fun", "stream" => "cbcsport.stream"],
    "ictimai-tv"   => ["id" => "903",   "domain" => "yayin2.canlitv.fun", "stream" => "ictimaitv.stream"],
    "space-tv"     => ["id" => "12312", "domain" => "yayin2.canlitv.fun", "stream" => "spacetv.stream"],
    "atv"          => ["id" => "11229", "domain" => "yayin2.canlitv.fun", "stream" => "atv.stream"],
    "cankiri-tv"   => ["id" => "9999",  "domain" => "yayin1.canlitv.fun", "stream" => "cankiritv.stream", "customPath" => "canlitv"],
    "cankiritv"    => ["id" => "9999",  "domain" => "yayin1.canlitv.fun", "stream" => "cankiritv.stream", "customPath" => "canlitv"],
];

if (isset($channelDatabase[$channelPath])) {
    $channel = $channelDatabase[$channelPath];
} else {
    $cleanName = str_replace('-', '', $channelPath);
    $channel = [
        "id"     => "32",
        "domain" => "yayin2.canlitv.fun",
        "stream" => $cleanName . ".stream",
    ];
}

// --- 1. Online tetikleyici ---
$browserHeaders = browserHeaders('https://canlitv.com/' . $channelPath);

if ($channel['id'] !== "9999") {
    $onlineTriggerUrl = "https://canlitv.com/online/online.php?sayfa={$channel['id']}&tur=1&ref=0&onay=1";
    @httpGet($onlineTriggerUrl, $browserHeaders);
}

// --- 2. Player sayfasından m3u8 yakala ---
$playerUrl = "https://canlitv.com/player/index.php?id={$channel['id']}&mobile=1";
$playerRes = httpGet($playerUrl, $browserHeaders);
$playerHtml = $playerRes['body'];

$m3u8Match = null;
if ($playerHtml) {
    $regex = '#https?://[^"\'\s\\\\]+\.m3u8(\?[^"\'\s\\\\]*)?#i';
    if (preg_match($regex, $playerHtml, $m)) {
        $m3u8Match = $m[0];
    } elseif (preg_match($regex, str_replace('\\', '', $playerHtml), $m)) {
        $m3u8Match = $m[0];
    }
}

if ($m3u8Match === null || strpos($m3u8Match, 'playlist.m3u8') !== false) {
    $m3u8Match = buildChunklistUrl($channel);
}

// --- 3. m3u8 içeriğini çek ---
$m3u8Parsed = parse_url($m3u8Match);
$m3u8Referer = $m3u8Parsed['scheme'] . '://' . $m3u8Parsed['host'] . '/';
$m3u8Res = httpGet($m3u8Match, browserHeaders($m3u8Referer));

if ($m3u8Res['code'] !== 200 || !$m3u8Res['body']) {
    // Alternatif chunklist dene
    $alt = buildChunklistUrl($channel);
    $altParsed = parse_url($alt);
    $altRef = $altParsed['scheme'] . '://' . $altParsed['host'] . '/';
    $m3u8Res = httpGet($alt, browserHeaders($altRef));
    if ($m3u8Res['code'] === 200 && $m3u8Res['body']) {
        $m3u8Match = $alt;
        $m3u8Parsed = $altParsed;
    } else {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'm3u8 alınamadı. Kod: ' . $m3u8Res['code'];
        exit;
    }
}

$m3u8Content = $m3u8Res['body'];
$baseUrl = $m3u8Parsed['scheme'] . '://' . $m3u8Parsed['host'] . dirname($m3u8Parsed['path']) . '/';

// --- 4. m3u8 içeriğini yeniden yaz: ts ve alt m3u8 linklerini proxy'ye çevir ---
$selfBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

$lines = explode("\n", $m3u8Content);
$out = [];
foreach ($lines as $line) {
    $trim = trim($line);
    if ($trim === '') { $out[] = $line; continue; }

    // Yorum satırı değilse ve URI ise
    if ($trim[0] !== '#') {
        if (preg_match('#^https?://#i', $trim)) {
            $absolute = $trim;
        } else {
            $absolute = $baseUrl . ltrim($trim, '/');
        }
        $out[] = $selfBase . '?ts=' . urlencode(base64_encode($absolute));
    } else {
        // EXT-X-KEY, EXT-X-MAP gibi satırlardaki URI="..." değerlerini de proxy'le
        if (preg_match('/URI="([^"]+)"/i', $trim, $um)) {
            $u = $um[1];
            if (!preg_match('#^https?://#i', $u)) {
                $u = $baseUrl . ltrim($u, '/');
            }
            $proxy = $selfBase . '?ts=' . urlencode(base64_encode($u));
            $trim = str_replace($um[1], $proxy, $trim);
        }
        $out[] = $trim;
    }
}
$newContent = implode("\n", $out);

header('Content-Type: application/vnd.apple.mpegurl');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');
echo $newContent;
exit;
