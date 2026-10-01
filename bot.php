<?php
// ======================================================
// PHP - M3U8 Live Proxy (AES-256-GCM) - Stream İsimli
// ======================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

const M3U_URL = "https://raw.githubusercontent.com/mutlumedya/mutluapk/refs/heads/main/oylebir.m3u";
const ENCRYPTION_KEY = "c7a8f9e1b2d3c4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9";
const CACHE_FILE = __DIR__ . '/channels_cache.json';
const CACHE_TTL  = 300;

// ------------------------------------------------------
// Tarayıcı kontrolü
// ------------------------------------------------------
function isWebBrowser() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $browserKeywords = ['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'OPR', 'MSIE', 'Trident'];
    $hasMozilla = strpos($ua, 'Mozilla') !== false;
    $isBrowserUA = $hasMozilla && array_reduce($browserKeywords, fn($c, $k) => $c || strpos($ua, $k) !== false, false);
    $isBrowserAccept = strpos($accept, 'text/html') !== false && strpos($accept, 'application/vnd.apple.mpegurl') === false;
    return $isBrowserUA || $isBrowserAccept;
}

// ------------------------------------------------------
// AES-256-GCM Şifreleme
// ------------------------------------------------------
function encryptData($dataObject) {
    $key = substr(ENCRYPTION_KEY, 0, 32);
    $iv = random_bytes(12);
    $plaintext = json_encode($dataObject);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) return '';
    $combined = $iv . $ciphertext . $tag;
    return rtrim(strtr(base64_encode($combined), '+/', '-_'), '=');
}

// ------------------------------------------------------
// AES-256-GCM Çözme
// ------------------------------------------------------
function decryptData($cipherText) {
    $key = substr(ENCRYPTION_KEY, 0, 32);
    $base64 = strtr($cipherText, '-_', '+/');
    while (strlen($base64) % 4) $base64 .= '=';
    $bytes = base64_decode($base64);
    if ($bytes === false || strlen($bytes) < 28) return null;
    $iv = substr($bytes, 0, 12);
    $tag = substr($bytes, -16);
    $ciphertext = substr($bytes, 12, -16);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) return null;
    return json_decode($plaintext, true);
}

// ------------------------------------------------------
// M3U Listesini Oku
// ------------------------------------------------------
function getChannels() {
    if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE) < CACHE_TTL)) {
        $cached = json_decode(file_get_contents(CACHE_FILE), true);
        if ($cached && count($cached) > 0) return $cached;
    }
    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
        'timeout' => 15
    ]]);
    $content = @file_get_contents(M3U_URL, false, $ctx);
    if ($content === false) {
        if (file_exists(CACHE_FILE)) return json_decode(file_get_contents(CACHE_FILE), true) ?: [];
        throw new Exception('Liste yüklenemedi');
    }
    $lines = preg_split('/\r?\n/', $content);
    $channels = [];
    $current = ['extinf' => '', 'name' => '', 'headers' => [], 'url' => ''];
    $expectingUrl = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '#EXTINF') === 0) {
            if (!empty($current['url'])) $channels[] = $current;
            $name = '';
            if (preg_match('/,([^,]+)$/', $line, $m)) $name = trim($m[1]);
            $current = ['extinf' => $line, 'name' => $name, 'headers' => [], 'url' => ''];
            $expectingUrl = true;
        } elseif (strpos($line, '#EXTVLCOPT:http-user-agent=') === 0) {
            $current['headers']['User-Agent'] = substr($line, strlen('#EXTVLCOPT:http-user-agent='));
        } elseif (strpos($line, '#EXTVLCOPT:http-referrer=') === 0) {
            $current['headers']['Referer'] = substr($line, strlen('#EXTVLCOPT:http-referrer='));
        } elseif (strpos($line, '#EXTVLCOPT:http-origin=') === 0) {
            $current['headers']['Origin'] = substr($line, strlen('#EXTVLCOPT:http-origin='));
        } elseif (strpos($line, 'http://') === 0 || strpos($line, 'https://') === 0) {
            if ($expectingUrl || empty($current['url'])) {
                $current['url'] = $line;
                $channels[] = $current;
                $current = ['extinf' => '', 'name' => '', 'headers' => [], 'url' => ''];
                $expectingUrl = false;
            }
        }
    }
    if (!empty($current['url'])) $channels[] = $current;
    $channels = array_values(array_filter($channels, fn($c) => !empty($c['url'])));
    if (count($channels) === 0) throw new Exception('Hiç kanal bulunamadı');
    @file_put_contents(CACHE_FILE, json_encode($channels, JSON_UNESCAPED_UNICODE));
    return $channels;
}

// ------------------------------------------------------
// M3U8 içeriğini yeniden yaz
// ------------------------------------------------------
function rewriteM3u8Content($content, $baseStreamUrl, $baseProxyUrl, $headersObj) {
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = preg_split('/\r?\n/', $content);
    $newLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;

        if (strpos($trimmed, '#') === 0) {
            if (strpos($trimmed, 'URI=') !== false) {
                $trimmed = preg_replace_callback('/URI="([^"]+)"/', function($m) use ($baseStreamUrl, $baseProxyUrl, $headersObj) {
                    $fullUrl = resolveUrl($m[1], $baseStreamUrl);
                    $encrypted = encryptData(['url' => $fullUrl, 'headers' => $headersObj]);
                    return 'URI="' . $baseProxyUrl . '?seg=' . $encrypted . '"';
                }, $trimmed);
            }
            $newLines[] = $trimmed;
        } else {
            $fullUrl = resolveUrl($trimmed, $baseStreamUrl);
            $encrypted = encryptData(['url' => $fullUrl, 'headers' => $headersObj]);
            $newLines[] = $baseProxyUrl . '?seg=' . $encrypted;
        }
    }
    return implode("\n", $newLines);
}

// ------------------------------------------------------
// Göreceli URL -> Mutlak
// ------------------------------------------------------
function resolveUrl($relative, $base) {
    if (preg_match('#^https?://#i', $relative)) return $relative;
    $parts = parse_url($base);
    if (!isset($parts['scheme'])) return $relative;
    $scheme = $parts['scheme'];
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    if (strpos($relative, '//') === 0) return $scheme . ':' . $relative;
    if (strpos($relative, '/') === 0) return $scheme . '://' . $host . $port . $relative;
    return $scheme . '://' . $host . $port . $dir . $relative;
}

// ------------------------------------------------------
// cURL ile içerik çek
// ------------------------------------------------------
function fetchUrl($url, $headers = []) {
    $ch = curl_init($url);
    $headerArr = [];
    foreach ($headers as $k => $v) $headerArr[] = "$k: $v";
    $headerArr[] = "User-Agent: " . ($headers['User-Agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $headerArr[] = "Accept: */*";
    $headerArr[] = "Connection: keep-alive";

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headerArr,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER         => true,
        CURLOPT_ENCODING       => '',
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $body = substr($response, $headerSize);
    return [
        'status' => $httpCode,
        'content_type' => $contentType,
        'body' => $body,
    ];
}

// ======================================================
// ROUTER
// ======================================================
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
         . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

$action  = $_GET['action'] ?? null;
$idParam = $_GET['ID'] ?? $_GET['id'] ?? null;
$seg     = $_GET['seg'] ?? null;
$stream  = $_GET['s'] ?? null;

// .htaccess'ten gelen path (stream1.m3u8 gibi)
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (preg_match('#/stream([0-9]+)\.m3u8$#', $requestPath, $m)) {
    $stream = 'stream' . $m[1];
}

try {
    // Parametresiz istek
    if (!$action && !$idParam && !$seg && !$stream) {
        if (isWebBrowser()) {
            header("Location: {$baseUrl}?action=list", true, 302);
            exit;
        }
        http_response_code(404);
        echo "Geçersiz İstek. Kullanım: ?action=list veya ?s=stream1";
        exit;
    }

    // ==================================================
    // 1. M3U LİSTESİ
    // ==================================================
    if ($action === 'list') {
        $channels = getChannels();
        $output = "#EXTM3U\n";
        foreach ($channels as $i => $ch) {
            $channelId = $i + 1;
            $extLine = $ch['extinf'] ?: "#EXTINF:-1, " . ($ch['name'] ?: "Kanal $channelId");
            $output .= "$extLine\n{$baseUrl}?s=stream{$channelId}\n";
        }
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache');
        echo $output;
        exit;
    }

    // ==================================================
    // 2. stream1 -> ID'ye çevir
    // ==================================================
    if ($stream) {
        $channels = getChannels();
        $foundId = null;
        foreach ($channels as $i => $ch) {
            if ('stream' . ($i + 1) === $stream) {
                $foundId = $i + 1;
                break;
            }
        }
        if ($foundId === null) {
            http_response_code(404);
            echo "Kanal bulunamadı: " . htmlspecialchars($stream);
            exit;
        }
        $idParam = $foundId;
    }

    // ==================================================
    // 3. TEK KANAL AKIŞI
    // ==================================================
    if ($idParam) {
        $channelId = (int)$idParam;
        $channels = getChannels();
        if ($channelId < 1 || $channelId > count($channels)) {
            http_response_code(404);
            echo "Kanal bulunamadı.";
            exit;
        }
        $ch = $channels[$channelId - 1];
        $headers = $ch['headers'];
        if (empty($headers['User-Agent'])) $headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

        $resp = fetchUrl($ch['url'], $headers);
        $ct = $resp['content_type'] ?? '';
        $body = $resp['body'];

        $isM3u8 = (
            stripos($ct, 'mpegurl') !== false ||
            stripos($ct, 'm3u') !== false ||
            strpos($ch['url'], '.m3u8') !== false ||
            strpos(substr($body, 0, 10), '#EXTM3U') !== false
        );

        if (!$isM3u8) {
            header('Content-Type: ' . ($ct ?: 'video/mp2t'));
            header('Access-Control-Allow-Origin: *');
            echo $body;
            exit;
        }

        $rewritten = rewriteM3u8Content($body, $ch['url'], $baseUrl, $ch['headers']);
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $rewritten;
        exit;
    }

    // ==================================================
    // 4. SEGMENT / ALT PLAYLIST
    // ==================================================
    if ($seg) {
        $payload = decryptData($seg);
        if (!$payload || empty($payload['url'])) {
            http_response_code(403);
            echo "Geçersiz Bağlantı.";
            exit;
        }
        $headers = $payload['headers'] ?? [];
        if (empty($headers['User-Agent'])) $headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

        $resp = fetchUrl($payload['url'], $headers);
        $ct = $resp['content_type'] ?? '';
        $body = $resp['body'];

        $isM3u8 = (
            stripos($ct, 'mpegurl') !== false ||
            stripos($ct, 'm3u') !== false ||
            strpos($payload['url'], '.m3u8') !== false ||
            strpos(substr($body, 0, 10), '#EXTM3U') !== false
        );

        if ($isM3u8) {
            $rewritten = rewriteM3u8Content($body, $payload['url'], $baseUrl, $payload['headers']);
            header('Content-Type: application/vnd.apple.mpegurl');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $rewritten;
            exit;
        }

        header('Content-Type: ' . ($ct ?: 'video/mp2t'));
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache');
        echo $body;
        exit;
    }

    http_response_code(404);
    echo "Geçersiz İstek";

} catch (Exception $e) {
    http_response_code(500);
    echo "Sunucu Hatası: " . $e->getMessage();
}
