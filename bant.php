<?php
/*
 |=====================================================================
 |  ÇOK KANALLI YAYIN PANELİ  -  Tek dosya PHP + FFmpeg
 |  Windows Server 2022 / VDS · XAMPP / IIS / WAMP uyumlu
 |
 |  Kurulum:
 |    1) Bu dosyayı index.php olarak web köküne atın.
 |    2) FFmpeg yolunu panelden girin (varsayılan C:\ffmpeg\bin\ffmpeg.exe).
 |    3) Giriş: admin / admin123  (hemen değiştirin!)
 |
 |  Çıkış linkleri:
 |    Ana kanal   : http://sunucu/hls/stream.m3u8
 |    Kanal "live" : http://sunucu/live/hls/stream.m3u8
 |    Kanal "diginet": http://sunucu/diginet/hls/stream.m3u8
 |=====================================================================
*/
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Istanbul');
@set_time_limit(0);

define('DS', DIRECTORY_SEPARATOR);
define('ROOT', __DIR__);
define('DATA', ROOT . DS . 'data');
define('HLSDIR', ROOT . DS . 'hls');
define('CHANNELS_FILE', DATA . DS . 'channels.json');
define('SETTINGS_FILE', DATA . DS . 'settings.json');

/* ---------------------------------------------------------------- klasörler */
foreach ([DATA, DATA . DS . 'sessions', HLSDIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}
if (!is_file(DATA . DS . 'web.config')) {
    @file_put_contents(DATA . DS . 'web.config',
        '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false" applyToWebDAV="false" /></requestFiltering></security></system.webServer></configuration>');
}
if (!is_file(DATA . DS . '.htaccess')) {
    @file_put_contents(DATA . DS . '.htaccess', "Require all denied\nDeny from all\n");
}

/* HLS MIME ayarları — ana ve tüm kanal klasörleri için */
function ensure_hls_config($dir) {
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $wc = $dir . DS . 'web.config';
    if (!is_file($wc)) {
        @file_put_contents($wc,
            '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><staticContent>'
          . '<remove fileExtension=".m3u8" /><mimeMap fileExtension=".m3u8" mimeType="application/vnd.apple.mpegurl" />'
          . '<remove fileExtension=".ts" /><mimeMap fileExtension=".ts" mimeType="video/mp2t" />'
          . '</staticContent><httpProtocol><customHeaders>'
          . '<add name="Access-Control-Allow-Origin" value="*" />'
          . '<add name="Cache-Control" value="no-cache" />'
          . '</customHeaders></httpProtocol></system.webServer></configuration>');
    }
    $ht = $dir . DS . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "Header set Access-Control-Allow-Origin \"*\"\n"
          . "Header set Cache-Control \"no-cache\"\n"
          . "AddType application/vnd.apple.mpegurl .m3u8\n"
          . "AddType video/mp2t .ts\n");
    }
}
ensure_hls_config(HLSDIR);

/* ------------------------------------------------------------------ oturum */
if (is_writable(DATA . DS . 'sessions')) session_save_path(DATA . DS . 'sessions');
session_name('YAYINPANEL');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* --------------------------------------------------------------- yardımcılar */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fs($s) { return str_replace('\\', '/', $s); }
function jout($a, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function iv($v, $min, $max, $def) { if (!is_numeric($v)) return $def; return max($min, min($max, (int)$v)); }
function clean_str($s) { return preg_replace('/[\x00-\x1F\x7F"]/', '', trim((string)$s)); }
function hexcol($v, $def) { return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? strtolower($v) : $def; }
function pick($v, $arr, $def) { return in_array($v, $arr, true) ? $v : $def; }
function slugify($s) {
    $s = trim((string)$s);
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
    $s = preg_replace('/-+/', '-', $s);
    return $s === '' ? '' : $s;
}
function fix_utf8($s) {
    if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
        $r = @iconv('Windows-1254', 'UTF-8//IGNORE', $s);
        return $r === false ? utf8_encode($s) : $r;
    }
    return $s;
}
function base_url() {
    $s = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $d = rtrim(fs(dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $s . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $d;
}
function tail_file($f, $bytes) {
    if (!is_file($f)) return '';
    $fp = @fopen($f, 'rb');
    if (!$fp) return '';
    $s = filesize($f);
    if ($s > $bytes) fseek($fp, -$bytes, SEEK_END);
    $d = stream_get_contents($fp);
    fclose($fp);
    return fix_utf8((string)$d);
}
function read_json($f, $def = []) {
    if (!is_file($f)) return $def;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : $def;
}
function write_json($f, $a) {
    return file_put_contents($f, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/* Kanal bazlı yollar */
function chan_dir($slug)        { return $slug === '' ? ROOT : ROOT . DS . $slug; }
function chan_data($slug)       { return $slug === '' ? DATA : DATA . DS . $slug; }
function chan_hls($slug)        { return $slug === '' ? HLSDIR : ROOT . DS . $slug . DS . 'hls'; }
function chan_cfg_file($slug)   { return chan_data($slug) . DS . 'config.json'; }
function chan_log($slug, $n)    { return chan_data($slug) . DS . $n; }
function chan_url($slug, $path='') {
    $base = base_url();
    $prefix = $slug === '' ? '' : '/' . $slug;
    return $base . $prefix . ($path ? '/' . ltrim($path, '/') : '');
}

/* --------------------------------------------------------------- ayarlar */
function default_settings() {
    return [
        'admin_user' => 'admin',
        'admin_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'pass_default' => true,
        'ffmpeg_path' => 'C:\\ffmpeg\\bin\\ffmpeg.exe',
    ];
}
function settings() {
    $s = default_settings();
    if (is_file(SETTINGS_FILE)) {
        $j = read_json(SETTINGS_FILE);
        if ($j) $s = array_merge($s, $j);
    }
    return $s;
}
function save_settings($s) { return write_json(SETTINGS_FILE, $s); }
if (!is_file(SETTINGS_FILE)) save_settings(default_settings());

/* ------------------------------------------------------------------ kanal ayarları */
function default_channel($name) {
    return [
        'name' => $name,
        'enabled' => 1,
        'autostart' => 0,
        'source_mode' => 'single',   // single | playlist
        'input_url' => '',
        'playlist' => '',            // her satır: "Başlık| URL"
        'input_ua' => '',
        'input_extra' => '',
        'input_realtime' => 0,
        'mode' => 'encode',
        'out_type' => 'hls',         // hls | rtmp | mpegts
        'out_url' => '',
        'hls_time' => 6,
        'hls_list' => 6,
        'logo_enabled' => 0,
        'logo_file' => '',
        'logo_pos' => 'tr',
        'logo_width' => 160,
        'logo_opacity' => 100,
        'logo_margin' => 25,
        'band_enabled' => 0,
        'band_text' => '',
        'band_style' => 'scroll',
        'band_pos' => 'bottom',
        'band_height' => 50,
        'band_font' => 'arial',
        'band_font_size' => 28,
        'band_font_color' => '#ffffff',
        'band_bg_color' => '#b91c1c',
        'band_bg_opacity' => 85,
        'band_speed' => 120,
        'res' => 'orig',
        'fps' => 0,
        'vcodec' => 'libx264',
        'preset' => 'ultrafast',
        'vbitrate' => 4000,
        'abitrate' => 128,
        'gop' => 4,
        'autorestart' => 1,
        'restart_delay' => 5,
        'loop_playlist' => 1,
    ];
}
function load_channels() {
    $c = read_json(CHANNELS_FILE, []);
    if (!is_array($c)) $c = [];
    // ana kanal her zaman var
    if (!isset($c[''])) $c[''] = default_channel('Ana Kanal');
    return $c;
}
function save_channels($c) { return write_json(CHANNELS_FILE, $c); }
function get_channel($slug) {
    $c = load_channels();
    return $c[$slug] ?? null;
}
function save_channel($slug, $cfg) {
    $c = load_channels();
    $c[$slug] = $cfg;
    save_channels($c);
}

/* parse channel post */
function parse_channel_post($post, $old) {
    global $FONTS, $RES, $VC, $PRESETS;
    $c = $old;
    if (isset($post['name'])) $c['name'] = clean_str($post['name']);
    foreach (['input_url', 'input_ua', 'input_extra', 'out_url'] as $k) {
        if (isset($post[$k])) $c[$k] = clean_str($post[$k]);
    }
    if (isset($post['playlist'])) {
        // satır satır temizle
        $lines = preg_split('/\r\n|\r|\n/', (string)$post['playlist']);
        $out = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l === '') continue;
            $l = preg_replace('/[\x00-\x1F\x7F]/', '', $l);
            $out[] = $l;
        }
        $c['playlist'] = implode("\n", $out);
    }
    $c['source_mode'] = pick($post['source_mode'] ?? '', ['single', 'playlist'], 'single');
    $c['mode'] = pick($post['mode'] ?? '', ['encode', 'copy'], 'encode');
    $c['out_type'] = pick($post['out_type'] ?? '', ['rtmp', 'hls', 'mpegts'], 'hls');
    $c['logo_pos'] = pick($post['logo_pos'] ?? '', ['tl', 'tr', 'bl', 'br', 'center'], 'tr');
    $c['band_style'] = pick($post['band_style'] ?? '', ['scroll', 'static'], 'scroll');
    $c['band_pos'] = pick($post['band_pos'] ?? '', ['bottom', 'top'], 'bottom');
    $c['band_font'] = pick($post['band_font'] ?? '', array_keys($FONTS), 'arial');
    $c['res'] = pick($post['res'] ?? '', $RES, 'orig');
    $c['vcodec'] = pick($post['vcodec'] ?? '', $VC, 'libx264');
    $c['preset'] = pick($post['preset'] ?? '', $PRESETS, 'ultrafast');
    $ints = [
        'hls_time' => [2, 20, 6], 'hls_list' => [3, 30, 6],
        'logo_width' => [20, 1000, 160], 'logo_opacity' => [5, 100, 100], 'logo_margin' => [0, 300, 25],
        'band_height' => [20, 200, 50], 'band_font_size' => [10, 100, 28], 'band_bg_opacity' => [0, 100, 85],
        'band_speed' => [20, 600, 120], 'fps' => [0, 60, 0],
        'vbitrate' => [200, 20000, 4000], 'abitrate' => [32, 512, 128],
        'gop' => [1, 10, 4], 'restart_delay' => [1, 120, 5],
    ];
    foreach ($ints as $k => $r) $c[$k] = iv($post[$k] ?? null, $r[0], $r[1], $r[2]);
    foreach (['input_realtime', 'logo_enabled', 'band_enabled', 'autorestart', 'enabled', 'autostart', 'loop_playlist'] as $k) {
        $c[$k] = !empty($post[$k]) ? 1 : 0;
    }
    $c['band_font_color'] = hexcol($post['band_font_color'] ?? '', '#ffffff');
    $c['band_bg_color'] = hexcol($post['band_bg_color'] ?? '', '#b91c1c');
    $t = str_replace(["\r\n", "\r", "\n"], '  •  ', (string)($post['band_text'] ?? ''));
    $t = preg_replace('/[\x00-\x1F\x7F]/', '', $t);
    $c['band_text'] = function_exists('mb_substr') ? mb_substr(trim($t), 0, 1500, 'UTF-8') : substr(trim($t), 0, 1500);
    return $c;
}

$FONTS = ['arial' => 'arial.ttf', 'arialbd' => 'arialbd.ttf', 'tahoma' => 'tahoma.ttf', 'verdana' => 'verdana.ttf', 'segoeui' => 'segoeui.ttf', 'calibri' => 'calibri.ttf', 'impact' => 'impact.ttf'];
$RES = ['orig', '1920x1080', '1280x720', '854x480', '640x360'];
$VC = ['libx264', 'h264_nvenc', 'h264_qsv', 'h264_amf'];
$PRESETS = ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium'];

/* Playlist hazırla */
function prepare_playlist($slug, $cfg) {
    $dir = chan_data($slug);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $concatFile = $dir . DS . 'concat.txt';
    $list = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$cfg['playlist']) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // "Başlık| URL" veya sadece URL
        if (strpos($line, '|') !== false) {
            list($title, $url) = array_map('trim', explode('|', $line, 2));
        } else {
            $title = ''; $url = $line;
        }
        if ($url === '') continue;
        $list[] = ['title' => $title, 'url' => $url];
    }
    if (!$list) return null;
    $lines = [];
    foreach ($list as $item) {
        $u = str_replace("'", "'\\''", $item['url']);
        $lines[] = "file '" . $u . "'";
    }
    @file_put_contents($concatFile, implode("\n", $lines) . "\n");
    return ['file' => $concatFile, 'items' => $list];
}

/* ------------------------------------------------------------- süreç yönetimi */
function pid_alive($pid) {
    $pid = (int)$pid;
    if ($pid <= 0 || !function_exists('exec')) return false;
    $o = [];
    @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $o);
    return strpos(implode("\n", $o), '"' . $pid . '"') !== false;
}
function read_pid($f) {
    $s = @file_get_contents($f);
    return $s ? (int)preg_replace('/\D/', '', $s) : 0;
}
function launch($cmd) {
    if (class_exists('COM')) {
        try { $sh = new COM('WScript.Shell'); $sh->Run($cmd, 0, false); return true; } catch (Throwable $e) {}
    }
    $d = [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']];
    $pr = @proc_open('cmd /c start "" /B ' . $cmd, $d, $pipes);
    if (is_resource($pr)) { proc_close($pr); return true; }
    return false;
}
function q($a) {
    if ($a === '') return '""';
    if (preg_match('/^[A-Za-z0-9_\-\.\/:@+=%]+$/', $a)) return $a;
    return '"' . str_replace('"', '\\"', $a) . '"';
}

/* FFmpeg argümanları */
function build_args($slug, $cfg, $S, &$err) {
    global $FONTS;
    $err = '';
    $in = '';
    $concatFile = null;
    $isPlaylist = ($cfg['source_mode'] === 'playlist');
    if ($isPlaylist) {
        $pl = prepare_playlist($slug, $cfg);
        if (!$pl) { $err = 'Playlist boş veya geçersiz.'; return []; }
        $concatFile = $pl['file'];
    } else {
        $in = $cfg['input_url'];
        if ($in === '') { $err = 'Kaynak linki boş.'; return []; }
    }

    $a = ['-hide_banner', '-y', '-stats_period', '5', '-loglevel', 'warning'];

    if ($isPlaylist) {
        // concat demuxer — dosyaları sırayla oynat
        array_push($a, '-f', 'concat', '-safe', '0', '-fflags', '+genpts', '-i', $concatFile);
    } else {
        $isHttp = preg_match('~^https?://~i', $in);
        $isFile = !$isHttp && !preg_match('~^(rtmp|rtsp|udp|srt|tcp|mms)://~i', $in);
        if ($isHttp) {
            array_push($a, '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '2');
        }
        if ($cfg['input_ua'] !== '') array_push($a, '-user_agent', $cfg['input_ua']);
        if ($cfg['input_realtime'] && $isFile) $a[] = '-re';
        if ($cfg['input_extra'] !== '') {
            foreach (preg_split('/\s+/', trim($cfg['input_extra'])) as $x) {
                if ($x !== '') $a[] = $x;
            }
        }
        if ($isHttp || !$isFile) {
            array_push($a, '-analyzeduration', '2000000', '-probesize', '2000000');
        }
        array_push($a, '-i', $in);
    }

    if ($cfg['mode'] === 'copy') {
        array_push($a, '-c', 'copy');
    } else {
        $useLogo = $cfg['logo_enabled'] && $cfg['logo_file'] && is_file(chan_data($slug) . DS . $cfg['logo_file']);
        $useBand = $cfg['band_enabled'] && trim($cfg['band_text']) !== '';
        if ($useLogo) array_push($a, '-i', $cfg['logo_file']);

        $vf = [];
        if ($cfg['res'] !== 'orig') {
            list($w, $hh) = explode('x', $cfg['res']);
            $vf[] = "scale={$w}:{$hh}:force_original_aspect_ratio=decrease,setsar=1,pad={$w}:{$hh}:(ow-iw)/2:(oh-ih)/2";
        }
        if ($useBand) {
            $bh = (int)$cfg['band_height'];
            $op = number_format($cfg['band_bg_opacity'] / 100, 2, '.', '');
            $bgc = '0x' . substr($cfg['band_bg_color'], 1);
            $fc = '0x' . substr($cfg['band_font_color'], 1);
            $by = $cfg['band_pos'] === 'top' ? '0' : "ih-$bh";
            $vf[] = "drawbox=x=0:y=$by:w=iw:h=$bh:color=$bgc@$op:t=fill";
            $ty = $cfg['band_pos'] === 'top' ? "($bh-text_h)/2" : "h-$bh+($bh-text_h)/2";
            $tx = $cfg['band_style'] === 'scroll' ? "w-mod(t*{$cfg['band_speed']},w+text_w)" : "(w-text_w)/2";
            $vf[] = "drawtext=fontfile=font.ttf:textfile=band.txt:reload=0:expansion=none:fontsize={$cfg['band_font_size']}:fontcolor=$fc:shadowcolor=black@0.5:shadowx=1:shadowy=1:x='$tx':y='$ty'";
        }
        if (!$vf) $vf[] = 'null';
        $graph = '[0:v:0]' . implode(',', $vf);
        if ($useLogo) {
            $m = (int)$cfg['logo_margin'];
            $pos = ['tl' => "x=$m:y=$m", 'tr' => "x=W-w-$m:y=$m", 'bl' => "x=$m:y=H-h-$m", 'br' => "x=W-w-$m:y=H-h-$m", 'center' => 'x=(W-w)/2:y=(H-h)/2'][$cfg['logo_pos']];
            $lg = '[1:v]scale=' . (int)$cfg['logo_width'] . ':-1,format=rgba';
            if ($cfg['logo_opacity'] < 100) $lg .= ',colorchannelmixer=aa=' . number_format($cfg['logo_opacity'] / 100, 2, '.', '');
            $graph .= '[bg];' . $lg . '[lg];[bg][lg]overlay=' . $pos . ':format=auto,format=yuv420p[v]';
        } else {
            $graph .= ',format=yuv420p[v]';
        }
        array_push($a, '-filter_complex', $graph, '-map', '[v]', '-map', '0:a:0?');

        $vb = (int)$cfg['vbitrate'];
        $bufs = $vb * 4;
        switch ($cfg['vcodec']) {
            case 'h264_nvenc':
                array_push($a, '-c:v', 'h264_nvenc', '-preset', 'p4', '-rc', 'cbr', '-profile:v', 'main'); break;
            case 'h264_qsv':
                array_push($a, '-c:v', 'h264_qsv', '-preset', 'veryfast', '-profile:v', 'main'); break;
            case 'h264_amf':
                array_push($a, '-c:v', 'h264_amf', '-quality', 'speed', '-rc', 'cbr'); break;
            default:
                array_push($a, '-c:v', 'libx264', '-preset', $cfg['preset'], '-profile:v', 'main', '-tune', 'zerolatency');
        }
        array_push($a, '-b:v', $vb . 'k', '-maxrate', $vb . 'k', '-bufsize', $bufs . 'k');
        $fpsOut = $cfg['fps'] > 0 ? (int)$cfg['fps'] : 25;
        array_push($a, '-g', (string)($fpsOut * $cfg['gop']));
        if ($cfg['fps'] > 0) array_push($a, '-r', (string)$cfg['fps']);
        array_push($a, '-c:a', 'aac', '-b:a', $cfg['abitrate'] . 'k');
        if ($cfg['out_type'] === 'rtmp') array_push($a, '-ar', '44100');
    }

    if ($cfg['out_type'] === 'hls') {
        $hls = chan_hls($slug);
        if (!is_dir($hls)) @mkdir($hls, 0777, true);
        ensure_hls_config($hls);
        $d = fs($hls);
        array_push($a, '-f', 'hls',
            '-hls_time', (string)$cfg['hls_time'],
            '-hls_list_size', (string)$cfg['hls_list'],
            '-hls_flags', 'delete_segments+omit_endlist',
            '-hls_segment_type', 'mpegts',
            '-hls_segment_filename', $d . '/seg_%05d.ts',
            $d . '/stream.m3u8');
    } else {
        if ($cfg['out_url'] === '') { $err = 'Çıkış linki boş.'; return []; }
        array_push($a, '-f', $cfg['out_type'] === 'rtmp' ? 'flv' : 'mpegts', $cfg['out_url']);
    }
    return $a;
}

/* Runner PS1 */
function write_runner($slug, $cfg) {
    $dir = chan_data($slug);
    $auto = $cfg['autorestart'] ? '$true' : '$false';
    $delay = (int)$cfg['restart_delay'];
    $base = str_replace("'", "''", $dir);
    $loop = $cfg['loop_playlist'] && $cfg['source_mode'] === 'playlist' ? '$true' : '$false';

    $tpl = <<<'PS'
$ErrorActionPreference = 'Continue'
$base = '{{BASE}}'
$auto = {{AUTO}}
$delay = {{DELAY}}
$loop = {{LOOP}}
function Log($m) { Add-Content -Path "$base\panel.log" -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) -Encoding ASCII }
Set-Content -Path "$base\runner.pid" -Value $PID -Encoding ASCII
$ff = [IO.File]::ReadAllText("$base\ffpath.txt").Trim()
$n = 0
while ($true) {
  $n++
  $a = [IO.File]::ReadAllText("$base\args.txt").Trim()
  Log "FFmpeg baslatiliyor (deneme #$n)"
  $code = -1
  try {
    $p = Start-Process -FilePath $ff -ArgumentList $a -WorkingDirectory "$base" -WindowStyle Hidden -PassThru -RedirectStandardError "$base\ffmpeg.log"
    $null = $p.Handle
    Set-Content -Path "$base\ffmpeg.pid" -Value $p.Id -Encoding ASCII
    $p.WaitForExit()
    $code = $p.ExitCode
  } catch { Log ("Hata: " + $_.Exception.Message) }
  Remove-Item "$base\ffmpeg.pid" -ErrorAction SilentlyContinue
  if (Test-Path "$base\stop.flag") { Log "Yayin kullanici tarafindan durduruldu"; break }
  try { Get-Content "$base\ffmpeg.log" -Tail 4 | ForEach-Object { Log ("  > " + $_) } } catch {}
  if (-not $auto) { Log "FFmpeg durdu (kod $code). Otomatik yeniden baslatma kapali."; break }
  if ($loop -and $code -eq 0) { Log "Oynatma listesi bitti, basa donuluyor." }
  else { Log "FFmpeg durdu (kod $code). $delay sn sonra yeniden baslatilacak." }
  Start-Sleep -Seconds $delay
  if (Test-Path "$base\stop.flag") { break }
}
Remove-Item "$base\runner.pid" -ErrorAction SilentlyContinue
PS;

    $ps = str_replace(
        ['{{BASE}}', '{{AUTO}}', '{{DELAY}}', '{{LOOP}}'],
        [$base, $auto, $delay, $loop],
        $tpl
    );
    return file_put_contents(chan_data($slug) . DS . 'runner.ps1', $ps);
}

/* Durum */
function channel_status($slug) {
    $c = get_channel($slug);
    if (!$c) return null;
    $dir = chan_data($slug);
    $r = read_pid($dir . DS . 'runner.pid');
    $f = read_pid($dir . DS . 'ffmpeg.pid');
    $ra = pid_alive($r);
    $fa = $ra && $f && pid_alive($f);
    if (!$ra && is_file($dir . DS . 'runner.pid')) {
        @unlink($dir . DS . 'runner.pid'); @unlink($dir . DS . 'ffmpeg.pid');
    }
    $st = read_json($dir . DS . 'state.json', []);
    $uptime = ($fa && !empty($st['started'])) ? time() - (int)$st['started'] : 0;
    $log = tail_file($dir . DS . 'ffmpeg.log', 20000);
    $lines = array_values(array_filter(preg_split('/[\r\n]+/', $log), 'strlen'));
    $stats = ['fps' => '-', 'bitrate' => '-', 'speed' => '-', 'time' => '-', 'size' => '-'];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match('/(frame|size)=.*speed=/', $lines[$i])) {
            $l = $lines[$i];
            if (preg_match('/fps=\s*([\d\.]+)/', $l, $m)) $stats['fps'] = $m[1];
            if (preg_match('/bitrate=\s*(\S+)/', $l, $m)) $stats['bitrate'] = $m[1];
            if (preg_match('/speed=\s*(\S+)/', $l, $m)) $stats['speed'] = $m[1];
            if (preg_match('/time=\s*(\S+)/', $l, $m)) $stats['time'] = $m[1];
            if (preg_match('/size=\s*(\S+)/', $l, $m)) $stats['size'] = $m[1];
            break;
        }
    }
    $plog = tail_file($dir . DS . 'panel.log', 6000);
    $plines = array_values(array_filter(preg_split('/[\r\n]+/', ltrim($plog, "\xEF\xBB\xBF")), 'strlen'));
    $hlsUrl = chan_url($slug, 'hls/stream.m3u8');
    return [
        'ok' => true,
        'slug' => $slug,
        'name' => $c['name'],
        'state' => $fa ? 'running' : ($ra ? 'waiting' : 'stopped'),
        'runner' => $r, 'ffmpeg' => $f, 'uptime' => $uptime,
        'stats' => $stats,
        'log' => array_slice($lines, -30),
        'panel_log' => array_slice($plines, -10),
        'hls_url' => $hlsUrl,
        'enabled' => (int)$c['enabled'],
    ];
}

/* Başlat / Durdur */
function start_channel($slug) {
    $S = settings();
    $c = get_channel($slug);
    if (!$c) return ['ok' => false, 'msg' => 'Kanal bulunamadı.'];
    if (!function_exists('exec')) return ['ok' => false, 'msg' => 'PHP exec() kapalı (disable_functions).'];
    if (DS !== '\\') return ['ok' => false, 'msg' => 'Bu panel Windows sunucu için yazılmıştır.'];

    $st = channel_status($slug);
    if ($st && $st['state'] !== 'stopped') return ['ok' => false, 'msg' => 'Yayın zaten çalışıyor.'];

    if (!is_file($S['ffmpeg_path'])) return ['ok' => false, 'msg' => 'FFmpeg bulunamadı: ' . $S['ffmpeg_path']];
    $args = build_args($slug, $c, $S, $err);
    if ($err) return ['ok' => false, 'msg' => $err];

    global $FONTS;
    $win = getenv('WINDIR') ?: 'C:\\Windows';
    $src = $win . '\\Fonts\\' . $FONTS[$c['band_font']];
    $dir = chan_data($slug);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    if (is_file($src)) @copy($src, $dir . DS . 'font.ttf');
    elseif ($c['mode'] === 'encode' && $c['band_enabled']) return ['ok' => false, 'msg' => 'Yazı tipi bulunamadı: ' . $src];

    // band.txt yaz
    @file_put_contents($dir . DS . 'band.txt', $c['band_text']);

    if ($c['out_type'] === 'hls') {
        $hls = chan_hls($slug);
        if (!is_dir($hls)) @mkdir($hls, 0777, true);
        ensure_hls_config($hls);
        foreach (glob($hls . DS . '*.{ts,m3u8}', GLOB_BRACE) ?: [] as $f) @unlink($f);
    }

    @file_put_contents($dir . DS . 'args.txt', implode(' ', array_map('q', $args)));
    @file_put_contents($dir . DS . 'ffpath.txt', $S['ffmpeg_path']);
    @file_put_contents($dir . DS . 'ffmpeg.log', '');
    @unlink($dir . DS . 'stop.flag');
    @unlink($dir . DS . 'runner.pid');
    @unlink($dir . DS . 'ffmpeg.pid');
    write_runner($slug, $c);
    write_json($dir . DS . 'state.json', ['started' => time()]);

    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' . $dir . DS . 'runner.ps1"';
    if (!launch($cmd)) return ['ok' => false, 'msg' => 'İşlem başlatılamadı.'];
    for ($i = 0; $i < 8; $i++) { usleep(500000); if (read_pid($dir . DS . 'ffmpeg.pid')) break; }
    $st = channel_status($slug);
    $ok = $st && $st['state'] !== 'stopped';
    return ['ok' => $ok, 'msg' => $ok ? 'Yayın başlatıldı.' : 'Yayın başlamadı. Logu kontrol edin.'];
}

function stop_channel($slug) {
    $dir = chan_data($slug);
    @file_put_contents($dir . DS . 'stop.flag', '1');
    $r = read_pid($dir . DS . 'runner.pid');
    $f = read_pid($dir . DS . 'ffmpeg.pid');
    if ($r) @exec('taskkill /F /T /PID ' . $r . ' 2>NUL');
    if ($f) @exec('taskkill /F /T /PID ' . $f . ' 2>NUL');
    @unlink($dir . DS . 'runner.pid');
    @unlink($dir . DS . 'ffmpeg.pid');
    return ['ok' => true, 'msg' => 'Yayın durduruldu.'];
}

/* ===================================================================== GİRİŞ */
$authed = !empty($_SESSION['auth']);
$S = settings();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'])) {
    $ok = hash_equals($S['admin_user'], (string)$_POST['login_user'])
       && password_verify((string)($_POST['login_pass'] ?? ''), $S['admin_hash']);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    sleep(1);
    $loginErr = 'Kullanıcı adı veya şifre hatalı.';
}

if (!$authed) {
    if (isset($_GET['api'])) jout(['ok' => false, 'msg' => 'Oturum yok'], 401);
    ?><!DOCTYPE html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yayın Paneli · Giriş</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4"
      style="background-image:radial-gradient(circle at 20% 10%,#312e8180,transparent 40%),radial-gradient(circle at 80% 90%,#0f766e60,transparent 40%)">
<form method="post" class="w-full max-w-sm bg-slate-900/80 backdrop-blur border border-slate-800 rounded-2xl p-8 shadow-2xl">
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-fuchsia-500 flex items-center justify-center text-xl">📡</div>
        <div><h1 class="text-xl font-bold">Yayın Paneli</h1><p class="text-xs text-slate-400">Çok kanallı FFmpeg yönetimi</p></div>
    </div>
    <?php if (!empty($loginErr)): ?><div class="mb-4 text-sm bg-red-500/10 border border-red-500/40 text-red-300 rounded-lg px-3 py-2"><?= h($loginErr) ?></div><?php endif; ?>
    <label class="block text-xs text-slate-400 mb-1">Kullanıcı adı</label>
    <input name="login_user" autofocus required class="w-full mb-4 rounded-lg bg-slate-950 border border-slate-700 px-3 py-2.5 focus:outline-none focus:border-indigo-500">
    <label class="block text-xs text-slate-400 mb-1">Şifre</label>
    <input name="login_pass" type="password" required class="w-full mb-6 rounded-lg bg-slate-950 border border-slate-700 px-3 py-2.5 focus:outline-none focus:border-indigo-500">
    <button class="w-full py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 font-semibold transition">Giriş Yap</button>
</form></body></html>
<?php
    exit;
}

/* ====================================================================== API */
if (isset($_GET['api'])) {
    $api = $_GET['api'];
    $slug = isset($_GET['ch']) ? (string)$_GET['ch'] : '';

    if ($api === 'logo') {
        $c = get_channel($slug);
        if ($c && $c['logo_file'] && is_file(chan_data($slug) . DS . $c['logo_file'])) {
            $f = $c['logo_file'];
            $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Cache-Control: no-store');
            readfile(chan_data($slug) . DS . $f);
        } else http_response_code(404);
        exit;
    }
    if ($api === 'status') {
        $st = channel_status($slug);
        jout($st ?: ['ok' => false]);
    }
    if ($api === 'status_all') {
        $chans = load_channels();
        $out = [];
        foreach ($chans as $k => $c) {
            $st = channel_status($k);
            if ($st) $out[] = $st;
        }
        jout(['ok' => true, 'channels' => $out]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['ok' => false, 'msg' => 'Geçersiz istek'], 405);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) jout(['ok' => false, 'msg' => 'Güvenlik anahtarı geçersiz.'], 403);

    switch ($api) {
        case 'save_channel': {
            $c = get_channel($slug);
            if (!$c) jout(['ok' => false, 'msg' => 'Kanal yok.']);
            $new = parse_channel_post($_POST, $c);
            save_channel($slug, $new);
            @file_put_contents(chan_data($slug) . DS . 'band.txt', $new['band_text']);
            jout(['ok' => true, 'msg' => 'Kanal kaydedildi.', 'cfg' => $new]);
        }

        case 'create_channel': {
            $raw = clean_str($_POST['slug'] ?? '');
            $name = clean_str($_POST['name'] ?? '');
            $slugNew = slugify($raw);
            if ($slugNew === '') jout(['ok' => false, 'msg' => 'Geçerli bir klasör adı girin (harf, rakam, _ -).']);
            if ($name === '') $name = $slugNew;
            $chans = load_channels();
            if (isset($chans[$slugNew])) jout(['ok' => false, 'msg' => 'Bu isimde kanal var.']);
            $chans[$slugNew] = default_channel($name);
            // varsayılan ayarları ana kanaldan al
            if (isset($chans[''])) {
                $base = $chans[''];
                foreach (['vcodec','preset','vbitrate','abitrate','gop','res','fps','mode','logo_pos','band_style','band_pos',
                          'band_font','band_font_size','band_font_color','band_bg_color','band_bg_opacity','band_height',
                          'band_speed','logo_width','logo_opacity','logo_margin','hls_time','hls_list','autorestart','restart_delay'] as $k) {
                    $chans[$slugNew][$k] = $base[$k];
                }
            }
            save_channels($chans);
            @mkdir(chan_data($slugNew), 0777, true);
            @mkdir(chan_hls($slugNew), 0777, true);
            ensure_hls_config(chan_hls($slugNew));
            jout(['ok' => true, 'msg' => 'Kanal oluşturuldu.', 'slug' => $slugNew, 'name' => $name]);
        }

        case 'delete_channel': {
            if ($slug === '') jout(['ok' => false, 'msg' => 'Ana kanal silinemez.']);
            stop_channel($slug);
            $chans = load_channels();
            unset($chans[$slug]);
            save_channels($chans);
            // klasörleri sil (kullanıcı onayı zaten panelde)
            $d1 = chan_data($slug); $d2 = chan_hls($slug);
            $rm = function($dir) use (&$rm) {
                if (!is_dir($dir)) return;
                foreach (scandir($dir) as $x) {
                    if ($x === '.' || $x === '..') continue;
                    $p = $dir . DS . $x;
                    if (is_dir($p)) $rm($p); else @unlink($p);
                }
                @rmdir($dir);
            };
            if (strpos(fs($d1), fs(DATA)) === 0) $rm($d1);
            if (strpos(fs($d2), fs(ROOT)) === 0 && $d2 !== ROOT) $rm($d2);
            jout(['ok' => true, 'msg' => 'Kanal silindi.']);
        }

        case 'start': jout(start_channel($slug));
        case 'stop':  jout(stop_channel($slug));
        case 'restart':
            stop_channel($slug);
            sleep(2);
            jout(start_channel($slug));
        case 'start_all': {
            $chans = load_channels();
            $ok = 0; $fail = 0;
            foreach ($chans as $k => $c) {
                if (!$c['enabled']) continue;
                $r = start_channel($k);
                if ($r['ok']) $ok++; else $fail++;
                usleep(300000);
            }
            jout(['ok' => true, 'msg' => "$ok başlatıldı, $fail başarısız."]);
        }
        case 'stop_all': {
            $chans = load_channels();
            foreach ($chans as $k => $c) stop_channel($k);
            jout(['ok' => true, 'msg' => 'Tüm kanallar durduruldu.']);
        }
        case 'cmd': {
            $c = get_channel($slug);
            if (!$c) jout(['ok' => false, 'msg' => 'Kanal yok.']);
            $args = build_args($slug, $c, $S, $err);
            if ($err) jout(['ok' => false, 'msg' => $err]);
            jout(['ok' => true, 'cmd' => '"' . $S['ffmpeg_path'] . '" ' . implode(' ', array_map('q', $args))]);
        }

        case 'logo_upload': {
            $c = get_channel($slug);
            if (!$c) jout(['ok' => false, 'msg' => 'Kanal yok.']);
            if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) jout(['ok' => false, 'msg' => 'Dosya yüklenemedi.']);
            $f = $_FILES['logo'];
            if ($f['size'] > 8 * 1024 * 1024) jout(['ok' => false, 'msg' => 'Dosya 8 MB üstünde olamaz.']);
            $info = @getimagesize($f['tmp_name']);
            $map = [IMAGETYPE_PNG=>'png', IMAGETYPE_JPEG=>'jpg', IMAGETYPE_GIF=>'gif', IMAGETYPE_WEBP=>'webp'];
            if (!$info || !isset($map[$info[2]])) jout(['ok' => false, 'msg' => 'Sadece PNG/JPG/GIF/WEBP.']);
            $dir = chan_data($slug);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            foreach (glob($dir . DS . 'logo.*') ?: [] as $old) @unlink($old);
            $name = 'logo.' . $map[$info[2]];
            if (!move_uploaded_file($f['tmp_name'], $dir . DS . $name)) jout(['ok' => false, 'msg' => 'Kaydedilemedi.']);
            $c['logo_file'] = $name;
            save_channel($slug, $c);
            jout(['ok' => true, 'msg' => 'Logo yüklendi.', 'file' => $name]);
        }
        case 'logo_delete': {
            $c = get_channel($slug);
            if (!$c) jout(['ok' => false, 'msg' => 'Kanal yok.']);
            $dir = chan_data($slug);
            foreach (glob($dir . DS . 'logo.*') ?: [] as $old) @unlink($old);
            $c['logo_file'] = '';
            save_channel($slug, $c);
            jout(['ok' => true, 'msg' => 'Logo silindi.']);
        }

        case 'settings': {
            $s = $S;
            if (isset($_POST['ffmpeg_path'])) $s['ffmpeg_path'] = clean_str($_POST['ffmpeg_path']);
            save_settings($s);
            jout(['ok' => true, 'msg' => 'Ayarlar kaydedildi.', 'settings' => $s]);
        }
        case 'password': {
            $u = clean_str($_POST['new_user'] ?? '');
            $cur = (string)($_POST['cur_pass'] ?? '');
            $new = (string)($_POST['new_pass'] ?? '');
            if (!password_verify($cur, $S['admin_hash'])) jout(['ok' => false, 'msg' => 'Mevcut şifre yanlış.']);
            if (strlen($new) < 6) jout(['ok' => false, 'msg' => 'Yeni şifre en az 6 karakter.']);
            if ($u === '') $u = $S['admin_user'];
            $S['admin_user'] = $u;
            $S['admin_hash'] = password_hash($new, PASSWORD_DEFAULT);
            $S['pass_default'] = false;
            save_settings($S);
            jout(['ok' => true, 'msg' => 'Giriş bilgileri güncellendi.']);
        }
        case 'test': {
            $ff = $S['ffmpeg_path'];
            if (!is_file($ff)) jout(['ok' => false, 'msg' => 'Dosya yok: ' . $ff]);
            $o = []; @exec('"' . $ff . '" -hide_banner -version 2>&1', $o);
            $ver = $o[0] ?? 'Sürüm okunamadı';
            $o = []; @exec('"' . $ff . '" -hide_banner -filters 2>&1', $o);
            $drawtext = strpos(implode("\n", $o), 'drawtext') !== false;
            $overlay = strpos(implode("\n", $o), ' overlay ') !== false;
            $o = []; @exec('"' . $ff . '" -hide_banner -encoders 2>&1', $o);
            $enc = implode("\n", $o);
            jout(['ok' => true, 'version' => $ver, 'drawtext' => $drawtext, 'overlay' => $overlay,
                'libx264' => strpos($enc, 'libx264') !== false, 'nvenc' => strpos($enc, 'h264_nvenc') !== false,
                'qsv' => strpos($enc, 'h264_qsv') !== false, 'amf' => strpos($enc, 'h264_amf') !== false]);
        }
    }
    jout(['ok' => false, 'msg' => 'Bilinmeyen işlem'], 400);
}

/* ===================================================================== PANEL */
$chans = load_channels();
$sys = [
    'php' => PHP_VERSION,
    'os' => php_uname('s') . ' ' . php_uname('r'),
    'exec' => function_exists('exec'),
    'com' => class_exists('COM'),
    'user' => get_current_user(),
    'writable' => is_writable(DATA),
    'upload' => ini_get('upload_max_filesize'),
];
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yayın Paneli · Çok Kanallı</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<style>
    body{background:#020617;background-image:radial-gradient(circle at 15% 0%,#312e8155,transparent 40%),radial-gradient(circle at 90% 100%,#0f766e40,transparent 40%);background-attachment:fixed}
    .card{background:rgba(15,23,42,.8);border:1px solid #1e293b;border-radius:1rem;padding:1.25rem}
    .lbl{display:block;font-size:.72rem;color:#94a3b8;margin-bottom:.3rem;font-weight:500}
    .inp{width:100%;border-radius:.5rem;background:#020617;border:1px solid #334155;padding:.5rem .75rem;font-size:.875rem;color:#f1f5f9;outline:none}
    .inp:focus{border-color:#6366f1}
    select.inp{padding-right:.5rem}
    input[type=color].inp{padding:.2rem;height:2.3rem}
    .hint{font-size:.7rem;color:#64748b;margin-top:.25rem}
    .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:.6rem;font-size:.82rem;font-weight:600;transition:.15s;cursor:pointer}
    .btn:disabled{opacity:.4;cursor:not-allowed}
    .tab{padding:.5rem .9rem;border-radius:.6rem;font-size:.82rem;color:#94a3b8;cursor:pointer;white-space:nowrap;border:1px solid transparent}
    .tab:hover{color:#e2e8f0}
    .tab.active{background:#4f46e5;color:#fff}
    .tabp{display:none}.tabp.active{display:block}
    .term{background:#020617;border:1px solid #1e293b;border-radius:.6rem;padding:.75rem;font:12px/1.5 Consolas,monospace;color:#86efac;height:14rem;overflow:auto;white-space:pre-wrap;word-break:break-all}
    @keyframes pulse2{0%,100%{opacity:1}50%{opacity:.35}}
    .live{animation:pulse2 1.2s infinite}
    .sw{position:relative;width:2.6rem;height:1.4rem;flex:none}
    .sw input{opacity:0;position:absolute;inset:0;z-index:2;cursor:pointer;width:100%;height:100%;margin:0}
    .sw span{position:absolute;inset:0;background:#334155;border-radius:999px;transition:.2s}
    .sw span:after{content:"";position:absolute;width:1.05rem;height:1.05rem;left:.18rem;top:.17rem;background:#fff;border-radius:50%;transition:.2s}
    .sw input:checked+span{background:#10b981}
    .sw input:checked+span:after{transform:translateX(1.2rem)}
    .grid-ch{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem}
    .ch-card{background:rgba(15,23,42,.9);border:1px solid #1e293b;border-radius:1rem;padding:1rem;display:flex;flex-direction:column;gap:.6rem}
    .ch-card.live{border-color:#10b981}
    .ch-card.wait{border-color:#f59e0b}
    .dot{width:.6rem;height:.6rem;border-radius:50%;display:inline-block}
    .modal{position:fixed;inset:0;background:rgba(2,6,23,.85);backdrop-filter:blur(4px);display:none;z-index:60;overflow-y:auto;padding:1rem}
    .modal.open{display:block}
    .modal-inner{max-width:960px;margin:2rem auto;background:rgba(15,23,42,.98);border:1px solid #1e293b;border-radius:1rem;padding:1.5rem}
</style>
</head>
<body class="text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto p-4 md:p-6">

    <header class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-fuchsia-500 flex items-center justify-center text-xl">📡</div>
            <div><h1 class="text-xl font-bold leading-tight">Yayın Paneli</h1><p class="text-xs text-slate-400">Çok kanallı FFmpeg yönetimi</p></div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button id="bStartAll" class="btn bg-emerald-600 hover:bg-emerald-500">▶ Tümünü Başlat</button>
            <button id="bStopAll" class="btn bg-red-600 hover:bg-red-500">■ Tümünü Durdur</button>
            <button id="bNew" class="btn bg-indigo-600 hover:bg-indigo-500">➕ Yeni Kanal</button>
            <button id="bSettings" class="btn bg-slate-700 hover:bg-slate-600">⚙ Genel Ayarlar</button>
            <a href="?logout=1" class="btn bg-slate-800 hover:bg-slate-700 text-slate-200">Çıkış</a>
        </div>
    </header>

    <?php if (!empty($S['pass_default'])): ?>
    <div class="mb-4 text-sm bg-amber-500/10 border border-amber-500/40 text-amber-200 rounded-xl px-4 py-3">
        ⚠️ Varsayılan şifre (<b>admin / admin123</b>) kullanılıyor. <b>Genel Ayarlar</b> sekmesinden değiştirin.
    </div>
    <?php endif; ?>

    <div class="mb-4 text-xs text-slate-400 bg-slate-900/60 border border-slate-800 rounded-xl p-3">
        <b class="text-slate-200">Çıkış linkleri:</b>
        Ana kanal → <code class="text-indigo-300"><?= h(base_url()) ?>/hls/stream.m3u8</code> ·
        <span id="chSample">kanal</span> → <code class="text-indigo-300"><?= h(base_url()) ?>/<span id="chSample2">kanal</span>/hls/stream.m3u8</code>
    </div>

    <div id="chGrid" class="grid-ch"></div>

    <p class="text-center text-xs text-slate-600 mt-8">Yayın Paneli · PHP <?= h(PHP_VERSION) ?> · Çok Kanallı</p>
</div>

<!-- Kanal düzenleme modalı -->
<div class="modal" id="modal">
    <div class="modal-inner">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-bold" id="mTitle">Kanal</h2>
                <p class="text-xs text-slate-400" id="mSub">—</p>
            </div>
            <button id="mClose" class="btn bg-slate-700 hover:bg-slate-600">✕ Kapat</button>
        </div>

        <nav class="flex gap-1 overflow-x-auto pb-2 mb-3" id="tabs">
            <div class="tab active" data-t="src">🔗 Kaynak</div>
            <div class="tab" data-t="enc">⚙ Kodlama</div>
            <div class="tab" data-t="logo">🖼 Logo</div>
            <div class="tab" data-t="band">📰 Bant</div>
            <div class="tab" data-t="log">📊 Log & Test</div>
            <div class="tab" data-t="del">🗑 Sil</div>
        </nav>

        <form id="cfg" onsubmit="return false">
            <input type="hidden" id="chSlug">

            <div class="tabp active card" id="t-src">
                <div class="grid sm:grid-cols-2 gap-4 mb-3">
                    <div>
                        <label class="lbl">Kanal adı (görünen)</label>
                        <input name="name" class="inp">
                    </div>
                    <div>
                        <label class="lbl">Kaynak modu</label>
                        <select name="source_mode" class="inp" id="srcMode">
                            <option value="single">Tek link (canlı yayın)</option>
                            <option value="playlist">Oynatma listesi (birden fazla link)</option>
                        </select>
                    </div>
                </div>

                <div id="srcSingle">
                    <label class="lbl">Kaynak yayın linki (m3u8, rtmp, http, udp, srt, mp4 …)</label>
                    <input name="input_url" class="inp font-mono" placeholder="http://ornek.com/canli/index.m3u8">
                    <div class="grid sm:grid-cols-2 gap-4 mt-3">
                        <div><label class="lbl">User-Agent</label><input name="input_ua" class="inp" placeholder="VLC/3.0.18"></div>
                        <div><label class="lbl">Ek giriş parametreleri</label><input name="input_extra" class="inp font-mono" placeholder="-rw_timeout 15000000"></div>
                    </div>
                    <label class="flex items-center gap-3 text-sm mt-3"><span class="sw"><input type="checkbox" name="input_realtime"><span></span></span> Gerçek zamanlı oku (-re) — sadece dosya/mp4 için</label>
                </div>

                <div id="srcPlaylist" style="display:none">
                    <label class="lbl">Oynatma listesi — her satır bir bölüm</label>
                    <textarea name="playlist" rows="8" class="inp font-mono" placeholder="Arka Sokaklar 1| https://ornek.com/aska1.m3u8&#10;Arka Sokaklar 2| https://ornek.com/aska2.m3u8&#10;https://ornek.com/dizi3.mp4"></textarea>
                    <p class="hint">Format: <code>Başlık| URL</code> veya sadece <code>URL</code>. Sırayla oynatılır, sonra başa döner.</p>
                    <label class="flex items-center gap-3 text-sm mt-3"><span class="sw"><input type="checkbox" name="loop_playlist"><span></span></span> Liste bitince başa dön (kesintisiz yayın)</label>
                </div>

                <hr class="border-slate-800 my-4">
                <h3 class="font-semibold mb-2">Çıkış</h3>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="lbl">Çıkış türü</label>
                        <select name="out_type" class="inp" id="outType">
                            <option value="hls">HLS (kendi sunucumuzda yayın)</option>
                            <option value="rtmp">RTMP (YouTube, Facebook…)</option>
                            <option value="mpegts">MPEG-TS (udp/srt/tcp)</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Çalışma modu</label>
                        <select name="mode" class="inp">
                            <option value="encode">Yeniden kodla (logo + bant aktif)</option>
                            <option value="copy">Kopyala (CPU yok, logo/bant YOK)</option>
                        </select>
                    </div>
                </div>
                <div id="outUrlWrap" style="display:none">
                    <label class="lbl mt-3">Çıkış linki (RTMP/MPEGTS)</label>
                    <input name="out_url" class="inp font-mono" placeholder="rtmp://a.rtmp.youtube.com/live2/XXXX">
                </div>
                <div id="hlsOpts" class="grid grid-cols-2 gap-4 mt-3">
                    <div><label class="lbl">HLS parça süresi (sn)</label><input type="number" name="hls_time" class="inp"></div>
                    <div><label class="lbl">Liste uzunluğu</label><input type="number" name="hls_list" class="inp"></div>
                </div>
            </div>

            <div class="tabp card" id="t-enc">
                <div class="grid sm:grid-cols-2 gap-4">
                    <div><label class="lbl">Çözünürlük</label>
                        <select name="res" class="inp"><option value="orig">Orijinal (en stabil)</option><option value="1920x1080">1920x1080</option><option value="1280x720">1280x720</option><option value="854x480">854x480</option><option value="640x360">640x360</option></select></div>
                    <div><label class="lbl">FPS (0 = orijinal)</label><input type="number" name="fps" class="inp"></div>
                    <div><label class="lbl">Video kodlayıcı</label>
                        <select name="vcodec" class="inp"><option value="libx264">libx264 (CPU)</option><option value="h264_nvenc">h264_nvenc (NVIDIA)</option><option value="h264_qsv">h264_qsv (Intel)</option><option value="h264_amf">h264_amf (AMD)</option></select></div>
                    <div><label class="lbl">x264 preset</label>
                        <select name="preset" class="inp"><option>ultrafast</option><option>superfast</option><option>veryfast</option><option>faster</option><option>fast</option><option>medium</option></select></div>
                    <div><label class="lbl">Video bitrate (kbps)</label><input type="number" name="vbitrate" class="inp"></div>
                    <div><label class="lbl">Ses bitrate (kbps)</label><input type="number" name="abitrate" class="inp"></div>
                    <div><label class="lbl">Keyframe aralığı (sn)</label><input type="number" name="gop" class="inp"></div>
                </div>
                <hr class="border-slate-800 my-4">
                <h3 class="font-semibold mb-2">Otomatik Yeniden Başlatma</h3>
                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="flex items-center gap-3 text-sm"><span class="sw"><input type="checkbox" name="autorestart"><span></span></span> FFmpeg çökerse yeniden başlat</label>
                    <div><label class="lbl">Bekleme (sn)</label><input type="number" name="restart_delay" class="inp"></div>
                </div>
                <hr class="border-slate-800 my-4">
                <h3 class="font-semibold mb-2">Kanal Durumu</h3>
                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="flex items-center gap-3 text-sm"><span class="sw"><input type="checkbox" name="enabled"><span></span></span> Kanal aktif (toplu başlatmaya dahil)</label>
                </div>
            </div>

            <div class="tabp card" id="t-logo">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold">Logo</h3>
                    <label class="flex items-center gap-2 text-sm"><span class="sw"><input type="checkbox" name="logo_enabled"><span></span></span> Aktif</label>
                </div>
                <div class="flex flex-wrap items-center gap-4 p-3 rounded-xl border border-dashed border-slate-700 bg-slate-950 mb-4">
                    <div class="w-28 h-20 rounded-lg bg-[repeating-conic-gradient(#1e293b_0_25%,#0f172a_0_50%)] bg-[length:16px_16px] flex items-center justify-center overflow-hidden">
                        <img id="logoThumb" class="max-w-full max-h-full hidden" alt="">
                        <span id="logoNone" class="text-xs text-slate-500">Logo yok</span>
                    </div>
                    <div class="flex-1 min-w-[12rem]">
                        <input type="file" id="logoFile" accept="image/png,image/jpeg,image/gif,image/webp" class="text-xs text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-white file:cursor-pointer">
                        <p class="hint">PNG (şeffaf) önerilir. Max 8 MB.</p>
                    </div>
                    <button type="button" id="bLogoDel" class="btn bg-slate-800 hover:bg-red-600/80">Sil</button>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div><label class="lbl">Konum</label>
                        <select name="logo_pos" class="inp"><option value="tl">Sol üst</option><option value="tr">Sağ üst</option><option value="bl">Sol alt</option><option value="br">Sağ alt</option><option value="center">Orta</option></select></div>
                    <div><label class="lbl">Genişlik (px)</label><input type="number" name="logo_width" class="inp"></div>
                    <div><label class="lbl">Opaklık (%)</label><input type="range" min="5" max="100" name="logo_opacity" class="w-full accent-indigo-500"></div>
                    <div><label class="lbl">Kenar boşluğu (px)</label><input type="number" name="logo_margin" class="inp"></div>
                </div>
            </div>

            <div class="tabp card" id="t-band">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold">Alt Bant</h3>
                    <label class="flex items-center gap-2 text-sm"><span class="sw"><input type="checkbox" name="band_enabled"><span></span></span> Aktif</label>
                </div>
                <label class="lbl">Bant metni</label>
                <textarea name="band_text" rows="3" class="inp"></textarea>
                <p class="hint">Değişiklik için kaydettikten sonra <b>Yeniden Başlat</b> gerekir.</p>
                <div class="grid sm:grid-cols-3 gap-4 mt-3">
                    <div><label class="lbl">Stil</label><select name="band_style" class="inp"><option value="scroll">Kayan</option><option value="static">Sabit</option></select></div>
                    <div><label class="lbl">Konum</label><select name="band_pos" class="inp"><option value="bottom">Alt</option><option value="top">Üst</option></select></div>
                    <div><label class="lbl">Yazı tipi</label>
                        <select name="band_font" class="inp"><option value="arial">Arial</option><option value="arialbd">Arial Kalın</option><option value="tahoma">Tahoma</option><option value="verdana">Verdana</option><option value="segoeui">Segoe UI</option><option value="calibri">Calibri</option><option value="impact">Impact</option></select></div>
                    <div><label class="lbl">Yükseklik (px)</label><input type="number" name="band_height" class="inp"></div>
                    <div><label class="lbl">Yazı boyutu</label><input type="number" name="band_font_size" class="inp"></div>
                    <div><label class="lbl">Kayma hızı</label><input type="number" name="band_speed" class="inp"></div>
                    <div><label class="lbl">Yazı rengi</label><input type="color" name="band_font_color" class="inp"></div>
                    <div><label class="lbl">Bant rengi</label><input type="color" name="band_bg_color" class="inp"></div>
                    <div><label class="lbl">Opaklık (%)</label><input type="range" min="0" max="100" name="band_bg_opacity" class="w-full accent-indigo-500 mt-2"></div>
                </div>
            </div>

            <div class="tabp card" id="t-log">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-semibold">FFmpeg Çıktısı</h3>
                    <span class="text-xs text-slate-500">Canlı</span>
                </div>
                <div id="mLog" class="term">—</div>
                <h3 class="font-semibold mt-3 mb-2">Panel Olayları</h3>
                <div id="mPlog" class="term" style="height:8rem;color:#fcd34d">—</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" id="mCmd" class="btn bg-slate-800 hover:bg-slate-700">🔍 FFmpeg komutu</button>
                    <button type="button" id="mCopy" class="btn bg-slate-800 hover:bg-slate-700">📋 HLS linkini kopyala</button>
                    <button type="button" id="mPlay" class="btn bg-indigo-600 hover:bg-indigo-500">▶ İzle</button>
                </div>
                <pre id="mCmdOut" class="term mt-2 hidden" style="height:auto;max-height:12rem;color:#a5b4fc"></pre>
                <video id="mVid" controls muted class="w-full rounded-lg bg-black hidden aspect-video mt-3"></video>
            </div>

            <div class="tabp card" id="t-del">
                <h3 class="font-semibold mb-2">Kanalı Sil</h3>
                <p class="text-sm text-slate-400 mb-4">Bu kanalın tüm ayarları, logları ve HLS dosyaları silinecek. Bu işlem geri alınamaz.</p>
                <button type="button" id="bDelete" class="btn bg-red-600 hover:bg-red-500">🗑 Kanalı Sil</button>
            </div>
        </form>

        <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-800 pt-4">
            <button id="mSave" class="btn bg-slate-700 hover:bg-slate-600">💾 Kaydet</button>
            <button id="mStart" class="btn bg-emerald-600 hover:bg-emerald-500">▶ Başlat</button>
            <button id="mRestart" class="btn bg-amber-600 hover:bg-amber-500">⟲ Yeniden Başlat</button>
            <button id="mStop" class="btn bg-red-600 hover:bg-red-500">■ Durdur</button>
        </div>
    </div>
</div>

<!-- Yeni kanal modalı -->
<div class="modal" id="newModal">
    <div class="modal-inner" style="max-width:480px">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold">Yeni Kanal</h2>
            <button id="nClose" class="btn bg-slate-700 hover:bg-slate-600">✕</button>
        </div>
        <label class="lbl">Klasör adı (URL'de görünecek)</label>
        <input id="nSlug" class="inp mb-3" placeholder="diginet">
        <p class="hint mb-3">Sadece harf, rakam, <code>_</code> ve <code>-</code>. Çıkış linki: <code><?= h(base_url()) ?>/<span id="nPreview">diginet</span>/hls/stream.m3u8</code></p>
        <label class="lbl">Görünen ad</label>
        <input id="nName" class="inp mb-4" placeholder="Diginet TV">
        <button id="nCreate" class="btn bg-indigo-600 hover:bg-indigo-500 w-full justify-center">➕ Oluştur</button>
    </div>
</div>

<!-- Genel ayarlar modalı -->
<div class="modal" id="setModal">
    <div class="modal-inner" style="max-width:600px">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold">Genel Ayarlar</h2>
            <button id="sClose" class="btn bg-slate-700 hover:bg-slate-600">✕</button>
        </div>
        <label class="lbl">FFmpeg yolu</label>
        <input id="sFF" class="inp font-mono mb-3">
        <div class="flex flex-wrap gap-2 mb-4">
            <button id="sTest" class="btn bg-indigo-600 hover:bg-indigo-500">🧪 FFmpeg Test</button>
        </div>
        <div id="sTestOut" class="text-sm space-y-1 mb-4"></div>
        <hr class="border-slate-800 my-4">
        <h3 class="font-semibold mb-3">Giriş Bilgilerini Değiştir</h3>
        <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="lbl">Yeni kullanıcı adı</label><input id="pwUser" class="inp"></div>
            <div><label class="lbl">Mevcut şifre</label><input id="pwCur" type="password" class="inp"></div>
            <div><label class="lbl">Yeni şifre</label><input id="pwNew" type="password" class="inp"></div>
        </div>
        <button id="bPw" class="btn bg-slate-700 hover:bg-slate-600 mt-3">🔑 Şifre Değiştir</button>
        <hr class="border-slate-800 my-4">
        <h3 class="font-semibold mb-2">Sunucu</h3>
        <ul class="text-sm space-y-1 text-slate-300">
            <li>PHP: <b><?= h($sys['php']) ?></b> · <?= h($sys['os']) ?></li>
            <li>Kullanıcı: <b><?= h($sys['user']) ?></b></li>
            <li>exec(): <?= $sys['exec'] ? '<span class="text-emerald-400">Açık ✓</span>' : '<span class="text-red-400">KAPALI ✗</span>' ?></li>
            <li>COM: <?= $sys['com'] ? '<span class="text-emerald-400">Var ✓</span>' : '<span class="text-amber-400">Yok</span>' ?></li>
            <li>data yazılabilir: <?= $sys['writable'] ? '<span class="text-emerald-400">Evet ✓</span>' : '<span class="text-red-400">HAYIR ✗</span>' ?></li>
        </ul>
        <button id="sSave" class="btn bg-indigo-600 hover:bg-indigo-500 w-full justify-center mt-4">💾 Genel Ayarları Kaydet</button>
    </div>
</div>

<div id="toasts" class="fixed bottom-4 right-4 space-y-2 z-[70]"></div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
const SETTINGS = <?= json_encode($S, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG) ?>;
delete SETTINGS.admin_hash;
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
let CH = null;                  // aktif kanal cfg
let chSlug = '';                // aktif kanal slug
let pollTimer = null;

function toast(msg, ok = true) {
    const d = document.createElement('div');
    d.className = 'px-4 py-3 rounded-lg text-sm shadow-xl border max-w-xs ' +
        (ok ? 'bg-emerald-950 border-emerald-700 text-emerald-100' : 'bg-red-950 border-red-700 text-red-100');
    d.textContent = msg;
    $('#toasts').appendChild(d);
    setTimeout(() => d.remove(), 4000);
}
async function api(name, data = {}, ch = '') {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    const url = '?api=' + name + (ch !== '' ? '&ch=' + encodeURIComponent(ch) : '');
    try {
        const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF': CSRF }, body: fd });
        if (r.status === 401) { location.reload(); return { ok: false, msg: 'Oturum bitti' }; }
        return await r.json();
    } catch (e) { return { ok: false, msg: 'Sunucuya ulaşılamadı' }; }
}
async function apiGet(name, ch = '') {
    const url = '?api=' + name + (ch !== '' ? '&ch=' + encodeURIComponent(ch) : '');
    try {
        const r = await fetch(url);
        if (r.status === 401) { location.reload(); return { ok: false }; }
        return await r.json();
    } catch (e) { return { ok: false }; }
}

/* ---- Kanal grid ---- */
let allChannels = [];
async function renderGrid() {
    const r = await apiGet('status_all');
    if (!r.ok) return;
    allChannels = r.channels;
    const g = $('#chGrid');
    g.innerHTML = '';
    for (const ch of allChannels) {
        const c = document.createElement('div');
        c.className = 'ch-card ' + (ch.state === 'running' ? 'live' : (ch.state === 'waiting' ? 'wait' : ''));
        const dotColor = ch.state === 'running' ? '#10b981' : (ch.state === 'waiting' ? '#f59e0b' : '#64748b');
        const statusTxt = ch.state === 'running' ? 'CANLI' : (ch.state === 'waiting' ? 'BAĞLANIYOR' : 'KAPALI');
        c.innerHTML = `
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="dot" style="background:${dotColor}"></span>
                    <b>${escapeHtml(ch.name)}</b>
                </div>
                <span class="text-[11px] text-slate-400 font-mono">${escapeHtml(ch.slug || '(ana)')}</span>
            </div>
            <div class="text-xs text-slate-400">${statusTxt} ${ch.enabled ? '' : '· pasif'}</div>
            <div class="text-[11px] text-slate-500 font-mono break-all">
                ${escapeHtml(ch.hls_url)}
            </div>
            <div class="grid grid-cols-3 gap-1 text-[11px] text-slate-400">
                <div>FPS<br><span class="text-slate-200 font-mono">${ch.stats.fps}</span></div>
                <div>Bitrate<br><span class="text-slate-200 font-mono">${ch.stats.bitrate}</span></div>
                <div>Hız<br><span class="text-slate-200 font-mono">${ch.stats.speed}</span></div>
            </div>
            <div class="flex flex-wrap gap-1 mt-auto pt-2">
                <button data-a="edit" data-s="${escapeHtml(ch.slug)}" class="btn bg-slate-700 hover:bg-slate-600 !py-1 !px-2 !text-xs">⚙ Düzenle</button>
                ${ch.state === 'stopped'
                    ? `<button data-a="start" data-s="${escapeHtml(ch.slug)}" class="btn bg-emerald-600 hover:bg-emerald-500 !py-1 !px-2 !text-xs">▶</button>`
                    : `<button data-a="stop" data-s="${escapeHtml(ch.slug)}" class="btn bg-red-600 hover:bg-red-500 !py-1 !px-2 !text-xs">■</button>`}
                <button data-a="restart" data-s="${escapeHtml(ch.slug)}" class="btn bg-amber-600 hover:bg-amber-500 !py-1 !px-2 !text-xs">⟲</button>
                <button data-a="copy" data-s="${escapeHtml(ch.slug)}" data-url="${escapeHtml(ch.hls_url)}" class="btn bg-slate-800 hover:bg-slate-700 !py-1 !px-2 !text-xs">📋</button>
            </div>
        `;
        g.appendChild(c);
    }
}
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

document.addEventListener('click', async e => {
    const b = e.target.closest('button[data-a]');
    if (!b) return;
    const a = b.dataset.a, s = b.dataset.s;
    if (a === 'edit') return openChannel(s);
    if (a === 'copy') {
        navigator.clipboard.writeText(b.dataset.url);
        return toast('Link kopyalandı');
    }
    if (a === 'start' || a === 'stop' || a === 'restart') {
        b.disabled = true;
        const r = await api(a, {}, s);
        toast(r.msg, r.ok);
        setTimeout(renderGrid, 800);
    }
});

/* ---- Modal ---- */
function openModal() { $('#modal').classList.add('open'); }
function closeModal() { $('#modal').classList.remove('open'); if (pollTimer) clearInterval(pollTimer); }
$('#mClose').onclick = closeModal;
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

async function openChannel(slug) {
    chSlug = slug;
    $('#chSlug').value = slug;
    const r = await fetch('?api=status&ch=' + encodeURIComponent(slug)).then(x => x.json());
    const cfg = await fetch('?api=status&ch=' + encodeURIComponent(slug)).then(x => x.json());
    // config kanal listesinden
    const chans = await fetch('?api=status_all').then(x => x.json());
    // daha basit: cfg'yi save_channel endpoint'i dönmüyor; settings'ten çekelim yerine grid'den isim al
    // Bunun yerine kanal cfg'sini ayrı endpoint'ten alalım:
    const chCfg = await fetch('?api=channel&ch=' + encodeURIComponent(slug)).then(x => x.json()).catch(() => null);
    // API'de channel endpoint'i yok, o yüzden config'i status_all içinden alamayız.
    // Basit çözüm: kanal bilgisini PHP'den JSON olarak gömülü alalım.
    // (Aşağıda __CHANNELS__ globaline gömülecek.)
    const cfgData = window.__CHANNELS__[slug];
    if (!cfgData) return toast('Kanal bulunamadı', false);
    CH = cfgData;
    $('#mTitle').textContent = cfgData.name || slug;
    $('#mSub').textContent = 'Klasör: ' + (slug || '(ana)') + ' · Çıkış: ' + (slug ? '/' + slug : '') + '/hls/stream.m3u8';
    fillForm();
    updateSrcMode(); updateOutType();
    logoUi(); updatePreview();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollChannel, 3000);
    pollChannel();
    openModal();
}

/* form doldur/topla */
function fillForm() {
    $$('#cfg [name]').forEach(el => {
        const v = CH[el.name];
        if (v === undefined) return;
        if (el.type === 'checkbox') el.checked = !!+v; else el.value = v;
    });
}
function collect() {
    const o = {};
    $$('#cfg [name]').forEach(el => o[el.name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value);
    return o;
}

/* tablar */
$$('#tabs .tab').forEach(t => t.onclick = () => {
    $$('#tabs .tab').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    $$('.tabp').forEach(x => x.classList.remove('active'));
    $('#t-' + t.dataset.t).classList.add('active');
});

function updateSrcMode() {
    const m = $('#srcMode').value;
    $('#srcSingle').style.display = m === 'single' ? '' : 'none';
    $('#srcPlaylist').style.display = m === 'playlist' ? '' : 'none';
}
function updateOutType() {
    const t = $('#outType').value;
    $('#outUrlWrap').style.display = t === 'hls' ? 'none' : '';
    $('#hlsOpts').style.display = t === 'hls' ? '' : 'none';
}
$('#srcMode').onchange = updateSrcMode;
$('#outType').onchange = updateOutType;
document.addEventListener('input', e => { if (e.target.closest('#cfg')) updatePreview(); });

/* logo */
function logoUi() {
    const has = !!CH.logo_file;
    $('#logoThumb').classList.toggle('hidden', !has);
    $('#logoNone').classList.toggle('hidden', has);
    if (has) $('#logoThumb').src = '?api=logo&ch=' + encodeURIComponent(chSlug) + '&t=' + Date.now();
    $('#bLogoDel').disabled = !has;
}
$('#logoFile').onchange = async e => {
    const f = e.target.files[0]; if (!f) return;
    const r = await api('logo_upload', { logo: f }, chSlug);
    toast(r.msg, r.ok);
    if (r.ok) { CH.logo_file = r.file; logoUi(); }
    e.target.value = '';
};
$('#bLogoDel').onclick = async () => {
    if (!confirm('Logo silinsin mi?')) return;
    const r = await api('logo_delete', {}, chSlug);
    toast(r.msg, r.ok);
    if (r.ok) { CH.logo_file = ''; logoUi(); }
};

/* önizleme */
function updatePreview() {
    // Önizleme minimal — grid kartında yeterli
}

/* Poll kanal */
async function pollChannel() {
    const r = await apiGet('status', chSlug);
    if (!r.ok) return;
    const lg = $('#mLog'), atEnd = lg.scrollTop + lg.clientHeight >= lg.scrollHeight - 20;
    lg.textContent = r.log.length ? r.log.join('\n') : '—';
    if (atEnd) lg.scrollTop = lg.scrollHeight;
    const pl = $('#mPlog');
    pl.textContent = r.panel_log.length ? r.panel_log.join('\n') : '—';
    pl.scrollTop = pl.scrollHeight;
    $('#mStart').disabled = r.state !== 'stopped';
    $('#mStop').disabled = r.state === 'stopped';
}

/* butonlar */
$('#mSave').onclick = async () => {
    const r = await api('save_channel', collect(), chSlug);
    toast(r.msg, r.ok);
    if (r.ok) { CH = r.cfg; window.__CHANNELS__[chSlug] = r.cfg; renderGrid(); }
};
$('#mStart').onclick = async () => {
    await api('save_channel', collect(), chSlug);
    const r = await api('start', {}, chSlug);
    toast(r.msg, r.ok);
    setTimeout(pollChannel, 800);
};
$('#mStop').onclick = async () => {
    if (!confirm('Durdurulsun mu?')) return;
    const r = await api('stop', {}, chSlug);
    toast(r.msg, r.ok);
    setTimeout(pollChannel, 800);
};
$('#mRestart').onclick = async () => {
    await api('save_channel', collect(), chSlug);
    const r = await api('restart', {}, chSlug);
    toast(r.msg, r.ok);
    setTimeout(pollChannel, 800);
};
$('#mCmd').onclick = async () => {
    await api('save_channel', collect(), chSlug);
    const r = await api('cmd', {}, chSlug);
    const o = $('#mCmdOut');
    o.classList.remove('hidden');
    o.textContent = r.ok ? r.cmd : r.msg;
};
$('#mCopy').onclick = () => {
    const url = <?= json_encode(base_url()) ?> + (chSlug ? '/' + chSlug : '') + '/hls/stream.m3u8';
    navigator.clipboard.writeText(url);
    toast('Link kopyalandı');
};
$('#mPlay').onclick = () => {
    const v = $('#mVid'); v.classList.remove('hidden');
    const url = <?= json_encode(base_url()) ?> + (chSlug ? '/' + chSlug : '') + '/hls/stream.m3u8?t=' + Date.now();
    const go = () => {
        if (window.Hls && Hls.isSupported()) {
            if (v._h) v._h.destroy();
            v._h = new Hls();
            v._h.loadSource(url);
            v._h.attachMedia(v);
            v._h.on(Hls.Events.MANIFEST_PARSED, () => v.play());
        } else { v.src = url; v.play(); }
    };
    if (window.Hls) return go();
    const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/hls.js@1'; s.onload = go; document.head.appendChild(s);
};
$('#bDelete').onclick = async () => {
    if (chSlug === '') return toast('Ana kanal silinemez.', false);
    if (!confirm('Kanal ve tüm dosyaları silinsin mi?')) return;
    const r = await api('delete_channel', {}, chSlug);
    toast(r.msg, r.ok);
    if (r.ok) { closeModal(); renderGrid(); }
};

/* Yeni kanal */
$('#bNew').onclick = () => { $('#newModal').classList.add('open'); $('#nSlug').focus(); };
$('#nClose').onclick = () => $('#newModal').classList.remove('open');
$('#nSlug').addEventListener('input', e => { $('#nPreview').textContent = e.target.value || 'diginet'; });
$('#nCreate').onclick = async () => {
    const r = await api('create_channel', { slug: $('#nSlug').value, name: $('#nName').value });
    toast(r.msg, r.ok);
    if (r.ok) {
        $('#newModal').classList.remove('open');
        $('#nSlug').value = ''; $('#nName').value = '';
        location.reload();
    }
};

/* Genel ayarlar */
$('#bSettings').onclick = () => { $('#sFF').value = SETTINGS.ffmpeg_path; $('#setModal').classList.add('open'); };
$('#sClose').onclick = () => $('#setModal').classList.remove('open');
$('#sSave').onclick = async () => {
    const r = await api('settings', { ffmpeg_path: $('#sFF').value });
    toast(r.msg, r.ok);
    if (r.ok) location.reload();
};
$('#sTest').onclick = async () => {
    await api('settings', { ffmpeg_path: $('#sFF').value });
    const o = $('#sTestOut'); o.innerHTML = '<span class="text-slate-400">Test ediliyor…</span>';
    const r = await api('test');
    if (!r.ok) { o.innerHTML = '<div class="text-red-400">✗ ' + escapeHtml(r.msg) + '</div>'; return; }
    const li = (ok, t) => `<div class="${ok ? 'text-emerald-400' : 'text-amber-400'}">${ok ? '✓' : '✗'} ${t}</div>`;
    o.innerHTML = `<div class="text-slate-200 font-mono text-xs mb-2">${escapeHtml(r.version)}</div>` +
        li(r.drawtext, 'drawtext') + li(r.overlay, 'overlay') + li(r.libx264, 'libx264') +
        li(r.nvenc, 'nvenc') + li(r.qsv, 'qsv') + li(r.amf, 'amf');
};
$('#bPw').onclick = async () => {
    const r = await api('password', { new_user: $('#pwUser').value, cur_pass: $('#pwCur').value, new_pass: $('#pwNew').value });
    toast(r.msg, r.ok);
    if (r.ok) setTimeout(() => location.reload(), 1200);
};

/* Tümünü başlat/durdur */
$('#bStartAll').onclick = async () => {
    if (!confirm('Tüm aktif kanallar başlatılsın mı?')) return;
    const r = await api('start_all');
    toast(r.msg, r.ok);
    setTimeout(renderGrid, 1500);
};
$('#bStopAll').onclick = async () => {
    if (!confirm('Tüm kanallar durdurulsun mu?')) return;
    const r = await api('stop_all');
    toast(r.msg, r.ok);
    setTimeout(renderGrid, 1000);
};

/* İlk yükleme */
window.__CHANNELS__ = <?= json_encode($chans, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG) ?>;
renderGrid();
setInterval(renderGrid, 5000);
</script>
</body>
</html>
