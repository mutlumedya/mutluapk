<?php
// ======================================================
// PHP - M3U8 Proxy (AES-256-GCM Şifreli)
// ======================================================

const M3U_URL = "https://raw.githubusercontent.com/mutlumedya/mutluapk/refs/heads/main/oylebir.m3u";

// 32 byte anahtar (AES-256)
const ENCRYPTION_KEY = "c7a8f9e1b2d3c4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9";

// Önbellek dosyası
const CACHE_FILE = __DIR__ . '/channels_cache.json';
const CACHE_TTL  = 300; // 5 dakika

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
// AES-256-GCM Şifreleme (URL-safe Base64)
// ------------------------------------------------------
function encryptData($dataObject) {
    try {
        $key = substr(ENCRYPTION_KEY, 0, 32);
        $iv = random_bytes(12); // GCM için 12 byte IV
        $plaintext = json_encode($dataObject);

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) return '';

        // IV + ciphertext + tag birleştir
        $combined = $iv . $ciphertext . $tag;

        // URL-safe Base64
        $base64 = base64_encode($combined);
        return rtrim(strtr($base64, '+/', '-_'), '=');
    } catch (Exception $e) {
        return '';
    }
}

// ------------------------------------------------------
// AES-256-GCM Çözme
// ------------------------------------------------------
function decryptData($cipherText) {
    try {
        $key = substr(ENCRYPTION_KEY, 0, 32);

        // URL-safe Base64 -> normal Base64
        $base64 = strtr($cipherText, '-_', '+/');
        while (strlen($base64) % 4) $base64 .= '=';

        $bytes = base64_decode($base64);
        if ($bytes === false || strlen($bytes) < 28) return null; // 12 IV + 16 tag minimum

        $iv = substr($bytes, 0, 12);
        $tag = substr($bytes, -16);
        $ciphertext = substr($bytes, 12, -16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) return null;
        return json_decode($plaintext, true);
    } catch (Exception $e) {
        return null;
    }
}

// ------------------------------------------------------
// M3U Listesini Oku ve Önbellekle
// ------------------------------------------------------
function getChannels() {
    // Önbellek kontrolü
    if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE) < CACHE_TTL)) {
        $cached = json_decode(file_get_contents(CACHE_FILE), true);
        if ($cached && count($cached) > 0) return $cached;
    }

    $ctx = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            'timeout' => 15
        ]
    ]);

    $content = @file_get_contents(M3U_URL, false, $ctx);
    if ($content === false) {
        // Önbellek varsa eski halini kullan
        if (file_exists(CACHE_FILE)) {
            return json_decode(file_get_contents(CACHE_FILE), true) ?: [];
        }
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
        }
        elseif (strpos($line, '#EXTVLCOPT:http-user-agent=') === 0) {
            $current['headers']['User-Agent'] = substr($line, strlen('#EXTVLCOPT:http-user-agent='));
        }
        elseif (strpos($line, '#EXTVLCOPT:http-referrer=') === 0) {
            $current['headers']['Referer'] = substr($line, strlen('#EXTVLCOPT:http-referrer='));
        }
        elseif (strpos($line, '#EXTVLCOPT:http-origin=') === 0) {
            $current['headers']['Origin'] = substr($line, strlen('#EXTVLCOPT:http-origin='));
        }
        elseif (strpos($line, 'http://') === 0 || strpos($line, 'https://') === 0) {
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

    // Önbelleğe yaz
    @file_put_contents(CACHE_FILE, json_encode($channels, JSON_UNESCAPED_UNICODE));
    return $channels;
}

// ------------------------------------------------------
// M3U8 içeriğini yeniden yaz (segmentleri şifrele)
// ------------------------------------------------------
function rewriteM3u8Content($content, $baseStreamUrl, $baseProxyUrl, $headersObj) {
    $lines = preg_split('/\r?\n/', $content);
    $newLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;

        if (strpos($trimmed, '#') === 0) {
            // URI="..." içeren satırları şifrele
            if (strpos($trimmed, 'URI=') !== false) {
                $trimmed = preg_replace_callback('/URI="([^"]+)"/', function($m) use ($baseStreamUrl, $baseProxyUrl, $headersObj) {
                    $fullUrl = resolveUrl($m[1], $baseStreamUrl);
                    $encrypted = encryptData(['url' => $fullUrl, 'headers' => $headersObj]);
                    return 'URI="' . $baseProxyUrl . '/proxy/' . $encrypted . '"';
                }, $trimmed);
            }
            $newLines[] = $trimmed;
        } else {
            $fullUrl = resolveUrl($trimmed, $baseStreamUrl);
            $encrypted = encryptData(['url' => $fullUrl, 'headers' => $headersObj]);
            $newLines[] = $baseProxyUrl . '/proxy/' . $encrypted;
        }
    }
    return implode("\n", $newLines);
}

// ------------------------------------------------------
// Göreceli URL'yi mutlak URL'ye çevir
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

    if (strpos($relative, '//') === 0) {
        return $scheme . ':' . $relative;
    }
    if (strpos($relative, '/') === 0) {
        return $scheme . '://' . $host . $port . $relative;
    }
    return $scheme . '://' . $host . $port . $dir . $relative;
}

// ------------------------------------------------------
// cURL ile fetch
// ------------------------------------------------------
function fetchUrl($url, $headers = []) {
    $ch = curl_init($url);
    $headerArr = [];
    foreach ($headers as $k => $v) $headerArr[] = "$k: $v";
    $headerArr[] = "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)";

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headerArr,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER         => true,
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'status' => $httpCode,
        'content_type' => $contentType,
        'body' => $body,
        'raw_headers' => $rawHeaders,
    ];
}

// ======================================================
// ROUTER
// ======================================================

$pathname = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$idParam = $_GET['ID'] ?? $_GET['id'] ?? null;

try {
    // 1. Ana sayfa yönlendirme
    if ($pathname === '/' || $pathname === '/index.html' || $pathname === '/index.php') {
        if (isWebBrowser()) {
            header("Location: $baseUrl/index.m3u", true, 302);
            exit;
        }
    }

    // 2. /index.m3u listesi
    if ($pathname === '/index.m3u' || ($pathname === '/index.m3u8' && !$idParam)) {
        $channels = getChannels();
        $output = "#EXTM3U\n";
        foreach ($channels as $i => $ch) {
            $channelId = $i + 1;
            $extLine = $ch['extinf'] ?: "#EXTINF:-1, " . ($ch['name'] ?: "Kanal $channelId");
            $output .= "$extLine\n$baseUrl/index.m3u8?ID=$channelId\n";
        }
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=60');
        echo $output;
        exit;
    }

    // 3. /index.m3u8?ID=X
    if ($pathname === '/index.m3u8' && $idParam) {
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

        // Doğrudan .ts akışı
        if (strpos($ct, 'mpegurl') === false && strpos($ch['url'], '.m3u8') === false) {
            header('Content-Type: ' . ($ct ?: 'video/mp2t'));
            header('Access-Control-Allow-Origin: *');
            echo $resp['body'];
            exit;
        }

        // M3U8 manifest
        $rewritten = rewriteM3u8Content($resp['body'], $ch['url'], $baseUrl, $ch['headers']);
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache');
        echo $rewritten;
        exit;
    }

    // 4. /proxy/...
    if (strpos($pathname, '/proxy/') === 0) {
        $encrypted = substr($pathname, strlen('/proxy/'));
        if ($encrypted === '') {
            http_response_code(400);
            echo "Geçersiz Şifreli İstek.";
            exit;
        }

        $payload = decryptData($encrypted);
        if (!$payload || empty($payload['url'])) {
            http_response_code(403);
            echo "Geçersiz veya Süresi Dolmuş Bağlantı.";
            exit;
        }

        $headers = $payload['headers'] ?? [];
        if (empty($headers['User-Agent'])) $headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

        $resp = fetchUrl($payload['url'], $headers);
        $ct = $resp['content_type'] ?? '';

        // Alt m3u8
        if (strpos($ct, 'mpegurl') !== false || strpos($payload['url'], '.m3u8') !== false) {
            $rewritten = rewriteM3u8Content($resp['body'], $payload['url'], $baseUrl, $payload['headers']);
            header('Content-Type: application/vnd.apple.mpegurl');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-cache');
            echo $rewritten;
            exit;
        }

        // Normal segment
        header('Content-Type: ' . ($ct ?: 'application/octet-stream'));
        header('Access-Control-Allow-Origin: *');
        echo $resp['body'];
        exit;
    }

    http_response_code(404);
    echo "Geçersiz İstek";

} catch (Exception $e) {
    http_response_code(500);
    echo "Sunucu Hatası: " . $e->getMessage();
}
