<?php
/**
 * CatCast.tv Stream Resolver + Proxy
 * Kullanım: /catcast.php?id=KANALADI.m3u8
 * Örnek:    /catcast.php?id=mutlutv.m3u8
 */

// Hata ayıklama (canlıda kapat: 0 yap)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// -------------------- YARDIMCI FONKSİYON --------------------
function http_get($url, $extraHeaders = []) {
    $ch = curl_init();
    $headers = array_merge([
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
        'Referer: https://catcast.tv/',
        'Origin: https://catcast.tv',
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ['code' => $code, 'body' => $body, 'error' => $err];
}

// -------------------- PARAMETRE KONTROLÜ --------------------
if (empty($_GET['id'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Hata: 'id' parametresi gerekli.\nKullanım: ?id=mutlutv.m3u8";
    exit();
}

$cleanId = preg_replace('/\.m3u8$/i', '', trim($_GET['id']));
$cleanId = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $cleanId); // güvenlik

// -------------------- 1. ADIM: KANAL BİLGİSİ --------------------
$channelUrl = "https://api.catcast.tv/api/channels/getbyshortname/" . urlencode($cleanId);
$r1 = http_get($channelUrl);

if ($r1['error']) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 1 cURL hatası: " . $r1['error'] . "\nURL: $channelUrl";
    exit();
}

if ($r1['code'] !== 200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 1 başarısız. HTTP: {$r1['code']}\nURL: $channelUrl\nCevap:\n" . substr($r1['body'], 0, 500);
    exit();
}

$channelData = json_decode($r1['body'], true);
if (!is_array($channelData)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 1 JSON çözümlenemedi.\nCevap:\n" . substr($r1['body'], 0, 500);
    exit();
}

// ID'yi birden fazla olası yerden ara
$entityId = $channelData['id']
         ?? $channelData['data']['id']
         ?? $channelData['data']['channel']['id']
         ?? $channelData['channel']['id']
         ?? null;

if (!$entityId) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 1: Kanal ID bulunamadı.\nGelen JSON:\n" . json_encode($channelData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------- 2. ADIM: GÜNCEL PROGRAM --------------------
$programUrl = "https://api.catcast.tv/api/channels/" . urlencode($entityId) . "/getcurrentprogram";
$r2 = http_get($programUrl);

if ($r2['error']) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 2 cURL hatası: " . $r2['error'] . "\nURL: $programUrl";
    exit();
}

if ($r2['code'] !== 200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 2 başarısız. HTTP: {$r2['code']}\nURL: $programUrl\nCevap:\n" . substr($r2['body'], 0, 500);
    exit();
}

$programData = json_decode($r2['body'], true);
if (!is_array($programData)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 2 JSON çözümlenemedi.\nCevap:\n" . substr($r2['body'], 0, 500);
    exit();
}

// Yayın URL'sini birden fazla olası alandan ara
$streamUrl = $programData['data']['full_mobile_url']
          ?? $programData['data']['full_url']
          ?? $programData['data']['hls_url']
          ?? $programData['data']['stream_url']
          ?? $programData['full_mobile_url']
          ?? null;

if (!$streamUrl) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Adım 2: Yayın URL'si bulunamadı.\nGelen JSON:\n" . json_encode($programData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------- 3. ADIM: M3U8 PROXY --------------------
// Yayın URL'si .m3u8 ise proxy'le, değilse direkt yönlendir
if (stripos($streamUrl, '.m3u8') !== false) {
    $r3 = http_get($streamUrl, [
        'Accept: application/vnd.apple.mpegurl, application/x-mpegURL, */*',
    ]);

    if ($r3['error'] || $r3['code'] !== 200) {
        // Proxy başarısız olursa direkt yönlendir
        header("Location: " . $streamUrl, true, 302);
        exit();
    }

    // M3U8 içeriğini al
    $m3u8 = $r3['body'];

    // Segment ve alt-playlist URL'lerini proxy üzerinden geçir
    $baseUrl = preg_replace('#/[^/]*$#', '/', $streamUrl);

    $lines = explode("\n", $m3u8);
    $out = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            // URI="..." içeren etiketleri de düzelt
            if (preg_match('/URI="([^"]+)"/', $trim, $m)) {
                $abs = (strpos($m[1], 'http') === 0) ? $m[1] : $baseUrl . $m[1];
                $proxied = 'proxy.php?u=' . urlencode($abs);
                $trim = str_replace($m[1], $proxied, $trim);
            }
            $out[] = $trim;
        } else {
            $abs = (strpos($trim, 'http') === 0) ? $trim : $baseUrl . $trim;
            $out[] = 'proxy.php?u=' . urlencode($abs);
        }
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Access-Control-Allow-Origin: *');
    echo implode("\n", $out);
    exit();
}

// M3U8 değilse direkt yönlendir
header("Location: " . $streamUrl, true, 302);
exit();
