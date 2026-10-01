<?php
// Cloudflare Worker -> PHP (birebir dönüşüm)

function hashCode($str) {
    $hash = 0;
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $hash = (($hash << 5) - $hash) + ord($str[$i]);
        // JS'deki "hash |= 0" (32-bit işaretli tam sayıya indirgeme)
        $hash &= 0xFFFFFFFF;
        if ($hash >= 0x80000000) $hash -= 0x100000000;
    }
    return $hash;
}

function buildChunklistUrl($channel) {
    $timeSlot = intdiv(time(), 300); // 5 dakikalık periyot
    $tRand = (abs(hashCode($channel['stream'] . $timeSlot)) % 900000000) + 100000000;
    $basePath = isset($channel['customPath']) ? $channel['customPath'] : 'live';
    return "https://{$channel['domain']}/{$basePath}/{$channel['stream']}/chunklist_w{$tRand}.m3u8?hash=9520d7940ddaf87a835f52f01f2206be";
}

function httpGet($url, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body; // hata olursa false
}

function redirectTo($url) {
    header('Location: ' . $url, true, 302);
    exit;
}

// --- Yol çözümleme ---
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$rawPath = trim(mb_strtolower(ltrim($path, '/'), 'UTF-8'));
$channelPath = preg_replace('/\s+/', '-', rawurldecode($rawPath));

if ($channelPath === '' || $channelPath === 'index.php') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kullanım için kanal adı belirtin. Örnek: /az-tv, /cankiri-tv, /arb-gunes-tv, /xezer-tv';
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

try {
    $browserHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Referer: https://canlitv.com/' . $channelPath,
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
    ];

    // 1. Online onay tetikleyicisi
    if ($channel['id'] !== "9999") {
        $onlineTriggerUrl = "https://canlitv.com/online/online.php?sayfa={$channel['id']}&tur=1&ref=0&onay=1";
        @httpGet($onlineTriggerUrl, $browserHeaders);
    }

    // 2. Player sayfasından güncel m3u8 adresini yakala
    $playerUrl = "https://canlitv.com/player/index.php?id={$channel['id']}&mobile=1";
    $playerHtml = httpGet($playerUrl, $browserHeaders);
    if ($playerHtml === false) {
        throw new Exception('Player isteği başarısız');
    }

    $regex = '#https?://[^"\'\s]+\.m3u8(\?[^"\'\s]*)?#i';
    $m3u8Match = null;
    if (preg_match($regex, $playerHtml, $m)) {
        $m3u8Match = $m[0];
    } elseif (preg_match($regex, str_replace('\\', '', $playerHtml), $m)) {
        $m3u8Match = $m[0];
    }

    // 3. Nihai adres
    if ($m3u8Match !== null) {
        if (strpos($m3u8Match, 'playlist.m3u8') !== false) {
            $finalStreamUrl = buildChunklistUrl($channel);
        } else {
            $finalStreamUrl = $m3u8Match;
        }
    } else {
        $finalStreamUrl = buildChunklistUrl($channel);
    }

    // 4. Doğrudan yönlendirme (oynatıcı akışı kendi IP'siyle alır)
    redirectTo($finalStreamUrl);

} catch (Throwable $e) {
    redirectTo(buildChunklistUrl($channel));
}
