<?php
/*
 |=====================================================================
 |  YAYIN PANELİ  -  Tek dosya PHP + FFmpeg yayın yönetimi (Windows)
 |  Varsayılan giriş:  admin / admin123   (ilk girişte değiştirin!)
 |
 |  Gereksinimler : PHP 7.4+  (exec, proc_open açık olmalı)
 |                  Windows Server + PowerShell + FFmpeg
 |  FFmpeg yolu   : C:\ffmpeg\bin\ffmpeg.exe  (Ayarlar'dan değiştirilebilir)
 |
 |  Otomatik başlatma (sunucu yeniden başlarsa) için Görev Zamanlayıcı:
 |      php.exe "C:\yol\index.php" watchdog      (her 1 dk ya da açılışta)
 |=====================================================================
*/
error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(120);
date_default_timezone_set('Europe/Istanbul');

/* ------------------------- AYARLAR (isteğe bağlı) ------------------- */
// Veri klasörünü web kökü DIŞINA taşımak daha güvenlidir. Örn: 'C:\\yayin_data'
define('DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'panel_data');
// HLS çıktıları web'den erişilebilir olmalı (panel ile aynı klasör altında)
define('HLS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'hls');
define('DEFAULT_FFMPEG', 'C:\\ffmpeg\\bin\\ffmpeg.exe');

/* ------------------------- YARDIMCI FONKSİYONLAR -------------------- */
function wp($p) { return str_replace('/', '\\', $p); }

function ensure_dirs() {
    foreach ([DATA_DIR, DATA_DIR . '/logos', DATA_DIR . '/run', DATA_DIR . '/logs', HLS_DIR] as $d) {
        if (!is_dir($d)) @mkdir($d, 0777, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $wc = DATA_DIR . '/web.config';
    if (!file_exists($wc)) @file_put_contents($wc, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>');
    $hw = HLS_DIR . '/web.config';
    if (!file_exists($hw)) @file_put_contents($hw, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><staticContent><remove fileExtension=".m3u8" /><mimeMap fileExtension=".m3u8" mimeType="application/vnd.apple.mpegurl" /><remove fileExtension=".ts" /><mimeMap fileExtension=".ts" mimeType="video/mp2t" /></staticContent><httpProtocol><customHeaders><add name="Access-Control-Allow-Origin" value="*" /><add name="Cache-Control" value="no-cache" /></customHeaders></httpProtocol></system.webServer></configuration>');
    $ha = HLS_DIR . '/.htaccess';
    if (!file_exists($ha)) @file_put_contents($ha, "AddType application/vnd.apple.mpegurl .m3u8\nAddType video/mp2t .ts\n<IfModule mod_headers.c>\nHeader set Access-Control-Allow-Origin \"*\"\nHeader set Cache-Control \"no-cache\"\n</IfModule>\n");
}

function db_read($name, $default) {
    $f = DATA_DIR . "/$name.php";
    if (!is_file($f)) return $default;
    $raw = (string)file_get_contents($f);
    $pos = strpos($raw, "\n");
    $raw = $pos === false ? '' : substr($raw, $pos + 1);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $default;
}
function db_write($name, $data) {
    $f = DATA_DIR . "/$name.php";
    $fp = fopen($f, 'c+');
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, "<?php http_response_code(403); exit; ?>\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function get_config() {
    $c = db_read('config', []);
    $def = [
        'ffmpeg' => DEFAULT_FFMPEG, 'loglevel' => 'warning',
        'admin_user' => 'admin', 'admin_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'pw_changed' => 0, 'next_id' => 1,
    ];
    $new = $c + $def;
    if ($new !== $c) db_write('config', $new);
    return $new;
}
function save_config($c) { db_write('config', $c); }

function stream_defaults() {
    return [
        'id' => 0, 'name' => '', 'source' => '', 'output_type' => 'rtmp', 'output_url' => '', 'mode' => 'transcode',
        'logo' => '', 'logo_pos' => 'tr', 'logo_x' => 10, 'logo_y' => 10, 'logo_margin' => 20, 'logo_width' => 150, 'logo_opacity' => 100,
        'vcodec' => 'libx264', 'preset' => 'veryfast', 'vbitrate' => 2500, 'resolution' => '', 'fps' => 0,
        'abitrate' => 128, 'audio_mode' => 'aac',
        'realtime' => 0, 'reconnect' => 1, 'user_agent' => '', 'extra_in' => '', 'extra_out' => '',
        'autorestart' => 1, 'hls_time' => 4, 'hls_list' => 6,
        'desired' => 0, 'pid' => 0, 'started_at' => 0, 'created' => 0,
    ];
}

function json_out($d, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function exec_ok() {
    if (!function_exists('exec')) return false;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $dis, true);
}
function is_win() { return stripos(PHP_OS, 'WIN') === 0; }

function pick($v, $allowed, $def) { return in_array($v, $allowed, true) ? $v : $def; }
function clampi($v, $min, $max, $def) {
    if (!is_numeric($v)) return $def;
    return max($min, min($max, (int)$v));
}

/* batch için güvenli tırnaklama */
function bq($s) {
    $s = preg_replace('/[\r\n\x00"]/', '', (string)$s);
    $s = str_replace('%', '%%', $s);
    return '"' . $s . '"';
}
function split_args($str) {
    $out = [];
    if (preg_match_all('/"[^"]*"|\S+/', (string)$str, $m)) {
        foreach ($m[0] as $t) $out[] = trim($t, '"');
    }
    return $out;
}

function run_proc(array $cmd) {
    if (PHP_VERSION_ID < 70400) return [-1, '', 'PHP 7.4 veya üzeri gerekli'];
    $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($p)) return [-1, '', 'Program başlatılamadı'];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    return [$rc, $out, $err];
}

function alive_pids() {
    $o = []; $r = [];
    @exec('tasklist /FO CSV /NH 2>NUL', $o);
    foreach ($o as $l) {
        $c = str_getcsv($l);
        if (count($c) >= 2 && strtolower($c[0]) === 'cmd.exe') $r[(int)$c[1]] = true;
    }
    return $r;
}

function stream_update($id, $fields) {
    $streams = db_read('streams', []);
    if (!isset($streams[$id])) return;
    $streams[$id] = $fields + $streams[$id];
    db_write('streams', $streams);
}

function with_lock($fn) {
    $fp = fopen(DATA_DIR . '/run/ops.lock', 'c');
    flock($fp, LOCK_EX);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}

function tail_file($f, $n = 12000) {
    if (!is_file($f)) return '';
    $sz = filesize($f);
    $fp = fopen($f, 'rb');
    if ($sz > $n) fseek($fp, -$n, SEEK_END);
    $t = stream_get_contents($fp);
    fclose($fp);
    return $t;
}

/* ------------------------- FFMPEG KOMUTU --------------------------- */
function logo_path($s) {
    if (!$s['logo']) return '';
    $p = DATA_DIR . '/logos/' . basename($s['logo']);
    return is_file($p) ? wp($p) : '';
}

function build_args($s, $cfg) {
    $s += stream_defaults();
    $a = [$cfg['ffmpeg'], '-hide_banner', '-loglevel', $cfg['loglevel'], '-y'];
    $src = $s['source'];
    $isNet = (bool)preg_match('~^https?://~i', $src);

    if ($s['reconnect'] && $isNet) array_push($a, '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5');
    if ($s['user_agent'] !== '') array_push($a, '-user_agent', $s['user_agent']);
    if ($s['realtime']) $a[] = '-re';
    foreach (split_args($s['extra_in']) as $x) $a[] = $x;
    array_push($a, '-i', $src);

    $logo = logo_path($s);
    $hasLogo = $logo !== '';
    $transcode = ($s['mode'] === 'transcode') || $hasLogo;

    $w = $h = 0;
    if ($s['resolution'] && preg_match('/^(\d+)x(\d+)$/', $s['resolution'], $m)) { $w = (int)$m[1]; $h = (int)$m[2]; }

    if ($hasLogo) {
        if (strtolower(pathinfo($logo, PATHINFO_EXTENSION)) === 'gif') array_push($a, '-ignore_loop', '0');
        else array_push($a, '-loop', '1');
        array_push($a, '-i', $logo);

        $main = '[0:v]' . ($w ? "scale=$w:$h:force_original_aspect_ratio=decrease,pad=$w:$h:(ow-iw)/2:(oh-ih)/2,setsar=1" : 'null') . '[bg]';
        $lw = (int)$s['logo_width'];
        $lg = '[1:v]' . ($lw > 0 ? "scale=$lw:-1," : '') . 'format=rgba';
        if ((int)$s['logo_opacity'] < 100) $lg .= ',colorchannelmixer=aa=' . round($s['logo_opacity'] / 100, 2);
        $lg .= '[lg]';
        $mg = (int)$s['logo_margin'];
        switch ($s['logo_pos']) {
            case 'tl': $x = $mg; $y = $mg; break;
            case 'bl': $x = $mg; $y = "H-h-$mg"; break;
            case 'br': $x = "W-w-$mg"; $y = "H-h-$mg"; break;
            case 'c':  $x = '(W-w)/2'; $y = '(H-h)/2'; break;
            case 'custom': $x = (int)$s['logo_x']; $y = (int)$s['logo_y']; break;
            default:   $x = "W-w-$mg"; $y = $mg;
        }
        $ov = "[bg][lg]overlay=$x:$y:shortest=1[v]";
        array_push($a, '-filter_complex', "$main;$lg;$ov", '-map', '[v]', '-map', '0:a?');
    } elseif ($transcode) {
        if ($w) array_push($a, '-vf', "scale=$w:$h:force_original_aspect_ratio=decrease,pad=$w:$h:(ow-iw)/2:(oh-ih)/2,setsar=1");
        array_push($a, '-map', '0:v:0?', '-map', '0:a?');
    }

    if ($transcode) {
        $vb = (int)$s['vbitrate'];
        array_push($a, '-c:v', $s['vcodec']);
        if ($s['vcodec'] === 'libx264') array_push($a, '-preset', $s['preset'], '-sc_threshold', '0');
        array_push($a, '-pix_fmt', 'yuv420p', '-b:v', $vb . 'k', '-maxrate', $vb . 'k', '-bufsize', ($vb * 2) . 'k');
        $fps = (int)$s['fps'];
        if ($fps > 0) array_push($a, '-r', (string)$fps);
        array_push($a, '-g', (string)(($fps > 0 ? $fps : 30) * 2));
        if ($s['audio_mode'] === 'aac') array_push($a, '-c:a', 'aac', '-b:a', (int)$s['abitrate'] . 'k', '-ar', '44100', '-ac', '2');
        elseif ($s['audio_mode'] === 'copy') array_push($a, '-c:a', 'copy');
        else $a[] = '-an';
    } else {
        array_push($a, '-c', 'copy');
    }

    foreach (split_args($s['extra_out']) as $x) $a[] = $x;

    switch ($s['output_type']) {
        case 'hls':
            $dir = HLS_DIR . '/' . $s['id'];
            array_push($a, '-f', 'hls', '-hls_time', (string)(int)$s['hls_time'], '-hls_list_size', (string)(int)$s['hls_list'],
                '-hls_flags', 'delete_segments+omit_endlist',
                '-hls_segment_filename', wp($dir . '/seg_%05d.ts'), wp($dir . '/index.m3u8'));
            break;
        case 'mpegts': array_push($a, '-f', 'mpegts', $s['output_url']); break;
        case 'custom': $a[] = $s['output_url']; break;
        default:       array_push($a, '-f', 'flv', $s['output_url']);
    }
    return $a;
}

/* ------------------------- BAŞLAT / DURDUR ------------------------- */
function launch_detached($bat, $id) {
    $ps = wp(DATA_DIR . '/run/launch_' . $id . '.ps1');
    $cl = 'cmd.exe /c ""' . $bat . '""';
    $q = function ($t) { return str_replace("'", "''", $t); };
    $script = "\$r = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{ CommandLine = '" . $q($cl) . "'; CurrentDirectory = '" . $q(dirname($bat)) . "' }\r\n"
        . "if (\$r.ReturnValue -ne 0) { Write-Output ('ERR:' + \$r.ReturnValue) } else { Write-Output ('PID:' + \$r.ProcessId) }\r\n";
    file_put_contents($ps, $script);
    $out = [];
    exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $ps . '" 2>&1', $out, $rc);
    $txt = implode("\n", $out);
    if (preg_match('/PID:(\d+)/', $txt, $m)) return (int)$m[1];
    throw new Exception('İşlem başlatılamadı. PowerShell çıktısı: ' . trim($txt));
}

function stream_start($id) {
    $streams = db_read('streams', []);
    if (!isset($streams[$id])) throw new Exception('Yayın bulunamadı');
    $s = $streams[$id] + stream_defaults();
    $s['id'] = (int)$id;
    $cfg = get_config();
    if (!is_win()) throw new Exception('Bu panel Windows sunucular için hazırlanmıştır.');
    if (!exec_ok()) throw new Exception('PHP exec() fonksiyonu kapalı (disable_functions).');
    if (!is_file($cfg['ffmpeg'])) throw new Exception('FFmpeg bulunamadı: ' . $cfg['ffmpeg']);
    $alive = alive_pids();
    if ($s['pid'] && isset($alive[(int)$s['pid']])) return;

    $log = DATA_DIR . '/logs/stream_' . $id . '.log';
    file_put_contents($log, '');
    if ($s['output_type'] === 'hls') {
        $d = HLS_DIR . '/' . $id;
        if (is_dir($d)) { foreach (glob($d . '/*') as $f) @unlink($f); } else @mkdir($d, 0777, true);
    }

    $args = build_args($s, $cfg);
    $line = implode(' ', array_map('bq', $args));
    $bat = wp(DATA_DIR . '/run/stream_' . $id . '.bat');
    $logw = wp($log);
    $b  = "@echo off\r\nchcp 65001 >nul\r\ntitle YayinPanel_" . (int)$id . "\r\ncd /d " . bq(wp(DATA_DIR)) . "\r\n";
    $b .= ":loop\r\n";
    $b .= "echo [%date% %time%] FFmpeg baslatiliyor... >> " . bq($logw) . "\r\n";
    $b .= $line . " 2>> " . bq($logw) . "\r\n";
    $b .= "set RC=%errorlevel%\r\n";
    $b .= "echo [%date% %time%] FFmpeg durdu (kod: %RC%) >> " . bq($logw) . "\r\n";
    if ($s['autorestart']) {
        $b .= "ping 127.0.0.1 -n 6 >nul\r\ngoto loop\r\n";
    } else {
        $b .= "exit /b %RC%\r\n";
    }
    file_put_contents($bat, $b);

    $pid = launch_detached($bat, $id);
    stream_update($id, ['desired' => 1, 'pid' => $pid, 'started_at' => time()]);
}

function stream_stop($id) {
    $streams = db_read('streams', []);
    if (!isset($streams[$id])) return;
    $pid = (int)$streams[$id]['pid'];
    stream_update($id, ['desired' => 0, 'pid' => 0, 'started_at' => 0]);
    if ($pid) {
        $alive = alive_pids();
        if (isset($alive[$pid])) @exec('taskkill /PID ' . $pid . ' /T /F 2>&1');
    }
}

function watchdog() {
    $fp = fopen(DATA_DIR . '/run/ops.lock', 'c');
    if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return; }
    try {
        $streams = db_read('streams', []);
        $alive = null;
        foreach ($streams as $id => $s) {
            if (empty($s['desired'])) continue;
            if ($alive === null) $alive = alive_pids();
            if (!empty($s['pid']) && isset($alive[(int)$s['pid']])) continue;
            if (!empty($s['autorestart'])) {
                try { stream_start((int)$id); } catch (Exception $e) { @file_put_contents(DATA_DIR . '/logs/stream_' . $id . '.log', '[' . date('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND); }
            } else {
                stream_update($id, ['desired' => 0, 'pid' => 0, 'started_at' => 0]);
            }
        }
    } finally { flock($fp, LOCK_UN); fclose($fp); }
}

function remove_stream_files($id, $s) {
    if (!empty($s['logo'])) @unlink(DATA_DIR . '/logos/' . basename($s['logo']));
    @unlink(DATA_DIR . '/run/stream_' . $id . '.bat');
    @unlink(DATA_DIR . '/run/launch_' . $id . '.ps1');
    @unlink(DATA_DIR . '/logs/stream_' . $id . '.log');
    $d = HLS_DIR . '/' . $id;
    if (is_dir($d)) { foreach (glob($d . '/*') as $f) @unlink($f); @rmdir($d); }
}

/* ------------------------- BAŞLANGIÇ ------------------------------- */
ensure_dirs();
$CFG = get_config();

if (PHP_SAPI === 'cli') {
    $cmd = isset($argv[1]) ? $argv[1] : '';
    if ($cmd === 'watchdog') { watchdog(); echo "OK\n"; }
    else echo "Kullanim: php index.php watchdog\n";
    exit;
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'lifetime' => 0]);
session_name('YAYINPANEL');
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$a = isset($_GET['a']) ? $_GET['a'] : '';

/* --- Çıkış --- */
if ($a === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

/* --- Giriş --- */
$loginError = '';
if (empty($_SESSION['auth']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'])) {
    $u = (string)$_POST['login_user'];
    $p = (string)($_POST['login_pass'] ?? '');
    if (hash_equals($CFG['admin_user'], $u) && password_verify($p, $CFG['admin_hash'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = 1;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    sleep(1);
    $loginError = 'Kullanıcı adı veya şifre hatalı.';
}

if (empty($_SESSION['auth'])) {
    if ($a !== '') json_out(['error' => 'auth'], 401);
    ?><!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yayın Paneli - Giriş</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<style>body{background:radial-gradient(circle at 20% 10%,#1e1b4b 0,#070b14 45%)}
.inp{width:100%;background:#0d1424;border:1px solid #243049;border-radius:.7rem;padding:.7rem .9rem;color:#e5e9f5;outline:none}
.inp:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.25)}</style></head>
<body class="min-h-screen flex items-center justify-center p-4 text-slate-200">
<form method="post" class="w-full max-w-sm bg-[#0e1526]/90 border border-[#1d2840] rounded-2xl p-8 shadow-2xl">
  <div class="text-center mb-6">
    <div class="mx-auto w-14 h-14 rounded-2xl bg-indigo-600 flex items-center justify-center text-2xl mb-3">📡</div>
    <h1 class="text-xl font-bold">Yayın Paneli</h1>
    <p class="text-sm text-slate-400">Yönetici girişi</p>
  </div>
  <?php if ($loginError): ?><div class="mb-4 text-sm bg-rose-500/10 border border-rose-500/30 text-rose-300 rounded-lg px-3 py-2"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
  <label class="text-xs text-slate-400">Kullanıcı adı</label>
  <input class="inp mb-4 mt-1" name="login_user" autofocus autocomplete="username" required>
  <label class="text-xs text-slate-400">Şifre</label>
  <input class="inp mb-6 mt-1" type="password" name="login_pass" autocomplete="current-password" required>
  <button class="w-full bg-indigo-600 hover:bg-indigo-500 transition rounded-xl py-3 font-semibold">Giriş Yap</button>
</form></body></html>
<?php
    exit;
}

/* --- Giriş yapılmış: oturum kilidini bırak --- */
$csrf = $_SESSION['csrf'];
session_write_close();

/* ------------------------- API ------------------------------------- */
if ($a !== '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = isset($_SERVER['HTTP_X_CSRF']) ? $_SERVER['HTTP_X_CSRF'] : '';
        if (!hash_equals($csrf, $tok)) json_out(['error' => 'Güvenlik doğrulaması başarısız, sayfayı yenileyin.'], 403);
    }
    try {
        switch ($a) {

            case 'list':
                watchdog();
                $streams = db_read('streams', []);
                $alive = alive_pids();
                $out = [];
                foreach ($streams as $id => $s) {
                    $s += stream_defaults();
                    $run = $s['pid'] && isset($alive[(int)$s['pid']]);
                    $s['running'] = $run;
                    $s['state'] = $run ? 'running' : ($s['desired'] ? 'down' : 'stopped');
                    $s['uptime'] = $run ? max(0, time() - (int)$s['started_at']) : 0;
                    $s['logo_url'] = $s['logo'] ? '?a=logo&id=' . $id . '&v=' . substr(md5($s['logo']), 0, 6) : '';
                    $s['hls_path'] = $s['output_type'] === 'hls' ? 'hls/' . $id . '/index.m3u8' : '';
                    $out[] = $s;
                }
                usort($out, function ($x, $y) { return $x['id'] <=> $y['id']; });
                $cfg = get_config();
                json_out([
                    'streams' => $out,
                    'config' => ['ffmpeg' => $cfg['ffmpeg'], 'loglevel' => $cfg['loglevel'], 'admin_user' => $cfg['admin_user']],
                    'env' => [
                        'os_ok' => is_win(), 'exec_ok' => exec_ok(), 'ffmpeg_ok' => is_file($cfg['ffmpeg']),
                        'default_pw' => !$cfg['pw_changed'], 'php' => PHP_VERSION,
                    ],
                ]);

            case 'save':
                $id = (int)($_POST['id'] ?? 0);
                $streams = db_read('streams', []);
                if ($id) {
                    if (!isset($streams[$id])) json_out(['error' => 'Yayın bulunamadı'], 404);
                    $s = $streams[$id] + stream_defaults();
                } else {
                    $cfg = get_config();
                    $id = (int)$cfg['next_id'];
                    $cfg['next_id'] = $id + 1;
                    save_config($cfg);
                    $s = stream_defaults();
                    $s['id'] = $id;
                    $s['created'] = time();
                }
                $t = function ($k) { return trim(preg_replace('/[\r\n\x00]/', '', (string)($_POST[$k] ?? ''))); };
                $s['name'] = mb_substr($t('name'), 0, 80);
                $s['source'] = $t('source');
                $s['output_type'] = pick($t('output_type'), ['rtmp', 'hls', 'mpegts', 'custom'], 'rtmp');
                $s['output_url'] = $t('output_url');
                $s['mode'] = pick($t('mode'), ['transcode', 'copy'], 'transcode');
                $s['logo_pos'] = pick($t('logo_pos'), ['tl', 'tr', 'bl', 'br', 'c', 'custom'], 'tr');
                $s['logo_x'] = clampi($t('logo_x'), -5000, 5000, 10);
                $s['logo_y'] = clampi($t('logo_y'), -5000, 5000, 10);
                $s['logo_margin'] = clampi($t('logo_margin'), 0, 2000, 20);
                $s['logo_width'] = clampi($t('logo_width'), 0, 3000, 150);
                $s['logo_opacity'] = clampi($t('logo_opacity'), 0, 100, 100);
                $s['vcodec'] = pick($t('vcodec'), ['libx264', 'h264_nvenc', 'h264_qsv', 'h264_amf'], 'libx264');
                $s['preset'] = pick($t('preset'), ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium'], 'veryfast');
                $s['vbitrate'] = clampi($t('vbitrate'), 100, 50000, 2500);
                $s['resolution'] = pick($t('resolution'), ['', '1920x1080', '1280x720', '854x480', '640x360'], '');
                $s['fps'] = clampi($t('fps'), 0, 120, 0);
                $s['abitrate'] = clampi($t('abitrate'), 32, 512, 128);
                $s['audio_mode'] = pick($t('audio_mode'), ['aac', 'copy', 'none'], 'aac');
                $s['hls_time'] = clampi($t('hls_time'), 1, 20, 4);
                $s['hls_list'] = clampi($t('hls_list'), 2, 50, 6);
                $s['user_agent'] = $t('user_agent');
                $s['extra_in'] = $t('extra_in');
                $s['extra_out'] = $t('extra_out');
                foreach (['realtime', 'reconnect', 'autorestart'] as $k) $s[$k] = isset($_POST[$k]) ? 1 : 0;

                if ($s['name'] === '') json_out(['error' => 'Yayın adı gerekli'], 422);
                if ($s['source'] === '') json_out(['error' => 'Kaynak (yayın linki) gerekli'], 422);
                if ($s['output_type'] !== 'hls' && $s['output_url'] === '') json_out(['error' => 'Çıkış adresi gerekli'], 422);

                // Logo
                if (!empty($_POST['remove_logo']) && $s['logo']) {
                    @unlink(DATA_DIR . '/logos/' . basename($s['logo']));
                    $s['logo'] = '';
                }
                if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $f = $_FILES['logo'];
                    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) json_out(['error' => 'Logo yalnızca png, jpg, gif veya webp olabilir'], 422);
                    if ($f['size'] > 5 * 1024 * 1024) json_out(['error' => 'Logo en fazla 5 MB olabilir'], 422);
                    if (!@getimagesize($f['tmp_name'])) json_out(['error' => 'Geçersiz görsel dosyası'], 422);
                    $name = 'logo_' . $id . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], DATA_DIR . '/logos/' . $name)) json_out(['error' => 'Logo kaydedilemedi (klasör yazma izni?)'], 500);
                    if ($s['logo']) @unlink(DATA_DIR . '/logos/' . basename($s['logo']));
                    $s['logo'] = $name;
                }

                $streams = db_read('streams', []);
                $streams[$id] = $s;
                db_write('streams', $streams);
                $alive = alive_pids();
                json_out(['ok' => true, 'id' => $id, 'running' => ($s['pid'] && isset($alive[(int)$s['pid']]))]);

            case 'start':
                $id = (int)($_POST['id'] ?? 0);
                with_lock(function () use ($id) { stream_start($id); });
                json_out(['ok' => true]);

            case 'stop':
                $id = (int)($_POST['id'] ?? 0);
                with_lock(function () use ($id) { stream_stop($id); });
                json_out(['ok' => true]);

            case 'restart':
                $id = (int)($_POST['id'] ?? 0);
                with_lock(function () use ($id) { stream_stop($id); sleep(1); stream_start($id); });
                json_out(['ok' => true]);

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                with_lock(function () use ($id) {
                    $streams = db_read('streams', []);
                    if (!isset($streams[$id])) return;
                    stream_stop($id);
                    $s = $streams[$id];
                    unset($streams[$id]);
                    db_write('streams', $streams);
                    remove_stream_files($id, $s);
                });
                json_out(['ok' => true]);

            case 'log':
                $id = (int)($_GET['id'] ?? 0);
                $streams = db_read('streams', []);
                if (!isset($streams[$id])) json_out(['error' => 'Yayın bulunamadı'], 404);
                $s = $streams[$id] + stream_defaults();
                $s['id'] = $id;
                $cfg = get_config();
                $args = build_args($s, $cfg);
                $cmdline = implode(' ', array_map(function ($x) { return preg_match('/[\s&|;()]/', $x) ? '"' . $x . '"' : $x; }, $args));
                json_out(['log' => tail_file(DATA_DIR . '/logs/stream_' . $id . '.log'), 'cmd' => $cmdline]);

            case 'clearlog':
                $id = (int)($_POST['id'] ?? 0);
                @file_put_contents(DATA_DIR . '/logs/stream_' . $id . '.log', '');
                json_out(['ok' => true]);

            case 'logo':
                $id = (int)($_GET['id'] ?? 0);
                $streams = db_read('streams', []);
                if (!isset($streams[$id]) || !$streams[$id]['logo']) { http_response_code(404); exit; }
                $p = DATA_DIR . '/logos/' . basename($streams[$id]['logo']);
                if (!is_file($p)) { http_response_code(404); exit; }
                $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                header('Content-Type: ' . (isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream'));
                header('Cache-Control: private, max-age=3600');
                readfile($p);
                exit;

            case 'probe':
                $src = trim((string)($_POST['source'] ?? ''));
                if ($src === '') json_out(['error' => 'Kaynak adresi boş'], 422);
                $cfg = get_config();
                $probe = dirname($cfg['ffmpeg']) . DIRECTORY_SEPARATOR . 'ffprobe.exe';
                if (!is_file($probe)) json_out(['error' => 'ffprobe.exe bulunamadı: ' . $probe], 422);
                $cmd = [$probe, '-v', 'error', '-rw_timeout', '10000000'];
                $ua = trim((string)($_POST['user_agent'] ?? ''));
                if ($ua !== '' && preg_match('~^https?://~i', $src)) { $cmd[] = '-user_agent'; $cmd[] = $ua; }
                array_push($cmd, '-show_entries', 'stream=index,codec_type,codec_name,width,height,r_frame_rate,bit_rate,sample_rate,channels', '-of', 'json', $src);
                list($rc, $o, $e) = run_proc($cmd);
                $j = json_decode($o, true);
                if (!$j || empty($j['streams'])) json_out(['ok' => false, 'message' => 'Kaynağa erişilemedi veya akış bulunamadı. ' . trim(mb_substr($e, 0, 400))]);
                $lines = [];
                foreach ($j['streams'] as $st) {
                    if (($st['codec_type'] ?? '') === 'video') {
                        $fr = '';
                        if (!empty($st['r_frame_rate']) && strpos($st['r_frame_rate'], '/') !== false) {
                            list($n, $d) = explode('/', $st['r_frame_rate']);
                            if ((float)$d > 0) $fr = ' ' . round($n / $d, 2) . ' fps';
                        }
                        $lines[] = 'Video: ' . ($st['codec_name'] ?? '?') . ' ' . ($st['width'] ?? '?') . 'x' . ($st['height'] ?? '?') . $fr;
                    } elseif (($st['codec_type'] ?? '') === 'audio') {
                        $lines[] = 'Ses: ' . ($st['codec_name'] ?? '?') . ' ' . ($st['sample_rate'] ?? '?') . ' Hz, ' . ($st['channels'] ?? '?') . ' kanal';
                    }
                }
                json_out(['ok' => true, 'message' => implode(' | ', $lines)]);

            case 'test_ffmpeg':
                $path = trim((string)($_POST['ffmpeg'] ?? ''), " \t\"");
                if (!is_file($path)) json_out(['ok' => false, 'message' => 'Dosya bulunamadı: ' . $path]);
                list($rc, $o, $e) = run_proc([$path, '-hide_banner', '-version']);
                $first = trim(strtok($o, "\n"));
                if ($first === '') json_out(['ok' => false, 'message' => 'FFmpeg çalıştırılamadı. ' . trim($e)]);
                list($rc2, $enc) = run_proc([$path, '-hide_banner', '-encoders']);
                $have = [];
                foreach (['libx264', 'h264_nvenc', 'h264_qsv', 'h264_amf'] as $en) if (strpos($enc, ' ' . $en . ' ') !== false) $have[] = $en;
                json_out(['ok' => true, 'message' => $first . ' — Kodlayıcılar: ' . ($have ? implode(', ', $have) : 'bulunamadı')]);

            case 'settings':
                $cfg = get_config();
                $ff = trim((string)($_POST['ffmpeg'] ?? ''), " \t\"");
                if ($ff === '') $ff = DEFAULT_FFMPEG;
                $cfg['ffmpeg'] = $ff;
                $cfg['loglevel'] = pick((string)($_POST['loglevel'] ?? ''), ['error', 'warning', 'info', 'verbose'], 'warning');
                $np = (string)($_POST['new_pass'] ?? '');
                $nu = trim((string)($_POST['new_user'] ?? ''));
                if ($np !== '' || ($nu !== '' && $nu !== $cfg['admin_user'])) {
                    if (!password_verify((string)($_POST['cur_pass'] ?? ''), $cfg['admin_hash'])) json_out(['error' => 'Mevcut şifre hatalı'], 422);
                    if ($nu !== '') $cfg['admin_user'] = mb_substr($nu, 0, 40);
                    if ($np !== '') {
                        if (strlen($np) < 6) json_out(['error' => 'Yeni şifre en az 6 karakter olmalı'], 422);
                        $cfg['admin_hash'] = password_hash($np, PASSWORD_DEFAULT);
                        $cfg['pw_changed'] = 1;
                    }
                }
                save_config($cfg);
                json_out(['ok' => true]);

            case 'sysinfo':
                $php = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
                json_out(['php' => $php, 'script' => __FILE__]);

            default:
                json_out(['error' => 'Bilinmeyen işlem'], 404);
        }
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 500);
    }
    exit;
}

$phpExe = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yayın Paneli</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
<style>
:root{color-scheme:dark}
body{background:radial-gradient(circle at 15% 0,#1a1745 0,#070b14 40%);min-height:100vh;color:#dfe5f5;font-family:ui-sans-serif,system-ui,"Segoe UI",sans-serif}
.inp{width:100%;background:#0b1222;border:1px solid #243049;border-radius:.6rem;padding:.5rem .7rem;color:#e5e9f5;font-size:.85rem;outline:none}
.inp:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2)}
.inp:disabled{opacity:.45}
.lbl{display:block;font-size:.72rem;color:#93a0bd;margin-bottom:.25rem;font-weight:500}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;padding:.45rem .8rem;border-radius:.6rem;font-size:.78rem;font-weight:600;transition:.15s;cursor:pointer;border:1px solid transparent;white-space:nowrap}
.btn:disabled{opacity:.5;cursor:wait}
.btn-pri{background:#6366f1;color:#fff}.btn-pri:hover{background:#4f46e5}
.btn-ok{background:#059669;color:#fff}.btn-ok:hover{background:#047857}
.btn-warn{background:#d97706;color:#fff}.btn-warn:hover{background:#b45309}
.btn-danger{background:#be123c;color:#fff}.btn-danger:hover{background:#9f1239}
.btn-ghost{background:#121a2e;border-color:#243049;color:#cbd5f1}.btn-ghost:hover{background:#1b2640}
.card{background:linear-gradient(180deg,#0f1729,#0c1322);border:1px solid #1d2840;border-radius:1rem}
.modal-bg{position:fixed;inset:0;background:rgba(2,6,15,.78);backdrop-filter:blur(4px);display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:1.5rem 1rem;z-index:50}
.modal-bg.open{display:flex}
.modal{background:#0d1426;border:1px solid #243049;border-radius:1.1rem;width:100%;box-shadow:0 25px 60px rgba(0,0,0,.6)}
.tab{padding:.5rem .9rem;font-size:.8rem;border-radius:.6rem;color:#93a0bd;cursor:pointer;font-weight:600}
.tab.active{background:#1e2547;color:#c7d2fe}
.dot{width:.55rem;height:.55rem;border-radius:9999px;display:inline-block}
.pulse{animation:pl 1.4s infinite}
@keyframes pl{0%{box-shadow:0 0 0 0 rgba(16,185,129,.6)}70%{box-shadow:0 0 0 7px rgba(16,185,129,0)}100%{box-shadow:0 0 0 0 rgba(16,185,129,0)}}
pre.log{background:#050912;border:1px solid #1d2840;border-radius:.6rem;padding:.7rem;font-size:.72rem;line-height:1.4;max-height:340px;overflow:auto;white-space:pre-wrap;word-break:break-all;color:#a7f3d0}
.sw{display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:#cbd5f1;cursor:pointer}
.sw input{accent-color:#6366f1;width:1rem;height:1rem}
#toasts{position:fixed;right:1rem;bottom:1rem;z-index:100;display:flex;flex-direction:column;gap:.5rem}
.toast{padding:.65rem .9rem;border-radius:.7rem;font-size:.82rem;max-width:360px;box-shadow:0 10px 30px rgba(0,0,0,.5);animation:ti .25s}
@keyframes ti{from{opacity:0;transform:translateY(8px)}to{opacity:1}}
</style>
</head>
<body>

<header class="border-b border-[#1d2840] bg-[#0a1020]/70 backdrop-blur sticky top-0 z-30">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-xl">📡</div>
    <div class="mr-auto">
      <h1 class="font-bold leading-tight">Yayın Paneli</h1>
      <p class="text-[11px] text-slate-400">FFmpeg • Logo • Canlı yayın yönetimi</p>
    </div>
    <button class="btn btn-ghost" onclick="openSettings()">⚙ Ayarlar</button>
    <button class="btn btn-pri" onclick="openForm(0)">＋ Yeni Yayın</button>
    <a class="btn btn-ghost" href="?a=logout">Çıkış</a>
  </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-6">
  <div id="warns" class="space-y-2 mb-4"></div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6" id="stats"></div>

  <div id="list" class="grid grid-cols-1 lg:grid-cols-2 gap-4"></div>
  <div id="empty" class="hidden card p-10 text-center text-slate-400">
    <div class="text-4xl mb-2">🎬</div>
    Henüz yayın yok. <button class="text-indigo-400 underline" onclick="openForm(0)">İlk yayını oluştur</button>
  </div>
</main>

<!-- ============ YAYIN FORMU ============ -->
<div class="modal-bg" id="mForm">
  <form class="modal max-w-3xl" id="sform" onsubmit="saveForm(event)" autocomplete="off">
    <input type="hidden" name="id" value="0">
    <div class="flex items-center justify-between p-4 border-b border-[#1d2840]">
      <h2 class="font-bold" id="formTitle">Yeni Yayın</h2>
      <button type="button" class="btn btn-ghost" onclick="closeM('mForm')">✕</button>
    </div>
    <div class="px-4 pt-3 flex flex-wrap gap-1">
      <span class="tab active" data-tab="t1">Genel</span>
      <span class="tab" data-tab="t2">Logo</span>
      <span class="tab" data-tab="t3">Video / Ses</span>
      <span class="tab" data-tab="t4">Gelişmiş</span>
    </div>

    <div class="p-4 space-y-4">
      <!-- GENEL -->
      <section data-pane="t1" class="space-y-4">
        <div><label class="lbl">Yayın adı</label><input class="inp" name="name" placeholder="Örn: Kanal 1" required></div>
        <div>
          <label class="lbl">Kaynak yayın linki (m3u8, ts, rtmp, rtsp, udp, srt, dosya yolu...)</label>
          <div class="flex gap-2">
            <input class="inp" name="source" placeholder="http://ornek.com/canli/index.m3u8" required>
            <button type="button" class="btn btn-ghost" onclick="probeSrc()">🔍 Test</button>
          </div>
          <div id="probeRes" class="text-xs mt-1 text-slate-400"></div>
        </div>
        <div class="grid sm:grid-cols-3 gap-3">
          <div>
            <label class="lbl">Çıkış türü</label>
            <select class="inp" name="output_type" onchange="updForm()">
              <option value="rtmp">RTMP (YouTube, Facebook, Nginx...)</option>
              <option value="hls">HLS (bu sunucudan yayınla)</option>
              <option value="mpegts">MPEG-TS (udp / srt / tcp)</option>
              <option value="custom">Özel (format otomatik)</option>
            </select>
          </div>
          <div class="sm:col-span-2" id="outUrlBox">
            <label class="lbl">Çıkış adresi</label>
            <input class="inp" name="output_url" id="outUrl" placeholder="rtmp://a.rtmp.youtube.com/live2/ANAHTAR">
          </div>
          <div class="sm:col-span-2 grid grid-cols-2 gap-3 hidden" id="hlsBox">
            <div><label class="lbl">HLS parça süresi (sn)</label><input class="inp" type="number" name="hls_time" min="1" max="20" value="4"></div>
            <div><label class="lbl">Liste uzunluğu (parça)</label><input class="inp" type="number" name="hls_list" min="2" max="50" value="6"></div>
          </div>
        </div>
        <div class="flex flex-wrap gap-x-6 gap-y-2">
          <label class="sw"><input type="checkbox" name="autorestart"> Kopunca otomatik yeniden başlat</label>
          <label class="sw"><input type="checkbox" name="reconnect"> HTTP kaynak için yeniden bağlan</label>
          <label class="sw"><input type="checkbox" name="realtime"> Gerçek zamanlı oku (-re) <span class="text-slate-500">(dosya kaynaklarında)</span></label>
        </div>
      </section>

      <!-- LOGO -->
      <section data-pane="t2" class="hidden">
        <div class="grid md:grid-cols-2 gap-5">
          <div class="space-y-3">
            <div>
              <label class="lbl">Logo dosyası (png / jpg / gif / webp)</label>
              <input class="inp" type="file" name="logo" id="logoFile" accept=".png,.jpg,.jpeg,.gif,.webp,image/*">
              <div class="text-xs text-slate-500 mt-1" id="logoCur"></div>
              <label class="sw mt-2 hidden" id="rmLogoBox"><input type="checkbox" name="remove_logo" id="rmLogo"> Mevcut logoyu kaldır</label>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="lbl">Konum</label>
                <select class="inp" name="logo_pos" onchange="updForm()">
                  <option value="tr">Sağ üst</option><option value="tl">Sol üst</option>
                  <option value="br">Sağ alt</option><option value="bl">Sol alt</option>
                  <option value="c">Orta</option><option value="custom">Özel (x,y)</option>
                </select>
              </div>
              <div><label class="lbl">Kenar boşluğu (px)</label><input class="inp" type="number" name="logo_margin" value="20" min="0"></div>
              <div><label class="lbl">Genişlik (px, 0=orijinal)</label><input class="inp" type="number" name="logo_width" value="150" min="0"></div>
              <div><label class="lbl">Saydamlık (%)</label><input class="inp" type="number" name="logo_opacity" value="100" min="0" max="100"></div>
              <div class="hidden" id="cxBox"><label class="lbl">X</label><input class="inp" type="number" name="logo_x" value="10"></div>
              <div class="hidden" id="cyBox"><label class="lbl">Y</label><input class="inp" type="number" name="logo_y" value="10"></div>
            </div>
            <p class="text-xs text-amber-300/80">⚠ Logo eklemek videoyu yeniden kodlamayı gerektirir (Kopyala modu devre dışı kalır).</p>
          </div>
          <div>
            <label class="lbl">Önizleme (16:9)</label>
            <div id="prevBox" class="relative w-full rounded-xl overflow-hidden border border-[#243049]" style="aspect-ratio:16/9;background:linear-gradient(135deg,#1f2a4d,#0b1020 60%,#2a1f4d)">
              <div class="absolute inset-0 flex items-center justify-center text-slate-600 text-sm select-none">video</div>
              <img id="prevLogo" class="absolute hidden" style="max-width:none" alt="">
            </div>
          </div>
        </div>
      </section>

      <!-- VİDEO / SES -->
      <section data-pane="t3" class="hidden space-y-4">
        <div>
          <label class="lbl">Mod</label>
          <select class="inp" name="mode" onchange="updForm()">
            <option value="transcode">Yeniden kodla (logo, boyut, bitrate için)</option>
            <option value="copy">Kopyala (kodlama yok, çok düşük CPU — logo kullanılamaz)</option>
          </select>
        </div>
        <div id="encBox" class="space-y-4">
          <div class="grid sm:grid-cols-3 gap-3">
            <div>
              <label class="lbl">Video kodlayıcı</label>
              <select class="inp" name="vcodec" onchange="updForm()">
                <option value="libx264">libx264 (CPU)</option>
                <option value="h264_nvenc">h264_nvenc (NVIDIA)</option>
                <option value="h264_qsv">h264_qsv (Intel)</option>
                <option value="h264_amf">h264_amf (AMD)</option>
              </select>
            </div>
            <div>
              <label class="lbl">x264 preset</label>
              <select class="inp" name="preset">
                <option>ultrafast</option><option>superfast</option><option selected>veryfast</option>
                <option>faster</option><option>fast</option><option>medium</option>
              </select>
            </div>
            <div><label class="lbl">Video bitrate (kbps)</label><input class="inp" type="number" name="vbitrate" value="2500" min="100"></div>
            <div>
              <label class="lbl">Çözünürlük</label>
              <select class="inp" name="resolution" onchange="updForm()">
                <option value="">Orijinal</option><option value="1920x1080">1920x1080</option>
                <option value="1280x720">1280x720</option><option value="854x480">854x480</option><option value="640x360">640x360</option>
              </select>
            </div>
            <div><label class="lbl">FPS (0 = orijinal)</label><input class="inp" type="number" name="fps" value="0" min="0" max="120"></div>
            <div>
              <label class="lbl">Ses</label>
              <select class="inp" name="audio_mode" onchange="updForm()">
                <option value="aac">AAC (yeniden kodla)</option><option value="copy">Kopyala</option><option value="none">Sessiz</option>
              </select>
            </div>
            <div><label class="lbl">Ses bitrate (kbps)</label><input class="inp" type="number" name="abitrate" value="128" min="32"></div>
          </div>
        </div>
      </section>

      <!-- GELİŞMİŞ -->
      <section data-pane="t4" class="hidden space-y-4">
        <div><label class="lbl">User-Agent (isteğe bağlı, http kaynaklar)</label><input class="inp" name="user_agent" placeholder="Mozilla/5.0 ..."></div>
        <div><label class="lbl">Ek giriş parametreleri (-i öncesi)</label><input class="inp" name="extra_in" placeholder="-rw_timeout 15000000"></div>
        <div><label class="lbl">Ek çıkış parametreleri</label><input class="inp" name="extra_out" placeholder="-metadata service_name=Kanal1"></div>
        <p class="text-xs text-slate-500">Parametreleri boşlukla ayırın. Oluşan tam komutu yayın kartındaki “Log” penceresinde görebilirsiniz.</p>
      </section>
    </div>

    <div class="p-4 border-t border-[#1d2840] flex justify-end gap-2">
      <button type="button" class="btn btn-ghost" onclick="closeM('mForm')">Vazgeç</button>
      <button class="btn btn-pri" id="saveBtn">💾 Kaydet</button>
    </div>
  </form>
</div>

<!-- ============ LOG ============ -->
<div class="modal-bg" id="mLog">
  <div class="modal max-w-3xl">
    <div class="flex items-center justify-between p-4 border-b border-[#1d2840]">
      <h2 class="font-bold" id="logTitle">Log</h2>
      <button class="btn btn-ghost" onclick="closeM('mLog')">✕</button>
    </div>
    <div class="p-4 space-y-3">
      <div><div class="lbl">FFmpeg komutu</div><pre class="log" style="color:#c7d2fe;max-height:140px" id="logCmd"></pre></div>
      <div><div class="lbl">Çıktı (otomatik yenilenir)</div><pre class="log" id="logBody"></pre></div>
      <div class="flex justify-end"><button class="btn btn-ghost" onclick="clearLog()">Logu temizle</button></div>
    </div>
  </div>
</div>

<!-- ============ OYNATICI ============ -->
<div class="modal-bg" id="mPlay">
  <div class="modal max-w-3xl">
    <div class="flex items-center justify-between p-4 border-b border-[#1d2840]">
      <h2 class="font-bold" id="playTitle">Önizleme</h2>
      <button class="btn btn-ghost" onclick="closeM('mPlay')">✕</button>
    </div>
    <div class="p-4 space-y-3">
      <video id="player" controls muted autoplay playsinline class="w-full rounded-xl bg-black" style="aspect-ratio:16/9"></video>
      <div class="flex gap-2">
        <input class="inp" id="playUrl" readonly>
        <button class="btn btn-pri" onclick="copyUrl()">Kopyala</button>
      </div>
      <p class="text-xs text-slate-500">HLS çıktısı başladıktan birkaç saniye sonra oynatılabilir.</p>
    </div>
  </div>
</div>

<!-- ============ AYARLAR ============ -->
<div class="modal-bg" id="mSet">
  <form class="modal max-w-xl" onsubmit="saveSettings(event)" id="setForm" autocomplete="off">
    <div class="flex items-center justify-between p-4 border-b border-[#1d2840]">
      <h2 class="font-bold">Ayarlar</h2>
      <button type="button" class="btn btn-ghost" onclick="closeM('mSet')">✕</button>
    </div>
    <div class="p-4 space-y-4">
      <div>
        <label class="lbl">FFmpeg yolu</label>
        <div class="flex gap-2">
          <input class="inp" name="ffmpeg" placeholder="C:\ffmpeg\bin\ffmpeg.exe">
          <button type="button" class="btn btn-ghost" onclick="testFF()">Test</button>
        </div>
        <div id="ffRes" class="text-xs mt-1 text-slate-400"></div>
      </div>
      <div>
        <label class="lbl">FFmpeg log seviyesi</label>
        <select class="inp" name="loglevel"><option>error</option><option>warning</option><option>info</option><option>verbose</option></select>
      </div>
      <hr class="border-[#1d2840]">
      <div class="text-sm font-semibold">Yönetici hesabı</div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="lbl">Kullanıcı adı</label><input class="inp" name="new_user"></div>
        <div><label class="lbl">Yeni şifre (boş = değişmez)</label><input class="inp" type="password" name="new_pass" autocomplete="new-password"></div>
        <div class="col-span-2"><label class="lbl">Mevcut şifre (değişiklik için gerekli)</label><input class="inp" type="password" name="cur_pass" autocomplete="off"></div>
      </div>
      <hr class="border-[#1d2840]">
      <div>
        <div class="text-sm font-semibold mb-1">Otomatik başlatma (Görev Zamanlayıcı)</div>
        <p class="text-xs text-slate-400 mb-2">Sunucu yeniden başlarsa “çalışıyor” durumundaki yayınları geri açmak için aşağıdaki komutu Görev Zamanlayıcı’da (açılışta + her 1 dakikada) çalıştırın:</p>
        <pre class="log" style="color:#fde68a;max-height:none">"<?= htmlspecialchars($phpExe) ?>" "<?= htmlspecialchars(__FILE__) ?>" watchdog</pre>
      </div>
    </div>
    <div class="p-4 border-t border-[#1d2840] flex justify-end gap-2">
      <button type="button" class="btn btn-ghost" onclick="closeM('mSet')">Kapat</button>
      <button class="btn btn-pri">Kaydet</button>
    </div>
  </form>
</div>

<div id="toasts"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const DEFAULTS = <?= json_encode(stream_defaults()) ?>;
const $ = id => document.getElementById(id);
let STREAMS = [], ENV = {}, CONFIG = {};
let logId = 0, logTimer = null, hlsObj = null, prevSrc = '';

/* ---------- yardımcılar ---------- */
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(msg, type='ok'){
  const d = document.createElement('div');
  d.className = 'toast ' + (type==='ok' ? 'bg-emerald-600 text-white' : type==='err' ? 'bg-rose-600 text-white' : 'bg-slate-700 text-white');
  d.textContent = msg; $('toasts').appendChild(d);
  setTimeout(() => d.remove(), type==='err' ? 6000 : 3000);
}
async function api(action, body, method='POST'){
  const opt = { method, headers: {'X-CSRF': CSRF} };
  if (body instanceof FormData) opt.body = body;
  else if (body) opt.body = new URLSearchParams(body);
  const r = await fetch('?a=' + action, opt);
  if (r.status === 401) { location.reload(); throw new Error('Oturum sona erdi'); }
  let j; try { j = await r.json(); } catch(e){ throw new Error('Sunucu yanıtı geçersiz (HTTP ' + r.status + ')'); }
  if (!r.ok || j.error) throw new Error(j.error || 'Bilinmeyen hata');
  return j;
}
function fmtUp(sec){
  if (!sec) return '—';
  const d = Math.floor(sec/86400), h = Math.floor(sec%86400/3600), m = Math.floor(sec%3600/60), s = sec%60;
  return (d? d+'g ':'') + (h||d ? h+'sa ':'') + m + 'dk ' + (d||h ? '' : s+'sn');
}
function openM(id){ $(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeM(id){
  $(id).classList.remove('open');
  if (!document.querySelector('.modal-bg.open')) document.body.style.overflow='';
  if (id==='mLog'){ clearInterval(logTimer); logTimer=null; }
  if (id==='mPlay'){ if (hlsObj){ hlsObj.destroy(); hlsObj=null; } const v=$('player'); v.pause(); v.removeAttribute('src'); v.load(); }
}
document.querySelectorAll('.modal-bg').forEach(m => m.addEventListener('mousedown', e => { if (e.target===m && m.id!=='mForm') closeM(m.id); }));

/* ---------- liste ---------- */
async function load(){
  try {
    const j = await api('list', null, 'GET');
    STREAMS = j.streams; ENV = j.env; CONFIG = j.config;
    render();
  } catch(e){ console.warn(e); }
}
function render(){
  const run = STREAMS.filter(s => s.state==='running').length;
  const down = STREAMS.filter(s => s.state==='down').length;
  $('stats').innerHTML = [
    ['Toplam yayın', STREAMS.length, '🎬', 'text-indigo-300'],
    ['Çalışan', run, '🟢', 'text-emerald-300'],
    ['Durmuş', STREAMS.length - run - down, '⏹', 'text-slate-300'],
    ['Sorunlu / Bekleyen', down, '⚠', 'text-amber-300'],
  ].map(x => `<div class="card p-4"><div class="flex items-center justify-between"><span class="text-xs text-slate-400">${x[0]}</span><span>${x[2]}</span></div><div class="text-3xl font-bold mt-1 ${x[3]}">${x[1]}</div></div>`).join('');

  const w = [];
  const box = (t,c) => `<div class="text-sm rounded-xl border px-4 py-2.5 ${c}">${t}</div>`;
  if (!ENV.os_ok) w.push(box('Bu panel Windows sunucu için tasarlanmıştır; yayın başlatma çalışmayabilir.', 'bg-rose-500/10 border-rose-500/30 text-rose-200'));
  if (!ENV.exec_ok) w.push(box('PHP <b>exec()</b> fonksiyonu kapalı. php.ini içindeki <code>disable_functions</code> satırından exec ve proc_open’ı kaldırın.', 'bg-rose-500/10 border-rose-500/30 text-rose-200'));
  if (!ENV.ffmpeg_ok) w.push(box('FFmpeg bulunamadı: <b>' + esc(CONFIG.ffmpeg) + '</b> — <button class="underline" onclick="openSettings()">Ayarlar</button>’dan yolu düzeltin.', 'bg-amber-500/10 border-amber-500/30 text-amber-200'));
  if (ENV.default_pw) w.push(box('Varsayılan şifre (admin / admin123) kullanılıyor. Güvenlik için <button class="underline" onclick="openSettings()">şifrenizi değiştirin</button>.', 'bg-amber-500/10 border-amber-500/30 text-amber-200'));
  $('warns').innerHTML = w.join('');

  $('empty').classList.toggle('hidden', STREAMS.length>0);
  $('list').innerHTML = STREAMS.map(card).join('');
}
function card(s){
  const st = {
    running: ['Yayında','bg-emerald-500 pulse','text-emerald-300'],
    down: ['Durdu / Yeniden deneniyor','bg-amber-500','text-amber-300'],
    stopped: ['Durduruldu','bg-slate-500','text-slate-400'],
  }[s.state];
  const outLabel = {rtmp:'RTMP', hls:'HLS', mpegts:'MPEG-TS', custom:'Özel'}[s.output_type];
  const outDesc = s.output_type==='hls' ? location.origin + location.pathname.replace(/[^\/]*$/,'') + s.hls_path : s.output_url;
  const isRun = s.state !== 'stopped';
  return `<div class="card p-4 flex flex-col gap-3">
    <div class="flex items-start gap-3">
      <div class="w-14 h-14 rounded-xl bg-[#0a1020] border border-[#1d2840] flex items-center justify-center overflow-hidden shrink-0">
        ${s.logo_url ? `<img src="${s.logo_url}" class="max-w-full max-h-full object-contain">` : '<span class="text-2xl opacity-40">📺</span>'}
      </div>
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2 flex-wrap">
          <h3 class="font-semibold truncate">${esc(s.name)}</h3>
          <span class="text-[10px] px-1.5 py-0.5 rounded bg-indigo-500/15 text-indigo-300 border border-indigo-500/20">${outLabel}</span>
          <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-500/15 text-slate-300 border border-slate-500/20">${s.mode==='copy' && !s.logo ? 'COPY' : 'ENCODE'}</span>
        </div>
        <div class="flex items-center gap-2 text-xs mt-1 ${st[2]}"><span class="dot ${st[1]}"></span>${st[0]}${s.running ? ' • ' + fmtUp(s.uptime) : ''}</div>
      </div>
    </div>
    <div class="text-xs space-y-1 text-slate-400">
      <div class="truncate" title="${esc(s.source)}"><span class="text-slate-500">Kaynak:</span> ${esc(s.source)}</div>
      <div class="truncate" title="${esc(outDesc)}"><span class="text-slate-500">Çıkış:</span> ${esc(outDesc)}</div>
    </div>
    <div class="flex flex-wrap gap-2 pt-1">
      ${isRun
        ? `<button class="btn btn-danger" onclick="act('stop',${s.id},this)">■ Durdur</button><button class="btn btn-warn" onclick="act('restart',${s.id},this)">↻ Yeniden</button>`
        : `<button class="btn btn-ok" onclick="act('start',${s.id},this)">▶ Başlat</button>`}
      ${s.hls_path ? `<button class="btn btn-ghost" onclick="openPlay(${s.id})">▷ İzle</button>` : ''}
      <button class="btn btn-ghost" onclick="openLog(${s.id})">📄 Log</button>
      <button class="btn btn-ghost" onclick="openForm(${s.id})">✎ Düzenle</button>
      <button class="btn btn-ghost ml-auto" onclick="delStream(${s.id})">🗑</button>
    </div>
  </div>`;
}
async function act(action, id, btn){
  if (btn) btn.disabled = true;
  try { await api(action, {id}); toast({start:'Yayın başlatıldı', stop:'Yayın durduruldu', restart:'Yayın yeniden başlatıldı'}[action]); }
  catch(e){ toast(e.message, 'err'); }
  await load();
}
async function delStream(id){
  const s = STREAMS.find(x => x.id===id);
  if (!confirm('"' + s.name + '" silinsin mi? Yayın durdurulur ve dosyaları silinir.')) return;
  try { await api('delete', {id}); toast('Silindi'); } catch(e){ toast(e.message,'err'); }
  load();
}

/* ---------- form ---------- */
const form = $('sform');
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));
function switchTab(id){
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab===id));
  document.querySelectorAll('[data-pane]').forEach(p => p.classList.toggle('hidden', p.dataset.pane!==id));
  if (id==='t2') drawPrev();
}
function openForm(id){
  const s = id ? STREAMS.find(x => x.id===id) : DEFAULTS;
  form.reset();
  for (const k in DEFAULTS){
    const el = form.elements[k];
    if (!el || el.type==='file') continue;
    if (el.type==='checkbox') el.checked = !!+s[k];
    else el.value = s[k];
  }
  form.elements.id.value = id;
  $('formTitle').textContent = id ? 'Yayını Düzenle: ' + s.name : 'Yeni Yayın';
  $('probeRes').textContent = '';
  $('logoFile').value = '';
  $('rmLogoBox').classList.toggle('hidden', !s.logo);
  $('logoCur').textContent = s.logo ? 'Mevcut logo kayıtlı. Yeni dosya seçerseniz değiştirilir.' : 'Henüz logo yok.';
  prevSrc = s.logo_url || '';
  switchTab('t1'); updForm(); openM('mForm');
}
function updForm(){
  const f = form.elements, ot = f.output_type.value;
  $('outUrlBox').classList.toggle('hidden', ot==='hls');
  $('hlsBox').classList.toggle('hidden', ot!=='hls');
  $('outUrl').placeholder = {rtmp:'rtmp://a.rtmp.youtube.com/live2/ANAHTAR', mpegts:'udp://239.0.0.1:1234?pkt_size=1316  veya  srt://ip:port', custom:'Çıkış adresi / dosya yolu'}[ot] || '';
  const custom = f.logo_pos.value==='custom';
  $('cxBox').classList.toggle('hidden', !custom); $('cyBox').classList.toggle('hidden', !custom);
  const hasLogo = prevSrc && !$('rmLogo').checked;
  const copy = f.mode.value==='copy' && !hasLogo;
  $('encBox').style.opacity = copy ? .4 : 1; $('encBox').style.pointerEvents = copy ? 'none' : '';
  f.preset.disabled = f.vcodec.value!=='libx264';
  f.abitrate.disabled = f.audio_mode.value!=='aac';
  drawPrev();
}
$('logoFile').addEventListener('change', e => {
  const file = e.target.files[0];
  if (file){ prevSrc = URL.createObjectURL(file); $('rmLogo').checked = false; }
  updForm();
});
$('rmLogo').addEventListener('change', () => { if ($('rmLogo').checked) prevSrc=''; else { const s=STREAMS.find(x=>x.id==form.elements.id.value); prevSrc = s ? s.logo_url : ''; } updForm(); });
form.addEventListener('input', e => { if (['logo_x','logo_y','logo_margin','logo_width','logo_opacity'].includes(e.target.name)) drawPrev(); });
function drawPrev(){
  const img = $('prevLogo'), box = $('prevBox'), f = form.elements;
  if (!prevSrc){ img.classList.add('hidden'); return; }
  if (img.getAttribute('src') !== prevSrc){ img.src = prevSrc; img.onload = drawPrev; }
  img.classList.remove('hidden');
  const pw = box.clientWidth; if (!pw) return;
  const refW = f.resolution.value ? +f.resolution.value.split('x')[0] : 1280, k = pw / refW;
  const w = (+f.logo_width.value || img.naturalWidth || 100) * k, m = (+f.logo_margin.value||0) * k;
  Object.assign(img.style, {width:w+'px', opacity:(+f.logo_opacity.value||0)/100, left:'auto', right:'auto', top:'auto', bottom:'auto', transform:''});
  switch (f.logo_pos.value){
    case 'tl': img.style.left=m+'px'; img.style.top=m+'px'; break;
    case 'bl': img.style.left=m+'px'; img.style.bottom=m+'px'; break;
    case 'br': img.style.right=m+'px'; img.style.bottom=m+'px'; break;
    case 'c': img.style.left='50%'; img.style.top='50%'; img.style.transform='translate(-50%,-50%)'; break;
    case 'custom': img.style.left=(+f.logo_x.value*k)+'px'; img.style.top=(+f.logo_y.value*k)+'px'; break;
    default: img.style.right=m+'px'; img.style.top=m+'px';
  }
}
async function saveForm(e){
  e.preventDefault();
  const btn = $('saveBtn'); btn.disabled = true;
  try {
    const wasId = +form.elements.id.value;
    const j = await api('save', new FormData(form));
    closeM('mForm'); toast('Kaydedildi');
    await load();
    if (j.running && confirm('Yayın şu anda çalışıyor. Değişikliklerin uygulanması için yeniden başlatılsın mı?')) await act('restart', j.id);
  } catch(err){ toast(err.message, 'err'); }
  btn.disabled = false;
}
async function probeSrc(){
  const src = form.elements.source.value.trim();
  if (!src) return toast('Önce kaynak adresini girin', 'err');
  const r = $('probeRes'); r.className = 'text-xs mt-1 text-slate-400'; r.textContent = 'Test ediliyor (en fazla ~15 sn)...';
  try {
    const j = await api('probe', {source: src, user_agent: form.elements.user_agent.value});
    r.className = 'text-xs mt-1 ' + (j.ok ? 'text-emerald-300' : 'text-rose-300');
    r.textContent = (j.ok ? '✔ ' : '✖ ') + j.message;
  } catch(e){ r.className = 'text-xs mt-1 text-rose-300'; r.textContent = '✖ ' + e.message; }
}

/* ---------- log ---------- */
async function pullLog(){
  try {
    const j = await api('log&id=' + logId, null, 'GET');
    const body = $('logBody'), atBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 20;
    body.textContent = j.log || '(log boş)';
    $('logCmd').textContent = j.cmd;
    if (atBottom) body.scrollTop = body.scrollHeight;
  } catch(e){}
}
function openLog(id){
  logId = id; const s = STREAMS.find(x => x.id===id);
  $('logTitle').textContent = 'Log: ' + s.name;
  $('logBody').textContent = 'Yükleniyor...'; openM('mLog');
  pullLog().then(() => { $('logBody').scrollTop = $('logBody').scrollHeight; });
  logTimer = setInterval(pullLog, 3000);
}
async function clearLog(){ await api('clearlog', {id: logId}); pullLog(); }

/* ---------- oynatıcı ---------- */
function openPlay(id){
  const s = STREAMS.find(x => x.id===id);
  const url = new URL(s.hls_path, location.href.split('?')[0]).href;
  $('playTitle').textContent = 'İzle: ' + s.name; $('playUrl').value = url; openM('mPlay');
  const v = $('player');
  if (window.Hls && Hls.isSupported()){ hlsObj = new Hls({lowLatencyMode:true}); hlsObj.loadSource(url); hlsObj.attachMedia(v); }
  else v.src = url;
  v.play().catch(()=>{});
}
function copyUrl(){ $('playUrl').select(); document.execCommand('copy'); toast('Link kopyalandı'); }

/* ---------- ayarlar ---------- */
function openSettings(){
  const f = $('setForm').elements;
  f.ffmpeg.value = CONFIG.ffmpeg; f.loglevel.value = CONFIG.loglevel; f.new_user.value = CONFIG.admin_user;
  f.new_pass.value = ''; f.cur_pass.value = ''; $('ffRes').textContent = '';
  openM('mSet');
}
async function testFF(){
  const r = $('ffRes'); r.className = 'text-xs mt-1 text-slate-400'; r.textContent = 'Test ediliyor...';
  try {
    const j = await api('test_ffmpeg', {ffmpeg: $('setForm').elements.ffmpeg.value});
    r.className = 'text-xs mt-1 ' + (j.ok ? 'text-emerald-300' : 'text-rose-300');
    r.textContent = (j.ok ? '✔ ' : '✖ ') + j.message;
  } catch(e){ r.className = 'text-xs mt-1 text-rose-300'; r.textContent = '✖ ' + e.message; }
}
async function saveSettings(e){
  e.preventDefault();
  try { await api('settings', new FormData($('setForm'))); toast('Ayarlar kaydedildi'); closeM('mSet'); load(); }
  catch(err){ toast(err.message, 'err'); }
}

load();
setInterval(() => { if (!document.hidden) load(); }, 5000);
</script>
</body>
</html>
