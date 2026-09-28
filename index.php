<?php
$USER_AGENT       = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) " .
                    "AppleWebKit/537.36 (KHTML, like Gecko) " .
                    "Chrome/124.0.0.0 Safari/537.36";
$DEFAULT_REFERER  = "https://inattv1303.xyz/";
$DEFAULT_ORIGIN   = "https://inattv1303.xyz";
$MASKED_EXTS      = [".jpg", ".jpeg", ".png", ".gif", ".webp", ".bmp"];
$TIMEOUT          = 30;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, HEAD, OPTIONS");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Expose-Headers: *");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function isMaskedSegment($url) {
    global $MASKED_EXTS;
    $path = strtolower(explode('?', $url)[0]);
    foreach ($MASKED_EXTS as $ext) {
        if (substr($path, -strlen($ext)) === $ext) return true;
    }
    return false;
}

function isManifest($url) {
    $u = strtolower($url);
    $path = explode('?', $u)[0];
    return strpos($path, '.m3u8') !== false
        || strpos($u, 'ext=m3u8') !== false
        || substr($path, -10) === '/mono.m3u8'
        || strpos($path, 'master') !== false
        || strpos($path, 'playlist') !== false;
}

function buildHeaders($referer = null, $origin = null) {
    global $USER_AGENT, $DEFAULT_REFERER, $DEFAULT_ORIGIN;
    return [
        "User-Agent: " . $USER_AGENT,
        "Accept: */*",
        "Referer: " . ($referer ?: $DEFAULT_REFERER),
        "Origin: " . ($origin ?: $DEFAULT_ORIGIN),
        "Accept-Language: tr-TR",
        "Connection: keep-alive",
    ];
}

function curlFetch($url, $headers) {
    global $TIMEOUT;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => "",
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'body'        => $body,
        'status'      => $status,
        'contentType' => $contentType,
        'error'       => $error,
    ];
}

function makeAbsolute($url, $baseOrigin, $baseDir) {
    if (preg_match('#^https?://#i', $url)) return $url;
    if (substr($url, 0, 2) === '//') return 'https:' . $url;
    if (substr($url, 0, 1) === '/') return $baseOrigin . $url;
    return $baseOrigin . $baseDir . $url;
}

$action = $_GET['action'] ?? 'info';
$target = $_GET['url'] ?? '';
$refOverride  = $_GET['referer'] ?? null;
$origOverride = $_GET['origin'] ?? null;

if ($action === 'health') {
    header("Content-Type: text/plain");
    echo "OK";
    exit;
}

if ($action === 'info') {
    header("Content-Type: text/plain; charset=utf-8");
    echo "IPTV Proxy\n";
    exit;
}

if ($target === '') {
    http_response_code(400);
    header("Content-Type: text/plain");
    echo "Missing ?url= parameter";
    exit;
}

if ($action === 'ts') {
    $headers = buildHeaders($refOverride, $origOverride);
    $headers[] = "Accept: video/mp2t,*/*";
    $res = curlFetch($target, $headers);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        http_response_code($res['status'] ?: 502);
        header("Content-Type: text/plain");
        echo "Upstream {$res['status']} for {$target}\n";
        if ($res['error']) echo "Error: {$res['error']}\n";
        exit;
    }

    header("Content-Type: video/mp2t");
    header("Cache-Control: public, max-age=5");
    header("Content-Length: " . strlen($res['body']));
    echo $res['body'];
    exit;
}

if ($action === 'hls') {
    $headers = buildHeaders($refOverride, $origOverride);
    $res = curlFetch($target, $headers);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        http_response_code($res['status'] ?: 502);
        header("Content-Type: text/plain");
        echo "Upstream {$res['status']} for {$target}\n";
        if ($res['error']) echo "Error: {$res['error']}\n";
        exit;
    }

    $text = $res['body'];

    if (strpos(ltrim($text), '#EXTM3U') !== 0) {
        header("Content-Type: " . ($res['contentType'] ?: 'application/octet-stream'));
        echo $text;
        exit;
    }

    $baseParts  = parse_url($target);
    $scheme     = $baseParts['scheme'] ?? 'https';
    $host       = $baseParts['host'] ?? '';
    $port       = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $baseOrigin = "{$scheme}://{$host}{$port}";
    $basePath   = $baseParts['path'] ?? '/';
    $baseDir    = substr($basePath, 0, strrpos($basePath, '/') + 1);

    $proxyUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

    $qsSuffix = '';
    if ($refOverride)  $qsSuffix .= '&referer=' . urlencode($refOverride);
    if ($origOverride) $qsSuffix .= '&origin='  . urlencode($origOverride);

    $lines = explode("\n", $text);
    $out = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') { $out[] = $line; continue; }

        if ($trimmed[0] === '#') {
            if (strpos($trimmed, 'URI="') !== false) {
                $trimmed = preg_replace_callback(
                    '/URI="([^"]+)"/',
                    function ($m) use ($baseOrigin, $baseDir, $proxyUrl, $qsSuffix) {
                        $abs = makeAbsolute($m[1], $baseOrigin, $baseDir);
                        return 'URI="' . $proxyUrl . '?action=ts&url=' . urlencode($abs) . $qsSuffix . '"';
                    },
                    $trimmed
                );
            }
            $out[] = $trimmed;
            continue;
        }

        $abs = makeAbsolute($trimmed, $baseOrigin, $baseDir);

        if (isMaskedSegment($abs)) {
            $out[] = $proxyUrl . '?action=ts&url=' . urlencode($abs) . $qsSuffix;
        } elseif (isManifest($abs)) {
            $out[] = $proxyUrl . '?action=hls&url=' . urlencode($abs) . $qsSuffix;
        } else {
            $out[] = $proxyUrl . '?action=ts&url=' . urlencode($abs) . $qsSuffix;
        }
    }

    header("Content-Type: application/vnd.apple.mpegurl");
    header("Cache-Control: no-cache");
    echo implode("\n", $out);
    exit;
}

if ($action === 'debug') {
    $headers = buildHeaders($refOverride, $origOverride);
    $res = curlFetch($target, $headers);

    header("Content-Type: text/plain; charset=utf-8");
    echo "=== DEBUG ===\n";
    echo "Target     : {$target}\n";
    echo "Referer    : " . ($refOverride ?: 'default') . "\n";
    echo "Origin     : " . ($origOverride ?: 'default') . "\n";
    echo "Status     : {$res['status']}\n";
    echo "ContentType: {$res['contentType']}\n";
    if ($res['error']) echo "Curl Error : {$res['error']}\n";
    echo "\n--- Body (first 2000 chars) ---\n";
    echo substr($res['body'], 0, 2000);
    exit;
}

http_response_code(404);
header("Content-Type: text/plain");
echo "Unknown action: {$action}";
