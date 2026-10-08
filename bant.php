<?php
/*
 |=====================================================================
 |  YAYIN PANELİ  -  Tek dosya PHP + FFmpeg (Windows Server 2022 / VDS)
 |  FFmpeg varsayılan yol : C:\ffmpeg\bin\ffmpeg.exe
 |  Varsayılan giriş      : admin / admin123   (giriş yaptıktan sonra değiştirin!)
 |  Kurulum               : Bu dosyayı (index.php) IIS/XAMPP/WAMP klasörüne atın.
 |                          Klasörde "data" ve "hls" klasörleri otomatik oluşur.
 |  Çoklu Yayın           : 4 tekil + 1 M3U liste desteği
 |  HLS Slug              : Her yayın için özel klasör adı (ZemTv, fluxtv vb.)
 |=====================================================================
*/
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Istanbul');
@set_time_limit(60);

define('DS', DIRECTORY_SEPARATOR);
define('DATA', __DIR__ . DS . 'data');
define('HLSDIR', __DIR__ . DS . 'hls');
define('STREAMS', __DIR__ . DS . 'streams');

/* ---------------------------------------------------------------- klasörler */
foreach ([DATA, DATA . DS . 'sessions', HLSDIR, STREAMS] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}
if (!is_file(DATA . DS . 'web.config')) {
    @file_put_contents(DATA . DS . 'web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false" applyToWebDAV="false" /></requestFiltering></security></system.webServer></configuration>');
}
if (!is_file(DATA . DS . '.htaccess')) {
    @file_put_contents(DATA . DS . '.htaccess', "Require all denied\nDeny from all\n");
}
if (!is_file(HLSDIR . DS . 'web.config')) {
    @file_put_contents(HLSDIR . DS . 'web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><staticContent><remove fileExtension=".m3u8" /><mimeMap fileExtension=".m3u8" mimeType="application/vnd.apple.mpegurl" /><remove fileExtension=".ts" /><mimeMap fileExtension=".ts" mimeType="video/mp2t" /></staticContent><httpProtocol><customHeaders><add name="Access-Control-Allow-Origin" value="*" /><add name="Cache-Control" value="no-cache" /></customHeaders></httpProtocol></system.webServer></configuration>');
}
if (!is_file(HLSDIR . DS . '.htaccess')) {
    @file_put_contents(HLSDIR . DS . '.htaccess', "Header set Access-Control-Allow-Origin \"*\"\nHeader set Cache-Control \"no-cache\"\nAddType application/vnd.apple.mpegurl .m3u8\nAddType video/mp2t .ts\n");
}

/* ------------------------------------------------------------------ oturum */
if (is_writable(DATA . DS . 'sessions')) session_save_path(DATA . DS . 'sessions');
session_name('YAYINPANEL');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* --------------------------------------------------------------- yardımcılar */
function p($n) { return DATA . DS . $n; }
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
function panel_log($m) {
    @file_put_contents(p('panel.log'), '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n", FILE_APPEND | LOCK_EX);
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
function slugify($s, $def = 'ZemTv') {
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$s);
    return $s !== '' ? $s : $def;
}

/* ------------------------------------------------------------------ ayarlar */
function defaults() {
    return [
        'admin_user' => 'admin',
        'admin_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'pass_default' => true,
        'ffmpeg_path' => 'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'input_url' => '',
        'input_ua' => '',
        'input_extra' => '',
        'input_realtime' => 0,
        'mode' => 'encode',
        'out_type' => 'rtmp',
        'out_url' => '',
        'hls_time' => 4,
        'hls_list' => 6,
        'hls_slug' => 'ZemTv',
        'logo_enabled' => 1,
        'logo_file' => '',
        'logo_pos' => 'tr',
        'logo_width' => 160,
        'logo_opacity' => 100,
        'logo_margin' => 25,
        'band_enabled' => 1,
        'band_text' => 'Canlı yayına hoş geldiniz  •  Bu alana istediğiniz duyuruyu yazabilirsiniz',
        'band_style' => 'scroll',
        'band_pos' => 'bottom',
        'band_height' => 50,
        'band_font' => 'arial',
        'band_font_size' => 28,
        'band_font_color' => '#ffffff',
        'band_bg_color' => '#b91c1c',
        'band_bg_opacity' => 85,
        'band_speed' => 120,
        'res' => '1280x720',
        'fps' => 25,
        'vcodec' => 'libx264',
        'preset' => 'veryfast',
        'vbitrate' => 2500,
        'abitrate' => 128,
        'gop' => 2,
        'autorestart' => 1,
        'restart_delay' => 5,
        // Çoklu yayın ayarları
        'multi_enabled' => 0,
        'multi_streams' => [],
        'm3u_enabled' => 0,
        'm3u_url' => '',
        'm3u_auto_refresh' => 1,
        'm3u_refresh_interval' => 60,
        'm3u_current_index' => 0,
        'm3u_last_refresh' => 0,
        'm3u_channels' => [],
    ];
}
function cfg() {
    $c = defaults();
    if (is_file(p('config.json'))) {
        $j = json_decode((string)@file_get_contents(p('config.json')), true);
        if (is_array($j)) $c = array_merge($c, $j);
    }
    return $c;
}
function save_cfg($c) {
    return file_put_contents(p('config.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
if (!is_file(p('config.json'))) save_cfg(defaults());

$FONTS = ['arial' => 'arial.ttf', 'arialbd' => 'arialbd.ttf', 'tahoma' => 'tahoma.ttf', 'verdana' => 'verdana.ttf', 'segoeui' => 'segoeui.ttf', 'calibri' => 'calibri.ttf', 'impact' => 'impact.ttf'];
$RES = ['orig', '1920x1080', '1280x720', '854x480', '640x360'];
$VC = ['libx264', 'h264_nvenc', 'h264_qsv', 'h264_amf'];
$PRESETS = ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium'];

function parse_post($post, $old) {
    global $FONTS, $RES, $VC, $PRESETS;
    $c = $old;
    foreach (['ffmpeg_path', 'input_url', 'input_ua', 'input_extra', 'out_url', 'm3u_url', 'hls_slug'] as $k) {
        if (isset($post[$k])) $c[$k] = clean_str($post[$k]);
    }
    if ($c['ffmpeg_path'] === '') $c['ffmpeg_path'] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
    $c['hls_slug'] = slugify($c['hls_slug'] ?? 'ZemTv', 'ZemTv');
    $c['mode'] = pick($post['mode'] ?? '', ['encode', 'copy'], 'encode');
    $c['out_type'] = pick($post['out_type'] ?? '', ['rtmp', 'hls', 'mpegts'], 'rtmp');
    $c['logo_pos'] = pick($post['logo_pos'] ?? '', ['tl', 'tr', 'bl', 'br', 'center'], 'tr');
    $c['band_style'] = pick($post['band_style'] ?? '', ['scroll', 'static'], 'scroll');
    $c['band_pos'] = pick($post['band_pos'] ?? '', ['bottom', 'top'], 'bottom');
    $c['band_font'] = pick($post['band_font'] ?? '', array_keys($FONTS), 'arial');
    $c['res'] = pick($post['res'] ?? '', $RES, '1280x720');
    $c['vcodec'] = pick($post['vcodec'] ?? '', $VC, 'libx264');
    $c['preset'] = pick($post['preset'] ?? '', $PRESETS, 'veryfast');
    $ints = [
        'hls_time' => [1, 20, 4], 'hls_list' => [3, 30, 6], 'logo_width' => [20, 1000, 160], 'logo_opacity' => [5, 100, 100],
        'logo_margin' => [0, 300, 25], 'band_height' => [20, 200, 50], 'band_font_size' => [10, 100, 28], 'band_bg_opacity' => [0, 100, 85],
        'band_speed' => [20, 600, 120], 'fps' => [0, 60, 25], 'vbitrate' => [200, 20000, 2500], 'abitrate' => [32, 512, 128],
        'gop' => [1, 10, 2], 'restart_delay' => [1, 120, 5], 'm3u_refresh_interval' => [10, 600, 60],
    ];
    foreach ($ints as $k => $r) $c[$k] = iv($post[$k] ?? null, $r[0], $r[1], $r[2]);
    foreach (['input_realtime', 'logo_enabled', 'band_enabled', 'autorestart', 'multi_enabled', 'm3u_enabled', 'm3u_auto_refresh'] as $k) $c[$k] = !empty($post[$k]) ? 1 : 0;
    $c['band_font_color'] = hexcol($post['band_font_color'] ?? '', '#ffffff');
    $c['band_bg_color'] = hexcol($post['band_bg_color'] ?? '', '#b91c1c');
    $t = str_replace(["\r\n", "\r", "\n"], '  •  ', (string)($post['band_text'] ?? ''));
    $t = preg_replace('/[\x00-\x1F\x7F]/', '', $t);
    $c['band_text'] = function_exists('mb_substr') ? mb_substr(trim($t), 0, 1500, 'UTF-8') : substr(trim($t), 0, 1500);

    // Çoklu yayınları parse et (slug dahil)
    if (isset($post['multi_streams']) && is_array($post['multi_streams'])) {
        $streams = [];
        foreach ($post['multi_streams'] as $idx => $s) {
            if (!is_array($s)) continue;
            $slug = slugify($s['slug'] ?? '', 'yayin' . ($idx + 1));
            // Slug boşsa isimden türet
            if (empty($s['slug'])) {
                $tmp = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '_', clean_str($s['name'] ?? '')));
                if ($tmp !== '') $slug = $tmp;
            }
            $streams[] = [
                'name' => clean_str($s['name'] ?? 'Yayın ' . ($idx + 1)),
                'url' => clean_str($s['url'] ?? ''),
                'slug' => $slug,
                'enabled' => !empty($s['enabled']) ? 1 : 0,
                'type' => pick($s['type'] ?? '', ['m3u8', 'mp4', 'rtmp', 'other'], 'm3u8'),
            ];
        }
        while (count($streams) < 4) {
            $streams[] = [
                'name' => 'Yayın ' . (count($streams) + 1),
                'url' => '',
                'slug' => 'yayin' . (count($streams) + 1),
                'enabled' => 0,
                'type' => 'm3u8',
            ];
        }
        $c['multi_streams'] = array_slice($streams, 0, 4);
    }
    return $c;
}
function write_band($c) {
    @file_put_contents(p('band.txt'), $c['band_text']);
}

/* -------------------------------------------------- M3U liste işleme */
function parse_m3u($url) {
    $channels = [];
    $content = '';
    if (preg_match('~^https?://~i', $url)) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']]);
        $content = @file_get_contents($url, false, $ctx);
    } elseif (is_file($url)) {
        $content = @file_get_contents($url);
    }
    if (!$content) return $channels;
    $lines = preg_split('/[\r\n]+/', $content);
    $current = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#EXTM3U') === 0) continue;
        if (strpos($line, '#EXTINF:') === 0) {
            $current = ['info' => $line];
            if (preg_match('/,(.+)$/', $line, $m)) $current['name'] = trim($m[1]);
            if (preg_match('/tvg-logo="([^"]+)"/', $line, $m)) $current['logo'] = $m[1];
            if (preg_match('/group-title="([^"]+)"/', $line, $m)) $current['group'] = $m[1];
        } elseif ($line[0] !== '#') {
            if (!empty($current)) {
                $current['url'] = $line;
                $channels[] = $current;
                $current = [];
            }
        }
    }
    return $channels;
}

function get_active_stream_url($c) {
    // M3U liste aktifse ondan al
    if ($c['m3u_enabled'] && !empty($c['m3u_channels'])) {
        $idx = (int)$c['m3u_current_index'];
        if (isset($c['m3u_channels'][$idx])) {
            return $c['m3u_channels'][$idx]['url'];
        }
        return $c['m3u_channels'][0]['url'] ?? '';
    }
    // Çoklu yayın aktifse ilk enabled olanı al
    if ($c['multi_enabled'] && !empty($c['multi_streams'])) {
        foreach ($c['multi_streams'] as $s) {
            if (!empty($s['enabled']) && $s['url'] !== '') return $s['url'];
        }
    }
    // Tekli yayın
    return $c['input_url'];
}

/* Aktif yayının slug'ını döndür */
function get_active_slug($c) {
    // M3U liste — sabit slug
    if ($c['m3u_enabled'] && !empty($c['m3u_channels'])) {
        return slugify($c['hls_slug'] ?? 'ZemTv', 'ZemTv');
    }
    // Çoklu yayın — aktif olanın slug'ı
    if ($c['multi_enabled'] && !empty($c['multi_streams'])) {
        foreach ($c['multi_streams'] as $i => $s) {
            if (!empty($s['enabled']) && $s['url'] !== '') {
                return slugify($s['slug'] ?? '', 'yayin' . ($i + 1));
            }
        }
    }
    // Tekli yayın
    return slugify($c['hls_slug'] ?? 'ZemTv', 'ZemTv');
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
    $s = @file_get_contents(p($f));
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

function build_args($c, &$err) {
    global $FONTS;
    $err = '';
    $in = get_active_stream_url($c);
    if ($in === '') { $err = 'Kaynak (giriş) linki boş.'; return []; }
    $a = ['-hide_banner', '-y', '-stats_period', '3'];
    if (preg_match('~^https?://~i', $in)) {
        array_push($a, '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_at_eof', '1', '-reconnect_delay_max', '5');
    }
    if ($c['input_ua'] !== '') array_push($a, '-user_agent', $c['input_ua']);
    if ($c['input_realtime']) $a[] = '-re';
    if ($c['input_extra'] !== '') foreach (preg_split('/\s+/', $c['input_extra']) as $x) if ($x !== '') $a[] = $x;
    array_push($a, '-i', $in);

    if ($c['mode'] === 'copy') {
        array_push($a, '-c', 'copy');
    } else {
        $useLogo = $c['logo_enabled'] && $c['logo_file'] && is_file(p($c['logo_file']));
        $useBand = $c['band_enabled'] && trim($c['band_text']) !== '';
        if ($useLogo) array_push($a, '-i', $c['logo_file']); // çalışma dizini = data

        $vf = [];
        if ($c['res'] !== 'orig') {
            list($w, $hh) = explode('x', $c['res']);
            $vf[] = "scale=$w:$hh:force_original_aspect_ratio=decrease";
            $vf[] = "pad=$w:$hh:(ow-iw)/2:(oh-ih)/2";
            $vf[] = 'setsar=1';
        }
        if ($c['fps'] > 0) $vf[] = 'fps=' . $c['fps'];
        if ($useBand) {
            $bh = (int)$c['band_height'];
            $op = number_format($c['band_bg_opacity'] / 100, 2, '.', '');
            $bgc = '0x' . substr($c['band_bg_color'], 1);
            $fc = '0x' . substr($c['band_font_color'], 1);
            $by = $c['band_pos'] === 'top' ? '0' : "ih-$bh";
            $vf[] = "drawbox=x=0:y=$by:w=iw:h=$bh:color=$bgc@$op:t=fill";
            $ty = $c['band_pos'] === 'top' ? "($bh-text_h)/2" : "h-$bh+($bh-text_h)/2";
            $tx = $c['band_style'] === 'scroll' ? "w-mod(t*{$c['band_speed']},w+text_w)" : "(w-text_w)/2";
            $vf[] = "drawtext=fontfile=font.ttf:textfile=band.txt:reload=1:expansion=none:fontsize={$c['band_font_size']}:fontcolor=$fc:shadowcolor=black@0.5:shadowx=1:shadowy=1:x='$tx':y='$ty'";
        }
        if (!$vf) $vf[] = 'null';
        $graph = '[0:v:0]' . implode(',', $vf);
        if ($useLogo) {
            $m = (int)$c['logo_margin'];
            $pos = ['tl' => "x=$m:y=$m", 'tr' => "x=W-w-$m:y=$m", 'bl' => "x=$m:y=H-h-$m", 'br' => "x=W-w-$m:y=H-h-$m", 'center' => 'x=(W-w)/2:y=(H-h)/2'][$c['logo_pos']];
            $lg = '[1:v]scale=' . (int)$c['logo_width'] . ':-1,format=rgba';
            if ($c['logo_opacity'] < 100) $lg .= ',colorchannelmixer=aa=' . number_format($c['logo_opacity'] / 100, 2, '.', '');
            $graph .= '[bg];' . $lg . '[lg];[bg][lg]overlay=' . $pos . ',format=yuv420p[v]';
        } else {
            $graph .= ',format=yuv420p[v]';
        }
        array_push($a, '-filter_complex', $graph, '-map', '[v]', '-map', '0:a:0?');

        $fpsOut = $c['fps'] > 0 ? $c['fps'] : 25;
        $vb = (int)$c['vbitrate'];
        switch ($c['vcodec']) {
            case 'h264_nvenc': array_push($a, '-c:v', 'h264_nvenc', '-preset', 'p4', '-profile:v', 'main'); break;
            case 'h264_qsv':   array_push($a, '-c:v', 'h264_qsv', '-preset', 'veryfast'); break;
            case 'h264_amf':   array_push($a, '-c:v', 'h264_amf', '-quality', 'speed'); break;
            default:           array_push($a, '-c:v', 'libx264', '-preset', $c['preset'], '-profile:v', 'main', '-sc_threshold', '0');
        }
        array_push($a, '-b:v', $vb . 'k', '-maxrate', $vb . 'k', '-bufsize', ($vb * 2) . 'k', '-g', (string)($fpsOut * $c['gop']));
        array_push($a, '-c:a', 'aac', '-b:a', $c['abitrate'] . 'k', '-ar', '44100', '-ac', '2');
    }

    if ($c['out_type'] === 'hls') {
        $slug = get_active_slug($c);
        $outDir = HLSDIR . DS . $slug;
        if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
        $d = fs($outDir);
        array_push($a, '-f', 'hls', '-hls_time', (string)$c['hls_time'], '-hls_list_size', (string)$c['hls_list'],
            '-hls_flags', 'delete_segments+independent_segments+omit_endlist', '-hls_segment_filename', $d . '/seg_%05d.ts', $d . '/stream.m3u8');
    } else {
        if ($c['out_url'] === '') { $err = 'Çıkış (yayın) linki boş.'; return []; }
        array_push($a, '-f', $c['out_type'] === 'rtmp' ? 'flv' : 'mpegts', $c['out_url']);
    }
    return $a;
}

function write_runner($c) {
    $tpl = <<<'PS'
$ErrorActionPreference = 'Continue'
$base = '{{BASE}}'
$auto = {{AUTO}}
$delay = {{DELAY}}
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
    $p = Start-Process -FilePath $ff -ArgumentList $a -WorkingDirectory $base -WindowStyle Hidden -PassThru -RedirectStandardError "$base\ffmpeg.log"
    $null = $p.Handle
    Set-Content -Path "$base\ffmpeg.pid" -Value $p.Id -Encoding ASCII
    $p.WaitForExit()
    $code = $p.ExitCode
  } catch { Log ("Hata: " + $_.Exception.Message) }
  Remove-Item "$base\ffmpeg.pid" -ErrorAction SilentlyContinue
  if (Test-Path "$base\stop.flag") { Log "Yayin kullanici tarafindan durduruldu"; break }
  try { Get-Content "$base\ffmpeg.log" -Tail 4 | ForEach-Object { Log ("  > " + $_) } } catch {}
  if (-not $auto) { Log "FFmpeg durdu (kod $code). Otomatik yeniden baslatma kapali."; break }
  Log "FFmpeg durdu (kod $code). $delay sn sonra yeniden baslatilacak."
  Start-Sleep -Seconds $delay
  if (Test-Path "$base\stop.flag") { break }
}
Remove-Item "$base\runner.pid" -ErrorAction SilentlyContinue
PS;
    $ps = str_replace(['{{BASE}}', '{{AUTO}}', '{{DELAY}}'], [str_replace("'", "''", DATA), $c['autorestart'] ? '$true' : '$false', (int)$c['restart_delay']], $tpl);
    return file_put_contents(p('runner.ps1'), $ps);
}

function get_status() {
    $c = cfg();
    $r = read_pid('runner.pid');
    $f = read_pid('ffmpeg.pid');
    $ra = pid_alive($r);
    $fa = $ra && $f && pid_alive($f);
    if (!$ra && is_file(p('runner.pid'))) { @unlink(p('runner.pid')); @unlink(p('ffmpeg.pid')); }
    $st = json_decode((string)@file_get_contents(p('state.json')), true) ?: [];
    $uptime = ($fa && !empty($st['started'])) ? time() - (int)$st['started'] : 0;
    $log = tail_file(p('ffmpeg.log'), 30000);
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
    $plog = tail_file(p('panel.log'), 6000);
    $plines = array_values(array_filter(preg_split('/[\r\n]+/', ltrim($plog, "\xEF\xBB\xBF")), 'strlen'));
    $slug = get_active_slug($c);
    return [
        'ok' => true,
        'state' => $fa ? 'running' : ($ra ? 'waiting' : 'stopped'),
        'runner' => $r, 'ffmpeg' => $f, 'uptime' => $uptime,
        'stats' => $stats,
        'log' => array_slice($lines, -40),
        'panel_log' => array_slice($plines, -14),
        'hls_url' => base_url() . '/hls/' . $slug . '/stream.m3u8',
        'hls_slug' => $slug,
        'active_url' => get_active_stream_url($c),
        'm3u_index' => (int)$c['m3u_current_index'],
        'm3u_total' => count($c['m3u_channels'] ?? []),
    ];
}

function start_stream() {
    $c = cfg();
    if (!function_exists('exec')) return ['ok' => false, 'msg' => 'PHP exec() fonksiyonu kapalı (disable_functions). php.ini dosyasından açın.'];
    if (DS !== '\\') return ['ok' => false, 'msg' => 'Bu panel Windows sunucu için yazılmıştır.'];
    $s = get_status();
    if ($s['state'] !== 'stopped') return ['ok' => false, 'msg' => 'Yayın zaten çalışıyor.'];
    if (!is_file($c['ffmpeg_path'])) return ['ok' => false, 'msg' => 'FFmpeg bulunamadı: ' . $c['ffmpeg_path']];
    $args = build_args($c, $err);
    if ($err) return ['ok' => false, 'msg' => $err];

    // yazı tipini data klasörüne kopyala (filtrelerde göreli yol kullanmak için)
    global $FONTS;
    $win = getenv('WINDIR') ?: 'C:\\Windows';
    $src = $win . '\\Fonts\\' . $FONTS[$c['band_font']];
    if (is_file($src)) @copy($src, p('font.ttf'));
    elseif ($c['mode'] === 'encode' && $c['band_enabled']) return ['ok' => false, 'msg' => 'Yazı tipi bulunamadı: ' . $src];
    write_band($c);

    if ($c['out_type'] === 'hls') {
        $slug = get_active_slug($c);
        $outDir = HLSDIR . DS . $slug;
        if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
        foreach (glob($outDir . DS . '*.{ts,m3u8}', GLOB_BRACE) ?: [] as $f) @unlink($f);
    }
    file_put_contents(p('args.txt'), implode(' ', array_map('q', $args)));
    file_put_contents(p('ffpath.txt'), $c['ffmpeg_path']);
    file_put_contents(p('ffmpeg.log'), '');
    @unlink(p('stop.flag'));
    @unlink(p('runner.pid'));
    @unlink(p('ffmpeg.pid'));
    write_runner($c);
    file_put_contents(p('state.json'), json_encode(['started' => time()]));
    panel_log('Yayın başlatma komutu verildi. Kaynak: ' . get_active_stream_url($c) . ' | Slug: ' . get_active_slug($c));
    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' . p('runner.ps1') . '"';
    if (!launch($cmd)) return ['ok' => false, 'msg' => 'İşlem başlatılamadı (COM/proc_open kapalı olabilir).'];
    for ($i = 0; $i < 8; $i++) { usleep(500000); if (read_pid('ffmpeg.pid')) break; }
    $s = get_status();
    return ['ok' => $s['state'] !== 'stopped', 'msg' => $s['state'] !== 'stopped' ? 'Yayın başlatıldı.' : 'Yayın başlamadı. Log bölümünü kontrol edin.'];
}

function stop_stream() {
    @file_put_contents(p('stop.flag'), '1');
    $r = read_pid('runner.pid');
    $f = read_pid('ffmpeg.pid');
    if ($r) @exec('taskkill /F /T /PID ' . $r . ' 2>NUL');
    if ($f) @exec('taskkill /F /T /PID ' . $f . ' 2>NUL');
    @unlink(p('runner.pid'));
    @unlink(p('ffmpeg.pid'));
    panel_log('Yayın durduruldu (panelden).');
    return ['ok' => true, 'msg' => 'Yayın durduruldu.'];
}

/* ===================================================================== GİRİŞ */
$authed = !empty($_SESSION['auth']);
$cfgNow = cfg();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Giriş sayfası ayrı bir parametre ile açılır
$showLogin = isset($_GET['login']);
$showPanel = isset($_GET['panel']) && $authed;

if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'])) {
    $ok = hash_equals($cfgNow['admin_user'], (string)$_POST['login_user']) && password_verify((string)($_POST['login_pass'] ?? ''), $cfgNow['admin_hash']);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ?panel=1');
        exit;
    }
    sleep(1);
    $loginErr = 'Kullanıcı adı veya şifre hatalı.';
}

/* ====================================================================== API */
if (isset($_GET['api'])) {
    $api = $_GET['api'];

    if ($api === 'logo') {
        $f = $cfgNow['logo_file'];
        if ($f && is_file(p($f))) {
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Cache-Control: no-store');
            readfile(p($f));
        } else { http_response_code(404); }
        exit;
    }
    if ($api === 'status') {
        if (!$authed) jout(['ok' => false, 'msg' => 'Oturum yok'], 401);
        jout(get_status());
    }
    if ($api === 'm3u_parse') {
        if (!$authed) jout(['ok' => false, 'msg' => 'Oturum yok'], 401);
        $url = clean_str($_POST['url'] ?? '');
        if ($url === '') jout(['ok' => false, 'msg' => 'URL boş']);
        $ch = parse_m3u($url);
        $cfgNow['m3u_channels'] = $ch;
        $cfgNow['m3u_last_refresh'] = time();
        if (empty($ch)) {
            jout(['ok' => false, 'msg' => 'M3U listesi okunamadı veya boş.']);
        }
        save_cfg($cfgNow);
        jout(['ok' => true, 'msg' => count($ch) . ' kanal bulundu.', 'channels' => $ch]);
    }
    if ($api === 'm3u_select') {
        if (!$authed) jout(['ok' => false, 'msg' => 'Oturum yok'], 401);
        $idx = iv($_POST['index'] ?? 0, 0, 9999, 0);
        $cfgNow['m3u_current_index'] = $idx;
        save_cfg($cfgNow);
        jout(['ok' => true, 'msg' => 'Kanal seçildi.', 'index' => $idx]);
    }
    if ($api === 'm3u_refresh') {
        if (!$authed) jout(['ok' => false, 'msg' => 'Oturum yok'], 401);
        $ch = parse_m3u($cfgNow['m3u_url']);
        $cfgNow['m3u_channels'] = $ch;
        $cfgNow['m3u_last_refresh'] = time();
        save_cfg($cfgNow);
        jout(['ok' => true, 'msg' => count($ch) . ' kanal güncellendi.', 'channels' => $ch]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['ok' => false, 'msg' => 'Geçersiz istek'], 405);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) jout(['ok' => false, 'msg' => 'Güvenlik anahtarı geçersiz, sayfayı yenileyin.'], 403);

    switch ($api) {
        case 'save':
            $c = parse_post($_POST, $cfgNow);
            save_cfg($c);
            write_band($c);
            jout(['ok' => true, 'msg' => 'Ayarlar kaydedildi.', 'cfg' => $c]);

        case 'start': jout(start_stream());
        case 'stop': jout(stop_stream());
        case 'restart':
            stop_stream();
            sleep(2);
            jout(start_stream());

        case 'cmd':
            $args = build_args($cfgNow, $err);
            if ($err) jout(['ok' => false, 'msg' => $err]);
            jout(['ok' => true, 'cmd' => '"' . $cfgNow['ffmpeg_path'] . '" ' . implode(' ', array_map('q', $args))]);

        case 'logo_upload':
            if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) jout(['ok' => false, 'msg' => 'Dosya yüklenemedi (php.ini upload_max_filesize kontrol edin).']);
            $f = $_FILES['logo'];
            if ($f['size'] > 8 * 1024 * 1024) jout(['ok' => false, 'msg' => 'Dosya 8 MB üstünde olamaz.']);
            $info = @getimagesize($f['tmp_name']);
            $map = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($map[$info[2]])) jout(['ok' => false, 'msg' => 'Sadece PNG, JPG, GIF, WEBP kabul edilir.']);
            foreach (glob(DATA . DS . 'logo.*') ?: [] as $old) @unlink($old);
            $name = 'logo.' . $map[$info[2]];
            if (!move_uploaded_file($f['tmp_name'], p($name))) jout(['ok' => false, 'msg' => 'Dosya kaydedilemedi (klasör yazma izni).']);
            $cfgNow['logo_file'] = $name;
            save_cfg($cfgNow);
            jout(['ok' => true, 'msg' => 'Logo yüklendi. (Yayın açıksa yeniden başlatın)', 'file' => $name]);

        case 'logo_delete':
            foreach (glob(DATA . DS . 'logo.*') ?: [] as $old) @unlink($old);
            $cfgNow['logo_file'] = '';
            save_cfg($cfgNow);
            jout(['ok' => true, 'msg' => 'Logo silindi.']);

        case 'test':
            $ff = $cfgNow['ffmpeg_path'];
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

        case 'password':
            $u = clean_str($_POST['new_user'] ?? '');
            $cur = (string)($_POST['cur_pass'] ?? '');
            $new = (string)($_POST['new_pass'] ?? '');
            if (!password_verify($cur, $cfgNow['admin_hash'])) jout(['ok' => false, 'msg' => 'Mevcut şifre yanlış.']);
            if (strlen($new) < 6) jout(['ok' => false, 'msg' => 'Yeni şifre en az 6 karakter olmalı.']);
            if ($u === '') $u = $cfgNow['admin_user'];
            $cfgNow['admin_user'] = $u;
            $cfgNow['admin_hash'] = password_hash($new, PASSWORD_DEFAULT);
            $cfgNow['pass_default'] = false;
            save_cfg($cfgNow);
            jout(['ok' => true, 'msg' => 'Giriş bilgileri güncellendi.']);
    }
    jout(['ok' => false, 'msg' => 'Bilinmeyen işlem'], 400);
}

/* =============================================================== GİRİŞ SAYFASI */
if ($showLogin && !$authed) {
    ?><!DOCTYPE html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zemmedya Yayıncılık · Giriş</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4" style="background-image:radial-gradient(circle at 20% 10%,#312e8180,transparent 40%),radial-gradient(circle at 80% 90%,#0f766e60,transparent 40%)">
<form method="post" class="w-full max-w-sm bg-slate-900/80 backdrop-blur border border-slate-800 rounded-2xl p-8 shadow-2xl">
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-fuchsia-500 flex items-center justify-center text-xl">📡</div>
        <div><h1 class="text-xl font-bold">Zemmedya</h1><p class="text-xs text-slate-400">Yayıncılık Yönetim Sistemi</p></div>
    </div>
    <?php if (!empty($loginErr)): ?><div class="mb-4 text-sm bg-red-500/10 border border-red-500/40 text-red-300 rounded-lg px-3 py-2"><?= h($loginErr) ?></div><?php endif; ?>
    <label class="block text-xs text-slate-400 mb-1">Kullanıcı adı</label>
    <input name="login_user" autofocus required class="w-full mb-4 rounded-lg bg-slate-950 border border-slate-700 px-3 py-2.5 focus:outline-none focus:border-indigo-500">
    <label class="block text-xs text-slate-400 mb-1">Şifre</label>
    <input name="login_pass" type="password" required class="w-full mb-6 rounded-lg bg-slate-950 border border-slate-700 px-3 py-2.5 focus:outline-none focus:border-indigo-500">
    <button class="w-full py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 font-semibold transition">Giriş Yap</button>
    <p class="text-center text-xs text-slate-500 mt-4"><a href="?" class="hover:text-indigo-400">← Ana sayfaya dön</a></p>
</form></body></html><?php
    exit;
}

/* ============================================================= PANEL SAYFASI */
if ($showPanel) {
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
<title>Zemmedya Yayın Paneli</title>
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
    .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1rem;border-radius:.6rem;font-size:.85rem;font-weight:600;transition:.15s;cursor:pointer}
    .btn:disabled{opacity:.4;cursor:not-allowed}
    .tab{padding:.5rem .9rem;border-radius:.6rem;font-size:.82rem;color:#94a3b8;cursor:pointer;white-space:nowrap;border:1px solid transparent}
    .tab:hover{color:#e2e8f0}
    .tab.active{background:#4f46e5;color:#fff}
    .tabp{display:none}.tabp.active{display:block}
    .term{background:#020617;border:1px solid #1e293b;border-radius:.6rem;padding:.75rem;font:12px/1.5 Consolas,monospace;color:#86efac;height:15rem;overflow:auto;white-space:pre-wrap;word-break:break-all}
    @keyframes mq{from{transform:translateX(100%)}to{transform:translateX(-100%)}}
    .mq{display:inline-block;white-space:nowrap;animation:mq 12s linear infinite}
    @keyframes pulse2{0%,100%{opacity:1}50%{opacity:.35}}
    .live{animation:pulse2 1.2s infinite}
    .sw{position:relative;width:2.6rem;height:1.4rem;flex:none}
    .sw input{opacity:0;position:absolute;inset:0;z-index:2;cursor:pointer;width:100%;height:100%;margin:0}
    .sw span{position:absolute;inset:0;background:#334155;border-radius:999px;transition:.2s}
    .sw span:after{content:"";position:absolute;width:1.05rem;height:1.05rem;left:.18rem;top:.17rem;background:#fff;border-radius:50%;transition:.2s}
    .sw input:checked+span{background:#10b981}
    .sw input:checked+span:after{transform:translateX(1.2rem)}
</style>
</head>
<body class="text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto p-4 md:p-6">

    <header class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-fuchsia-500 flex items-center justify-center text-xl">📡</div>
            <div><h1 class="text-xl font-bold leading-tight">Zemmedya Yayın Paneli</h1><p class="text-xs text-slate-400">Çoklu Yayın · Logo · Alt Bant · M3U Liste</p></div>
        </div>
        <div class="flex items-center gap-3">
            <a href="?" class="text-xs text-slate-400 hover:text-indigo-400 hidden sm:inline">🏠 Ana Sayfa</a>
            <span class="text-xs text-slate-400 hidden sm:inline">👤 <?= h($cfgNow['admin_user']) ?></span>
            <a href="?logout=1" class="btn bg-slate-800 hover:bg-slate-700 text-slate-200">Çıkış</a>
        </div>
    </header>

    <?php if (!empty($cfgNow['pass_default'])): ?>
    <div class="mb-4 text-sm bg-amber-500/10 border border-amber-500/40 text-amber-200 rounded-xl px-4 py-3">
        ⚠️ Varsayılan şifre (<b>admin / admin123</b>) kullanılıyor. Lütfen <b>Sistem</b> sekmesinden değiştirin.
    </div>
    <?php endif; ?>

    <section class="card mb-5">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div id="pill" class="px-4 py-2 rounded-full bg-slate-800 text-slate-300 text-sm font-bold flex items-center gap-2">
                    <span id="dot" class="w-2.5 h-2.5 rounded-full bg-slate-500"></span><span id="pillTxt">Kontrol ediliyor…</span>
                </div>
                <div class="text-sm text-slate-400">Süre: <span id="uptime" class="text-slate-100 font-mono">00:00:00</span></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button id="bSave" class="btn bg-slate-700 hover:bg-slate-600">💾 Kaydet</button>
                <button id="bStart" class="btn bg-emerald-600 hover:bg-emerald-500">▶ Yayını Başlat</button>
                <button id="bRestart" class="btn bg-amber-600 hover:bg-amber-500">⟲ Yeniden Başlat</button>
                <button id="bStop" class="btn bg-red-600 hover:bg-red-500">■ Durdur</button>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4">
            <div class="bg-slate-950 rounded-lg p-3 border border-slate-800"><div class="text-[11px] text-slate-500">FPS</div><div id="sFps" class="font-mono text-lg">-</div></div>
            <div class="bg-slate-950 rounded-lg p-3 border border-slate-800"><div class="text-[11px] text-slate-500">Bitrate</div><div id="sBr" class="font-mono text-lg">-</div></div>
            <div class="bg-slate-950 rounded-lg p-3 border border-slate-800"><div class="text-[11px] text-slate-500">Hız</div><div id="sSp" class="font-mono text-lg">-</div></div>
            <div class="bg-slate-950 rounded-lg p-3 border border-slate-800"><div class="text-[11px] text-slate-500">Yayın zamanı</div><div id="sTm" class="font-mono text-lg">-</div></div>
            <div class="bg-slate-950 rounded-lg p-3 border border-slate-800 col-span-2 md:col-span-1"><div class="text-[11px] text-slate-500">Gönderilen</div><div id="sSz" class="font-mono text-lg">-</div></div>
        </div>
    </section>

    <div class="grid lg:grid-cols-3 gap-5">
        <div class="lg:col-span-2">
            <nav class="flex gap-1 overflow-x-auto pb-2 mb-3" id="tabs">
                <div class="tab active" data-t="log">📊 Durum & Log</div>
                <div class="tab" data-t="multi">📺 Çoklu Yayın</div>
                <div class="tab" data-t="m3u">📋 M3U Liste</div>
                <div class="tab" data-t="src">🔗 Kaynak & Çıkış</div>
                <div class="tab" data-t="logo">🖼 Logo</div>
                <div class="tab" data-t="band">📰 Alt Bant</div>
                <div class="tab" data-t="enc">⚙ Kodlama</div>
                <div class="tab" data-t="sys">🛠 Sistem</div>
            </nav>

            <form id="cfg" onsubmit="return false">
                <!-- LOG -->
                <div class="tabp active card" id="t-log">
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="font-semibold">FFmpeg Çıktısı</h2>
                        <span class="text-xs text-slate-500">Her 3 sn yenilenir</span>
                    </div>
                    <div class="mb-2 text-xs text-slate-400">Aktif Kaynak: <span id="activeUrl" class="font-mono text-slate-200 break-all">-</span></div>
                    <div id="log" class="term">Henüz log yok.</div>
                    <h2 class="font-semibold mt-4 mb-2">Panel Olayları</h2>
                    <div id="plog" class="term" style="height:9rem;color:#fcd34d">-</div>

                    <div id="hlsBox" class="mt-4 hidden">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <h2 class="font-semibold">HLS Yayın Linki</h2>
                            <input id="hlsUrl" readonly class="inp flex-1 font-mono text-xs min-w-[12rem]">
                            <button type="button" id="bCopy" class="btn bg-slate-700 hover:bg-slate-600">Kopyala</button>
                            <button type="button" id="bPlay" class="btn bg-indigo-600 hover:bg-indigo-500">▶ İzle</button>
                        </div>
                        <video id="vid" controls muted class="w-full rounded-lg bg-black hidden aspect-video"></video>
                    </div>

                    <div class="mt-4">
                        <button type="button" id="bCmd" class="btn bg-slate-800 hover:bg-slate-700">🔍 FFmpeg komutunu göster</button>
                        <pre id="cmdOut" class="term mt-2 hidden" style="height:auto;max-height:12rem;color:#a5b4fc"></pre>
                    </div>
                </div>

                <!-- ÇOKLU YAYIN -->
                <div class="tabp card space-y-4" id="t-multi">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">📺 Çoklu Yayın Yönetimi</h2>
                        <label class="flex items-center gap-2 text-sm"><span class="sw"><input type="checkbox" name="multi_enabled"><span></span></span> Aktif</label>
                    </div>
                    <p class="hint">4 adet tekil yayın tanımlayabilirsiniz. Aktif olan ilk yayın kullanılır. M3U liste aktifse öncelik M3U'dadır. Her yayının kendi <b>HLS klasör adı (slug)</b> vardır.</p>
                    <div id="multiList" class="space-y-3"></div>
                </div>

                <!-- M3U LİSTE -->
                <div class="tabp card space-y-4" id="t-m3u">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">📋 M3U Liste Desteği</h2>
                        <label class="flex items-center gap-2 text-sm"><span class="sw"><input type="checkbox" name="m3u_enabled"><span></span></span> Aktif</label>
                    </div>
                    <p class="hint">M3U/M3U8 liste URL'si girin. Panel otomatik olarak kanalları çeker ve seçilen kanalı yayınlar.</p>
                    <div>
                        <label class="lbl">M3U Liste URL</label>
                        <div class="flex gap-2">
                            <input name="m3u_url" class="inp font-mono flex-1" placeholder="http://ornek.com/playlist.m3u">
                            <button type="button" id="bM3uParse" class="btn bg-indigo-600 hover:bg-indigo-500 whitespace-nowrap">🔍 Listeyi Çek</button>
                        </div>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><label class="lbl">Otomatik yenile</label>
                            <label class="flex items-center gap-2 text-sm mt-1"><span class="sw"><input type="checkbox" name="m3u_auto_refresh"><span></span></span> Açık</label>
                        </div>
                        <div><label class="lbl">Yenileme aralığı (sn)</label><input type="number" name="m3u_refresh_interval" class="inp"></div>
                    </div>
                    <div id="m3uInfo" class="text-sm text-slate-400"></div>
                    <div id="m3uChannels" class="max-h-80 overflow-y-auto space-y-1 border border-slate-800 rounded-lg p-2"></div>
                </div>

                <!-- KAYNAK & ÇIKIŞ -->
                <div class="tabp card space-y-4" id="t-src">
                    <h2 class="font-semibold">Tekli Kaynak (Giriş)</h2>
                    <p class="hint">Çoklu yayın veya M3U liste aktif değilse bu kaynak kullanılır.</p>
                    <div>
                        <label class="lbl">Kaynak yayın linki (m3u8, rtmp, http, udp, srt, mp4 …)</label>
                        <input name="input_url" class="inp font-mono" placeholder="http://ornek.com/canli/index.m3u8">
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><label class="lbl">User-Agent (isteğe bağlı)</label><input name="input_ua" class="inp" placeholder="VLC/3.0.18 LibVLC/3.0.18"></div>
                        <div><label class="lbl">Ek giriş parametreleri (isteğe bağlı)</label><input name="input_extra" class="inp font-mono" placeholder="-rw_timeout 15000000"></div>
                    </div>
                    <label class="flex items-center gap-3 text-sm"><span class="sw"><input type="checkbox" name="input_realtime"><span></span></span> Gerçek zamanlı oku (<code class="text-indigo-300">-re</code>) — sadece dosya/mp4 kaynaklar için</label>

                    <hr class="border-slate-800">
                    <h2 class="font-semibold">Çıkış (Yayın)</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="lbl">Çıkış türü</label>
                            <select name="out_type" class="inp">
                                <option value="rtmp">RTMP (YouTube, Facebook, Nginx-RTMP…)</option>
                                <option value="hls">HLS (bu sunucudan m3u8 yayını)</option>
                                <option value="mpegts">MPEG-TS (udp:// srt:// tcp://)</option>
                            </select>
                        </div>
                        <div>
                            <label class="lbl">Çalışma modu</label>
                            <select name="mode" class="inp">
                                <option value="encode">Yeniden kodla (Logo + Bant aktif)</option>
                                <option value="copy">Kopyala (CPU yok, logo/bant YOK)</option>
                            </select>
                        </div>
                    </div>
                    <div id="outUrlWrap">
                        <label class="lbl">Çıkış linki</label>
                        <input name="out_url" class="inp font-mono" placeholder="rtmp://a.rtmp.youtube.com/live2/XXXX-XXXX-XXXX">
                        <p class="hint">RTMP için yayın anahtarıyla birlikte tam adresi yazın.</p>
                    </div>
                    <div id="hlsOpts" class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="lbl">HLS yayın klasörü (slug)</label>
                            <input name="hls_slug" class="inp font-mono" placeholder="ZemTv">
                            <p class="hint">Sadece harf, rakam, - ve _ kullanın. Yayın adresi: <code id="slugPreview">/hls/ZemTv/stream.m3u8</code></p>
                        </div>
                        <div><label class="lbl">HLS parça süresi (sn)</label><input type="number" name="hls_time" class="inp"></div>
                        <div><label class="lbl">Liste uzunluğu (parça)</label><input type="number" name="hls_list" class="inp"></div>
                        <p class="hint col-span-2">HLS seçilirse yayın <code id="slugPreview2">/hls/ZemTv/stream.m3u8</code> adresinde yayınlanır.</p>
                    </div>
                </div>

                <!-- LOGO -->
                <div class="tabp card space-y-4" id="t-logo">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Logo Ayarları</h2>
                        <label class="flex items-center gap-2 text-sm"><span class="sw"><input type="checkbox" name="logo_enabled"><span></span></span> Logo aktif</label>
                    </div>
                    <div class="flex flex-wrap items-center gap-4 p-3 rounded-xl border border-dashed border-slate-700 bg-slate-950">
                        <div class="w-28 h-20 rounded-lg bg-[repeating-conic-gradient(#1e293b_0_25%,#0f172a_0_50%)] bg-[length:16px_16px] flex items-center justify-center overflow-hidden">
                            <img id="logoThumb" class="max-w-full max-h-full hidden" alt="">
                            <span id="logoNone" class="text-xs text-slate-500">Logo yok</span>
                        </div>
                        <div class="flex-1 min-w-[12rem]">
                            <input type="file" id="logoFile" accept="image/png,image/jpeg,image/gif,image/webp" class="text-xs text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-white file:cursor-pointer">
                            <p class="hint">PNG (şeffaf) önerilir. En fazla 8 MB. Yükleme limiti: <?= h($sys['upload']) ?></p>
                        </div>
                        <button type="button" id="bLogoDel" class="btn bg-slate-800 hover:bg-red-600/80">Sil</button>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="lbl">Konum</label>
                            <select name="logo_pos" class="inp">
                                <option value="tl">Sol üst</option><option value="tr">Sağ üst</option>
                                <option value="bl">Sol alt</option><option value="br">Sağ alt</option>
                                <option value="center">Orta</option>
                            </select>
                        </div>
                        <div><label class="lbl">Genişlik (px)</label><input type="number" name="logo_width" class="inp"></div>
                        <div><label class="lbl">Şeffaflık (% görünürlük)</label><input type="range" min="5" max="100" name="logo_opacity" class="w-full accent-indigo-500"></div>
                        <div><label class="lbl">Kenar boşluğu (px)</label><input type="number" name="logo_margin" class="inp"></div>
                    </div>
                    <p class="hint">Logo değişikliklerinin yayına yansıması için yayını yeniden başlatın.</p>
                </div>

                <!-- BAND -->
                <div class="tabp card space-y-4" id="t-band">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Alt Bant (Yazı Bandı)</h2>
                        <label class="flex items-center gap-2 text-sm"><span class="sw"><input type="checkbox" name="band_enabled"><span></span></span> Bant aktif</label>
                    </div>
                    <div>
                        <label class="lbl">Bant metni</label>
                        <textarea name="band_text" rows="3" class="inp"></textarea>
                        <p class="hint">✅ <b>Canlı güncelleme:</b> Yayın açıkken sadece metni değiştirip "Kaydet / Bandı Güncelle" derseniz yayın kesilmeden yazı değişir.</p>
                    </div>
                    <button type="button" id="bBand" class="btn bg-fuchsia-600 hover:bg-fuchsia-500">📰 Bandı Güncelle</button>
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div><label class="lbl">Stil</label><select name="band_style" class="inp"><option value="scroll">Kayan yazı</option><option value="static">Sabit (ortalı)</option></select></div>
                        <div><label class="lbl">Konum</label><select name="band_pos" class="inp"><option value="bottom">Alt</option><option value="top">Üst</option></select></div>
                        <div><label class="lbl">Yazı tipi</label>
                            <select name="band_font" class="inp"><option value="arial">Arial</option><option value="arialbd">Arial Kalın</option><option value="tahoma">Tahoma</option><option value="verdana">Verdana</option><option value="segoeui">Segoe UI</option><option value="calibri">Calibri</option><option value="impact">Impact</option></select></div>
                        <div><label class="lbl">Bant yüksekliği (px)</label><input type="number" name="band_height" class="inp"></div>
                        <div><label class="lbl">Yazı boyutu (px)</label><input type="number" name="band_font_size" class="inp"></div>
                        <div><label class="lbl">Kayma hızı (px/sn)</label><input type="number" name="band_speed" class="inp"></div>
                        <div><label class="lbl">Yazı rengi</label><input type="color" name="band_font_color" class="inp"></div>
                        <div><label class="lbl">Bant rengi</label><input type="color" name="band_bg_color" class="inp"></div>
                        <div><label class="lbl">Bant opaklığı (%)</label><input type="range" min="0" max="100" name="band_bg_opacity" class="w-full accent-indigo-500 mt-2"></div>
                    </div>
                    <p class="hint">Yazı tipi, renk, boyut gibi değişiklikler için yayını yeniden başlatmanız gerekir.</p>
                </div>

                <!-- ENCODE -->
                <div class="tabp card space-y-4" id="t-enc">
                    <h2 class="font-semibold">Kodlama Ayarları</h2>
                    <p class="hint">Sadece "Yeniden kodla" modunda geçerlidir.</p>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><label class="lbl">Çözünürlük</label>
                            <select name="res" class="inp"><option value="orig">Orijinal</option><option value="1920x1080">1920x1080 (Full HD)</option><option value="1280x720">1280x720 (HD)</option><option value="854x480">854x480</option><option value="640x360">640x360</option></select></div>
                        <div><label class="lbl">FPS (0 = orijinal)</label><input type="number" name="fps" class="inp"></div>
                        <div><label class="lbl">Video kodlayıcı</label>
                            <select name="vcodec" class="inp"><option value="libx264">libx264 (CPU)</option><option value="h264_nvenc">h264_nvenc (NVIDIA)</option><option value="h264_qsv">h264_qsv (Intel)</option><option value="h264_amf">h264_amf (AMD)</option></select></div>
                        <div><label class="lbl">x264 hız ön ayarı</label>
                            <select name="preset" class="inp"><option>ultrafast</option><option>superfast</option><option>veryfast</option><option>faster</option><option>fast</option><option>medium</option></select></div>
                        <div><label class="lbl">Video bitrate (kbps)</label><input type="number" name="vbitrate" class="inp"></div>
                        <div><label class="lbl">Ses bitrate (kbps)</label><input type="number" name="abitrate" class="inp"></div>
                        <div><label class="lbl">Keyframe aralığı (sn)</label><input type="number" name="gop" class="inp"></div>
                    </div>
                    <hr class="border-slate-800">
                    <h2 class="font-semibold">Otomatik Yeniden Başlatma</h2>
                    <div class="grid sm:grid-cols-2 gap-4 items-end">
                        <label class="flex items-center gap-3 text-sm"><span class="sw"><input type="checkbox" name="autorestart"><span></span></span> FFmpeg çökerse / kaynak kesilirse otomatik yeniden başlat</label>
                        <div><label class="lbl">Bekleme süresi (sn)</label><input type="number" name="restart_delay" class="inp"></div>
                    </div>
                </div>

                <!-- SİSTEM -->
                <div class="tabp card space-y-5" id="t-sys">
                    <h2 class="font-semibold">FFmpeg</h2>
                    <div>
                        <label class="lbl">ffmpeg.exe yolu</label>
                        <input name="ffmpeg_path" class="inp font-mono" placeholder="C:\ffmpeg\bin\ffmpeg.exe">
                    </div>
                    <button type="button" id="bTest" class="btn bg-indigo-600 hover:bg-indigo-500">🧪 FFmpeg'i Test Et</button>
                    <div id="testOut" class="text-sm space-y-1"></div>

                    <hr class="border-slate-800">
                    <h2 class="font-semibold">Sunucu Bilgisi</h2>
                    <ul class="text-sm space-y-1 text-slate-300">
                        <li>PHP: <b><?= h($sys['php']) ?></b> · <?= h($sys['os']) ?></li>
                        <li>PHP çalışma kullanıcısı: <b><?= h($sys['user']) ?></b></li>
                        <li>exec(): <?= $sys['exec'] ? '<span class="text-emerald-400">Açık ✓</span>' : '<span class="text-red-400">KAPALI ✗ (php.ini → disable_functions)</span>' ?></li>
                        <li>COM (WScript): <?= $sys['com'] ? '<span class="text-emerald-400">Var ✓</span>' : '<span class="text-amber-400">Yok (proc_open yedek yöntemi kullanılır)</span>' ?></li>
                        <li>data klasörü yazılabilir: <?= $sys['writable'] ? '<span class="text-emerald-400">Evet ✓</span>' : '<span class="text-red-400">HAYIR ✗ — klasöre yazma izni verin</span>' ?></li>
                    </ul>
                </div>
            </form>

            <div class="card mt-5 space-y-3 hidden" id="pwCard">
                <h2 class="font-semibold">Giriş Bilgilerini Değiştir</h2>
                <div class="grid sm:grid-cols-3 gap-3">
                    <div><label class="lbl">Yeni kullanıcı adı</label><input id="pwUser" class="inp" value="<?= h($cfgNow['admin_user']) ?>"></div>
                    <div><label class="lbl">Mevcut şifre</label><input id="pwCur" type="password" class="inp"></div>
                    <div><label class="lbl">Yeni şifre</label><input id="pwNew" type="password" class="inp"></div>
                </div>
                <button id="bPw" class="btn bg-slate-700 hover:bg-slate-600">🔑 Güncelle</button>
            </div>
        </div>

        <aside>
            <div class="card sticky top-4">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="font-semibold text-sm">Taslak Önizleme</h2>
                    <span class="text-[11px] text-slate-500" id="pvRes">1280x720</span>
                </div>
                <div id="pv" class="relative w-full aspect-video rounded-lg overflow-hidden border border-slate-800" style="background:linear-gradient(135deg,#1e1b4b,#0f172a 45%,#134e4a)">
                    <div class="absolute inset-0 flex items-center justify-center text-slate-600 text-4xl select-none">▶</div>
                    <img id="pvLogo" class="absolute hidden" alt="">
                    <div id="pvBand" class="absolute left-0 right-0 overflow-hidden flex items-center"><div id="pvTxt"></div></div>
                </div>
                <p class="hint">Logo ve bant konumunun yaklaşık görünümü.</p>
                <div class="mt-4 text-xs text-slate-400 space-y-1 border-t border-slate-800 pt-3">
                    <div>Kaynak: <span id="sumIn" class="text-slate-200 break-all">-</span></div>
                    <div>Çıkış: <span id="sumOut" class="text-slate-200 break-all">-</span></div>
                    <div>Mod: <span id="sumMode" class="text-slate-200">-</span></div>
                </div>
            </div>
        </aside>
    </div>

    <p class="text-center text-xs text-slate-600 mt-8">Zemmedya Yayıncılık · PHP <?= h(PHP_VERSION) ?> · FFmpeg</p>
</div>

<div id="toasts" class="fixed bottom-4 right-4 space-y-2 z-50"></div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
let CFG = <?= json_encode($cfgNow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
delete CFG.admin_hash;
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
let state = 'stopped', uptimeBase = 0, uptimeAt = 0, busy = false;

function toast(msg, ok = true) {
    const d = document.createElement('div');
    d.className = 'px-4 py-3 rounded-lg text-sm shadow-xl border max-w-xs ' + (ok ? 'bg-emerald-950 border-emerald-700 text-emerald-100' : 'bg-red-950 border-red-700 text-red-100');
    d.textContent = msg;
    $('#toasts').appendChild(d);
    setTimeout(() => d.remove(), 4500);
}
async function api(name, data = {}) {
    const fd = new FormData();
    for (const k in data) {
        if (Array.isArray(data[k])) {
            data[k].forEach((v, i) => {
                for (const kk in v) fd.append(`${k}[${i}][${kk}]`, v[kk]);
            });
        } else fd.append(k, data[k]);
    }
    try {
        const r = await fetch('?api=' + name, { method: 'POST', headers: { 'X-CSRF': CSRF }, body: fd });
        if (r.status === 401) { location.reload(); return { ok: false, msg: 'Oturum bitti' }; }
        return await r.json();
    } catch (e) { return { ok: false, msg: 'Sunucuya ulaşılamadı' }; }
}

$$('#tabs .tab').forEach(t => t.onclick = () => {
    $$('#tabs .tab').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    $$('.tabp').forEach(x => x.classList.remove('active'));
    $('#t-' + t.dataset.t).classList.add('active');
    $('#pwCard').classList.toggle('hidden', t.dataset.t !== 'sys');
});

function fillForm() {
    $$('#cfg [name]').forEach(el => {
        const v = CFG[el.name];
        if (v === undefined) return;
        if (el.type === 'checkbox') el.checked = !!+v; else el.value = v;
    });
    renderMulti();
    renderM3U();
}
function collect() {
    const o = {};
    $$('#cfg [name]').forEach(el => {
        if (el.name === 'multi_streams') return;
        o[el.name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
    });
    // Çoklu yayınları topla (slug dahil)
    const multi = [];
    $$('#multiList .multi-item').forEach(item => {
        const name = item.querySelector('[data-k="name"]')?.value || '';
        const url = item.querySelector('[data-k="url"]')?.value || '';
        const slug = item.querySelector('[data-k="slug"]')?.value || '';
        const type = item.querySelector('[data-k="type"]')?.value || 'm3u8';
        const enabled = item.querySelector('[data-k="enabled"]')?.checked ? 1 : 0;
        multi.push({ name, url, slug, type, enabled });
    });
    o.multi_streams = multi;
    return o;
}
async function save(silent) {
    const r = await api('save', collect());
    if (r.ok) { CFG = r.cfg; delete CFG.admin_hash; updateSummary(); }
    if (!silent || !r.ok) toast(r.msg, r.ok);
    return r.ok;
}

function updateSummary() {
    const f = collect();
    $('#sumIn').textContent = f.input_url || '(girilmedi)';
    $('#sumOut').textContent = f.out_type === 'hls' ? 'HLS → /hls/' + (f.hls_slug || 'ZemTv') + '/stream.m3u8' : (f.out_url ? f.out_url.replace(/(\/[^\/]{4})[^\/]*$/, '$1…') : '(girilmedi)');
    $('#sumMode').textContent = f.mode === 'copy' ? 'Kopyala (logo/bant yok)' : 'Yeniden kodla (' + f.vcodec + ')';
    $('#outUrlWrap').classList.toggle('hidden', f.out_type === 'hls');
    $('#hlsOpts').classList.toggle('hidden', f.out_type !== 'hls');
    const slug = (f.hls_slug || 'ZemTv').replace(/[^A-Za-z0-9_\-]/g, '') || 'ZemTv';
    const sp = document.getElementById('slugPreview');
    const sp2 = document.getElementById('slugPreview2');
    if (sp) sp.textContent = '/hls/' + slug + '/stream.m3u8';
    if (sp2) sp2.textContent = '/hls/' + slug + '/stream.m3u8';
    updatePreview();
}
function updatePreview() {
    const f = collect();
    const res = f.res === 'orig' ? '1280x720' : f.res;
    const [W, H] = res.split('x').map(Number);
    $('#pvRes').textContent = f.res === 'orig' ? 'orijinal' : res;
    const box = $('#pv'), bw = box.clientWidth, k = bw / W;
    const logo = $('#pvLogo');
    const hasLogo = CFG.logo_file && +f.logo_enabled && f.mode === 'encode';
    logo.classList.toggle('hidden', !hasLogo);
    if (hasLogo) {
        if (!logo.dataset.v) { logo.src = '?api=logo&t=' + Date.now(); logo.dataset.v = 1; }
        logo.style.width = (f.logo_width * k) + 'px';
        logo.style.opacity = f.logo_opacity / 100;
        const m = f.logo_margin * k + 'px';
        logo.style.left = logo.style.right = logo.style.top = logo.style.bottom = 'auto';
        logo.style.transform = '';
        if (f.logo_pos === 'center') { logo.style.left = '50%'; logo.style.top = '50%'; logo.style.transform = 'translate(-50%,-50%)'; }
        else {
            logo.style[f.logo_pos[1] === 'l' ? 'left' : 'right'] = m;
            logo.style[f.logo_pos[0] === 't' ? 'top' : 'bottom'] = m;
        }
    }
    const band = $('#pvBand'), txt = $('#pvTxt');
    const hasBand = +f.band_enabled && f.mode === 'encode' && f.band_text.trim();
    band.style.display = hasBand ? 'flex' : 'none';
    if (hasBand) {
        band.style.height = (f.band_height * k) + 'px';
        band.style.top = f.band_pos === 'top' ? '0' : 'auto';
        band.style.bottom = f.band_pos === 'top' ? 'auto' : '0';
        const hex = f.band_bg_color, o = f.band_bg_opacity / 100;
        band.style.background = `rgba(${parseInt(hex.slice(1,3),16)},${parseInt(hex.slice(3,5),16)},${parseInt(hex.slice(5,7),16)},${o})`;
        txt.textContent = f.band_text;
        txt.style.color = f.band_font_color;
        txt.style.fontSize = (f.band_font_size * k) + 'px';
        txt.style.whiteSpace = 'nowrap';
        txt.className = f.band_style === 'scroll' ? 'mq' : 'mx-auto';
        txt.style.animationDuration = Math.max(4, (W + f.band_text.length * f.band_font_size * .5) / f.band_speed) + 's';
    }
}
document.addEventListener('input', e => { if (e.target.closest('#cfg')) updateSummary(); });
window.addEventListener('resize', updatePreview);

/* Çoklu yayın render */
function renderMulti() {
    const list = $('#multiList');
    const streams = CFG.multi_streams || [];
    while (streams.length < 4) streams.push({ name: 'Yayın ' + (streams.length + 1), url: '', slug: 'yayin' + (streams.length + 1), type: 'm3u8', enabled: 0 });
    list.innerHTML = streams.slice(0, 4).map((s, i) => `
        <div class="multi-item border border-slate-800 rounded-lg p-3 space-y-2">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-indigo-400 w-6">#${i + 1}</span>
                <input data-k="name" class="inp flex-1" value="${esc(s.name || 'Yayın ' + (i + 1))}" placeholder="Yayın adı">
                <label class="flex items-center gap-1 text-xs"><input type="checkbox" data-k="enabled" ${s.enabled ? 'checked' : ''}> Aktif</label>
            </div>
            <div class="flex gap-2">
                <select data-k="type" class="inp w-28">
                    <option value="m3u8" ${s.type === 'm3u8' ? 'selected' : ''}>M3U8</option>
                    <option value="mp4" ${s.type === 'mp4' ? 'selected' : ''}>MP4</option>
                    <option value="rtmp" ${s.type === 'rtmp' ? 'selected' : ''}>RTMP</option>
                    <option value="other" ${s.type === 'other' ? 'selected' : ''}>Diğer</option>
                </select>
                <input data-k="url" class="inp flex-1 font-mono text-xs" value="${esc(s.url)}" placeholder="http://.../yayin.m3u8">
            </div>
            <div class="flex gap-2 items-center">
                <span class="text-[10px] text-slate-500 whitespace-nowrap">HLS slug:</span>
                <input data-k="slug" class="inp flex-1 font-mono text-xs" value="${esc(s.slug || 'yayin' + (i + 1))}" placeholder="ZemTv">
                <span class="text-[10px] text-slate-500">/hls/<b class="text-indigo-400">${esc(s.slug || 'yayin' + (i + 1))}</b>/stream.m3u8</span>
            </div>
        </div>
    `).join('');
}
function esc(s) { return String(s || '').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

/* M3U render */
function renderM3U() {
    const ch = CFG.m3u_channels || [];
    const idx = CFG.m3u_current_index || 0;
    $('#m3uInfo').textContent = ch.length ? `${ch.length} kanal yüklü · Seçili: #${idx + 1}` : 'Henüz liste çekilmedi.';
    $('#m3uChannels').innerHTML = ch.length ? ch.map((c, i) => `
        <div class="m3u-ch flex items-center gap-2 p-2 rounded ${i == idx ? 'bg-indigo-600/30 border border-indigo-500' : 'hover:bg-slate-800'}" data-i="${i}">
            <span class="text-xs text-slate-500 w-8">#${i + 1}</span>
            <span class="flex-1 text-sm truncate">${esc(c.name || c.url)}</span>
            ${c.group ? `<span class="text-[10px] text-slate-500">${esc(c.group)}</span>` : ''}
        </div>
    `).join('') : '<p class="text-xs text-slate-500 p-2">Liste boş.</p>';
    $$('#m3uChannels .m3u-ch').forEach(el => el.onclick = async () => {
        const i = +el.dataset.i;
        const r = await api('m3u_select', { index: i });
        if (r.ok) { CFG.m3u_current_index = i; renderM3U(); toast(r.msg); }
    });
}

$('#bM3uParse').onclick = async () => {
    const url = $('[name="m3u_url"]').value.trim();
    if (!url) return toast('M3U URL girin', false);
    toast('Liste çekiliyor…');
    const r = await api('m3u_parse', { url });
    if (r.ok) { CFG.m3u_channels = r.channels; CFG.m3u_current_index = 0; renderM3U(); }
    toast(r.msg, r.ok);
};

function fmt(s) { s = Math.max(0, s | 0); return [s / 3600 | 0, (s % 3600) / 60 | 0, s % 60].map(n => String(n).padStart(2, '0')).join(':'); }
function render(s) {
    state = s.state;
    const map = {
        running: ['CANLI YAYINDA', 'bg-emerald-500/15 text-emerald-300', 'bg-emerald-400 live'],
        waiting: ['YENİDEN BAĞLANIYOR…', 'bg-amber-500/15 text-amber-300', 'bg-amber-400 live'],
        stopped: ['YAYIN KAPALI', 'bg-slate-800 text-slate-300', 'bg-slate-500']
    }[s.state];
    $('#pill').className = 'px-4 py-2 rounded-full text-sm font-bold flex items-center gap-2 ' + map[1];
    $('#dot').className = 'w-2.5 h-2.5 rounded-full ' + map[2];
    $('#pillTxt').textContent = map[0];
    uptimeBase = s.uptime; uptimeAt = Date.now();
    $('#bStart').disabled = s.state !== 'stopped' || busy;
    $('#bStop').disabled = s.state === 'stopped' || busy;
    $('#bRestart').disabled = busy;
    $('#sFps').textContent = s.stats.fps; $('#sBr').textContent = s.stats.bitrate; $('#sSp').textContent = s.stats.speed;
    $('#sTm').textContent = s.stats.time; $('#sSz').textContent = s.stats.size;
    $('#activeUrl').textContent = s.active_url || '-';
    const lg = $('#log'), atEnd = lg.scrollTop + lg.clientHeight >= lg.scrollHeight - 20;
    lg.textContent = s.log.length ? s.log.join('\n') : 'Henüz log yok.';
    if (atEnd) lg.scrollTop = lg.scrollHeight;
    const pl = $('#plog');
    pl.textContent = s.panel_log.length ? s.panel_log.join('\n') : '-';
    pl.scrollTop = pl.scrollHeight;
    const hls = CFG.out_type === 'hls';
    $('#hlsBox').classList.toggle('hidden', !hls);
    $('#hlsUrl').value = s.hls_url;
    const sp = document.getElementById('slugPreview');
    const sp2 = document.getElementById('slugPreview2');
    if (sp && s.hls_slug) sp.textContent = '/hls/' + s.hls_slug + '/stream.m3u8';
    if (sp2 && s.hls_slug) sp2.textContent = '/hls/' + s.hls_slug + '/stream.m3u8';
}
async function poll() {
    try { const r = await fetch('?api=status'); if (r.status === 401) return location.reload(); render(await r.json()); } catch (e) {}
}
setInterval(() => { $('#uptime').textContent = state === 'running' ? fmt(uptimeBase + (Date.now() - uptimeAt) / 1000) : '00:00:00'; }, 500);
setInterval(poll, 3000);

async function act(name, label) {
    busy = true; $$('.btn').forEach(b => { if (['bStart','bStop','bRestart'].includes(b.id)) b.disabled = true; });
    if (name !== 'stop') { if (!await save(true)) { busy = false; poll(); return; } }
    toast(label + '…');
    const r = await api(name);
    toast(r.msg, r.ok);
    busy = false;
    setTimeout(poll, 800);
    poll();
}
$('#bSave').onclick = () => save(false);
$('#bStart').onclick = () => act('start', 'Yayın başlatılıyor');
$('#bStop').onclick = () => { if (confirm('Yayın durdurulsun mu?')) act('stop', 'Durduruluyor'); };
$('#bRestart').onclick = () => act('restart', 'Yeniden başlatılıyor');
$('#bBand').onclick = async () => { if (await save(true)) toast(state === 'running' ? 'Bant metni canlı olarak güncellendi.' : 'Bant metni kaydedildi.'); };
$('#bCmd').onclick = async () => {
    await save(true);
    const r = await api('cmd'), o = $('#cmdOut');
    o.classList.remove('hidden');
    o.textContent = r.ok ? r.cmd : r.msg;
};
$('#bCopy').onclick = () => { $('#hlsUrl').select(); document.execCommand('copy'); toast('Link kopyalandı'); };
$('#bPlay').onclick = () => {
    const v = $('#vid'); v.classList.remove('hidden');
    const url = $('#hlsUrl').value + '?t=' + Date.now();
    const go = () => {
        if (window.Hls && Hls.isSupported()) { if (v._h) v._h.destroy(); v._h = new Hls({ lowLatencyMode: false }); v._h.loadSource(url); v._h.attachMedia(v); v._h.on(Hls.Events.MANIFEST_PARSED, () => v.play()); }
        else { v.src = url; v.play(); }
    };
    if (window.Hls) return go();
    const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/hls.js@1'; s.onload = go; document.head.appendChild(s);
};

function logoUi() {
    const has = !!CFG.logo_file;
    $('#logoThumb').classList.toggle('hidden', !has);
    $('#logoNone').classList.toggle('hidden', has);
    if (has) $('#logoThumb').src = '?api=logo&t=' + Date.now();
    $('#bLogoDel').disabled = !has;
    $('#pvLogo').dataset.v = '';
}
$('#logoFile').onchange = async e => {
    const f = e.target.files[0]; if (!f) return;
    const r = await api('logo_upload', { logo: f });
    toast(r.msg, r.ok);
    if (r.ok) { CFG.logo_file = r.file; logoUi(); updatePreview(); }
    e.target.value = '';
};
$('#bLogoDel').onclick = async () => {
    if (!confirm('Logo silinsin mi?')) return;
    const r = await api('logo_delete'); toast(r.msg, r.ok);
    if (r.ok) { CFG.logo_file = ''; logoUi(); updatePreview(); }
};

$('#bTest').onclick = async () => {
    await save(true);
    const o = $('#testOut'); o.innerHTML = '<span class="text-slate-400">Test ediliyor…</span>';
    const r = await api('test');
    if (!r.ok) { o.innerHTML = '<div class="text-red-400">✗ ' + r.msg + '</div>'; return; }
    const li = (ok, t) => `<div class="${ok ? 'text-emerald-400' : 'text-amber-400'}">${ok ? '✓' : '✗'} ${t}</div>`;
    o.innerHTML = `<div class="text-slate-200 font-mono text-xs mb-2">${r.version.replace(/</g, '&lt;')}</div>` +
        li(r.drawtext, 'drawtext filtresi (alt bant)') + li(r.overlay, 'overlay filtresi (logo)') + li(r.libx264, 'libx264 (CPU H.264)') +
        li(r.nvenc, 'h264_nvenc (NVIDIA)') + li(r.qsv, 'h264_qsv (Intel)') + li(r.amf, 'h264_amf (AMD)');
};
$('#bPw').onclick = async () => {
    const r = await api('password', { new_user: $('#pwUser').value, cur_pass: $('#pwCur').value, new_pass: $('#pwNew').value });
    toast(r.msg, r.ok);
    if (r.ok) setTimeout(() => location.reload(), 1200);
};

fillForm(); updateSummary(); logoUi(); poll();
</script>
</body>
</html><?php
    exit;
}

/* =============================================================== ANA SAYFA */
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zemmedya Yayıncılık · Profesyonel Canlı Yayın Çözümleri</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<style>
    body{background:#020617;background-image:radial-gradient(circle at 15% 0%,#312e8160,transparent 40%),radial-gradient(circle at 85% 100%,#0f766e50,transparent 40%);background-attachment:fixed}
    .grad{background:linear-gradient(135deg,#6366f1,#a855f7 50%,#ec4899)}
    .grad2{background:linear-gradient(135deg,#0ea5e9,#10b981)}
    .card{background:rgba(15,23,42,.7);border:1px solid #1e293b;border-radius:1rem;padding:1.5rem;backdrop-filter:blur(12px);transition:.25s}
    .card:hover{border-color:#4f46e5;transform:translateY(-4px);box-shadow:0 12px 40px -12px rgba(79,70,229,.4)}
    .glow{box-shadow:0 0 60px -12px rgba(99,102,241,.6)}
    @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
    .float{animation:float 3s ease-in-out infinite}
    @keyframes pulse2{0%,100%{opacity:1}50%{opacity:.4}}
    .live{animation:pulse2 1.5s infinite}
    .marquee{overflow:hidden;white-space:nowrap}
    .marquee span{display:inline-block;padding-left:100%;animation:mq 25s linear infinite}
    @keyframes mq{0%{transform:translateX(0)}100%{transform:translateX(-100%)}}
</style>
</head>
<body class="text-slate-100 min-h-screen">

<!-- Üst Menü -->
<nav class="sticky top-0 z-40 border-b border-slate-800/80 bg-slate-950/80 backdrop-blur-lg">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl grad flex items-center justify-center text-lg shadow-lg">📡</div>
            <div>
                <div class="font-bold leading-tight">Zemmedya</div>
                <div class="text-[10px] text-slate-400 tracking-widest">YAYINCILIK</div>
            </div>
        </div>
        <div class="hidden md:flex items-center gap-6 text-sm text-slate-300">
            <a href="#ozellikler" class="hover:text-indigo-400 transition">Özellikler</a>
            <a href="#paketler" class="hover:text-indigo-400 transition">Paketler</a>
            <a href="#iletisim" class="hover:text-indigo-400 transition">İletişim</a>
        </div>
        <a href="?login=1" class="btn inline-flex items-center gap-2 px-4 py-2 rounded-lg grad text-white font-semibold text-sm hover:opacity-90 transition">🔐 Yönetim Girişi</a>
    </div>
</nav>

<!-- Hero -->
<section class="relative max-w-7xl mx-auto px-4 pt-12 md:pt-20 pb-16">
    <div class="grid lg:grid-cols-2 gap-10 items-center">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs font-semibold mb-5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 live"></span> 7/24 Aktif Yayın Altyapısı
            </div>
            <h1 class="text-4xl md:text-6xl font-black leading-tight mb-5">
                Profesyonel <span class="grad bg-clip-text text-transparent">Canlı Yayın</span> Çözümleri
            </h1>
            <p class="text-slate-400 text-lg mb-7 leading-relaxed">
                Tekli ve çoklu M3U8 yayın desteği, 1K kalite, kayan yazı bandı, logo ekleme ve M3U liste algılama ile 
                <b class="text-slate-200">Zemmedya Yayıncılık</b> güvencesiyle kesintisiz yayın keyfi.
            </p>
            <div class="flex flex-wrap gap-3">
                <a href="#paketler" class="btn px-6 py-3 rounded-xl grad text-white font-bold shadow-lg glow">🚀 Paketleri İncele</a>
                <a href="?login=1" class="btn px-6 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 font-semibold">🔐 Yönetim Paneli</a>
            </div>
            <div class="flex flex-wrap gap-6 mt-8 text-sm">
                <div><div class="text-2xl font-bold text-indigo-400">5+</div><div class="text-slate-500 text-xs">Eşzamanlı Yayın</div></div>
                <div><div class="text-2xl font-bold text-fuchsia-400">1K</div><div class="text-slate-500 text-xs">Full HD Kalite</div></div>
                <div><div class="text-2xl font-bold text-emerald-400">%99.9</div><div class="text-slate-500 text-xs">Uptime</div></div>
                <div><div class="text-2xl font-bold text-amber-400">7/24</div><div class="text-slate-500 text-xs">Teknik Destek</div></div>
            </div>
        </div>
        <div class="relative">
            <div class="card float glow">
                <div class="aspect-video rounded-lg bg-gradient-to-br from-indigo-900 via-slate-900 to-fuchsia-900 relative overflow-hidden border border-slate-800">
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="text-center">
                            <div class="text-6xl mb-3">🎬</div>
                            <div class="text-sm text-slate-300 font-semibold">CANLI YAYIN</div>
                            <div class="text-xs text-slate-500 mt-1">1080p · 60fps · H.264</div>
                        </div>
                    </div>
                    <div class="absolute top-3 left-3 px-2 py-1 rounded bg-red-600 text-white text-[10px] font-bold flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-white live"></span> CANLI
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 bg-red-700/90 text-white text-xs py-2 marquee">
                        <span>📢 Hoş geldiniz • Zemmedya Yayıncılık ile kesintisiz canlı yayın keyfi • 1K kalite • Logo ve bant desteği • Tüm hakları saklıdır</span>
                    </div>
                    <div class="absolute top-3 right-3 px-2 py-1 rounded bg-black/60 text-white text-[10px] font-bold">ZEM TV</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Özellikler -->
<section id="ozellikler" class="max-w-7xl mx-auto px-4 py-16">
    <div class="text-center mb-12">
        <h2 class="text-3xl md:text-4xl font-bold mb-3">Neler <span class="grad bg-clip-text text-transparent">Sunuyoruz?</span></h2>
        <p class="text-slate-400 max-w-2xl mx-auto">Modern altyapımızla yayınlarınızı profesyonel seviyeye taşıyın.</p>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <div class="card">
            <div class="w-12 h-12 rounded-xl grad flex items-center justify-center text-2xl mb-4">📺</div>
            <h3 class="font-bold text-lg mb-2">Çoklu Yayın Desteği</h3>
            <p class="text-slate-400 text-sm">4 tekil yayın + 1 M3U liste olmak üzere 5 farklı kaynak aynı anda yönetilebilir.</p>
        </div>
        <div class="card">
            <div class="w-12 h-12 rounded-xl grad2 flex items-center justify-center text-2xl mb-4">📋</div>
            <h3 class="font-bold text-lg mb-2">M3U Liste Algılama</h3>
            <p class="text-slate-400 text-sm">M3U/M3U8 liste URL'nizi girin, kanalları otomatik çekip seçili kanalı yayınlayın.</p>
        </div>
        <div class="card">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-2xl mb-4">📰</div>
            <h3 class="font-bold text-lg mb-2">Kayan Yazı Bandı</h3>
            <p class="text-slate-400 text-sm">Canlı güncellenebilen kayan yazı bandı ile duyurularınızı anında iletin.</p>
        </div>
        <div class="card">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-pink-500 to-rose-600 flex items-center justify-center text-2xl mb-4">🖼</div>
            <h3 class="font-bold text-lg mb-2">Logo Ekleme</h3>
            <p class="text-slate-400 text-sm">PNG/JPG logo yükleyin, konum ve şeffaflık ayarlarıyla yayınınıza marka kimliği katın.</p>
        </div>
        <div class="card">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center text-2xl mb-4">✨</div>
            <h3 class="font-bold text-lg mb-2">1K Full HD Kalite</h3>
            <p class="text-slate-400 text-sm">1080p çözünürlük, 60fps'e kadar akıcı yayın ve profesyonel bitrate kontrolü.</p>
        </div>
        <div class="card">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-2xl mb-4">⚡</div>
            <h3 class="font-bold text-lg mb-2">Otomatik Yeniden Başlatma</h3>
            <p class="text-slate-400 text-sm">Yayın kesildiğinde FFmpeg otomatik olarak yeniden başlar, kesintisiz hizmet.</p>
        </div>
    </div>
</section>

<!-- Paketler -->
<section id="paketler" class="max-w-7xl mx-auto px-4 py-16">
    <div class="text-center mb-12">
        <h2 class="text-3xl md:text-4xl font-bold mb-3">Yayın <span class="grad bg-clip-text text-transparent">Paketleri</span></h2>
        <p class="text-slate-400 max-w-2xl mx-auto">İhtiyacınıza uygun paketi seçin, anında yayına başlayın.</p>
    </div>
    <div class="grid md:grid-cols-3 gap-6">
        <div class="card">
            <div class="text-sm text-slate-400 mb-1">BAŞLANGIÇ</div>
            <div class="text-3xl font-black mb-1">₺499<span class="text-sm text-slate-500 font-normal">/ay</span></div>
            <p class="text-slate-400 text-xs mb-5">Küçük ölçekli yayınlar için</p>
            <ul class="space-y-2 text-sm text-slate-300 mb-6">
                <li class="flex items-center gap-2">✓ <span>1 Tekli Yayın</span></li>
                <li class="flex items-center gap-2">✓ <span>720p HD Kalite</span></li>
                <li class="flex items-center gap-2">✓ <span>Logo Ekleme</span></li>
                <li class="flex items-center gap-2">✓ <span>Kayan Yazı Bandı</span></li>
                <li class="flex items-center gap-2">✓ <span>7/24 Teknik Destek</span></li>
            </ul>
            <a href="#iletisim" class="btn w-full justify-center py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 font-semibold">Başla</a>
        </div>
        <div class="card border-indigo-500/50 relative" style="border-color:#6366f1;box-shadow:0 0 40px -10px rgba(99,102,241,.5)">
            <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full grad text-white text-[10px] font-bold">POPÜLER</div>
            <div class="text-sm text-indigo-400 mb-1 font-semibold">PROFESYONEL</div>
            <div class="text-3xl font-black mb-1">₺1.299<span class="text-sm text-slate-500 font-normal">/ay</span></div>
            <p class="text-slate-400 text-xs mb-5">Profesyonel yayıncılar için</p>
            <ul class="space-y-2 text-sm text-slate-300 mb-6">
                <li class="flex items-center gap-2">✓ <span class="font-bold text-indigo-400">5 Eşzamanlı Yayın</span></li>
                <li class="flex items-center gap-2">✓ <span class="font-bold text-indigo-400">1K Full HD Kalite</span></li>
                <li class="flex items-center gap-2">✓ <span>M3U Liste Desteği</span></li>
                <li class="flex items-center gap-2">✓ <span>Çoklu Kaynak Yönetimi</span></li>
                <li class="flex items-center gap-2">✓ <span>Gelişmiş Logo & Bant</span></li>
                <li class="flex items-center gap-2">✓ <span>Öncelikli Destek</span></li>
            </ul>
            <a href="#iletisim" class="btn w-full justify-center py-2.5 rounded-lg grad text-white font-bold">Hemen Al</a>
        </div>
        <div class="card">
            <div class="text-sm text-slate-400 mb-1">KURUMSAL</div>
            <div class="text-3xl font-black mb-1">₺2.999<span class="text-sm text-slate-500 font-normal">/ay</span></div>
            <p class="text-slate-400 text-xs mb-5">Büyük ölçekli operasyonlar için</p>
            <ul class="space-y-2 text-sm text-slate-300 mb-6">
                <li class="flex items-center gap-2">✓ <span>Sınırsız Yayın</span></li>
                <li class="flex items-center gap-2">✓ <span>4K Ultra HD Kalite</span></li>
                <li class="flex items-center gap-2">✓ <span>Özel Sunucu (VDS)</span></li>
                <li class="flex items-center gap-2">✓ <span>API Erişimi</span></li>
                <li class="flex items-center gap-2">✓ <span>7/24 Özel Destek</span></li>
                <li class="flex items-center gap-2">✓ <span>Özel Yazılım Geliştirme</span></li>
            </ul>
            <a href="#iletisim" class="btn w-full justify-center py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 font-semibold">İletişime Geç</a>
        </div>
    </div>
</section>

<!-- İletişim -->
<section id="iletisim" class="max-w-7xl mx-auto px-4 py-16">
    <div class="card text-center max-w-2xl mx-auto">
        <h2 class="text-2xl md:text-3xl font-bold mb-3">Yayın Satın Almaya <span class="grad bg-clip-text text-transparent">Hazır mısınız?</span></h2>
        <p class="text-slate-400 mb-6">Hemen bize ulaşın, size özel çözümlerimizi konuşalım.</p>
        <div class="flex flex-wrap justify-center gap-3">
            <a href="mailto:info@zemmedya.com" class="btn px-6 py-3 rounded-xl grad text-white font-bold">📧 info@zemmedya.com</a>
            <a href="tel:+905555555555" class="btn px-6 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 font-semibold">📞 +90 555 555 55 55</a>
        </div>
        <div class="mt-6 pt-6 border-t border-slate-800 text-xs text-slate-500">
            <p>© 2022-2026 Zemmedya Yayıncılık. Tüm hakları saklıdır.</p>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="border-t border-slate-800 mt-8">
    <div class="max-w-7xl mx-auto px-4 py-8 text-center text-sm text-slate-500">
        <div class="flex items-center justify-center gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg grad flex items-center justify-center text-base">📡</div>
            <div class="text-left">
                <div class="font-bold text-slate-300 leading-tight">Zemmedya Yayıncılık</div>
                <div class="text-[10px] tracking-widest text-slate-500">PROFESYONEL CANLI YAYIN</div>
            </div>
        </div>
        <p class="mb-2">Tekli & Çoklu M3U8 Yayın · Logo · Kayan Yazı Bandı · 1K Full HD Kalite</p>
        <p class="text-slate-600">© 2022-2026 Zemmedya Yayıncılık. Tüm hakları saklıdır.</p>
    </div>
</footer>

</body>
</html>
<?php
exit;
