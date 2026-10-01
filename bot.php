<?php
// bot.php

class Bot {
    private $cookieFile;
    private $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36'
    ];
    private $proxy;
    private $timeout;
    private $minDelay;
    private $lastRequest = 0;
    private $logger;

    public function __construct(array $options = []) {
        $this->cookieFile = $options['cookie_file'] ?? sys_get_temp_dir() . '/bot_cookies.txt';
        $this->proxy      = $options['proxy'] ?? null;
        $this->timeout    = $options['timeout'] ?? 30;
        $this->minDelay   = $options['min_delay'] ?? 2;
        $this->logger     = $options['logger'] ?? function ($msg) {
            echo date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
        };
    }

    public function request(string $url, string $method = 'GET', array $data = [], array $headers = []): ?string {
        // Hız sınırı
        $now = microtime(true);
        $diff = $now - $this->lastRequest;
        if ($diff < $this->minDelay) {
            usleep((int)(($this->minDelay - $diff) * 1000000));
        }
        $this->lastRequest = microtime(true);

        $ch = curl_init();
        $defaultHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        $headers = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_USERAGENT      => $this->userAgents[array_rand($this->userAgents)],
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => false, // canlıda true yap
            CURLOPT_SSL_VERIFYHOST => false, // canlıda 2 yap
        ]);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif (!empty($data)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log("cURL hatası: $error");
            return null;
        }
        if ($httpCode >= 400) {
            $this->log("HTTP $httpCode: $url");
            return null;
        }
        return $response;
    }

    public function get(string $url, array $headers = []): ?string {
        return $this->request($url, 'GET', [], $headers);
    }

    public function post(string $url, array $data = [], array $headers = []): ?string {
        return $this->request($url, 'POST', $data, $headers);
    }

    public function parseHtml(string $html): DOMXPath {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        return new DOMXPath($dom);
    }

    public function saveJson(string $file, $data): void {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function log(string $message): void {
        call_user_func($this->logger, $message);
    }
}

interface Task {
    public function run(Bot $bot): void;
}

class FilmModuTask implements Task {
    private $baseUrl = 'https://www.filmmodu.one/';
    private $outputFile;

    public function __construct(string $outputFile = 'filmler.json') {
        $this->outputFile = $outputFile;
    }

    public function run(Bot $bot): void {
        $bot->log("Film listesi çekiliyor...");
        $filmler = $this->listFilms($bot);
        $bot->log(count($filmler) . " film bulundu.");

        $detaylar = [];
        foreach ($filmler as $i => $film) {
            $bot->log("Detay çekiliyor: " . $film['baslik']);
            $detay = $this->getFilmDetails($bot, $film['url']);
            if ($detay) {
                $detaylar[] = array_merge($film, $detay);
            }
            if ($i >= 4) break; // İlk 5 filmle sınırlı örnek
        }

        $bot->saveJson($this->outputFile, $detaylar);
        $bot->log("Kaydedildi: " . $this->outputFile);
    }

    private function listFilms(Bot $bot): array {
        $html = $bot->get($this->baseUrl);
        if (!$html) return [];

        $xpath = $bot->parseHtml($html);
        $filmler = [];
        $links = $xpath->query('//a[contains(@href, "film-izle")]');

        foreach ($links as $link) {
            $baslik = trim($link->textContent);
            $url    = $link->getAttribute('href');
            if (empty($baslik) || strpos($url, 'film-izle') === false) continue;

            if (!preg_match('#^https?://#', $url)) {
                $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
            }
            if (isset($filmler[$url])) continue;
            $filmler[$url] = ['baslik' => $baslik, 'url' => $url];
        }
        return array_values($filmler);
    }

    private function getFilmDetails(Bot $bot, string $url): ?array {
        $html = $bot->get($url);
        if (!$html) return null;

        $xpath = $bot->parseHtml($html);
        $detay = [];

        $metaTitle = $xpath->query('//meta[@property="og:title"]/@content')->item(0);
        $metaDesc  = $xpath->query('//meta[@property="og:description"]/@content')->item(0);
        $metaImage = $xpath->query('//meta[@property="og:image"]/@content')->item(0);

        $detay['baslik']   = $metaTitle ? $metaTitle->nodeValue : '';
        $detay['aciklama'] = $metaDesc  ? $metaDesc->nodeValue  : '';
        $detay['kapak']    = $metaImage ? $metaImage->nodeValue : '';

        if (preg_match('/var videoId\s*=\s*[\'"]([^\'"]+)[\'"]/', $html, $m)) {
            $detay['video_id'] = $m[1];
        }
        return $detay;
    }
}

// ---- Çalıştırma ----
$bot = new Bot([
    'min_delay' => 3,
    'timeout'   => 30,
    // 'proxy' => 'http://127.0.0.1:8080',
]);

$task = new FilmModuTask('filmler.json');
$task->run($bot);
