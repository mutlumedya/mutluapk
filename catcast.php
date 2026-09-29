<?php
// CORS başlıklarını ekle
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS isteği için erken yanıt
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ID parametresini kontrol et
if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    echo 'ID parameter is required. Usage: ?id=AlvinOvcu.m3u8';
    exit();
}

// .m3u8 uzantısını temizleme
$cleanId = preg_replace('/\.m3u8$/', '', $_GET['id']);

try {
    // 1. Adım: Kanal bilgilerini getir
    $channelUrl = "https://api.catcast.tv/api/channels/getbyshortname/" . urlencode($cleanId);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $channelUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept: application/json',
        'Referer: https://catcast.tv/'
    ]);
    
    $channelResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("First API failed with status: " . $httpCode);
    }
    
    $channelData = json_decode($channelResponse, true);
    
    if ($channelData === null) {
        throw new Exception("Failed to parse channel API response");
    }
    
    // API yanıt yapısına göre entity_id'yi bulma
    $entityId = null;
    
    // Kök seviyedeki id'yi kullan
    if (isset($channelData['id'])) {
        $entityId = $channelData['id'];
    } 
    // data objesi içindeki id'yi kullan
    else if (isset($channelData['data']['id'])) {
        $entityId = $channelData['data']['id'];
    }
    else {
        throw new Exception('ID not found in API response');
    }
    
    error_log("Found entity ID: " . $entityId);
    
    // 2. Adım: Program bilgilerini getir
    $programUrl = "https://api.catcast.tv/api/channels/" . urlencode($entityId) . "/getcurrentprogram";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $programUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept: application/json',
        'Referer: https://catcast.tv/'
    ]);
    
    $programResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Second API failed with status: " . $httpCode);
    }
    
    $programData = json_decode($programResponse, true);
    
    if ($programData === null) {
        throw new Exception("Failed to parse program API response");
    }
    
    // full_mobile_url kontrolü
    if (!isset($programData['data']['full_mobile_url'])) {
        throw new Exception('Stream URL (full_mobile_url) not found in program data');
    }
    
    $streamUrl = $programData['data']['full_mobile_url'];
    error_log("Found stream URL: " . $streamUrl);
    
    // 3. Adım: Nihai URL'ye yönlendir
    header("Location: " . $streamUrl, true, 302);
    exit();
    
} catch (Exception $error) {
    error_log("PHP Error: " . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    header('Access-Control-Allow-Origin: *');
    echo "Error: " . $error->getMessage();
    exit();
}
?>
