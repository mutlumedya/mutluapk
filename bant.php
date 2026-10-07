<?php
/* ============================================================================
   BANT KONTROL v1.0 — Tek dosya PHP yayın bant (7/24 RTMP) kontrol paneli
   ----------------------------------------------------------------------------
   Gereksinim : PHP 7.4+ (Windows Server 2022 / IIS veya Apache), ffmpeg
   ffmpeg yolu: C:\ffmpeg\bin\ffmpeg.exe  (Ayarlar bölümünden değiştirilebilir)
   Kurulum    : Bu dosyayı wwwroot içine at, tarayıcıdan aç, giriş yap.
   Varsayılan : kullanıcı "admin"  şifre "band2024"  -> HEMEN DEĞİŞTİR!
   Tüm veriler: index.php yanındaki /data klasöründe (json + medya + loglar)
   ============================================================================ */

@ini_set('display_errors', '0');
error_reporting(E_ALL);
@set_time_limit(600);
@ini_set('memory_limit', '512M');

/* ============================== YAPILANDIRMA ============================== */
define('APP_NAME',    'BANT KONTROL');
define('APP_VER',     '1.0');
define('BASE_DIR',    __DIR__);
define('DATA_DIR',    BASE_DIR . '/data');
define('MEDIA_DIR',   DATA_DIR . '/media');
define('LOGO_DIR',    DATA_DIR . '/logos');
define('THUMB_DIR',   DATA_DIR . '/thumbs');
define('LOG_DIR',     DATA_DIR . '/logs');
define('RUN_DIR',     DATA_DIR . '/run');
define('DEF_FFMPEG',  'C:/ffmpeg/bin/ffmpeg.exe');
define('DEF_FFPROBE', 'C:/ffmpeg/bin/ffprobe.exe');
define('DEF_USER',    'admin');
define('DEF_PASS',    'band2024');
define('IS_WIN',      DIRECTORY_SEPARATOR === '\\');

$IS_CLI = (php_sapi_name() === 'cli');

/* ================================ YARDIMCI ================================ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_win(){ return IS_WIN; }
function win_path($p){ return str_replace('/', '\\', (string)$p); }
function nix_path($p){ return str_replace('\\', '/', (string)$p); }

function ensure_dirs(){
    foreach ([DATA_DIR, MEDIA_DIR, LOGO_DIR, THUMB_DIR, LOG_DIR, RUN_DIR] as $d) {
        if (!is_dir($d)) @mkdir($d, 0777, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    $wc = DATA_DIR . '/web.config';
    if (!file_exists($wc)) {
        @file_put_contents($wc, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<configuration><system.webServer><security><requestFiltering><hiddenSegments>' .
            '<add segment="data" /></hiddenSegments></requestFiltering></security></system.webServer></configuration>');
    }
}
function read_json($file, $default = []){
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $default;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $default;
}
function write_json($file, $data){
    $tmp = $file . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $file);
}
function rid($n = 6){ return substr(md5(uniqid((string)mt_rand(), true)), 0, $n); }
function human_size($b){
    $b = (float)$b; $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}
function human_time($sec){
    $sec = (int)$sec; if ($sec < 0) $sec = 0;
    $d = intdiv($sec, 86400); $hh = intdiv($sec % 86400, 3600);
    $mm = intdiv($sec % 3600, 60); $ss = $sec % 60;
    $out = '';
    if ($d) $out .= $d . 'g ';
    if ($d || $hh) $out .= str_pad((string)$hh, 2, '0', STR_PAD_LEFT) . ':';
    $out .= str_pad((string)$mm, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$ss, 2, '0', STR_PAD_LEFT);
    return $out;
}
function human_dur($sec){
    $sec = (float)$sec; if ($sec <= 0) return '—';
    $hh = intdiv((int)$sec, 3600); $mm = intdiv(((int)$sec) % 3600, 60); $ss = ((int)$sec) % 60;
    return ($hh ? $hh . 's ' : '') . str_pad((string)$mm, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$ss, 2, '0', STR_PAD_LEFT);
}
function clean_filename($name){
    $name = basename((string)$name);
    $name = preg_replace('/[^\w\.\-\x{00C0}-\x{024F} ]+/u', '', $name);
    $name = str_replace([' ', '..'], ['_', ''], (string)$name);
    return $name === '' ? ('dosya_' . rid(4)) : $name;
}
function tail_bytes($file, $bytes = 24000){
    if (!is_file($file)) return '';
    $size = filesize($file);
    $fp = @fopen($file, 'rb'); if (!$fp) return '';
    if ($size > $bytes) @fseek($fp, $size - $bytes);
    $data = (string)@fread($fp, $bytes);
    @fclose($fp);
    return $data;
}

/* ================================= AYARLAR ================================ */
function settings_load(){
    $s = read_json(DATA_DIR . '/settings.json', []);
    $def = [
        'ffmpeg'      => DEF_FFMPEG,
        'ffprobe'     => DEF_FFPROBE,
        'username'    => DEF_USER,
        'pass_hash'   => '',
        'is_default'  => 1,
        'fail_count'  => 0,
        'fail_time'   => 0,
        'poll'        => 4,
        'log_keep'    => 400,
        'installed'   => date('Y-m-d H:i:s'),
    ];
    $s = array_merge($def, $s);
    if (empty($s['pass_hash'])) { $s['pass_hash'] = password_hash(DEF_PASS, PASSWORD_DEFAULT); $s['is_default'] = 1; write_json(DATA_DIR . '/settings.json', $s); }
    return $s;
}
function settings_save($s){ return write_json(DATA_DIR . '/settings.json', $s); }
function ffmpeg_path(){ global $SETTINGS; return nix_path($SETTINGS['ffmpeg'] ?: DEF_FFMPEG); }
function ffprobe_path(){ global $SETTINGS; return nix_path($SETTINGS['ffprobe'] ?: DEF_FFPROBE); }
function ffmpeg_ok(){ $p = win_path(ffmpeg_path()); return is_file($p); }

/* =============================== OTURUM/GİRİŞ ============================= */
function sess_boot(){
    if (session_status() === PHP_SESSION_NONE) {
        session_name('BANDKONTROL');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
}
function is_authed(){ return !empty($_SESSION['authed']) && !empty($_SESSION['auth_time']); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_ok(){ return isset($_POST['_token']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['_token']); }
function flash($type, $msg){ $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg]; }
function take_flashes(){ $f = $_SESSION['flash'] ?? []; $_SESSION['flash'] = []; return $f; }

/* ============================ SÜREÇ YÖNETİMİ ============================== */
function ps_esc($s){ return str_replace("'", "''", (string)$s); }
function ps_run($script){
    if (!is_win()) return (string)@shell_exec($script);
    $f = RUN_DIR . '/ps_' . md5($script . microtime(true) . mt_rand()) . '.ps1';
    @file_put_contents($f, "\$ErrorActionPreference='Continue'\r\n" . $script);
    $out = @shell_exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg(win_path($f)) . ' 2>&1');
    @unlink($f);
    return (string)$out;
}
function arg_line(array $args){
    $line = '';
    foreach ($args as $a) {
        $a = (string)$a;
        if ($a === '') continue;
        if (preg_match('/[\s"&|<>^,;()\[\]{}]/', $a)) $line .= ' "' . str_replace('"', '\\"', $a) . '"';
        else $line .= ' ' . $a;
    }
    return ltrim($line);
}
function launch_detached($exe, array $args, $errLog, $outLog){
    $line = arg_line($args);
    if (is_win()) {
        $script  = "\$a = '" . ps_esc($line) . "'\r\n";
        $script .= "\$p = Start-Process -FilePath '" . ps_esc(win_path($exe)) . "' -ArgumentList \$a"
                 . " -RedirectStandardError '" . ps_esc(win_path($errLog)) . "'"
                 . " -RedirectStandardOutput '" . ps_esc(win_path($outLog)) . "'"
                 . " -WindowStyle Hidden -PassThru\r\n";
        $script .= "Start-Sleep -Milliseconds 150\r\nWrite-Output \$p.Id\r\n";
        $out = ps_run($script);
        if (preg_match('/(\d+)\s*$/m', trim($out), $m)) return ['pid' => (int)$m[1], 'error' => ''];
        return ['pid' => 0, 'error' => trim(preg_replace('/\s+/', ' ', $out)) ?: 'Süreç başlatılamadı (PowerShell yanıt vermedi)'];
    }
    $full = escapeshellarg($exe) . ' ' . implode(' ', array_map('escapeshellarg', $args))
          . ' > ' . escapeshellarg($outLog) . ' 2> ' . escapeshellarg($errLog) . ' & echo $!';
    $pid = (int)trim((string)@shell_exec($full));
    return ['pid' => $pid, 'error' => $pid ? '' : 'Süreç başlatılamadı'];
}
function pid_alive($pid){
    $pid = (int)$pid; if ($pid <= 0) return false;
    if (!isset($GLOBALS['PID_CACHE']) || !is_array($GLOBALS['PID_CACHE'])) $GLOBALS['PID_CACHE'] = [];
    if (array_key_exists($pid, $GLOBALS['PID_CACHE'])) return $GLOBALS['PID_CACHE'][$pid];
    $res = false;
    if (is_win()) {
        $out = (string)@shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH /FO CSV 2>NUL');
        foreach (preg_split('/\r\n|\r|\n/', $out) as $l) {
            if (trim($l) === '') continue;
            $cols = str_getcsv($l);
            if (isset($cols[1]) && (int)$cols[1] === $pid) { $res = true; break; }
        }
    } else {
        $out = @shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null');
        $res = trim((string)$out) !== '';
    }
    $GLOBALS['PID_CACHE'][$pid] = $res;
    return $res;
}
function pid_note($pid, $alive){ if (!isset($GLOBALS['PID_CACHE']) || !is_array($GLOBALS['PID_CACHE'])) $GLOBALS['PID_CACHE'] = []; $GLOBALS['PID_CACHE'][(int)$pid] = (bool)$alive; }
function kill_pid($pid){
    $pid = (int)$pid; if ($pid <= 0) return false;
    if (is_win()) @shell_exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
    else @shell_exec('kill -9 ' . $pid . ' 2>/dev/null');
    pid_note($pid, false);
    return true;
}
function pids_by_needle($needle){
    $needle = trim((string)$needle); if ($needle === '') return [];
    $pids = [];
    if (is_win()) {
        $script = "Get-CimInstance Win32_Process -Filter \"Name='ffmpeg.exe'\" | Where-Object { \$_.CommandLine -like '*" . ps_esc($needle) . "*' } | ForEach-Object { \$_.ProcessId }";
        $out = ps_run($script);
        foreach (preg_split('/\r\n|\r|\n/', $out) as $l) { $l = trim($l); if (ctype_digit($l)) $pids[] = (int)$l; }
    }
    return array_values(array_unique($pids));
}
function ffmpeg_processes(){
    $rows = [];
    if (is_win()) {
        $script = "Get-CimInstance Win32_Process -Filter \"Name='ffmpeg.exe'\" | ForEach-Object { '{0}@@{1}@@{2}' -f \$_.ProcessId, \$_.CreationDate, \$_.CommandLine }";
        $out = ps_run($script);
        foreach (preg_split('/\r\n|\r|\n/', $out) as $l) {
            if (trim($l) === '' || strpos($l, '@@') === false) continue;
            $p = explode('@@', $l, 3);
            $rows[] = ['pid' => (int)$p[0], 'created' => $p[1] ?? '', 'cmd' => $p[2] ?? ''];
        }
    }
    return $rows;
}
function server_stats(){
    $cacheFile = DATA_DIR . '/cache_stats.json';
    $c = read_json($cacheFile, []);
    if (!empty($c['t']) && (time() - (int)$c['t']) < 5) return $c;
    $cpu = null; $memUsed = null; $memTotal = null;
    if (is_win()) {
        $script = "\$c=(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average\n"
                . "\$o=Get-CimInstance Win32_OperatingSystem\n"
                . "Write-Output ('{0}|{1}|{2}' -f \$c, (\$o.TotalVisibleMemorySize-\$o.FreePhysicalMemory), \$o.TotalVisibleMemorySize)";
        $out = trim(ps_run($script));
        $p = explode('|', $out);
        if (count($p) === 3) { $cpu = round((float)$p[0], 1); $memUsed = (float)$p[1] * 1024; $memTotal = (float)$p[2] * 1024; }
    } else {
        $l = @file_get_contents('/proc/loadavg');
        if ($l) { $x = explode(' ', trim($l)); $cpu = round(((float)$x[0]) * 10, 1); }
    }
    $free = @disk_free_space('C:' . DIRECTORY_SEPARATOR); $total = @disk_total_space('C:' . DIRECTORY_SEPARATOR);
    if ($free === false) { $free = @disk_free_space(BASE_DIR); $total = @disk_total_space(BASE_DIR); }
    $c = ['t' => time(), 'cpu' => $cpu, 'mem_used' => $memUsed, 'mem_total' => $memTotal,
          'disk_free' => $free ?: 0, 'disk_total' => $total ?: 0];
    write_json($cacheFile, $c);
    return $c;
}

/* ================================ ÇALIŞMA ANI ============================= */
function runtime_all(){
    if (!isset($GLOBALS['RT_CACHE']) || !is_array($GLOBALS['RT_CACHE'])) $GLOBALS['RT_CACHE'] = read_json(RUN_DIR . '/runtime.json', []);
    return $GLOBALS['RT_CACHE'];
}
function runtime_save($r){ write_json(RUN_DIR . '/runtime.json', $r); $GLOBALS['RT_CACHE'] = $r; }
function runtime_get($id){ $r = runtime_all(); return isset($r[$id]) && is_array($r[$id]) ? $r[$id] : []; }
function runtime_set($id, array $data){ $r = runtime_all(); $r[$id] = array_merge(isset($r[$id]) && is_array($r[$id]) ? $r[$id] : [], $data); runtime_save($r); }

/* ================================= KANALLAR =============================== */
function channels_file(){ return DATA_DIR . '/channels.json'; }
function channels_all(){
    $list = read_json(channels_file(), []);
    if (!is_array($list)) $list = [];
    $out = [];
    foreach ($list as $c) if (is_array($c) && !empty($c['id'])) $out[$c['id']] = $c;
    return $out;
}
function channel_get($id){ $c = channels_all(); return $c[$id] ?? null; }
function channels_save_all($list){ return write_json(channels_file(), array_values($list)); }

function default_channel($id = ''){
    return [
        'id'            => $id ?: ('bant_' . rid(6)),
        'name'          => '',
        'color'         => '#ff2e4d',
        'source_type'   => 'playlist',
        'items'         => [],
        'source_url'    => '',
        'source_live'   => 0,
        'rtmp_server'   => '',
        'stream_key'    => '',
        'logo'          => '',
        'logo_pos'      => 'tr',
        'logo_scale'    => 12,
        'logo_opacity'  => 92,
        'logo_margin'   => 28,
        'width'         => 1280,
        'height'        => 720,
        'fps'           => 25,
        'video_bitrate' => 2500,
        'audio_bitrate' => 128,
        'preset'        => 'veryfast',
        'volume'        => 100,
        'silent_audio'  => 0,
        'extra_args'    => '',
        'watchdog'      => 1,
        'auto_start'    => 0,
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ];
}
function channel_output_url($ch){
    $srv = trim((string)($ch['rtmp_server'] ?? ''));
    $key = trim((string)($ch['stream_key'] ?? ''));
    if ($srv === '') return '';
    $srv = rtrim($srv, '/');
    if ($key === '') return $srv;
    return $srv . '/' . ltrim($key, '/');
}
function output_format_for($url){
    $u = strtolower($url);
    if (strpos($u, 'rtmp') === 0 || strpos($u, 'srt') === 0 || strpos($u, 'rtsp') === 0) return strpos($u, 'srt') === 0 ? 'mpegts' : 'flv';
    if (substr($u, -5) === '.m3u8') return 'hls';
    if (substr($u, -4) === '.mp4')  return 'mp4';
    if (substr($u, -4) === '.ts')   return 'mpegts';
    return 'flv';
}
function log_path_for($id){ return LOG_DIR . '/bant_' . preg_replace('/[^a-z0-9_]/i', '', (string)$id) . '.log'; }

function ffconcat_quote($p){
    $p = nix_path($p);
    return "'" . str_replace("'", "'\\''", $p) . "'";
}
function write_playlist($ch){
    $lines = ['ffconcat version 1.0'];
    foreach ((array)($ch['items'] ?? []) as $it) {
        if (($it['type'] ?? 'file') === 'url') { $p = trim((string)($it['url'] ?? '')); }
        else { $p = trim((string)($it['path'] ?? '')); }
        if ($p === '') continue;
        $lines[] = 'file ' . ffconcat_quote($p);
    }
    $path = RUN_DIR . '/playlist_' . preg_replace('/[^a-z0-9_]/i', '', (string)$ch['id']) . '.txt';
    @file_put_contents($path, implode("\n", $lines) . "\n");
    return $path;
}
function logo_pos_expr($pos, $m){
    $m = max(0, (int)$m);
    switch ($pos) {
        case 'tl': return [$m, $m];
        case 'tc': return ['(main_w-overlay_w)/2', $m];
        case 'tr': return ['main_w-overlay_w-' . $m, $m];
        case 'ml': return [$m, '(main_h-overlay_h)/2'];
        case 'mc': return ['(main_w-overlay_w)/2', '(main_h-overlay_h)/2'];
        case 'mr': return ['main_w-overlay_w-' . $m, '(main_h-overlay_h)/2'];
        case 'bl': return [$m, 'main_h-overlay_h-' . $m];
        case 'bc': return ['(main_w-overlay_w)/2', 'main_h-overlay_h-' . $m];
        case 'br': default: return ['main_w-overlay_w-' . $m, 'main_h-overlay_h-' . $m];
    }
}
function tokenize_extra($str){
    $str = trim((string)$str); if ($str === '') return [];
    $out = []; $cur = ''; $q = false;
    for ($i = 0, $n = strlen($str); $i < $n; $i++) {
        $c = $str[$i];
        if ($c === '"') { $q = !$q; continue; }
        if (!$q && ($c === ' ' || $c === "\t")) { if ($cur !== '') { $out[] = $cur; $cur = ''; } continue; }
        $cur .= $c;
    }
    if ($cur !== '') $out[] = $cur;
    return $out;
}
function build_ffmpeg_args($ch){
    $W = max(160, (int)($ch['width'] ?? 1280)); $W -= $W % 2;
    $H = max(120, (int)($ch['height'] ?? 720));  $H -= $H % 2;
    $fps = (int)($ch['fps'] ?? 25); if ($fps < 1 || $fps > 120) $fps = 25;
    $vbr = max(200, (int)($ch['video_bitrate'] ?? 2500));
    $abr = max(32,  (int)($ch['audio_bitrate'] ?? 128));
    $presets = ['ultrafast','superfast','veryfast','faster','fast','medium','slow'];
    $preset = in_array($ch['preset'] ?? '', $presets, true) ? $ch['preset'] : 'veryfast';

    $out = channel_output_url($ch);
    if ($out === '') return [[], 'Yayın linki (RTMP sunucusu) boş.'];

    $args = ['-hide_banner', '-nostdin', '-loglevel', 'info'];
    $logoIndex = -1; $audioIndex = 0;

    if (($ch['source_type'] ?? 'playlist') === 'url') {
        $src = trim((string)($ch['source_url'] ?? ''));
        if ($src === '') return [[], 'Kaynak yayın/medya URL adresi boş.'];
        if (empty($ch['source_live'])) $args[] = '-re';
        $args = array_merge($args, ['-i', $src]);
    } else {
        $items = array_values(array_filter((array)($ch['items'] ?? []), function($i){
            return (($i['type'] ?? 'file') === 'url' && trim((string)($i['url'] ?? '')) !== '') || trim((string)($i['path'] ?? '')) !== '';
        }));
        if (!$items) return [[], 'Oynatma listesi boş — en az bir medya ekleyin.'];
        if (count($items) === 1 && ($items[0]['type'] ?? 'file') === 'file') {
            $p = nix_path($items[0]['path']);
            if (!is_file(win_path($p))) return [[], 'Medya bulunamadı: ' . ($items[0]['name'] ?? $p)];
            $args = array_merge($args, ['-stream_loop', '-1', '-re', '-i', $p]);
        } else {
            $pl = write_playlist($ch);
            if (!is_file($pl)) return [[], 'Oynatma listesi dosyası oluşturulamadı.'];
            $args = array_merge($args, ['-re', '-f', 'concat', '-safe', '0', '-stream_loop', '-1', '-i', nix_path($pl)]);
        }
    }

    $logoPath = '';
    if (!empty($ch['logo'])) {
        $cand = LOGO_DIR . '/' . basename((string)$ch['logo']);
        if (is_file($cand)) { $logoPath = $cand; $logoIndex = 1; }
    }
    if ($logoPath !== '') $args = array_merge($args, ['-i', nix_path($logoPath)]);

    if (!empty($ch['silent_audio'])) {
        $args = array_merge($args, ['-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100']);
        $audioIndex = ($logoPath !== '') ? 2 : 1;
    }

    $vf = "[0:v]scale={$W}:{$H}:force_original_aspect_ratio=decrease:flags=lanczos,"
        . "pad={$W}:{$H}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps={$fps},format=yuv420p";
    $mapV = '[vout]';
    if ($logoPath !== '') {
        $pct = min(60, max(2, (float)($ch['logo_scale'] ?? 12)));
        $lw  = (int)round($W * $pct / 100); $lw -= $lw % 2; if ($lw < 16) $lw = 16;
        $op  = min(1, max(0.05, (float)($ch['logo_opacity'] ?? 92) / 100));
        $op  = round($op, 3);
        $pos = logo_pos_expr($ch['logo_pos'] ?? 'br', $ch['logo_margin'] ?? 28);
        $vf .= "[base];[{$logoIndex}:v]scale={$lw}:-2:format=rgba,colorchannelmixer=aa={$op}[logo];"
             . "[base][logo]overlay=" . $pos[0] . ':' . $pos[1] . ":eof_action=repeat:format=auto[vout]";
    } else {
        $vf .= "[vout]";
    }
    $args = array_merge($args, ['-filter_complex', $vf, '-map', $mapV]);

    if (!empty($ch['silent_audio'])) $args = array_merge($args, ['-map', $audioIndex . ':a']);
    else $args = array_merge($args, ['-map', '0:a?']);

    $vol = (float)($ch['volume'] ?? 100);
    if (empty($ch['silent_audio']) && $vol > 0 && abs($vol - 100) > 0.5) {
        $args = array_merge($args, ['-af', 'volume=' . round($vol / 100, 3)]);
    }

    $args = array_merge($args, [
        '-c:v', 'libx264', '-preset', $preset, '-tune', 'zerolatency',
        '-b:v', $vbr . 'k', '-maxrate', $vbr . 'k', '-bufsize', ($vbr * 2) . 'k',
        '-g', (string)($fps * 2), '-keyint_min', (string)$fps, '-sc_threshold', '0',
        '-profile:v', 'high', '-pix_fmt', 'yuv420p',
        '-c:a', 'aac', '-b:a', $abr . 'k', '-ar', '44100', '-ac', '2',
        '-flags', '+global_header',
    ]);
    $extra = tokenize_extra($ch['extra_args'] ?? '');
    if ($extra) $args = array_merge($args, $extra);

    $fmt = output_format_for($out);
    $args = array_merge($args, ['-f', $fmt]);
    if ($fmt === 'flv') $args = array_merge($args, ['-flvflags', 'no_duration_filesize']);
    if ($fmt === 'hls') $args = array_merge($args, ['-hls_time', '4', '-hls_list_size', '6', '-hls_flags', 'delete_segments+append_list']);
    $args[] = $out;

    return [$args, ''];
}
function full_command_string($ch){
    list($args, $err) = build_ffmpeg_args($ch);
    if ($err) return ['cmd' => '', 'error' => $err];
    $line = arg_line($args);
    return ['cmd' => '"' . win_path(ffmpeg_path()) . '"' . ($line ? ' ' . $line : ''), 'error' => ''];
}

function parse_telemetry($text){
    $t = ['frame' => null, 'fps' => null, 'q' => null, 'bitrate' => null, 'speed' => null, 'size' => null, 'time' => null];
    $text = str_replace("\r", "\n", (string)$text);
    $lines = explode("\n", $text);
    $last = '';
    foreach ($lines as $l) if (strpos($l, 'frame=') !== false) $last = $l;
    if ($last === '') return $t;
    if (preg_match('/frame=\s*(\d+)/', $last, $m)) $t['frame'] = (int)$m[1];
    if (preg_match('/fps=\s*([\d\.]+)/', $last, $m)) $t['fps'] = (float)$m[1];
    if (preg_match('/bitrate=\s*([\d\.]+)kbits\/s/', $last, $m)) $t['bitrate'] = (float)$m[1];
    if (preg_match('/speed=\s*([\d\.]+)x/', $last, $m)) $t['speed'] = (float)$m[1];
    if (preg_match('/size=\s*(\S+)/', $last, $m)) $t['size'] = $m[1];
    if (preg_match('/time=(\S+)/', $last, $m)) $t['time'] = $m[1];
    return $t;
}
function log_errors_of($text){
    $text = str_replace("\r", "\n", (string)$text);
    $out = [];
    foreach (explode("\n", $text) as $l) {
        if (preg_match('/(error|failed|invalid|no such|unrecognized|cannot|permission denied)/i', $l)) $out[] = trim($l);
    }
    return array_slice(array_reverse($out), 0, 4);
}

function channel_state($ch){
    $rt = runtime_get($ch['id']);
    $pid = (int)($rt['pid'] ?? 0);
    $want = !empty($rt['want_running']);
    $alive = $pid > 0 && pid_alive($pid);
    if ($alive) return ['state' => 'running', 'pid' => $pid, 'rt' => $rt];
    if ($want)  return ['state' => 'error', 'pid' => 0, 'rt' => $rt];
    return ['state' => 'stopped', 'pid' => 0, 'rt' => $rt];
}

function channel_start($ch, $restartCount = 0){
    $id = $ch['id'];
    $st = channel_state($ch);
    if ($st['state'] === 'running') return ['ok' => false, 'msg' => 'Bu bant zaten yayında (PID ' . $st['pid'] . ').'];
    if (!ffmpeg_ok()) return ['ok' => false, 'msg' => 'ffmpeg bulunamadı: ' . win_path(ffmpeg_path()) . ' — Ayarlar\'dan yolu düzeltin.'];
    list($args, $err) = build_ffmpeg_args($ch);
    if ($err) return ['ok' => false, 'msg' => $err];

    $log = log_path_for($id);
    $outLog = $log . '.out';
    @file_put_contents($log, "=== " . date('Y-m-d H:i:s') . " | BANT BAŞLATILIYOR: " . ($ch['name'] ?: $id) . " ===\r\n");
    @file_put_contents($outLog, '');

    $res = launch_detached(win_path(ffmpeg_path()), $args, $log, $outLog);
    if (!$res['pid']) {
        runtime_set($id, ['pid' => 0, 'want_running' => false, 'last_error' => $res['error'], 'stopped_at' => time()]);
        return ['ok' => false, 'msg' => 'Başlatılamadı: ' . $res['error']];
    }
    pid_note((int)$res['pid'], true);
    $rt = runtime_get($id);
    runtime_set($id, [
        'pid'        => $res['pid'],
        'want_running' => true,
        'started_at' => time(),
        'restarts'   => (int)($rt['restarts'] ?? 0) + (int)$restartCount,
        'fail_streak' => $restartCount > 0 ? (int)($rt['fail_streak'] ?? 0) : 0,
        'last_error' => '',
        'log'        => $log,
        'cmd'        => '"' . win_path(ffmpeg_path()) . '" ' . arg_line($args),
        'target'     => channel_output_url($ch),
    ]);
    return ['ok' => true, 'msg' => '“' . ($ch['name'] ?: $id) . '” yayına alındı (PID ' . $res['pid'] . ').'];
}
function channel_stop($ch, $killOrphans = true){
    $id = $ch['id'];
    $rt = runtime_get($id);
    $pid = (int)($rt['pid'] ?? 0);
    $killed = 0;
    if ($pid > 0 && kill_pid($pid)) $killed++;
    if ($killOrphans) {
        $target = channel_output_url($ch);
        if ($target !== '') {
            foreach (pids_by_needle(substr($target, -40)) as $p) { if ($p !== $pid) { kill_pid($p); $killed++; } }
        }
    }
    runtime_set($id, ['pid' => 0, 'want_running' => false, 'stopped_at' => time(), 'last_error' => '']);
    return ['ok' => true, 'msg' => '“' . ($ch['name'] ?: $id) . '” yayından alındı.' . ($killed > 1 ? ' (' . $killed . ' süreç sonlandırıldı)' : '')];
}
function watchdog_tick(){
    $fixed = [];
    foreach (channels_all() as $ch) {
        $rt = runtime_get($ch['id']);
        if (empty($rt['want_running']) || empty($ch['watchdog'])) continue;
        $pid = (int)(isset($rt['pid']) ? $rt['pid'] : 0);
        if ($pid > 0 && pid_alive($pid)) {
            // sağlıklı çalışıyor: kısa ömürlü çökme sayacını sıfırla
            if (!empty($rt['started_at']) && (time() - (int)$rt['started_at']) > 90 && (int)($rt['fail_streak'] ?? 0) > 0) {
                runtime_set($ch['id'], ['fail_streak' => 0]);
            }
            continue;
        }
        if (!empty($rt['started_at']) && (time() - (int)$rt['started_at']) < 6) continue;
        $err = '';
        $lp = log_path_for($ch['id']);
        if (is_file($lp)) $err = implode(' | ', log_errors_of(tail_bytes($lp, 8000)));
        $streak = (int)($rt['fail_streak'] ?? 0);
        // arka arkaya 3 kez erken çöktüyse döngüyü kır (sonsuz yeniden başlatmayı önle)
        $lived = !empty($rt['started_at']) ? (time() - (int)$rt['started_at']) : 0;
        if ($streak >= 3) {
            runtime_set($ch['id'], ['want_running' => false, 'pid' => 0,
                'last_error' => 'Watchdog durduruldu: bant 3 kez üst üste çöktü. ' . ($err ?: 'Logları kontrol edin.')]);
            $fixed[] = ($ch['name'] ?: $ch['id']) . ': watchdog devre dışı (3 ardışık çökme)';
            continue;
        }
        $res = channel_start($ch, 1);
        if ($res['ok']) {
            runtime_set($ch['id'], ['fail_streak' => $lived < 25 ? $streak + 1 : 0]);
        } else {
            runtime_set($ch['id'], ['fail_streak' => $streak + 1, 'want_running' => false, 'last_error' => $res['msg']]);
        }
        $fixed[] = ($ch['name'] ?: $ch['id']) . ': ' . $res['msg'] . ($err ? ' [önceki hata: ' . $err . ']' : '');
    }
    return $fixed;
}

/* ================================== MEDYA ================================= */
function media_ext_ok($name, $kinds = ['video','audio','image']){
    $e = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    $vid = ['mp4','mov','mkv','avi','flv','ts','m2ts','webm','mpg','mpeg','wmv','m4v','3gp'];
    $aud = ['mp3','wav','aac','m4a','ogg','flac'];
    $img = ['jpg','jpeg','png','webp','bmp'];
    $ok = [];
    if (in_array('video', $kinds, true)) $ok = array_merge($ok, $vid);
    if (in_array('audio', $kinds, true)) $ok = array_merge($ok, $aud);
    if (in_array('image', $kinds, true)) $ok = array_merge($ok, $img);
    return in_array($e, $ok, true);
}
function media_kind_of($name){
    $e = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    if (in_array($e, ['mp3','wav','aac','m4a','ogg','flac'], true)) return 'audio';
    if (in_array($e, ['jpg','jpeg','png','webp','bmp'], true)) return 'image';
    return 'video';
}
function media_meta_all(){ return read_json(DATA_DIR . '/media_meta.json', []); }
function media_meta_save($m){ write_json(DATA_DIR . '/media_meta.json', $m); }
function external_paths(){ return read_json(DATA_DIR . '/external.json', []); }
function external_save($l){ write_json(DATA_DIR . '/external.json', array_values($l)); }

function ffprobe_info($file){
    $ff = win_path(ffprobe_path());
    if (!is_file($ff) || !is_file($file)) return null;
    $cmd = '"' . $ff . '" -v error -show_entries format=duration:stream=width,height,codec_name -of json ' . escapeshellarg($file);
    $out = (string)@shell_exec($cmd . ' 2>NUL');
    $j = json_decode($out, true);
    if (!is_array($j)) return null;
    $dur = isset($j['format']['duration']) ? (float)$j['format']['duration'] : 0;
    $w = 0; $hh = 0; $codec = '';
    foreach ((array)($j['streams'] ?? []) as $s) {
        if (($s['codec_type'] ?? '') === 'video' || !empty($s['width'])) { $w = (int)($s['width'] ?? 0); $hh = (int)($s['height'] ?? 0); $codec = (string)($s['codec_name'] ?? ''); break; }
    }
    return ['duration' => $dur, 'width' => $w, 'height' => $hh, 'codec' => $codec];
}
function make_thumb($file, $thumbPath){
    $ff = win_path(ffmpeg_path());
    if (!is_file($ff)) return false;
    if (media_kind_of($file) === 'image') { @copy($file, $thumbPath); return is_file($thumbPath); }
    foreach ([2, 0] as $ss) {
        $cmd = '"' . $ff . '" -hide_banner -loglevel error -y -ss ' . $ss . ' -i ' . escapeshellarg($file)
             . ' -frames:v 1 -vf "scale=320:-2" ' . escapeshellarg($thumbPath) . ' 2>NUL';
        @shell_exec($cmd);
        if (is_file($thumbPath) && filesize($thumbPath) > 100) return true;
    }
    return false;
}
function media_list(){
    $meta = media_meta_all();
    $items = [];
    foreach ((array)@scandir(MEDIA_DIR) as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = MEDIA_DIR . '/' . $f;
        if (!is_file($full) || !media_ext_ok($f)) continue;
        $key = 'media/' . $f;
        if (empty($meta[$key]) || empty($meta[$key]['probed'])) {
            $info = ffprobe_info($full) ?: [];
            $meta[$key] = array_merge(['probed' => 1], $info);
        }
        $items[] = [
            'key' => $key, 'name' => $f, 'path' => nix_path($full), 'origin' => 'Yüklendi',
            'size' => filesize($full), 'mtime' => filemtime($full), 'kind' => media_kind_of($f),
            'duration' => (float)($meta[$key]['duration'] ?? 0),
            'width' => (int)($meta[$key]['width'] ?? 0), 'height' => (int)($meta[$key]['height'] ?? 0),
            'codec' => (string)($meta[$key]['codec'] ?? ''),
            'thumb' => is_file(THUMB_DIR . '/' . $f . '.jpg') ? $f . '.jpg' : '',
        ];
    }
    media_meta_save($meta);
    foreach (external_paths() as $p) {
        $p = nix_path((string)$p);
        if ($p === '' || !is_file(win_path($p))) continue;
        $f = basename($p); $key = 'ext:' . $p;
        if (empty($meta[$key]) || empty($meta[$key]['probed'])) {
            $info = ffprobe_info(win_path($p)) ?: [];
            $meta[$key] = array_merge(['probed' => 1], $info);
        }
        $items[] = [
            'key' => $key, 'name' => $f, 'path' => $p, 'origin' => 'Sunucu yolu',
            'size' => filesize(win_path($p)), 'mtime' => filemtime(win_path($p)), 'kind' => media_kind_of($f),
            'duration' => (float)($meta[$key]['duration'] ?? 0),
            'width' => (int)($meta[$key]['width'] ?? 0), 'height' => (int)($meta[$key]['height'] ?? 0),
            'codec' => (string)($meta[$key]['codec'] ?? ''), 'thumb' => '',
        ];
    }
    media_meta_save($meta);
    usort($items, function($a, $b){ return strcmp($a['name'], $b['name']); });
    return $items;
}
function media_index(){ $m = []; foreach (media_list() as $it) $m[$it['key']] = $it; return $m; }

/* ============================== İSTEK YÖNETİMİ ============================ */
ensure_dirs();
sess_boot();
$SETTINGS = settings_load();

/* --- CLI modu: php index.php cli start_all | stop_all | watchdog --- */
if ($IS_CLI) {
    $argvLocal = isset($argv) ? $argv : (isset($_SERVER['argv']) ? $_SERVER['argv'] : []);
    $mode = isset($argvLocal[1]) ? (string)$argvLocal[1] : '';
    $sub  = isset($argvLocal[2]) ? (string)$argvLocal[2] : '';
    if ($mode === 'cli' || $mode === 'start_all' || $mode === 'stop_all' || $mode === 'watchdog') {
        if ($mode === 'cli') $mode = $sub;
        if ($mode === 'start_all') {
            $n = 0;
            foreach (channels_all() as $ch) {
                if (empty($ch['auto_start'])) continue;
                $r = channel_start($ch);
                echo ($r['ok'] ? '[OK] ' : '[HATA] ') . $r['msg'] . PHP_EOL;
                if ($r['ok']) $n++;
            }
            echo "Toplam {$n} bant başlatıldı." . PHP_EOL;
        } elseif ($mode === 'stop_all') {
            foreach (channels_all() as $ch) { $r = channel_stop($ch); echo $r['msg'] . PHP_EOL; }
        } elseif ($mode === 'watchdog') {
            echo "Watchdog başladı (5 sn aralık). Durdurmak için Ctrl+C" . PHP_EOL;
            while (true) { $f = watchdog_tick(); foreach ($f as $x) echo date('H:i:s') . ' ' . $x . PHP_EOL; sleep(5); }
        }
        exit;
    }
}

$ACTION = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$PAGE   = (string)($_GET['page'] ?? 'panel');
$JSON   = function($data){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; };

function channel_from_post($P, $existing = []){
    $ch = $existing ? array_merge(default_channel(), $existing) : default_channel();
    $str = function($k, $d = '') use ($P){ return isset($P[$k]) ? trim((string)$P[$k]) : $d; };
    $int = function($k, $d = 0) use ($P){ return isset($P[$k]) && $P[$k] !== '' ? (int)$P[$k] : $d; };
    $ch['name']          = $str('name');
    $ch['color']         = preg_match('/^#[0-9a-fA-F]{6}$/', $str('color', '#ff2e4d')) ? $str('color', '#ff2e4d') : '#ff2e4d';
    $ch['source_type']   = $str('source_type', 'playlist') === 'url' ? 'url' : 'playlist';
    $ch['source_url']    = $str('source_url');
    $ch['source_live']   = $int('source_live', 0) ? 1 : 0;
    $ch['rtmp_server']   = $str('rtmp_server');
    $ch['stream_key']    = $str('stream_key');
    $ch['logo_pos']      = $str('logo_pos', 'br');
    $ch['logo_scale']    = max(2, min(60, $int('logo_scale', 12)));
    $ch['logo_opacity']  = max(5, min(100, $int('logo_opacity', 92)));
    $ch['logo_margin']   = max(0, min(400, $int('logo_margin', 28)));
    $ch['width']         = max(160, $int('width', 1280));
    $ch['height']        = max(120, $int('height', 720));
    $ch['fps']           = max(1, min(120, $int('fps', 25)));
    $ch['video_bitrate'] = max(200, $int('video_bitrate', 2500));
    $ch['audio_bitrate'] = max(32, $int('audio_bitrate', 128));
    $ch['preset']        = $str('preset', 'veryfast');
    $ch['volume']        = max(0, min(300, $int('volume', 100)));
    $ch['silent_audio']  = $int('silent_audio', 0) ? 1 : 0;
    $ch['extra_args']    = $str('extra_args');
    $ch['watchdog']      = $int('watchdog', 1) ? 1 : 0;
    $ch['auto_start']    = $int('auto_start', 0) ? 1 : 0;
    $ch['updated_at']    = date('Y-m-d H:i:s');
    $items = [];
    $raw = $str('items_json', '');
    if ($raw !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            foreach ($dec as $it) {
                if (!is_array($it)) continue;
                if (($it['type'] ?? 'file') === 'url') {
                    $u = trim((string)($it['url'] ?? ''));
                    if ($u !== '') $items[] = ['type' => 'url', 'url' => $u, 'name' => trim((string)($it['name'] ?? $u))];
                } else {
                    $p = trim((string)($it['path'] ?? ''));
                    if ($p !== '') $items[] = ['type' => 'file', 'path' => nix_path($p), 'name' => trim((string)($it['name'] ?? basename($p))), 'duration' => (float)($it['duration'] ?? 0)];
                }
            }
        }
    }
    $ch['items'] = $items;
    return $ch;
}

/* ------------------------------ JSON UÇLARI ------------------------------ */
if ($ACTION !== '' && $ACTION !== 'login') {
    if (!is_authed() && !in_array($ACTION, ['asset'], true)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) $JSON(['ok' => false, 'msg' => 'Oturum gerekli']);
    }
}
if (is_authed() && $ACTION === 'status') {
    watchdog_tick();
    $data = [];
    foreach (channels_all() as $ch) {
        $st = channel_state($ch);
        $rt = $st['rt'];
        $tel = ['frame' => null, 'fps' => null, 'bitrate' => null, 'speed' => null];
        $lp = log_path_for($ch['id']);
        if (is_file($lp)) $tel = parse_telemetry(tail_bytes($lp, 6000));
        $data[] = [
            'id' => $ch['id'], 'name' => $ch['name'] ?: $ch['id'], 'state' => $st['state'], 'pid' => $st['pid'],
            'uptime' => ($st['state'] === 'running' && !empty($rt['started_at'])) ? (time() - (int)$rt['started_at']) : 0,
            'restarts' => (int)($rt['restarts'] ?? 0), 'tel' => $tel,
            'target' => channel_output_url($ch),
        ];
    }
    $JSON(['ok' => true, 'channels' => $data, 'server' => server_stats(), 'ts' => time(), 'ffmpeg_ok' => ffmpeg_ok()]);
}
if (is_authed() && $ACTION === 'log') {
    $id = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['id'] ?? ''));
    $lp = log_path_for($id);
    $txt = is_file($lp) ? tail_bytes($lp, 40000) : '';
    $lines = preg_split('/\r\n|\r|\n/', str_replace("\r", "\n", $txt));
    $lines = array_slice(array_values(array_filter($lines, function($l){ return trim($l) !== ''; })), -260);
    $JSON(['ok' => true, 'lines' => $lines, 'tel' => parse_telemetry($txt), 'exists' => is_file($lp)]);
}
if (is_authed() && $ACTION === 'procs') {
    $JSON(['ok' => true, 'procs' => ffmpeg_processes()]);
}
if (is_authed() && $ACTION === 'kill_pid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) $JSON(['ok' => false, 'msg' => 'Oturum doğrulaması başarısız.']);
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid <= 0) $JSON(['ok' => false, 'msg' => 'Geçersiz PID.']);
    kill_pid($pid);
    foreach (channels_all() as $c) { $rt = runtime_get($c['id']); if ((int)($rt['pid'] ?? 0) === $pid) runtime_set($c['id'], ['pid' => 0, 'want_running' => false, 'stopped_at' => time()]); }
    $JSON(['ok' => true, 'msg' => 'PID ' . $pid . ' sonlandırıldı.']);
}
if (is_authed() && $ACTION === 'build_cmd' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $P = $_POST;
    $existing = !empty($P['id']) ? (channel_get((string)$P['id']) ?: []) : [];
    $ch = channel_from_post($P, $existing);
    if (!empty($P['logo_keep']) && !empty($existing['logo'])) $ch['logo'] = $existing['logo'];
    $r = full_command_string($ch);
    $JSON(['ok' => $r['error'] === '', 'cmd' => $r['cmd'], 'error' => $r['error']]);
}

/* ------------------------------- GİRİŞ/ÇIKIŞ ------------------------------ */
if ($ACTION === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $locked = (!empty($SETTINGS['fail_count']) && $SETTINGS['fail_count'] >= 8 && (time() - (int)$SETTINGS['fail_time']) < 300);
    if ($locked) { flash('err', 'Çok fazla hatalı deneme. 5 dakika bekleyin.'); header('Location: index.php'); exit; }
    $u = (string)($_POST['user'] ?? ''); $p = (string)($_POST['pass'] ?? '');
    if (hash_equals((string)$SETTINGS['username'], $u) && password_verify($p, (string)$SETTINGS['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true; $_SESSION['auth_time'] = time(); $_SESSION['user'] = $u;
        $SETTINGS['fail_count'] = 0; settings_save($SETTINGS);
        flash('ok', 'Hoş geldin, ' . $u . '. Kontrol sende.');
        header('Location: index.php?page=panel'); exit;
    }
    $SETTINGS['fail_count'] = (int)$SETTINGS['fail_count'] + 1; $SETTINGS['fail_time'] = time(); settings_save($SETTINGS);
    flash('err', 'Kullanıcı adı veya şifre hatalı.');
    header('Location: index.php'); exit;
}
if (!is_authed() && $ACTION !== 'login') { $PAGE = 'login'; }

if (is_authed() && $_SERVER['REQUEST_METHOD'] === 'POST' && $ACTION !== '') {
    if (!csrf_ok()) { flash('err', 'Güvenlik doğrulaması başarısız (oturum yenilendi).'); header('Location: index.php?page=' . urlencode($PAGE)); exit; }

    if ($ACTION === 'logout') {
        $_SESSION = []; session_destroy(); header('Location: index.php'); exit;
    }
    if ($ACTION === 'channel_save') {
        $id = (string)($_POST['id'] ?? '');
        $list = channels_all();
        $existing = ($id !== '' && isset($list[$id])) ? $list[$id] : [];
        $ch = channel_from_post($_POST, $existing);
        if ($existing) $ch['id'] = $id; else $ch['id'] = 'bant_' . rid(6);
        if ($ch['name'] === '') $ch['name'] = 'Bant ' . (count($list) + 1);

        // logo yükleme
        if (!empty($_POST['logo_remove'])) $ch['logo'] = '';
        if (!empty($_FILES['logo_file']['name']) && ($_FILES['logo_file']['error'] ?? 4) === UPLOAD_ERR_OK) {
            $orig = (string)$_FILES['logo_file']['name'];
            if (!media_ext_ok($orig, ['image'])) flash('err', 'Logo yalnızca PNG/JPG/WEBP olabilir.');
            else {
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $name = preg_replace('/[^a-z0-9_]/i', '', $ch['id']) . '_logo.' . $ext;
                if (@move_uploaded_file($_FILES['logo_file']['tmp_name'], LOGO_DIR . '/' . $name)) {
                    if (!empty($existing['logo']) && $existing['logo'] !== $name) @unlink(LOGO_DIR . '/' . basename($existing['logo']));
                    $ch['logo'] = $name;
                    flash('ok', 'Logo yüklendi: ' . $name);
                } else flash('err', 'Logo kaydedilemedi (klasör yazma izni).');
            }
        } elseif (!empty($_POST['logo_keep']) && !empty($existing['logo'])) {
            $ch['logo'] = $existing['logo'];
        }
        if ($ch['source_type'] === 'playlist' && !$ch['items']) flash('err', 'Uyarı: oynatma listesi boş, bant başlatılamaz.');
        if (channel_output_url($ch) === '') flash('err', 'Uyarı: yayın linki boş.');

        $list[$ch['id']] = $ch;
        channels_save_all($list);
        flash('ok', '“' . $ch['name'] . '” kaydedildi.');
        $st = channel_state($ch);
        if ($st['state'] === 'running' && !empty($_POST['apply_restart'])) {
            channel_stop($ch); $r = channel_start($ch); flash($r['ok'] ? 'ok' : 'err', $r['msg']);
        }
        header('Location: index.php?page=bantlar'); exit;
    }
    if ($ACTION === 'channel_delete') {
        $id = (string)($_POST['id'] ?? ''); $list = channels_all();
        if (isset($list[$id])) {
            $ch = $list[$id];
            if (channel_state($ch)['state'] === 'running') channel_stop($ch);
            if (!empty($ch['logo'])) @unlink(LOGO_DIR . '/' . basename((string)$ch['logo']));
            @unlink(log_path_for($id)); @unlink(log_path_for($id) . '.out');
            @unlink(RUN_DIR . '/playlist_' . preg_replace('/[^a-z0-9_]/i', '', $id) . '.txt');
            unset($list[$id]); channels_save_all($list);
            $r = runtime_all(); unset($r[$id]); runtime_save($r);
            flash('ok', 'Bant silindi.');
        }
        header('Location: index.php?page=bantlar'); exit;
    }
    if ($ACTION === 'channel_start' || $ACTION === 'channel_stop' || $ACTION === 'channel_restart') {
        $id = (string)($_POST['id'] ?? ''); $ch = channel_get($id);
        if (!$ch) { flash('err', 'Bant bulunamadı.'); }
        else {
            if ($ACTION === 'channel_start') { $r = channel_start($ch); flash($r['ok'] ? 'ok' : 'err', $r['msg']); }
            if ($ACTION === 'channel_stop')  { $r = channel_stop($ch); flash($r['ok'] ? 'ok' : 'err', $r['msg']); }
            if ($ACTION === 'channel_restart') {
                channel_stop($ch); sleep(1); $r = channel_start($ch); flash($r['ok'] ? 'ok' : 'err', 'Yeniden başlatıldı: ' . $r['msg']);
            }
        }
        header('Location: index.php?page=' . urlencode((string)($_POST['back'] ?? 'bantlar'))); exit;
    }
    if ($ACTION === 'start_all' || $ACTION === 'stop_all') {
        $n = 0;
        foreach (channels_all() as $ch) {
            if ($ACTION === 'start_all') { $r = channel_start($ch); if ($r['ok']) $n++; elseif (strpos($r['msg'], 'zaten') === false) flash('err', $r['msg']); }
            else { channel_stop($ch); $n++; }
        }
        flash('ok', $ACTION === 'start_all' ? ($n . ' bant yayına alındı.') : ($n . ' bant durduruldu.'));
        header('Location: index.php?page=' . urlencode($PAGE)); exit;
    }
    if ($ACTION === 'media_upload') {
        $files = $_FILES['media'] ?? []; $count = 0; $errc = 0;
        if (!empty($files['name']) && is_array($files['name'])) {
            $meta = media_meta_all();
            for ($i = 0; $i < count($files['name']); $i++) {
                if (($files['error'][$i] ?? 4) !== UPLOAD_ERR_OK) { if (($files['error'][$i] ?? 4) !== UPLOAD_ERR_NO_FILE) $errc++; continue; }
                $orig = (string)$files['name'][$i];
                if (!media_ext_ok($orig, ['video','audio'])) { flash('err', 'Desteklenmeyen tür: ' . $orig); $errc++; continue; }
                $name = clean_filename($orig);
                $target = MEDIA_DIR . '/' . $name;
                if (is_file($target)) $target = MEDIA_DIR . '/' . pathinfo($name, PATHINFO_FILENAME) . '_' . rid(4) . '.' . pathinfo($name, PATHINFO_EXTENSION);
                if (@move_uploaded_file($files['tmp_name'][$i], $target)) {
                    $info = ffprobe_info($target) ?: [];
                    $meta['media/' . basename($target)] = array_merge(['probed' => 1], $info);
                    make_thumb($target, THUMB_DIR . '/' . basename($target) . '.jpg');
                    $count++;
                } else $errc++;
            }
            media_meta_save($meta);
        }
        flash($count ? 'ok' : 'err', $count . ' dosya kütüphaneye eklendi.' . ($errc ? ' (' . $errc . ' hata)' : ''));
        if ($count === 0 && $errc === 0) flash('err', 'Dosya seçilmedi. Büyük dosyalar için upload_max_filesize / post_max_size değerlerini artırın ya da “Sunucu yolu ekle” seçeneğini kullanın.');
        header('Location: index.php?page=medya'); exit;
    }
    if ($ACTION === 'media_add_path') {
        $p = nix_path(trim((string)($_POST['path'] ?? '')));
        if ($p === '' || !is_file(win_path($p))) flash('err', 'Bu yol geçerli bir dosya değil: ' . $p);
        elseif (!media_ext_ok($p, ['video','audio'])) flash('err', 'Desteklenmeyen dosya türü.');
        else {
            $ext = external_paths();
            if (!in_array($p, $ext, true)) { $ext[] = $p; external_save($ext); flash('ok', 'Sunucu dosyası kütüphaneye bağlandı: ' . basename($p)); }
            else flash('err', 'Bu dosya zaten ekli.');
        }
        header('Location: index.php?page=medya'); exit;
    }
    if ($ACTION === 'media_delete') {
        $key = (string)($_POST['key'] ?? '');
        if (strpos($key, 'ext:') === 0) {
            $p = substr($key, 4); $ext = array_values(array_filter(external_paths(), function($x) use ($p){ return $x !== $p; }));
            external_save($ext); flash('ok', 'Bağlantı kaldırıldı (dosya silinmedi).');
        } else {
            $f = basename($key); $full = MEDIA_DIR . '/' . $f;
            if (is_file($full) && media_ext_ok($f, ['video','audio','image'])) {
                @unlink($full); @unlink(THUMB_DIR . '/' . $f . '.jpg');
                $meta = media_meta_all(); unset($meta['media/' . $f]); media_meta_save($meta);
                flash('ok', 'Silindi: ' . $f);
            } else flash('err', 'Dosya bulunamadı.');
        }
        header('Location: index.php?page=medya'); exit;
    }
    if ($ACTION === 'media_thumbs') {
        $n = 0;
        foreach (media_list() as $it) {
            if ($it['origin'] !== 'Yüklendi') continue;
            $tp = THUMB_DIR . '/' . $it['name'] . '.jpg';
            if (is_file($tp)) continue;
            if (make_thumb(win_path($it['path']), $tp)) $n++;
        }
        flash('ok', $n . ' küçük resim oluşturuldu.');
        header('Location: index.php?page=medya'); exit;
    }
    if ($ACTION === 'settings_save') {
        $SETTINGS['ffmpeg']  = nix_path(trim((string)($_POST['ffmpeg'] ?? DEF_FFMPEG)));
        $SETTINGS['ffprobe'] = nix_path(trim((string)($_POST['ffprobe'] ?? DEF_FFPROBE)));
        $SETTINGS['username'] = trim((string)($_POST['username'] ?? DEF_USER)) ?: DEF_USER;
        $SETTINGS['poll'] = max(2, min(30, (int)($_POST['poll'] ?? 4)));
        $SETTINGS['log_keep'] = max(50, min(2000, (int)($_POST['log_keep'] ?? 400)));
        settings_save($SETTINGS);
        flash('ok', 'Ayarlar kaydedildi.');
        if (ffmpeg_ok()) flash('ok', 'ffmpeg doğrulandı: ' . win_path($SETTINGS['ffmpeg']));
        else flash('err', 'DİKKAT: ffmpeg bu yolda bulunamadı → ' . win_path($SETTINGS['ffmpeg']));
        header('Location: index.php?page=ayarlar'); exit;
    }
    if ($ACTION === 'password_change') {
        $cur = (string)($_POST['cur'] ?? ''); $new = (string)($_POST['new'] ?? ''); $new2 = (string)($_POST['new2'] ?? '');
        if (!password_verify($cur, (string)$SETTINGS['pass_hash'])) flash('err', 'Mevcut şifre hatalı.');
        elseif (strlen($new) < 6) flash('err', 'Yeni şifre en az 6 karakter olmalı.');
        elseif ($new !== $new2) flash('err', 'Yeni şifreler eşleşmiyor.');
        else { $SETTINGS['pass_hash'] = password_hash($new, PASSWORD_DEFAULT); $SETTINGS['is_default'] = 0; settings_save($SETTINGS); flash('ok', 'Şifre güncellendi.'); }
        header('Location: index.php?page=ayarlar'); exit;
    }
    if ($ACTION === 'ffmpeg_test') {
        $out = (string)@shell_exec('"' . win_path(ffmpeg_path()) . '" -hide_banner -version 2>&1');
        $_SESSION['fftest'] = $out !== '' ? $out : 'Çıktı alınamadı. Yol doğru mu? PHP\'nin powershell/cmd çalıştırma izni var mı?';
        header('Location: index.php?page=ayarlar'); exit;
    }
    if ($ACTION === 'log_clear') {
        $id = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['id'] ?? ''));
        @file_put_contents(log_path_for($id), "=== " . date('Y-m-d H:i:s') . " | log temizlendi ===\r\n");
        flash('ok', 'Log temizlendi.');
        header('Location: index.php?page=loglar&id=' . urlencode($id)); exit;
    }
    if ($ACTION === 'make_bat') {
        $php = nix_path((string)(PHP_BINARY ?: 'php'));
        $self = nix_path(__FILE__);
        $bat1 = RUN_DIR . '/../bant_start_all.bat';
        @file_put_contents(DATA_DIR . '/bant_start_all.bat', "@echo off\r\n\"" . win_path($php) . "\" \"" . win_path($self) . "\" cli start_all\r\n");
        @file_put_contents(DATA_DIR . '/bant_watchdog.bat', "@echo off\r\n:loop\r\n\"" . win_path($php) . "\" \"" . win_path($self) . "\" cli watchdog\r\nping -n 6 127.0.0.1 > nul\r\ngoto loop\r\n");
        flash('ok', 'BAT dosyaları oluşturuldu: data\\bant_start_all.bat ve data\\bant_watchdog.bat');
        header('Location: index.php?page=ayarlar'); exit;
    }
    if ($ACTION === 'kill_orphan') {
        $n = 0;
        foreach (ffmpeg_processes() as $p) { kill_pid((int)$p['pid']); $n++; }
        flash($n ? 'ok' : 'err', $n ? ($n . ' başıboş ffmpeg süreci sonlandırıldı.') : 'Çalışan ffmpeg süreci yok.');
        header('Location: index.php?page=loglar'); exit;
    }
}

/* ------------------------------ VARLIK SERVİSİ ---------------------------- */
if ($ACTION === 'asset' && is_authed()) {
    $kind = (string)($_GET['kind'] ?? '');
    $name = basename((string)($_GET['name'] ?? ''));
    $dir = $kind === 'logo' ? LOGO_DIR : ($kind === 'thumb' ? THUMB_DIR : MEDIA_DIR);
    $file = $dir . '/' . $name;
    if ($name !== '' && is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'bmp' => 'image/bmp'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        readfile($file); exit;
    }
    http_response_code(404); exit('yok');
}

/* ================================== VERİLER ============================== */
$CHANNELS = is_authed() ? channels_all() : [];
$MEDIA    = is_authed() ? media_list() : [];
$EDIT_ID  = (string)($_GET['edit'] ?? '');
$EDIT     = ($EDIT_ID !== '' && isset($CHANNELS[$EDIT_ID])) ? $CHANNELS[$EDIT_ID] : null;
if ($ACTION === 'new') $EDIT = default_channel('');
$E        = ($EDIT !== null) ? $EDIT : default_channel('');
$isNew    = empty($E['id']) || !isset($CHANNELS[$E['id']]);
$FLASHES  = take_flashes();

if (!is_authed()) { $PAGE = 'login'; }
else { watchdog_tick(); }

$RUN_LIST = [];
foreach ($CHANNELS as $ch) { $st = channel_state($ch); if ($st['state'] === 'running') $RUN_LIST[] = ['ch' => $ch, 'st' => $st]; }

$TITLES = ['panel' => 'Kontrol Paneli', 'bantlar' => 'Yayın Bantları', 'medya' => 'Medya Kütüphanesi',
           'loglar' => 'Loglar & Süreçler', 'ayarlar' => 'Ayarlar', 'yardim' => 'Kurulum & Yardım'];
$TITLE = $TITLES[$PAGE] ?? 'Kontrol Paneli';
$SELF_URL = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($TITLE) ?> · <?= h(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ============================ TEMEL / PALET ============================== */
:root{
  --ink:#0b0e15; --ink2:#111623; --ink3:#171e2d; --ink4:#1e2739;
  --line:#25304a; --line2:#31405e;
  --paper:#e9edf6; --muted:#8b98b2; --muted2:#65728d;
  --red:#ff2e4d; --amber:#ffb02e; --teal:#20d3a6; --blue:#4c8dff; --violet:#a06bff;
  --ok:#20d3a6; --warn:#ffb02e; --err:#ff2e4d;
  --sidebar:246px;
  --r-s:6px; --r-m:12px; --r-l:18px;
  --mono:'IBM Plex Mono',ui-monospace,Consolas,monospace;
  --disp:'Anton',Impact,sans-serif;
  --body:'IBM Plex Sans',system-ui,sans-serif;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  font-family:var(--body); color:var(--paper); background:var(--ink);
  font-size:14.5px; line-height:1.55; -webkit-font-smoothing:antialiased;
  min-height:100vh; overflow-x:hidden;
}
body::before{
  content:''; position:fixed; inset:0; z-index:-2; pointer-events:none;
  background:
    radial-gradient(900px 520px at 88% -8%, rgba(76,141,255,.16), transparent 62%),
    radial-gradient(760px 460px at -6% 104%, rgba(255,46,77,.13), transparent 60%),
    radial-gradient(620px 380px at 42% 46%, rgba(32,211,166,.06), transparent 70%),
    linear-gradient(180deg,#0b0e15 0%,#0a0d14 60%,#080b11 100%);
}
body::after{
  content:''; position:fixed; inset:0; z-index:-1; pointer-events:none; opacity:.5;
  background-image:
    repeating-linear-gradient(0deg, rgba(255,255,255,.028) 0 1px, transparent 1px 3px),
    linear-gradient(rgba(255,255,255,.022) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.022) 1px, transparent 1px);
  background-size:auto, 46px 46px, 46px 46px;
  mask-image:radial-gradient(1200px 700px at 60% 20%, #000 20%, transparent 88%);
}
.sweep{position:fixed;left:0;right:0;top:0;height:180px;z-index:-1;pointer-events:none;
  background:linear-gradient(180deg,rgba(76,141,255,.09),transparent);
  animation:sweep 9s linear infinite;opacity:.55}
@keyframes sweep{0%{transform:translateY(-180px)}100%{transform:translateY(105vh)}}
a{color:var(--blue);text-decoration:none}
a:hover{color:#8fb6ff}
::selection{background:var(--red);color:#fff}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-track{background:#0a0d14}
::-webkit-scrollbar-thumb{background:#26314a;border-radius:8px;border:2px solid #0a0d14}
::-webkit-scrollbar-thumb:hover{background:#3a4a6e}

/* ================================== GİRİŞ ================================ */
.login{min-height:100vh;display:grid;grid-template-columns:1.25fr .95fr;gap:0}
.login-left{padding:56px 60px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;
  border-right:1px solid var(--line)}
.login-left::after{content:'';position:absolute;right:-160px;top:50%;transform:translateY(-50%);width:520px;height:520px;
  border:1px solid rgba(76,141,255,.18);border-radius:50%;animation:pulseRing 5s ease-in-out infinite}
.login-left::before{content:'';position:absolute;right:-90px;top:50%;transform:translateY(-50%);width:340px;height:340px;
  border:1px dashed rgba(255,46,77,.22);border-radius:50%;animation:pulseRing 7s ease-in-out infinite reverse}
@keyframes pulseRing{0%,100%{opacity:.35;transform:translateY(-50%) scale(1)}50%{opacity:.9;transform:translateY(-50%) scale(1.06)}}
.brandmark{display:flex;align-items:center;gap:12px;font-family:var(--disp);letter-spacing:.06em;font-size:20px;text-transform:uppercase}
.brandmark .dot{width:11px;height:11px;border-radius:50%;background:var(--red);box-shadow:0 0 0 4px rgba(255,46,77,.16),0 0 18px var(--red);animation:blink 1.6s infinite}
@keyframes blink{0%,45%{opacity:1}55%,100%{opacity:.25}}
.login h1{font-family:var(--disp);font-weight:400;text-transform:uppercase;line-height:.92;margin:38px 0 0;
  font-size:clamp(46px,7.2vw,104px);letter-spacing:-.01em}
.login h1 em{font-style:normal;color:var(--red);-webkit-text-stroke:0}
.login h1 span.out{color:transparent;-webkit-text-stroke:1.6px var(--line2)}
.login-sub{max-width:520px;color:var(--muted);margin-top:22px;font-size:15.5px}
.login-feats{display:flex;flex-wrap:wrap;gap:8px;margin-top:26px}
.chip{font-family:var(--mono);font-size:11px;letter-spacing:.08em;text-transform:uppercase;border:1px solid var(--line);
  padding:5px 10px;border-radius:99px;color:var(--muted);background:rgba(255,255,255,.02)}
.chip b{color:var(--teal);font-weight:600}
.eqbars{display:flex;align-items:flex-end;gap:4px;height:56px;margin-top:34px}
.eqbars i{display:block;width:6px;background:linear-gradient(180deg,var(--red),var(--amber));border-radius:2px;animation:eq 1.2s ease-in-out infinite}
@keyframes eq{0%,100%{height:14%}50%{height:100%}}
.login-right{display:flex;align-items:center;justify-content:center;padding:44px}
.login-card{width:100%;max-width:380px;background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.012));
  border:1px solid var(--line);border-radius:var(--r-l);padding:30px 28px;position:relative;
  box-shadow:0 30px 80px -30px rgba(0,0,0,.9)}
.login-card::before{content:'';position:absolute;top:-1px;left:24px;right:24px;height:2px;background:linear-gradient(90deg,transparent,var(--red),var(--amber),transparent)}
.login-card h2{font-family:var(--disp);text-transform:uppercase;font-weight:400;font-size:26px;letter-spacing:.04em;margin:0 0 4px}
.login-card p.hint{color:var(--muted2);font-size:12.5px;margin:0 0 22px}
@media(max-width:940px){.login{grid-template-columns:1fr}.login-left{padding:36px 26px;border-right:0;border-bottom:1px solid var(--line)}.login-right{padding:30px 22px}}

/* ================================ GENEL FORM ============================= */
label.lb{display:block;font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin:0 0 6px}
.inp,select.inp,textarea.inp{
  width:100%;background:#0c1119;border:1px solid var(--line);color:var(--paper);border-radius:var(--r-s);
  padding:10px 12px;font-family:var(--body);font-size:14px;transition:border-color .18s,box-shadow .18s,background .18s}
textarea.inp{font-family:var(--mono);font-size:12.5px;resize:vertical}
.inp:focus,select.inp:focus,textarea.inp:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(76,141,255,.16);background:#0e1420}
.inp::placeholder{color:#4d5a75}
select.inp{appearance:none;background-image:linear-gradient(45deg,transparent 50%,var(--muted) 50%),linear-gradient(135deg,var(--muted) 50%,transparent 50%);
  background-position:calc(100% - 18px) 50%,calc(100% - 13px) 50%;background-size:5px 5px,5px 5px;background-repeat:no-repeat}
.field{margin-bottom:14px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.row3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.row4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:720px){.row2,.row3,.row4{grid-template-columns:1fr 1fr}}
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line2);background:linear-gradient(180deg,#1c2434,#141b28);
  color:var(--paper);padding:9px 15px;border-radius:var(--r-s);font-family:var(--body);font-weight:600;font-size:13px;cursor:pointer;
  transition:transform .12s,box-shadow .18s,border-color .18s,background .18s;white-space:nowrap}
.btn:hover{border-color:#4a5c82;transform:translateY(-1px);box-shadow:0 10px 22px -14px rgba(0,0,0,.9)}
.btn:active{transform:translateY(1px) scale(.99)}
.btn.pri{background:linear-gradient(180deg,#ff4a63,#e01234);border-color:#ff5f76;color:#fff;box-shadow:0 12px 30px -16px rgba(255,46,77,.9)}
.btn.pri:hover{filter:brightness(1.07)}
.btn.ok{background:linear-gradient(180deg,#25e0b0,#0fae86);border-color:#2ff0bd;color:#04231b}
.btn.warn{background:linear-gradient(180deg,#ffc44d,#e79308);border-color:#ffd070;color:#2a1b00}
.btn.ghost{background:transparent}
.btn.danger{background:linear-gradient(180deg,#3a1a22,#2a1218);border-color:#5b2531;color:#ff8ea0}
.btn.sm{padding:6px 10px;font-size:12px}
.btn.xs{padding:4px 8px;font-size:11px;border-radius:5px}
.btn[disabled]{opacity:.45;pointer-events:none}
.pill{display:inline-flex;align-items:center;gap:6px;font-family:var(--mono);font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;
  padding:4px 9px;border-radius:99px;border:1px solid var(--line2);color:var(--muted);background:rgba(255,255,255,.03)}
.pill i{width:7px;height:7px;border-radius:50%;background:currentColor;display:block}
.pill.on{color:#ffd7dd;border-color:rgba(255,46,77,.5);background:rgba(255,46,77,.14)}
.pill.on i{background:var(--red);box-shadow:0 0 10px var(--red);animation:blink 1.4s infinite}
.pill.off{color:var(--muted)}
.pill.err{color:#ffe2b0;border-color:rgba(255,176,46,.45);background:rgba(255,176,46,.12)}
.pill.err i{background:var(--amber);animation:blink .8s infinite}
.pill.teal{color:#bff5e6;border-color:rgba(32,211,166,.4);background:rgba(32,211,166,.1)}

/* ================================ YERLEŞİM =============================== */
.shell{display:flex;min-height:100vh}
.sidebar{width:var(--sidebar);flex:0 0 var(--sidebar);border-right:1px solid var(--line);background:linear-gradient(180deg,rgba(17,22,35,.92),rgba(9,12,19,.92));
  position:sticky;top:0;height:100vh;display:flex;flex-direction:column;z-index:40}
.side-brand{padding:22px 20px 18px;border-bottom:1px solid var(--line)}
.side-brand .t1{font-family:var(--disp);font-size:23px;letter-spacing:.05em;text-transform:uppercase;line-height:1}
.side-brand .t2{font-family:var(--mono);font-size:10px;letter-spacing:.24em;color:var(--muted2);text-transform:uppercase;margin-top:6px}
.onair{margin-top:14px;display:flex;align-items:center;gap:9px;border:1px solid rgba(255,46,77,.4);background:rgba(255,46,77,.1);
  border-radius:var(--r-s);padding:7px 10px;font-family:var(--mono);font-size:10.5px;letter-spacing:.16em;text-transform:uppercase;color:#ffc9d2}
.onair.live{box-shadow:inset 0 0 24px rgba(255,46,77,.18),0 0 22px -8px rgba(255,46,77,.6)}
.onair b{margin-left:auto;font-size:13px;color:#fff;font-family:var(--disp);letter-spacing:.04em}
.nav{padding:14px 12px;display:flex;flex-direction:column;gap:3px;overflow-y:auto;flex:1}
.nav a{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:var(--r-s);color:var(--muted);
  font-weight:600;font-size:13.5px;position:relative;transition:background .18s,color .18s,padding .18s}
.nav a .ic{width:20px;text-align:center;font-family:var(--mono);font-size:13px;opacity:.85}
.nav a:hover{background:rgba(255,255,255,.045);color:var(--paper);padding-left:16px}
.nav a.active{background:linear-gradient(90deg,rgba(255,46,77,.16),rgba(255,46,77,0));color:#fff}
.nav a.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:var(--red);box-shadow:0 0 12px var(--red)}
.nav a .cnt{margin-left:auto;font-family:var(--mono);font-size:10.5px;background:rgba(255,255,255,.07);border-radius:99px;padding:1px 7px;color:var(--muted)}
.side-foot{padding:14px;border-top:1px solid var(--line);font-family:var(--mono);font-size:10.5px;color:var(--muted2);line-height:1.9}
.side-foot .kv{display:flex;justify-content:space-between;gap:8px}
.side-foot .kv b{color:var(--paper);font-weight:500}
.bar{height:4px;background:#1a2233;border-radius:99px;overflow:hidden;margin-top:3px}
.bar span{display:block;height:100%;background:linear-gradient(90deg,var(--teal),var(--blue));border-radius:99px;transition:width .6s cubic-bezier(.2,.7,.3,1)}

.main{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:18px;padding:15px 26px;
  border-bottom:1px solid var(--line);background:rgba(10,13,20,.82);backdrop-filter:blur(10px)}
.topbar h1{font-family:var(--disp);font-weight:400;text-transform:uppercase;letter-spacing:.03em;font-size:22px;margin:0}
.topbar .sub{font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;color:var(--muted2);text-transform:uppercase}
.top-right{margin-left:auto;display:flex;align-items:center;gap:14px}
.clock{font-family:var(--mono);font-size:13px;color:var(--paper);letter-spacing:.06em}
.clock small{color:var(--muted2);display:block;font-size:10px;letter-spacing:.14em}
.content{padding:26px;flex:1;max-width:1560px;width:100%}
.ticker{border-top:1px solid var(--line);background:rgba(9,12,19,.9);overflow:hidden;height:32px;display:flex;align-items:center}
.ticker .track{display:flex;gap:44px;white-space:nowrap;animation:tick 34s linear infinite;font-family:var(--mono);font-size:11px;color:var(--muted2);letter-spacing:.08em}
.ticker .track b{color:var(--teal);font-weight:500}
.ticker .track u{color:var(--amber);text-decoration:none}
@keyframes tick{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
@media(max-width:1000px){
  .shell{flex-direction:column}.sidebar{width:100%;flex:0 0 auto;height:auto;position:relative}
  .nav{flex-direction:row;overflow-x:auto;padding:10px}.nav a{white-space:nowrap}.side-foot{display:none}
  .content{padding:18px}.topbar{padding:12px 16px}
}

/* ================================= KARTLAR =============================== */
.card{background:linear-gradient(180deg,rgba(255,255,255,.038),rgba(255,255,255,.012));border:1px solid var(--line);
  border-radius:var(--r-m);position:relative}
.card.pad{padding:20px}
.card h3{font-family:var(--disp);font-weight:400;text-transform:uppercase;letter-spacing:.05em;font-size:16px;margin:0 0 3px}
.card .hd{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--line)}
.card .hd .sub{font-family:var(--mono);font-size:10px;letter-spacing:.16em;color:var(--muted2);text-transform:uppercase}
.card .hd .right{margin-left:auto;display:flex;gap:8px;align-items:center}
.card .bd{padding:18px}
.reveal{opacity:0;transform:translateY(16px);transition:opacity .6s ease,transform .6s cubic-bezier(.2,.7,.3,1)}
.reveal.in{opacity:1;transform:none}

.grid-dash{display:grid;grid-template-columns:1.55fr .95fr;gap:18px;align-items:start}
@media(max-width:1180px){.grid-dash{grid-template-columns:1fr}}

/* ON AIR hero */
.hero{position:relative;overflow:hidden;border-radius:var(--r-l);border:1px solid var(--line2);
  background:radial-gradient(120% 130% at 12% 0%,rgba(255,46,77,.2),transparent 55%),linear-gradient(160deg,#151b29,#0c1017 70%);
  padding:24px 26px;clip-path:polygon(0 0,100% 0,100% calc(100% - 22px),calc(100% - 22px) 100%,0 100%)}
.hero .kicker{font-family:var(--mono);font-size:10.5px;letter-spacing:.28em;text-transform:uppercase;color:var(--amber)}
.hero .bigname{font-family:var(--disp);text-transform:uppercase;font-size:clamp(34px,5.2vw,62px);line-height:.95;margin:8px 0 2px;letter-spacing:-.005em}
.hero .target{font-family:var(--mono);font-size:12px;color:var(--muted);word-break:break-all}
.hero-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.metric{border:1px solid var(--line);background:rgba(8,11,18,.6);border-radius:var(--r-s);padding:9px 13px;min-width:104px}
.metric .k{font-family:var(--mono);font-size:9.5px;letter-spacing:.16em;color:var(--muted2);text-transform:uppercase}
.metric .v{font-family:var(--disp);font-size:24px;letter-spacing:.02em;line-height:1.15;margin-top:2px}
.metric .v small{font-family:var(--mono);font-size:11px;color:var(--muted);letter-spacing:0}
#wave{width:100%;height:78px;display:block;margin-top:18px;border:1px solid var(--line);border-radius:var(--r-s);background:rgba(6,9,15,.66)}
.vu{display:flex;align-items:flex-end;gap:3px;height:26px}
.vu i{width:4px;background:linear-gradient(180deg,var(--amber),var(--red));border-radius:1px;height:20%;animation:vu .9s ease-in-out infinite}
.vu i:nth-child(2n){animation-duration:1.15s;background:linear-gradient(180deg,var(--teal),var(--blue))}
.vu i:nth-child(3n){animation-duration:.72s}
.vu i:nth-child(4n){animation-duration:1.4s}
@keyframes vu{0%,100%{height:16%}50%{height:100%}}
.vu.idle i{animation-play-state:paused;height:12%;opacity:.35}

.statlist{display:flex;flex-direction:column;gap:1px;background:var(--line);border-radius:var(--r-m);overflow:hidden;border:1px solid var(--line)}
.statrow{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#101623;transition:background .2s}
.statrow:hover{background:#141c2b}
.statrow .k{font-family:var(--mono);font-size:10.5px;letter-spacing:.13em;text-transform:uppercase;color:var(--muted2)}
.statrow .v{margin-left:auto;font-family:var(--disp);font-size:20px;letter-spacing:.02em}
.statrow .v.sm{font-family:var(--mono);font-size:12.5px;color:var(--paper);font-weight:500}

/* kanal satırları */
.chanlist{display:flex;flex-direction:column;gap:12px}
.chan{display:grid;grid-template-columns:78px 1fr auto;gap:16px;align-items:center;padding:14px 16px;
  border:1px solid var(--line);border-radius:var(--r-m);background:linear-gradient(100deg,rgba(255,255,255,.04),rgba(255,255,255,.008));
  transition:transform .18s,border-color .2s,box-shadow .25s;position:relative;overflow:hidden}
.chan::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--cc,var(--red));opacity:.85}
.chan:hover{transform:translateY(-2px);border-color:var(--line2);box-shadow:0 22px 40px -28px rgba(0,0,0,.95)}
.chan.live{border-color:rgba(255,46,77,.35);background:linear-gradient(100deg,rgba(255,46,77,.1),rgba(255,255,255,.01))}
.thumb{width:78px;height:52px;border-radius:var(--r-s);border:1px solid var(--line);background:#0a0e16 center/cover no-repeat;
  display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
.thumb img{max-width:78%;max-height:78%;object-fit:contain}
.thumb .ph{font-family:var(--disp);font-size:20px;color:#2c3852}
.chan .nm{font-family:var(--disp);text-transform:uppercase;font-size:19px;letter-spacing:.02em;line-height:1.1}
.chan .mt{font-family:var(--mono);font-size:11px;color:var(--muted2);margin-top:4px;display:flex;flex-wrap:wrap;gap:10px}
.chan .mt b{color:var(--muted);font-weight:500}
.chan .acts{display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.telmini{display:flex;gap:14px;margin-top:7px;font-family:var(--mono);font-size:11px;color:var(--muted)}
.telmini b{color:var(--teal);font-weight:600}

/* modal */
.modal{position:fixed;inset:0;z-index:90;display:none;align-items:flex-start;justify-content:center;padding:26px 18px;overflow-y:auto}
.modal.open{display:flex}
.modal .bd-bg{position:fixed;inset:0;background:rgba(5,7,12,.82);backdrop-filter:blur(5px);animation:fade .25s}
@keyframes fade{from{opacity:0}to{opacity:1}}
.modal .sheet{position:relative;width:100%;max-width:1180px;background:linear-gradient(180deg,#141a27,#0d121c);
  border:1px solid var(--line2);border-radius:var(--r-l);box-shadow:0 60px 120px -40px #000;animation:rise .3s cubic-bezier(.2,.8,.3,1)}
@keyframes rise{from{opacity:0;transform:translateY(22px) scale(.985)}to{opacity:1;transform:none}}
.sheet-hd{display:flex;align-items:center;gap:14px;padding:18px 22px;border-bottom:1px solid var(--line);position:sticky;top:0;background:#121824;z-index:2;border-radius:var(--r-l) var(--r-l) 0 0}
.sheet-hd h2{font-family:var(--disp);text-transform:uppercase;font-weight:400;font-size:22px;margin:0;letter-spacing:.03em}
.sheet-bd{padding:22px;display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:980px){.sheet-bd{grid-template-columns:1fr}}
.sheet-ft{display:flex;gap:10px;align-items:center;padding:16px 22px;border-top:1px solid var(--line);position:sticky;bottom:0;background:#111725;border-radius:0 0 var(--r-l) var(--r-l);flex-wrap:wrap}
.fs{border:1px solid var(--line);border-radius:var(--r-m);padding:16px;background:rgba(255,255,255,.02);margin-bottom:16px}
.fs>legend,.fs .fst{font-family:var(--mono);font-size:10.5px;letter-spacing:.2em;text-transform:uppercase;color:var(--amber);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.fs .fst::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--line),transparent)}
.span2{grid-column:1/-1}

.posgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;width:132px}
.posgrid label{position:relative;aspect-ratio:1.5;border:1px solid var(--line);border-radius:4px;cursor:pointer;background:#0c1119;transition:.18s}
.posgrid label:hover{border-color:var(--line2);background:#131a27}
.posgrid input{position:absolute;opacity:0;pointer-events:none}
.posgrid label span{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
.posgrid label span::after{content:'';width:12px;height:8px;background:#3a4a6e;border-radius:2px;transition:.18s}
.posgrid input:checked + span::after{background:var(--red);box-shadow:0 0 12px var(--red)}
.posgrid label:has(input:checked){border-color:rgba(255,46,77,.6);background:rgba(255,46,77,.08)}

.preview{position:relative;aspect-ratio:16/9;border-radius:var(--r-s);border:1px solid var(--line2);overflow:hidden;
  background:
   radial-gradient(120% 100% at 30% 10%,#2b3b5c,#0d1420 70%),
   repeating-linear-gradient(45deg,rgba(255,255,255,.03) 0 8px,transparent 8px 16px)}
.preview .scan{position:absolute;inset:0;background:repeating-linear-gradient(0deg,rgba(0,0,0,.22) 0 2px,transparent 2px 4px);opacity:.55}
.preview .safe{position:absolute;inset:6%;border:1px dashed rgba(255,255,255,.14);border-radius:3px}
.preview .tag{position:absolute;left:8px;bottom:6px;font-family:var(--mono);font-size:9.5px;letter-spacing:.16em;color:rgba(255,255,255,.45);text-transform:uppercase}
.preview .lg{position:absolute;max-width:60%;max-height:60%;object-fit:contain;transition:left .18s,top .18s,transform .18s,opacity .18s;filter:drop-shadow(0 4px 12px rgba(0,0,0,.6))}
.preview .nolabel{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:11px;color:rgba(255,255,255,.35);letter-spacing:.1em}

input[type=range]{-webkit-appearance:none;appearance:none;width:100%;height:4px;background:#1e2739;border-radius:99px;outline:none}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:var(--amber);cursor:pointer;
  box-shadow:0 0 0 4px rgba(255,176,46,.16);transition:transform .15s}
input[type=range]::-webkit-slider-thumb:hover{transform:scale(1.15)}
input[type=range]::-moz-range-thumb{width:16px;height:16px;border:0;border-radius:50%;background:var(--amber);cursor:pointer}
.sw{display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;padding:7px 0}
.sw input{display:none}
.sw .track{width:40px;height:22px;border-radius:99px;background:#1c2434;border:1px solid var(--line);position:relative;transition:.22s;flex:0 0 auto}
.sw .track::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#54637f;transition:.22s}
.sw input:checked + .track{background:rgba(32,211,166,.22);border-color:rgba(32,211,166,.55)}
.sw input:checked + .track::after{left:20px;background:var(--teal);box-shadow:0 0 12px var(--teal)}
.sw .txt{font-size:13px;color:var(--muted)}
.sw .txt b{display:block;color:var(--paper);font-size:13.5px;font-weight:600}

.cmdbox{background:#070a11;border:1px solid var(--line);border-radius:var(--r-s);padding:13px;font-family:var(--mono);
  font-size:11.5px;line-height:1.75;color:#9fd8c4;word-break:break-all;white-space:pre-wrap;max-height:210px;overflow:auto}
.cmdbox .err{color:#ff9aa9}

/* tablolar */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted2);text-align:left;
  padding:9px 12px;border-bottom:1px solid var(--line);background:rgba(255,255,255,.02);position:sticky;top:0}
.tbl td{padding:10px 12px;border-bottom:1px solid rgba(37,48,74,.55);vertical-align:middle}
.tbl tr:hover td{background:rgba(255,255,255,.028)}
.tbl .nm{font-weight:600}
.tbl .mono{font-family:var(--mono);font-size:11.5px;color:var(--muted)}
.kindchip{font-family:var(--mono);font-size:9.5px;letter-spacing:.12em;text-transform:uppercase;padding:2px 7px;border-radius:4px;border:1px solid}
.kindchip.video{color:#9ec1ff;border-color:rgba(76,141,255,.4);background:rgba(76,141,255,.1)}
.kindchip.audio{color:#c4b0ff;border-color:rgba(160,107,255,.4);background:rgba(160,107,255,.1)}
.kindchip.image{color:#a9f0dc;border-color:rgba(32,211,166,.4);background:rgba(32,211,166,.1)}

.liblist{max-height:330px;overflow:auto;border:1px solid var(--line);border-radius:var(--r-s);background:#0b1018}
.libitem{display:flex;align-items:center;gap:10px;padding:8px 10px;border-bottom:1px solid rgba(37,48,74,.5);transition:background .15s}
.libitem:hover{background:rgba(76,141,255,.07)}
.libitem .nm{font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:190px}
.libitem .ds{font-family:var(--mono);font-size:10px;color:var(--muted2)}
.libitem .add{margin-left:auto}
.plist{border:1px solid var(--line);border-radius:var(--r-s);background:#0b1018;min-height:120px;max-height:330px;overflow:auto}
.pitem{display:flex;align-items:center;gap:9px;padding:8px 10px;border-bottom:1px solid rgba(37,48,74,.5)}
.pitem .no{font-family:var(--disp);font-size:15px;color:var(--muted2);width:22px;text-align:center}
.pitem .nm{font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.pitem .dur{font-family:var(--mono);font-size:10.5px;color:var(--teal)}
.empty{padding:26px;text-align:center;color:var(--muted2);font-family:var(--mono);font-size:11.5px;letter-spacing:.1em;text-transform:uppercase}

/* log */
.logbox{background:#060910;border:1px solid var(--line);border-radius:var(--r-s);padding:14px;font-family:var(--mono);
  font-size:11.5px;line-height:1.7;color:#8ea6c9;height:430px;overflow:auto;white-space:pre-wrap;word-break:break-word}
.logbox .l-err{color:#ff8b9c}.logbox .l-warn{color:#ffd08a}.logbox .l-ok{color:#7ce8c8}

/* toast */
.toasts{position:fixed;right:20px;bottom:20px;z-index:200;display:flex;flex-direction:column;gap:9px;max-width:380px}
.toast{border:1px solid var(--line2);border-left:3px solid var(--blue);background:rgba(16,21,33,.97);border-radius:var(--r-s);
  padding:11px 14px;font-size:13px;box-shadow:0 24px 50px -24px #000;animation:slidein .3s cubic-bezier(.2,.8,.3,1)}
.toast.ok{border-left-color:var(--teal)}.toast.err{border-left-color:var(--red)}.toast.warn{border-left-color:var(--amber)}
.toast b{font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted2);display:block;margin-bottom:2px}
@keyframes slidein{from{opacity:0;transform:translateX(28px)}to{opacity:1;transform:none}}

/* flash */
.flashes{display:flex;flex-direction:column;gap:9px;margin-bottom:18px}
.flash{display:flex;align-items:center;gap:11px;padding:11px 14px;border-radius:var(--r-s);border:1px solid var(--line);font-size:13.5px;
  background:rgba(76,141,255,.08);border-left:3px solid var(--blue);animation:rise .35s}
.flash.ok{background:rgba(32,211,166,.09);border-left-color:var(--teal)}
.flash.err{background:rgba(255,46,77,.09);border-left-color:var(--red)}
.flash .x{margin-left:auto;cursor:pointer;color:var(--muted2);font-family:var(--mono)}
.warnband{display:flex;gap:12px;align-items:center;padding:12px 16px;border-radius:var(--r-s);margin-bottom:18px;
  border:1px dashed rgba(255,176,46,.5);background:rgba(255,176,46,.07);font-size:13px;color:#ffe0ab}
.warnband b{font-family:var(--mono);letter-spacing:.1em;text-transform:uppercase;font-size:11px;color:var(--amber)}

.h2{font-family:var(--disp);text-transform:uppercase;font-weight:400;font-size:clamp(24px,3.4vw,38px);letter-spacing:.02em;margin:0 0 4px;line-height:1}
.kick{font-family:var(--mono);font-size:10.5px;letter-spacing:.26em;text-transform:uppercase;color:var(--amber);margin-bottom:8px}
.lead{color:var(--muted);max-width:74ch;margin:0 0 22px}
.sec-hd{display:flex;align-items:flex-end;gap:16px;margin:0 0 16px;flex-wrap:wrap}
.sec-hd .right{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.hr{height:1px;background:linear-gradient(90deg,var(--line),transparent);margin:26px 0}
.drop{border:2px dashed var(--line2);border-radius:var(--r-m);padding:26px;text-align:center;transition:.2s;background:rgba(255,255,255,.015)}
.drop.hover{border-color:var(--teal);background:rgba(32,211,166,.07);transform:scale(1.005)}
.drop .big{font-family:var(--disp);text-transform:uppercase;font-size:20px;letter-spacing:.04em}
.drop .sm{font-family:var(--mono);font-size:11px;color:var(--muted2);margin-top:6px;letter-spacing:.08em}
.helpline{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid rgba(37,48,74,.5)}
.helpline .n{font-family:var(--disp);font-size:26px;color:var(--line2);line-height:1;min-width:34px}
.helpline h4{margin:0 0 4px;font-size:14.5px}
.helpline p{margin:0;color:var(--muted);font-size:13px}
code.k{font-family:var(--mono);font-size:12px;background:#0a0e16;border:1px solid var(--line);border-radius:4px;padding:2px 6px;color:#9fd8c4}
.noteline{font-family:var(--mono);font-size:11px;color:var(--muted2);margin-top:6px}
.flexrow{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.mt0{margin-top:0}.mb0{margin-bottom:0}.mt8{margin-top:8px}.mt16{margin-top:16px}.mt24{margin-top:24px}
.muted{color:var(--muted)}.mono{font-family:var(--mono)}
</style>
</head>
<body class="pg-<?= h($PAGE) ?>">
<div class="sweep"></div>
<?php
/* ================================== GİRİŞ EKRANI ========================== */
if (!is_authed()): ?>
<div class="login">
  <div class="login-left">
    <div>
      <div class="brandmark"><span class="dot"></span> <?= h(APP_NAME) ?> <span style="color:var(--muted2);font-family:var(--mono);font-size:11px;letter-spacing:.2em">v<?= h(APP_VER) ?></span></div>
      <h1>Yayın<br><em>Bant</em><br><span class="out">Kontrol</span></h1>
      <p class="login-sub">Tek PHP dosyasıyla 7/24 kesintisiz yayın bant yönetimi. ffmpeg ile medya listelerini döngüye alır, logonuzu bindirir, RTMP yayın linkinize basar — süreci izler, koparsa otomatik kaldırır.</p>
      <div class="login-feats">
        <span class="chip">ffmpeg <b><?= ffmpeg_ok() ? 'HAZIR' : 'BULUNAMADI' ?></b></span>
        <span class="chip">logo overlay <b>9 KONUM</b></span>
        <span class="chip">rtmp / srt / hls <b>ÇIKIŞ</b></span>
        <span class="chip">watchdog <b>OTO. YENİDEN</b></span>
        <span class="chip">windows server <b>2022</b></span>
      </div>
      <div class="eqbars">
        <?php for ($i = 0; $i < 26; $i++): ?><i style="animation-delay:<?= round($i * 0.07, 2) ?>s;height:<?= 18 + ($i % 5) * 16 ?>%"></i><?php endfor; ?>
      </div>
    </div>
    <div class="mono" style="font-size:11px;color:var(--muted2);letter-spacing:.1em">
      SUNUCU: <?= h(php_uname('s') . ' ' . php_uname('r')) ?> &nbsp;·&nbsp; PHP <?= h(PHP_VERSION) ?> &nbsp;·&nbsp; <?= h(date('d.m.Y H:i')) ?>
    </div>
  </div>
  <div class="login-right">
    <form class="login-card" method="post" action="index.php">
      <input type="hidden" name="action" value="login">
      <h2>Panele Giriş</h2>
      <p class="hint">Yetkisiz erişim yayınınızı keser. Kimlik doğrulaması gerekli.</p>
      <?php foreach ($FLASHES as $f): ?>
        <div class="flash <?= h($f['type']) ?>" style="margin-bottom:12px"><?= h($f['msg']) ?></div>
      <?php endforeach; ?>
      <div class="field">
        <label class="lb" for="u">Kullanıcı</label>
        <input class="inp" id="u" name="user" value="<?= h(DEF_USER) ?>" autocomplete="username" required>
      </div>
      <div class="field">
        <label class="lb" for="p">Şifre</label>
        <input class="inp" id="p" name="pass" type="password" placeholder="••••••••" autocomplete="current-password" required>
      </div>
      <button class="btn pri" style="width:100%;justify-content:center;padding:12px" type="submit">GİRİŞ YAP →</button>
      <p class="noteline" style="text-align:center">İlk kurulum şifresi: <code class="k">band2024</code> — giriş sonrası Ayarlar'dan değiştirin.</p>
    </form>
  </div>
</div>
<?php
/* ================================== UYGULAMA ============================== */
else:
$SRV = server_stats();
$diskPct = $SRV['disk_total'] ? round((1 - $SRV['disk_free'] / $SRV['disk_total']) * 100) : 0;
$NAV = [
  ['panel',   'Panel',   '▤', ''],
  ['bantlar', 'Yayın Bantları', '▶', count($CHANNELS)],
  ['medya',   'Medya Kütüphanesi', '◫', count($MEDIA)],
  ['loglar',  'Loglar & Süreçler', '≡', ''],
  ['ayarlar', 'Ayarlar', '⚙', ''],
  ['yardim',  'Kurulum & Yardım', '?', ''],
];
?>
<div class="shell">
  <aside class="sidebar">
    <div class="side-brand">
      <div class="brandmark" style="font-size:17px"><span class="dot"></span> <?= h(APP_NAME) ?></div>
      <div class="t2">Yayın bant merkezi</div>
      <div class="onair <?= count($RUN_LIST) ? 'live' : '' ?>" id="onairBox">
        <span class="oadot" style="width:8px;height:8px;border-radius:50%;background:<?= count($RUN_LIST) ? 'var(--red)' : '#3c4a68' ?>;box-shadow:0 0 10px currentColor"></span>
        <span class="oalbl"><?= count($RUN_LIST) ? 'ON AIR' : 'YAYIN YOK' ?></span>
        <b id="onairCount"><?= count($RUN_LIST) ?></b>
      </div>
    </div>
    <nav class="nav">
      <?php foreach ($NAV as $n): ?>
      <a href="index.php?page=<?= h($n[0]) ?>" class="<?= $PAGE === $n[0] ? 'active' : '' ?>">
        <span class="ic"><?= $n[2] ?></span> <?= h($n[1]) ?>
        <?php if ($n[3] !== ''): ?><span class="cnt"><?= (int)$n[3] ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
      <div style="flex:1"></div>
      <a href="index.php" method="post" id="logoutLink"><span class="ic">⏻</span> Çıkış</a>
    </nav>
    <div class="side-foot">
      <div class="kv"><span>ffmpeg</span><b style="color:<?= ffmpeg_ok() ? 'var(--teal)' : 'var(--red)' ?>"><?= ffmpeg_ok() ? 'HAZIR' : 'YOK' ?></b></div>
      <div class="kv"><span>CPU</span><b id="sCpu"><?= $SRV['cpu'] === null ? '—' : $SRV['cpu'] . '%' ?></b></div>
      <div class="bar"><span id="sCpuBar" style="width:<?= min(100, (float)($SRV['cpu'] ?? 0)) ?>%"></span></div>
      <div class="kv mt8"><span>Disk (C:)</span><b><?= $diskPct ?>%</b></div>
      <div class="bar"><span style="width:<?= $diskPct ?>%;background:linear-gradient(90deg,var(--amber),var(--red))"></span></div>
      <div class="kv mt8"><span>Boş alan</span><b><?= human_size($SRV['disk_free']) ?></b></div>
      <div style="margin-top:10px;color:#4d5a75">v<?= h(APP_VER) ?> · <?= h($_SESSION['user'] ?? '') ?></div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div>
        <div class="sub"><?= h(APP_NAME) ?> / <?= h(strtoupper($PAGE)) ?></div>
        <h1><?= h($TITLE) ?></h1>
      </div>
      <div class="top-right">
        <div class="vu <?= count($RUN_LIST) ? '' : 'idle' ?>" id="vuTop">
          <?php for ($i = 0; $i < 14; $i++): ?><i style="animation-delay:<?= round($i * .09, 2) ?>s"></i><?php endfor; ?>
        </div>
        <div class="clock"><small>YEREL SAAT</small><span id="clk"><?= h(date('H:i:s')) ?></span></div>
        <?php if ($PAGE !== 'bantlar'): ?>
        <a class="btn pri sm" href="index.php?page=bantlar&action=new">+ YENİ BANT</a>
        <?php endif; ?>
      </div>
    </header>

    <main class="content">
    <?php foreach ($FLASHES as $f): ?>
      <div class="flash <?= h($f['type']) ?>"><?= h($f['msg']) ?><span class="x" onclick="this.parentNode.remove()">✕</span></div>
    <?php endforeach; ?>
    <?php if (!empty($SETTINGS['is_default'])): ?>
      <div class="warnband"><b>güvenlik</b> Varsayılan şifre (<code class="k">band2024</code>) hâlâ aktif. Bu panel ffmpeg komutu çalıştırır; <a href="index.php?page=ayarlar#sifre" style="color:#ffd08a;text-decoration:underline">şifrenizi hemen değiştirin</a>.</div>
    <?php endif; ?>
    <?php if (!ffmpeg_ok()): ?>
      <div class="warnband"><b>ffmpeg</b> Belirtilen yolda ffmpeg.exe yok: <code class="k"><?= h(win_path(ffmpeg_path())) ?></code> — <a href="index.php?page=ayarlar" style="color:#ffd08a;text-decoration:underline">Ayarlar</a>'dan düzeltin.</div>
    <?php endif; ?>

<?php /* ================================ PANEL ============================== */
if ($PAGE === 'panel'):
  $totalDur = 0; foreach ($MEDIA as $m) $totalDur += (float)$m['duration'];
  $heroCh = $RUN_LIST[0]['ch'] ?? null; $heroSt = $RUN_LIST[0]['st'] ?? null;
?>
      <div class="grid-dash">
        <div>
          <div class="hero reveal" id="heroPanel">
            <?php if ($heroCh): $lp = log_path_for($heroCh['id']); $tel = is_file($lp) ? parse_telemetry(tail_bytes($lp, 6000)) : []; ?>
            <div class="kicker">● Şu anda yayında</div>
            <div class="bigname" id="heroName"><?= h($heroCh['name'] ?: $heroCh['id']) ?></div>
            <div class="target" id="heroTarget"><?= h(channel_output_url($heroCh)) ?></div>
            <div class="hero-meta">
              <div class="metric"><div class="k">Süre</div><div class="v" id="heroUp"><?= human_time(time() - (int)($heroSt['rt']['started_at'] ?? time())) ?></div></div>
              <div class="metric"><div class="k">Kare</div><div class="v" id="heroFrame"><?= (int)($tel['frame'] ?? 0) ?></div></div>
              <div class="metric"><div class="k">FPS</div><div class="v" id="heroFps"><?= isset($tel['fps']) && $tel['fps'] !== null ? $tel['fps'] : '—' ?></div></div>
              <div class="metric"><div class="k">Bitrate</div><div class="v" id="heroBr"><?= isset($tel['bitrate']) && $tel['bitrate'] !== null ? round($tel['bitrate']) . '<small> kb/s</small>' : '—' ?></div></div>
              <div class="metric"><div class="k">Hız</div><div class="v" id="heroSp"><?= isset($tel['speed']) && $tel['speed'] !== null ? $tel['speed'] . '<small>x</small>' : '—' ?></div></div>
              <div class="metric"><div class="k">PID</div><div class="v" id="heroPid" style="font-family:var(--mono);font-size:17px"><?= (int)($heroSt['pid'] ?? 0) ?></div></div>
            </div>
            <canvas id="wave" width="900" height="78"></canvas>
            <div class="flexrow mt16">
              <a class="btn sm" href="index.php?page=loglar&id=<?= h($heroCh['id']) ?>">Canlı log</a>
              <form method="post" style="display:inline"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_restart"><input type="hidden" name="id" value="<?= h($heroCh['id']) ?>"><input type="hidden" name="back" value="panel"><button class="btn warn sm">Yeniden başlat</button></form>
              <form method="post" style="display:inline"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_stop"><input type="hidden" name="id" value="<?= h($heroCh['id']) ?>"><input type="hidden" name="back" value="panel"><button class="btn danger sm">Yayını kes</button></form>
              <?php if (count($RUN_LIST) > 1): ?><span class="pill teal" style="margin-left:auto">+<?= count($RUN_LIST) - 1 ?> bant daha yayında</span><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="kicker" style="color:var(--muted2)">○ Beklemede</div>
            <div class="bigname" style="color:#3d4c6c">Yayın yok</div>
            <div class="target">Şu anda çalışan bir bant yok. Bir bant oluşturup yayına alabilirsiniz.</div>
            <div class="flexrow mt16">
              <a class="btn pri" href="index.php?page=bantlar&action=new">+ Yeni yayın bant oluştur</a>
              <?php if ($CHANNELS): ?>
              <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="start_all"><input type="hidden" name="page" value="panel"><button class="btn ok">Tümünü başlat</button></form>
              <?php endif; ?>
              <a class="btn ghost" href="index.php?page=yardim">Kurulum rehberi</a>
            </div>
            <canvas id="wave" width="900" height="78" class="mt16"></canvas>
            <?php endif; ?>
          </div>

          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Bant Durumları</h3><div class="sub">canlı · <?= (int)$SETTINGS['poll'] ?> sn'de bir yenilenir</div></div>
              <div class="right"><span class="pill" id="lastSync"><i></i>senkron</span></div></div>
            <div class="bd" style="padding-top:6px">
              <?php if (!$CHANNELS): ?>
                <div class="empty">Henüz bant yok — “Yeni Bant” ile başlayın</div>
              <?php else: ?>
              <div class="chanlist" id="dashChans">
                <?php foreach ($CHANNELS as $ch): $st = channel_state($ch); ?>
                <div class="chan" style="--cc:<?= h($ch['color']) ?>" data-id="<?= h($ch['id']) ?>">
                  <div class="thumb">
                    <?php if (!empty($ch['logo'])): ?><img src="index.php?action=asset&kind=logo&name=<?= h(urlencode($ch['logo'])) ?>" alt="logo"><?php else: ?><span class="ph">▮</span><?php endif; ?>
                  </div>
                  <div style="min-width:0">
                    <div class="nm"><?= h($ch['name'] ?: $ch['id']) ?></div>
                    <div class="mt">
                      <span class="pill <?= $st['state'] ?>" data-role="pill"><i></i><span data-role="stext"><?= $st['state'] === 'running' ? 'YAYINDA' : ($st['state'] === 'error' ? 'KESİLDİ' : 'DURDU') ?></span></span>
                      <span><b><?= (int)$ch['width'] ?>x<?= (int)$ch['height'] ?></b> · <?= (int)$ch['fps'] ?>fps · <?= (int)$ch['video_bitrate'] ?>kbps</span>
                      <span data-role="uptime"><?= $st['state'] === 'running' ? human_time(time() - (int)($st['rt']['started_at'] ?? time())) : '—' ?></span>
                      <span><?= count($ch['items']) ?> medya<?= $ch['logo'] ? ' · logolu' : '' ?></span>
                    </div>
                    <div class="telmini" data-role="tel" style="<?= $st['state'] === 'running' ? '' : 'display:none' ?>">
                      <span>frame <b data-t="frame">0</b></span><span>fps <b data-t="fps">—</b></span>
                      <span>bitrate <b data-t="bitrate">—</b></span><span>hız <b data-t="speed">—</b></span>
                      <span>yeniden <b data-t="restarts"><?= (int)($st['rt']['restarts'] ?? 0) ?></b></span>
                    </div>
                  </div>
                  <div class="acts">
                    <?php if ($st['state'] === 'running'): ?>
                    <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_stop"><input type="hidden" name="id" value="<?= h($ch['id']) ?>"><input type="hidden" name="back" value="panel"><button class="btn danger sm">Durdur</button></form>
                    <?php else: ?>
                    <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_start"><input type="hidden" name="id" value="<?= h($ch['id']) ?>"><input type="hidden" name="back" value="panel"><button class="btn ok sm">Başlat</button></form>
                    <?php endif; ?>
                    <a class="btn sm ghost" href="index.php?page=bantlar&edit=<?= h($ch['id']) ?>">Düzenle</a>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div>
          <div class="card reveal">
            <div class="hd"><div><h3>Sistem</h3><div class="sub">VDS · windows server</div></div></div>
            <div class="bd" style="padding:0">
              <div class="statlist" style="border:0;border-radius:0">
                <div class="statrow"><span class="k">CPU yükü</span><span class="v" id="stCpu"><?= $SRV['cpu'] === null ? '—' : $SRV['cpu'] . '%' ?></span></div>
                <div class="statrow"><span class="k">RAM kullanım</span><span class="v sm" id="stMem"><?= $SRV['mem_total'] ? human_size($SRV['mem_used']) . ' / ' . human_size($SRV['mem_total']) : '—' ?></span></div>
                <div class="statrow"><span class="k">Disk boş</span><span class="v sm"><?= human_size($SRV['disk_free']) ?> / <?= human_size($SRV['disk_total']) ?></span></div>
                <div class="statrow"><span class="k">Çalışan bant</span><span class="v" id="stRun" style="color:var(--red)"><?= count($RUN_LIST) ?></span></div>
                <div class="statrow"><span class="k">Toplam bant</span><span class="v"><?= count($CHANNELS) ?></span></div>
                <div class="statrow"><span class="k">Medya</span><span class="v"><?= count($MEDIA) ?></span></div>
                <div class="statrow"><span class="k">Toplam süre</span><span class="v sm"><?= human_dur($totalDur) ?></span></div>
                <div class="statrow"><span class="k">ffmpeg</span><span class="v sm" style="color:<?= ffmpeg_ok() ? 'var(--teal)' : 'var(--red)' ?>"><?= ffmpeg_ok() ? 'HAZIR' : 'BULUNAMADI' ?></span></div>
                <div class="statrow"><span class="k">php</span><span class="v sm"><?= h(PHP_VERSION) ?></span></div>
              </div>
            </div>
          </div>

          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Hızlı İşlem</h3><div class="sub">tek tık</div></div></div>
            <div class="bd flexrow">
              <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="start_all"><button class="btn ok sm">▶ Tümünü başlat</button></form>
              <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="stop_all"><button class="btn danger sm">■ Tümünü durdur</button></form>
              <a class="btn sm" href="index.php?page=medya">↑ Medya yükle</a>
              <a class="btn sm" href="index.php?page=ayarlar">⚙ Ayarlar</a>
            </div>
          </div>

          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Akış Şeması</h3><div class="sub">bant nasıl çalışır</div></div></div>
            <div class="bd mono" style="font-size:11.5px;line-height:2;color:var(--muted)">
              <div><b style="color:var(--blue)">01</b> Medya kütüphanesi → oynatma listesi (ffconcat)</div>
              <div><b style="color:var(--blue)">02</b> <code class="k">-stream_loop -1 -re</code> ile sonsuz döngü</div>
              <div><b style="color:var(--blue)">03</b> scale/pad → <b><?= h('1280x720') ?></b> tuval, fps sabitleme</div>
              <div><b style="color:var(--blue)">04</b> logo → <code class="k">overlay</code> (konum/ölçek/opaklık)</div>
              <div><b style="color:var(--blue)">05</b> x264 + AAC → <code class="k">-f flv</code> → RTMP sunucusu</div>
              <div><b style="color:var(--teal)">06</b> Watchdog: süreç ölürse 5 sn içinde yeniden başlar</div>
            </div>
          </div>
        </div>
      </div>

<?php /* =============================== BANTLAR ============================ */
elseif ($PAGE === 'bantlar'):
?>
      <div class="sec-hd">
        <div>
          <div class="kick">yayın bantları</div>
          <h2 class="h2">Bant Listesi <span style="color:var(--muted2);font-size:.6em">(<?= count($CHANNELS) ?>)</span></h2>
        </div>
        <div class="right">
          <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="start_all"><button class="btn ok sm">▶ Tümünü başlat</button></form>
          <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="stop_all"><button class="btn danger sm">■ Tümünü durdur</button></form>
          <a class="btn pri" href="index.php?page=bantlar&action=new">+ YENİ BANT</a>
        </div>
      </div>
      <p class="lead">Her bant bir ffmpeg sürecidir: kaynak medyayı döngüye alır, logoyu bindirir, belirlediğiniz RTMP adresine kesintisiz basar. Düzenle'ye basarak logo, çözünürlük, bit hızı ve oynatma listesini değiştirebilirsiniz.</p>

      <?php if (!$CHANNELS): ?>
        <div class="card pad reveal" style="text-align:center;padding:56px 24px">
          <div style="font-family:var(--disp);font-size:52px;color:#26324a;letter-spacing:.04em">BOŞ BANT</div>
          <p class="muted" style="max-width:44ch;margin:10px auto 20px">Henüz yayın bandı tanımlamadınız. İlk bandı oluşturun: medya ekleyin, logoyu yerleştirin, RTMP linkinizi yapıştırın.</p>
          <a class="btn pri" href="index.php?page=bantlar&action=new">+ İLK BANDI OLUŞTUR</a>
          <a class="btn ghost" href="index.php?page=medya">Önce medya yükle</a>
        </div>
      <?php else: ?>
      <div class="chanlist">
        <?php foreach ($CHANNELS as $ch): $st = channel_state($ch); $lp = log_path_for($ch['id']);
              $tel = is_file($lp) ? parse_telemetry(tail_bytes($lp, 6000)) : []; ?>
        <div class="chan reveal <?= $st['state'] === 'running' ? 'live' : '' ?>" style="--cc:<?= h($ch['color']) ?>" data-id="<?= h($ch['id']) ?>">
          <div class="thumb">
            <?php if (!empty($ch['logo'])): ?><img src="index.php?action=asset&kind=logo&name=<?= h(urlencode($ch['logo'])) ?>" alt="logo"><?php else: ?><span class="ph">▮</span><?php endif; ?>
          </div>
          <div style="min-width:0">
            <div class="nm"><?= h($ch['name'] ?: $ch['id']) ?>
              <?php if (!empty($ch['watchdog'])): ?><span class="pill teal" style="margin-left:8px;vertical-align:2px"><i></i>watchdog</span><?php endif; ?>
              <?php if (!empty($ch['auto_start'])): ?><span class="pill" style="margin-left:4px;vertical-align:2px"><i></i>oto. başlat</span><?php endif; ?>
            </div>
            <div class="mt">
              <span class="pill <?= $st['state'] ?>" data-role="pill"><i></i><span data-role="stext"><?= $st['state'] === 'running' ? 'YAYINDA' : ($st['state'] === 'error' ? 'KESİLDİ' : 'DURDU') ?></span></span>
              <span>hedef <b><?= h(channel_output_url($ch) ?: '—') ?></b></span>
              <span><?= (int)$ch['width'] ?>x<?= (int)$ch['height'] ?> · <?= (int)$ch['fps'] ?>fps · <?= (int)$ch['video_bitrate'] ?>k · <?= h($ch['preset']) ?></span>
              <span>kaynak <b><?= $ch['source_type'] === 'url' ? 'URL' : (count($ch['items']) . ' medya') ?></b></span>
              <span data-role="uptime">süre <?= $st['state'] === 'running' ? human_time(time() - (int)($st['rt']['started_at'] ?? time())) : '—' ?></span>
            </div>
            <div class="telmini" data-role="tel" style="<?= $st['state'] === 'running' ? '' : 'display:none' ?>">
              <span>frame <b data-t="frame"><?= (int)($tel['frame'] ?? 0) ?></b></span>
              <span>fps <b data-t="fps"><?= $tel['fps'] ?? '—' ?></b></span>
              <span>bitrate <b data-t="bitrate"><?= isset($tel['bitrate']) && $tel['bitrate'] !== null ? round($tel['bitrate']) : '—' ?></b></span>
              <span>hız <b data-t="speed"><?= $tel['speed'] ?? '—' ?></b></span>
              <span>yeniden başlatma <b data-t="restarts"><?= (int)($st['rt']['restarts'] ?? 0) ?></b></span>
              <?php if ($st['state'] === 'error' && !empty($st['rt']['last_error'])): ?><span style="color:#ff8b9c">son hata: <?= h(substr((string)$st['rt']['last_error'], 0, 120)) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="acts">
            <?php if ($st['state'] === 'running'): ?>
              <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_stop"><input type="hidden" name="id" value="<?= h($ch['id']) ?>"><button class="btn danger sm">■ Durdur</button></form>
              <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_restart"><input type="hidden" name="id" value="<?= h($ch['id']) ?>"><button class="btn warn sm">↻ Yenile</button></form>
            <?php else: ?>
              <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_start"><input type="hidden" name="id" value="<?= h($ch['id']) ?>"><button class="btn ok sm">▶ Yayına al</button></form>
            <?php endif; ?>
            <a class="btn sm" href="index.php?page=loglar&id=<?= h($ch['id']) ?>">Log</a>
            <a class="btn sm" href="index.php?page=bantlar&edit=<?= h($ch['id']) ?>">Düzenle</a>
            <form method="post" onsubmit="return confirm('“<?= h(addslashes($ch['name'] ?: $ch['id'])) ?>” bandı silinsin mi? Çalışıyorsa durdurulur.')"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="channel_delete"><input type="hidden" name="id" value="<?= h($ch['id']) ?>"><button class="btn danger sm ghost">Sil</button></form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

<?php
/* ------------------------- DÜZENLEYİCİ (MODAL) ---------------------------- */
$E = $EDIT ?: default_channel('');
$isNew = empty($E['id']) || !isset($CHANNELS[$E['id']]);
$ecmd = full_command_string($E);
?>
      <div class="modal <?= ($EDIT !== null) ? 'open' : '' ?>" id="editorModal">
        <div class="bd-bg" data-close></div>
        <form class="sheet" method="post" enctype="multipart/form-data" id="editorForm">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="channel_save">
          <input type="hidden" name="id" value="<?= h($E['id']) ?>">
          <input type="hidden" name="logo_keep" value="1">
          <input type="hidden" name="items_json" id="itemsJson" value="<?= h(json_encode($E['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
          <div class="sheet-hd">
            <div>
              <div class="kick" style="margin:0"><?= $isNew ? 'yeni bant' : 'bandı düzenle' ?></div>
              <h2><?= h($isNew ? 'Yeni Yayın Bandı' : ($E['name'] ?: $E['id'])) ?></h2>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
              <span class="pill" id="cmdState"><i></i>komut hazır</span>
              <button type="button" class="btn ghost sm" data-close>✕ Kapat</button>
            </div>
          </div>
          <div class="sheet-bd">
            <!-- SOL -->
            <div>
              <div class="fs">
                <div class="fst">01 · Kimlik</div>
                <div class="row2">
                  <div class="field"><label class="lb">Bant adı</label><input class="inp" name="name" value="<?= h($E['name']) ?>" placeholder="ör. Ana Yayın Bant"></div>
                  <div class="field"><label class="lb">Renk etiketi</label><input class="inp" type="color" name="color" value="<?= h($E['color']) ?>" style="height:41px;padding:4px"></div>
                </div>
              </div>

              <div class="fs">
                <div class="fst">02 · Yayın linki (çıkış)</div>
                <div class="field">
                  <label class="lb">RTMP sunucusu</label>
                  <input class="inp mono" name="rtmp_server" id="fRtmp" value="<?= h($E['rtmp_server']) ?>" placeholder="rtmp://a.rtmp.youtube.com/live2">
                  <div class="noteline">YouTube: rtmp://a.rtmp.youtube.com/live2 · Twitch: rtmp://live-ist01.twitch.tv/app · Facebook: rtmps://live-api-s.facebook.com:443/rtmp</div>
                </div>
                <div class="field">
                  <label class="lb">Yayın anahtarı (stream key)</label>
                  <input class="inp mono" name="stream_key" id="fKey" value="<?= h($E['stream_key']) ?>" placeholder="xxxx-xxxx-xxxx-xxxx" autocomplete="off">
                </div>
                <div class="field mb0">
                  <label class="lb">Birleşik hedef</label>
                  <div class="cmdbox" id="targetPrev" style="max-height:70px"><?= h(channel_output_url($E) ?: '—') ?></div>
                </div>
              </div>

              <div class="fs">
                <div class="fst">03 · Kaynak</div>
                <div class="field">
                  <label class="lb">Kaynak tipi</label>
                  <select class="inp" name="source_type" id="fSrcType">
                    <option value="playlist" <?= $E['source_type'] === 'playlist' ? 'selected' : '' ?>>Oynatma listesi (medya kütüphanesi) — döngü</option>
                    <option value="url" <?= $E['source_type'] === 'url' ? 'selected' : '' ?>>Harici kaynak URL (mp4 / HLS / RTMP / SRT)</option>
                  </select>
                </div>
                <div id="srcUrlBox" style="<?= $E['source_type'] === 'url' ? '' : 'display:none' ?>">
                  <div class="field"><label class="lb">Kaynak URL / yol</label><input class="inp mono" name="source_url" value="<?= h($E['source_url']) ?>" placeholder="https://.../video.mp4  veya  rtmp://..."></div>
                  <label class="sw"><input type="checkbox" name="source_live" value="1" <?= !empty($E['source_live']) ? 'checked' : '' ?>><span class="track"></span><span class="txt"><b>Canlı kaynak</b>Kaynak zaten canlı akışsa işaretleyin (-re uygulanmaz)</span></label>
                </div>
                <div id="srcPlBox" style="<?= $E['source_type'] === 'url' ? 'display:none' : '' ?>">
                  <div class="row2" style="grid-template-columns:1fr 1fr;align-items:start">
                    <div>
                      <label class="lb">Kütüphane (<?= count($MEDIA) ?> dosya)</label>
                      <input class="inp" id="libSearch" placeholder="ara…" style="margin-bottom:8px">
                      <div class="liblist" id="libList">
                        <?php if (!$MEDIA): ?><div class="empty">kütüphane boş</div><?php endif; ?>
                        <?php foreach ($MEDIA as $m): ?>
                        <div class="libitem" data-name="<?= h(strtolower($m['name'])) ?>">
                          <span class="kindchip <?= h($m['kind']) ?>"><?= h($m['kind']) ?></span>
                          <div style="min-width:0"><div class="nm"><?= h($m['name']) ?></div><div class="ds"><?= human_dur($m['duration']) ?> · <?= human_size($m['size']) ?><?= $m['width'] ? ' · ' . $m['width'] . 'x' . $m['height'] : '' ?></div></div>
                          <button type="button" class="btn xs add" data-add='<?= h(json_encode(['type' => 'file', 'path' => $m['path'], 'name' => $m['name'], 'duration' => $m['duration']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>+ ekle</button>
                        </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <div>
                      <label class="lb">Oynatma listesi (sıra önemli)</label>
                      <div class="plist" id="pList"></div>
                      <div class="flexrow mt8">
                        <button type="button" class="btn xs" id="addUrlBtn">+ URL ekle</button>
                        <button type="button" class="btn xs" id="clearList">Listeyi boşalt</button>
                        <span class="mono" style="font-size:11px;color:var(--muted2);margin-left:auto">toplam <b id="plDur">—</b></span>
                      </div>
                      <div class="noteline">Liste <code class="k">ffconcat</code> dosyasına yazılır ve <code class="k">-stream_loop -1</code> ile sonsuz döner.</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- SAĞ -->
            <div>
              <div class="fs">
                <div class="fst">04 · Logo bindirme</div>
                <div class="preview" id="pv">
                  <div class="scan"></div><div class="safe"></div>
                  <img class="lg" id="pvLogo" alt="" style="display:none">
                  <div class="nolabel" id="pvNo">LOGO YOK</div>
                  <div class="tag"><?= (int)$E['width'] ?>×<?= (int)$E['height'] ?> · önizleme</div>
                </div>
                <div class="row2 mt16">
                  <div class="field">
                    <label class="lb">Logo dosyası (PNG/JPG/WEBP)</label>
                    <input class="inp" type="file" name="logo_file" id="logoFile" accept="image/png,image/jpeg,image/webp">
                    <div class="noteline">Mevcut: <b><?= h($E['logo'] ?: 'yok') ?></b><?php if ($E['logo']): ?> · <label style="color:#ff8ea0;cursor:pointer"><input type="checkbox" name="logo_remove" value="1" style="width:auto;vertical-align:-2px"> kaldır</label><?php endif; ?></div>
                  </div>
                  <div class="field">
                    <label class="lb">Konum</label>
                    <div class="posgrid" id="posGrid">
                      <?php $cells = ['tl','tc','tr','ml','mc','mr','bl','bc','br'];
                      foreach ($cells as $c): ?>
                      <label><input type="radio" name="logo_pos" value="<?= $c ?>" <?= $E['logo_pos'] === $c ? 'checked' : '' ?>><span></span></label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <div class="field"><label class="lb">Ölçek · <b id="vScale" class="mono" style="color:var(--teal)"><?= (int)$E['logo_scale'] ?>%</b></label>
                  <input type="range" name="logo_scale" id="rScale" min="3" max="40" value="<?= (int)$E['logo_scale'] ?>"></div>
                <div class="field"><label class="lb">Opaklık · <b id="vOp" class="mono" style="color:var(--teal)"><?= (int)$E['logo_opacity'] ?>%</b></label>
                  <input type="range" name="logo_opacity" id="rOp" min="10" max="100" value="<?= (int)$E['logo_opacity'] ?>"></div>
                <div class="field mb0"><label class="lb">Kenar boşluğu · <b id="vMar" class="mono" style="color:var(--teal)"><?= (int)$E['logo_margin'] ?>px</b></label>
                  <input type="range" name="logo_margin" id="rMar" min="0" max="160" value="<?= (int)$E['logo_margin'] ?>"></div>
              </div>

              <div class="fs">
                <div class="fst">05 · Görüntü & ses</div>
                <div class="row3">
                  <div class="field"><label class="lb">Çözünürlük</label>
                    <select class="inp" name="wh" id="fWH">
                      <?php foreach ([[640,360,'360p'],[854,480,'480p'],[1280,720,'720p HD'],[1920,1080,'1080p FHD'],[2560,1440,'1440p']] as $r): ?>
                      <option value="<?= $r[0] ?>x<?= $r[1] ?>" <?= ((int)$E['width'] === $r[0] && (int)$E['height'] === $r[1]) ? 'selected' : '' ?>><?= h($r[2]) ?> · <?= $r[0] ?>×<?= $r[1] ?></option>
                      <?php endforeach; ?>
                    </select></div>
                  <div class="field"><label class="lb">FPS</label>
                    <select class="inp" name="fps"><?php foreach ([24,25,30,50,60] as $f): ?><option value="<?= $f ?>" <?= (int)$E['fps'] === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select></div>
                  <div class="field"><label class="lb">Preset</label>
                    <select class="inp" name="preset"><?php foreach (['ultrafast','superfast','veryfast','faster','fast','medium'] as $p): ?><option value="<?= $p ?>" <?= $E['preset'] === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></div>
                </div>
                <input type="hidden" name="width" id="fW" value="<?= (int)$E['width'] ?>">
                <input type="hidden" name="height" id="fH" value="<?= (int)$E['height'] ?>">
                <div class="row2">
                  <div class="field"><label class="lb">Video bitrate · <b class="mono" id="vVbr" style="color:var(--teal)"><?= (int)$E['video_bitrate'] ?> kbps</b></label>
                    <input type="range" name="video_bitrate" id="rVbr" min="500" max="9000" step="100" value="<?= (int)$E['video_bitrate'] ?>"></div>
                  <div class="field"><label class="lb">Ses bitrate</label>
                    <select class="inp" name="audio_bitrate"><?php foreach ([96,128,160,192,256] as $a): ?><option value="<?= $a ?>" <?= (int)$E['audio_bitrate'] === $a ? 'selected' : '' ?>><?= $a ?> kbps AAC</option><?php endforeach; ?></select></div>
                </div>
                <div class="row2">
                  <div class="field mb0"><label class="lb">Ses seviyesi · <b class="mono" id="vVol" style="color:var(--teal)"><?= (int)$E['volume'] ?>%</b></label>
                    <input type="range" name="volume" id="rVol" min="0" max="200" value="<?= (int)$E['volume'] ?>"></div>
                  <div class="field mb0" style="display:flex;align-items:center">
                    <label class="sw"><input type="checkbox" name="silent_audio" value="1" <?= !empty($E['silent_audio']) ? 'checked' : '' ?>><span class="track"></span><span class="txt"><b>Sessiz kaynak</b>Kaynakta ses yoksa sessiz ses üreteci ekle</span></label>
                  </div>
                </div>
              </div>

              <div class="fs">
                <div class="fst">06 · Dayanıklılık & gelişmiş</div>
                <label class="sw"><input type="checkbox" name="watchdog" value="1" <?= !empty($E['watchdog']) ? 'checked' : '' ?>><span class="track"></span><span class="txt"><b>Watchdog</b>Süreç çökerse otomatik yeniden başlat (panel açıkken veya CLI watchdog ile)</span></label>
                <label class="sw"><input type="checkbox" name="auto_start" value="1" <?= !empty($E['auto_start']) ? 'checked' : '' ?>><span class="track"></span><span class="txt"><b>Otomatik başlat</b>Sunucu açılışında / “Tümünü başlat” komutunda bu bandı da başlat</span></label>
                <div class="field mt8"><label class="lb">Ek ffmpeg argümanları</label>
                  <textarea class="inp" name="extra_args" rows="2" placeholder="ör. -threads 4 -x264-params keyint=50"><?= h($E['extra_args']) ?></textarea></div>
              </div>

              <div class="fs mb0">
                <div class="fst">07 · Üretilecek komut</div>
                <div class="cmdbox" id="cmdBox"><?= $ecmd['error'] ? '<span class="err">' . h($ecmd['error']) . '</span>' : h($ecmd['cmd']) ?></div>
                <div class="flexrow mt8"><button type="button" class="btn xs" id="copyCmd">Komutu kopyala</button><span class="noteline">Değişikliklerde otomatik güncellenir.</span></div>
              </div>
            </div>
          </div>
          <div class="sheet-ft">
            <button class="btn pri" type="submit">💾 KAYDET</button>
            <?php if (!$isNew): ?>
            <label class="sw" style="padding:0"><input type="checkbox" name="apply_restart" value="1" checked><span class="track"></span><span class="txt">Kaydettikten sonra yayını yeniden başlat</span></label>
            <a class="btn sm" href="index.php?page=loglar&id=<?= h($E['id']) ?>">Log</a>
            <?php endif; ?>
            <button type="button" class="btn ghost sm" data-close style="margin-left:auto">Vazgeç</button>
          </div>
        </form>
      </div>

<?php /* ================================ MEDYA ============================= */
elseif ($PAGE === 'medya'):
  $totalSize = 0; foreach ($MEDIA as $m) $totalSize += (float)$m['size'];
?>
      <div class="sec-hd">
        <div><div class="kick">medya kütüphanesi</div><h2 class="h2">Dosyalar <span style="color:var(--muted2);font-size:.6em">(<?= count($MEDIA) ?> · <?= human_size($totalSize) ?>)</span></h2></div>
        <div class="right">
          <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="media_thumbs"><button class="btn sm">Küçük resimleri üret</button></form>
          <a class="btn sm" href="index.php?page=bantlar&action=new">+ Bant oluştur</a>
        </div>
      </div>
      <div class="grid-dash" style="grid-template-columns:1fr 1.35fr">
        <div>
          <div class="card reveal">
            <div class="hd"><div><h3>Dosya Yükle</h3><div class="sub">mp4 · mov · mkv · avi · ts · webm · mp3 · wav</div></div></div>
            <div class="bd">
              <form method="post" enctype="multipart/form-data" id="upForm">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="media_upload">
                <div class="drop" id="drop">
                  <div class="big">Dosyaları buraya bırak</div>
                  <div class="sm">veya seçmek için tıkla</div>
                  <input type="file" name="media[]" id="fileInp" multiple accept="video/*,audio/*,.mkv,.ts,.flv,.webm" style="display:none">
                  <div id="fileNames" class="mono mt8" style="font-size:11.5px;color:var(--teal)"></div>
                </div>
                <button class="btn pri mt16" style="width:100%;justify-content:center" type="submit">↑ YÜKLE</button>
                <div class="noteline">PHP limitleri: <code class="k">upload_max_filesize=<?= h(ini_get('upload_max_filesize')) ?></code> <code class="k">post_max_size=<?= h(ini_get('post_max_size')) ?></code> <code class="k">max_execution=<?= h(ini_get('max_execution_time')) ?>s</code><br>Büyük dosyalar için alttaki “sunucu yolu” yöntemini kullanın — çok daha hızlıdır.</div>
              </form>
            </div>
          </div>

          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Sunucudan Dosya Bağla</h3><div class="sub">kopyalamadan referans</div></div></div>
            <div class="bd">
              <form method="post" class="flexrow">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="media_add_path">
                <input class="inp mono" name="path" placeholder="D:\videolar\jenerik.mp4" style="flex:1;min-width:180px" required>
                <button class="btn ok">Bağla</button>
              </form>
              <div class="noteline">VDS üzerindeki hazır klasörleri (ör. <code class="k">D:\YAYIN\</code>) doğrudan listeye ekleyin; dosya kopyalanmaz, ffmpeg o yolu okur.</div>
            </div>
          </div>

          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Klasör</h3><div class="sub">fiziksel konum</div></div></div>
            <div class="bd mono" style="font-size:11.5px;color:var(--muted);line-height:2">
              <div>medya → <b style="color:var(--paper)"><?= h(win_path(MEDIA_DIR)) ?></b></div>
              <div>logo → <b style="color:var(--paper)"><?= h(win_path(LOGO_DIR)) ?></b></div>
              <div>loglar → <b style="color:var(--paper)"><?= h(win_path(LOG_DIR)) ?></b></div>
              <div>listeler → <b style="color:var(--paper)"><?= h(win_path(RUN_DIR)) ?></b></div>
            </div>
          </div>
        </div>

        <div class="card reveal">
          <div class="hd"><div><h3>Kütüphane</h3><div class="sub">süreler ffprobe ile okundu</div></div>
            <div class="right"><input class="inp" id="mSearch" placeholder="filtrele…" style="width:180px"></div></div>
          <div style="max-height:70vh;overflow:auto">
            <table class="tbl" id="mTbl">
              <thead><tr><th></th><th>Dosya</th><th>Tür</th><th>Süre</th><th>Çözünürlük</th><th>Boyut</th><th>Kaynak</th><th></th></tr></thead>
              <tbody>
              <?php if (!$MEDIA): ?><tr><td colspan="8"><div class="empty">kütüphane boş</div></td></tr><?php endif; ?>
              <?php foreach ($MEDIA as $m): ?>
                <tr data-name="<?= h(strtolower($m['name'])) ?>">
                  <td style="width:56px">
                    <?php if ($m['thumb']): ?><img src="index.php?action=asset&kind=thumb&name=<?= h(urlencode($m['thumb'])) ?>" style="width:48px;height:30px;object-fit:cover;border-radius:4px;border:1px solid var(--line)"><?php else: ?><span class="kindchip <?= h($m['kind']) ?>"><?= h(substr($m['kind'], 0, 3)) ?></span><?php endif; ?>
                  </td>
                  <td><div class="nm"><?= h($m['name']) ?></div><div class="mono" style="font-size:10.5px;color:var(--muted2)"><?= h($m['path']) ?></div></td>
                  <td><span class="kindchip <?= h($m['kind']) ?>"><?= h($m['kind']) ?></span></td>
                  <td class="mono"><?= human_dur($m['duration']) ?></td>
                  <td class="mono"><?= $m['width'] ? $m['width'] . '×' . $m['height'] : '—' ?><?= $m['codec'] ? '<br><span style="color:var(--muted2)">' . h($m['codec']) . '</span>' : '' ?></td>
                  <td class="mono"><?= human_size($m['size']) ?></td>
                  <td class="mono" style="font-size:10.5px;color:var(--muted2)"><?= h($m['origin']) ?></td>
                  <td>
                    <form method="post" onsubmit="return confirm('“<?= h(addslashes($m['name'])) ?>” silinsin mi?<?= $m['origin'] === 'Sunucu yolu' ? ' (yalnızca bağlantı kaldırılır)' : '' ?>')"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="media_delete"><input type="hidden" name="key" value="<?= h($m['key']) ?>"><button class="btn xs danger ghost">Sil</button></form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

<?php /* ================================ LOGLAR ============================ */
elseif ($PAGE === 'loglar'):
  $selId = (string)($_GET['id'] ?? '');
  if ($selId === '' && $CHANNELS) $selId = array_key_first($CHANNELS);
  $selCh = $selId ? channel_get($selId) : null;
  $lp = $selCh ? log_path_for($selCh['id']) : '';
  $procs = ffmpeg_processes();
?>
      <div class="sec-hd">
        <div><div class="kick">telemetri & hata ayıklama</div><h2 class="h2">Loglar ve Süreçler</h2></div>
        <div class="right">
          <?php if ($selCh): ?>
          <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="log_clear"><input type="hidden" name="id" value="<?= h($selCh['id']) ?>"><button class="btn sm">Logu temizle</button></form>
          <a class="btn sm" href="index.php?page=bantlar&edit=<?= h($selCh['id']) ?>">Bandı düzenle</a>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('TÜM ffmpeg süreçleri sonlandırılsın mı? Tüm yayınlar kesilir.')"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="kill_orphan"><button class="btn danger sm">Başıboş süreçleri öldür</button></form>
        </div>
      </div>

      <div class="card reveal">
        <div class="hd">
          <div><h3>Bant Seç</h3><div class="sub">log dosyası: <?= h($lp ? win_path($lp) : '—') ?></div></div>
          <div class="right">
            <select class="inp" id="logSel" style="min-width:230px" onchange="location='index.php?page=loglar&id='+encodeURIComponent(this.value)">
              <?php foreach ($CHANNELS as $c): ?><option value="<?= h($c['id']) ?>" <?= $c['id'] === $selId ? 'selected' : '' ?>><?= h($c['name'] ?: $c['id']) ?></option><?php endforeach; ?>
              <?php if (!$CHANNELS): ?><option value="">bant yok</option><?php endif; ?>
            </select>
            <label class="sw" style="padding:0"><input type="checkbox" id="logAuto" checked><span class="track"></span><span class="txt">Otomatik</span></label>
          </div>
        </div>
        <div class="bd">
          <?php if ($selCh): ?>
          <div class="flexrow mb0" style="margin-bottom:12px">
            <span class="pill" id="logState"><i></i>—</span>
            <span class="mono" style="font-size:11.5px;color:var(--muted)">frame <b id="lgFrame" style="color:var(--teal)">—</b> · fps <b id="lgFps" style="color:var(--teal)">—</b> · bitrate <b id="lgBr" style="color:var(--teal)">—</b> · hız <b id="lgSp" style="color:var(--teal)">—</b></span>
            <span class="mono" style="font-size:11px;color:var(--muted2);margin-left:auto"><?= is_file($lp) ? human_size(filesize($lp)) . ' log' : 'log yok' ?></span>
          </div>
          <?php endif; ?>
          <div class="logbox" id="logBox">log yükleniyor…</div>
          <div class="noteline">ffmpeg çıktısı stderr'e yazılır; <code class="k">frame=… fps=… bitrate=…</code> satırları canlı telemetridir.</div>
        </div>
      </div>

      <div class="card mt16 reveal">
        <div class="hd"><div><h3>Çalışan ffmpeg Süreçleri</h3><div class="sub">win32_process · <?= count($procs) ?> adet</div></div>
          <div class="right"><button class="btn xs" onclick="loadProcs()">↻ yenile</button></div></div>
        <div style="max-height:340px;overflow:auto">
          <table class="tbl"><thead><tr><th>PID</th><th>Başlangıç</th><th>Komut satırı</th><th></th></tr></thead>
          <tbody id="procBody"><?php foreach ($procs as $p): ?>
            <tr><td class="mono"><b><?= (int)$p['pid'] ?></b></td><td class="mono" style="font-size:11px;color:var(--muted2)"><?= h($p['created']) ?></td>
            <td class="mono" style="font-size:10.5px;color:var(--muted);max-width:640px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($p['cmd']) ?></td>
            <td><button class="btn xs danger ghost" onclick="if(confirm('PID <?= (int)$p['pid'] ?> sonlandırılsın mı?')){killPid(<?= (int)$p['pid'] ?>)}">Öldür</button></td></tr>
          <?php endforeach; ?>
          <?php if (!$procs): ?><tr><td colspan="4"><div class="empty">çalışan ffmpeg süreci yok</div></td></tr><?php endif; ?>
          </tbody></table>
        </div>
      </div>

<?php /* =============================== AYARLAR ============================ */
elseif ($PAGE === 'ayarlar'):
?>
      <div class="sec-hd"><div><div class="kick">yapılandırma</div><h2 class="h2">Ayarlar</h2></div>
        <div class="right"><form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="ffmpeg_test"><button class="btn sm">ffmpeg sürümünü test et</button></form></div></div>

      <div class="grid-dash" style="grid-template-columns:1.2fr .8fr">
        <div>
          <form class="card reveal" method="post">
            <div class="hd"><div><h3>ffmpeg & Panel</h3><div class="sub">yollar ve davranış</div></div></div>
            <div class="bd">
              <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="settings_save">
              <div class="field"><label class="lb">ffmpeg.exe yolu</label>
                <input class="inp mono" name="ffmpeg" value="<?= h(win_path($SETTINGS['ffmpeg'])) ?>">
                <div class="noteline">Durum: <?= ffmpeg_ok() ? '<b style="color:var(--teal)">dosya bulundu ✓</b>' : '<b style="color:var(--red)">bulunamadı ✗</b>' ?></div></div>
              <div class="field"><label class="lb">ffprobe.exe yolu (süre/çözünürlük okuma)</label>
                <input class="inp mono" name="ffprobe" value="<?= h(win_path($SETTINGS['ffprobe'])) ?>">
                <div class="noteline">Durum: <?= is_file(win_path(ffprobe_path())) ? '<b style="color:var(--teal)">bulundu ✓</b>' : '<b style="color:var(--red)">bulunamadı ✗</b>' ?></div></div>
              <div class="row2">
                <div class="field"><label class="lb">Kullanıcı adı</label><input class="inp" name="username" value="<?= h($SETTINGS['username']) ?>"></div>
                <div class="field"><label class="lb">Durum yoklama aralığı (sn)</label><input class="inp" type="number" name="poll" min="2" max="30" value="<?= (int)$SETTINGS['poll'] ?>"></div>
              </div>
              <div class="field mb0"><label class="lb">Log görünümünde tutulacak satır</label><input class="inp" type="number" name="log_keep" min="50" max="2000" value="<?= (int)$SETTINGS['log_keep'] ?>"></div>
            </div>
            <div style="padding:14px 18px;border-top:1px solid var(--line)"><button class="btn pri">Kaydet</button></div>
          </form>

          <form class="card mt16 reveal" method="post" id="sifre">
            <div class="hd"><div><h3>Şifre Değiştir</h3><div class="sub">bcrypt · oturum korumalı</div></div></div>
            <div class="bd">
              <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="password_change">
              <div class="row3">
                <div class="field"><label class="lb">Mevcut şifre</label><input class="inp" type="password" name="cur" required></div>
                <div class="field"><label class="lb">Yeni şifre</label><input class="inp" type="password" name="new" minlength="6" required></div>
                <div class="field mb0"><label class="lb">Yeni (tekrar)</label><input class="inp" type="password" name="new2" minlength="6" required></div>
              </div>
            </div>
            <div style="padding:14px 18px;border-top:1px solid var(--line)"><button class="btn warn">Şifreyi güncelle</button></div>
          </form>

          <?php if (!empty($_SESSION['fftest'])): ?>
          <div class="card mt16 reveal"><div class="hd"><div><h3>ffmpeg -version çıktısı</h3></div></div>
            <div class="bd"><div class="cmdbox"><?= h($_SESSION['fftest']) ?></div></div></div>
          <?php endif; ?>
        </div>

        <div>
          <div class="card reveal">
            <div class="hd"><div><h3>Sunucu Açılışında Başlat</h3><div class="sub">zamanlanmış görev</div></div>
              <div class="right"><form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="make_bat"><button class="btn xs">BAT üret</button></form></div></div>
            <div class="bd">
              <p class="muted" style="margin-top:0;font-size:13px">“Otomatik başlat” işaretli bantları sunucu açılışında başlatmak için yönetici CMD'de:</p>
              <div class="cmdbox">schtasks /Create /TN "BantKontrol" /TR "\"<?= h(win_path(PHP_BINARY ?: 'C:\php\php.exe')) ?>\" \"<?= h(win_path(__FILE__)) ?>\" cli start_all\"" /SC ONSTART /RU SYSTEM /RL HIGHEST</div>
              <div class="noteline">Kesintisiz koruma için watchdog döngüsü: <code class="k">data\bant_watchdog.bat</code> — süreç ölürse 5 sn içinde yeniden başlatır.</div>
              <div class="cmdbox mt8"><?= h(win_path(PHP_BINARY ?: 'php.exe')) ?> "<?= h(win_path(__FILE__)) ?>" cli watchdog</div>
            </div>
          </div>

          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Sistem</h3><div class="sub">anlık</div></div></div>
            <div class="bd" style="padding:0"><div class="statlist" style="border:0;border-radius:0">
              <div class="statrow"><span class="k">İşletim sistemi</span><span class="v sm"><?= h(php_uname('s') . ' ' . php_uname('r')) ?></span></div>
              <div class="statrow"><span class="k">PHP sürümü</span><span class="v sm"><?= h(PHP_VERSION) ?> · <?= h(php_sapi_name()) ?></span></div>
              <div class="statrow"><span class="k">CPU</span><span class="v sm" id="stCpu2"><?= $SRV['cpu'] === null ? '—' : $SRV['cpu'] . '%' ?></span></div>
              <div class="statrow"><span class="k">Disk boş</span><span class="v sm"><?= human_size($SRV['disk_free']) ?></span></div>
              <div class="statrow"><span class="k">Kurulum</span><span class="v sm"><?= h($SETTINGS['installed']) ?></span></div>
              <div class="statrow"><span class="k">shell_exec</span><span class="v sm" style="color:<?= function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true) ? 'var(--teal)' : 'var(--red)' ?>"><?= function_exists('shell_exec') ? 'etkin ✓' : 'kapalı ✗' ?></span></div>
            </div></div>
          </div>

          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Veri Klasörü</h3><div class="sub">yedekle</div></div></div>
            <div class="bd mono" style="font-size:11.5px;color:var(--muted);line-height:2">
              <div><?= h(win_path(DATA_DIR)) ?></div>
              <div style="color:var(--muted2)">channels.json · settings.json · media/ · logos/ · logs/ · run/</div>
              <div class="noteline">Bu klasör .htaccess/web.config ile dış erişime kapatılır. Yedeklerken komple kopyalayın.</div>
            </div>
          </div>
        </div>
      </div>

<?php /* ================================ YARDIM ============================ */
elseif ($PAGE === 'yardim'): ?>
      <div class="sec-hd"><div><div class="kick">rehber</div><h2 class="h2">Kurulum & Sorun Giderme</h2></div></div>
      <div class="grid-dash" style="grid-template-columns:1.15fr .85fr">
        <div class="card pad reveal">
          <?php
          $steps = [
            ['ffmpeg kurulumu', 'ffmpeg.org üzerinden Windows build indirin, C:\ffmpeg içine açın. Panelin beklediği yol: <code class="k">C:\ffmpeg\bin\ffmpeg.exe</code>. Farklıysa Ayarlar\'dan değiştirin ve “ffmpeg sürümünü test et” deyin.'],
            ['PHP yetkisi', 'Panel <code class="k">shell_exec</code> ile PowerShell/tasklist çalıştırır. IIS uygulama havuzunun kimliğinin (ör. ApplicationPoolIdentity) bu komutları çalıştırabildiğinden emin olun; <code class="k">disable_functions</code> içinde shell_exec olmamalı.'],
            ['Medya yükle', 'Medya Kütüphanesi\'nden dosya yükleyin ya da büyük dosyalar için sunucudaki tam yolu bağlayın (D:\YAYIN\...). Süre/çözünürlük bilgileri ffprobe ile otomatik okunur.'],
            ['Logo ekle', 'Bant düzenleyicide logo dosyasını seçin (PNG saydam önerilir). 3×3 konum ızgarası, ölçek (%), opaklık ve kenar boşluğu ayarlarını önizleme kutusunda canlı görün. ffmpeg tarafında <code class="k">scale + overlay + colorchannelmixer</code> ile bindirilir.'],
            ['Yayın linki', 'RTMP sunucusu + yayın anahtarını girin; panel bunları birleştirir: <code class="k">rtmp://sunucu/live/ANAHTAR</code>. Çıkış .m3u8 ise HLS, .mp4 ise kayıt moduna geçer.'],
            ['Yayına al', '“Yayına al” butonu süreci arka planda (hidden, detached) başlatır, PID\'yi kaydeder. Log sayfasından frame/fps/bitrate telemetrisini ve hata satırlarını izleyin.'],
            ['Watchdog + açılış', 'Watchdog açıkken süreç çökerse otomatik yeniden başlatılır (panel açıkken yoklama ile, kapalıyken <code class="k">cli watchdog</code> döngüsü ile). Sunucu yeniden başlayınca otomatik başlatma için Ayarlar\'daki schtasks komutunu kullanın.'],
          ];
          foreach ($steps as $i => $s): ?>
            <div class="helpline"><div class="n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
              <div><h4><?= h($s[0]) ?></h4><p><?= $s[1] ?></p></div></div>
          <?php endforeach; ?>
        </div>
        <div>
          <div class="card reveal">
            <div class="hd"><div><h3>Sık Karşılaşılanlar</h3><div class="sub">hata → çözüm</div></div></div>
            <div class="bd" style="font-size:13px">
              <div class="helpline" style="padding:10px 0"><div style="min-width:0"><h4 style="color:#ff9aa9">“Server error: Already publishing”</h4><p>Önceki ffmpeg süreci hâlâ canlı. Loglar sayfasından “Başıboş süreçleri öldür” ya da PID'yi sonlandırın.</p></div></div>
              <div class="helpline" style="padding:10px 0"><div style="min-width:0"><h4 style="color:#ff9aa9">Görüntü var ses yok</h4><p>Kaynakta ses kanalı yoktur: bant ayarlarında “Sessiz kaynak” seçeneğini açın (anullsrc eklenir).</p></div></div>
              <div class="helpline" style="padding:10px 0"><div style="min-width:0"><h4 style="color:#ff9aa9">Yayın kasıyor / speed&lt;1.0</h4><p>Preset'i <code class="k">ultrafast</code>'e çekin, bitrate'i düşürün veya çözünürlüğü 720p yapın. VDS CPU'su yetersiz kalıyorsa eşzamanlı bant sayısını azaltın.</p></div></div>
              <div class="helpline" style="padding:10px 0;border:0"><div style="min-width:0"><h4 style="color:#ff9aa9">Büyük dosya yüklenmiyor</h4><p>php.ini: <code class="k">upload_max_filesize=2G</code>, <code class="k">post_max_size=2G</code>, <code class="k">max_execution_time=0</code>. Ya da dosyayı RDP ile sunucuya kopyalayıp “Sunucudan Dosya Bağla”yı kullanın.</p></div></div>
            </div>
          </div>
          <div class="card mt16 reveal">
            <div class="hd"><div><h3>Üretilen ffmpeg Kalıbı</h3><div class="sub">referans</div></div></div>
            <div class="bd"><div class="cmdbox">"C:\ffmpeg\bin\ffmpeg.exe" -hide_banner -nostdin -loglevel info -re -f concat -safe 0 -stream_loop -1 -i "…/playlist.txt" -i "…/logo.png" -filter_complex "[0:v]scale=1280:720:…,fps=25,format=yuv420p[base];[1:v]scale=154:-2:format=rgba,colorchannelmixer=aa=0.92[logo];[base][logo]overlay=main_w-overlay_w-28:28:eof_action=repeat[vout]" -map "[vout]" -map 0:a? -c:v libx264 -preset veryfast -tune zerolatency -b:v 2500k -g 50 -c:a aac -b:a 128k -f flv "rtmp://sunucu/live/ANAHTAR"</div></div>
          </div>
        </div>
      </div>
<?php endif; ?>
    </main>

    <div class="ticker">
      <div class="track" id="tick">
        <?php
        $tickItems = [];
        foreach ($CHANNELS as $ch) { $s = channel_state($ch); $tickItems[] = ($s['state'] === 'running' ? '<b>● ' : '<u>○ ') . h($ch['name'] ?: $ch['id']) . ($s['state'] === 'running' ? ' YAYINDA</b>' : ' BEKLEMEDE</u>'); }
        $tickItems[] = 'ffmpeg ' . (ffmpeg_ok() ? '<b>HAZIR</b>' : '<u>YOK</u>');
        $tickItems[] = 'CPU <b>' . ($SRV['cpu'] === null ? '—' : $SRV['cpu'] . '%') . '</b>';
        $tickItems[] = 'disk boş <b>' . human_size($SRV['disk_free']) . '</b>';
        $tickItems[] = 'medya <b>' . count($MEDIA) . '</b>';
        $tickItems[] = 'watchdog <b>' . (count(array_filter($CHANNELS, function($c){ return !empty($c['watchdog']); })) . ' bant') . '</b>';
        if (!$tickItems) $tickItems[] = '<u>henüz bant tanımlanmadı</u>';
        $tickStr = implode(' &nbsp;·&nbsp; ', $tickItems);
        echo $tickStr . ' &nbsp;·&nbsp; ' . $tickStr;
        ?>
      </div>
    </div>
  </div>
</div>

<form method="post" id="hiddenForms" style="display:none">
  <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
</form>
<div class="toasts" id="toasts"></div>
<?php endif; /* is_authed */ ?>

<script>
/* ============================== YARDIMCI JS ============================== */
var POLL = <?= (int)(is_authed() ? $SETTINGS['poll'] : 4) ?> * 1000;
var AUTHED = <?= is_authed() ? 'true' : 'false' ?>;
function $(s, r){ return (r || document).querySelector(s); }
function $$(s, r){ return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function toast(msg, type){
  if (!AUTHED) return;
  var box = $('#toasts'); if (!box) return;
  var d = document.createElement('div');
  d.className = 'toast ' + (type || '');
  d.innerHTML = '<b>' + (type === 'err' ? 'hata' : (type === 'ok' ? 'tamam' : 'bilgi')) + '</b>' + msg;
  box.appendChild(d);
  setTimeout(function(){ d.style.transition = 'opacity .4s,transform .4s'; d.style.opacity = '0'; d.style.transform = 'translateX(24px)'; setTimeout(function(){ d.remove(); }, 400); }, 4600);
}
function post(action, extra){
  var f = document.createElement('form');
  f.method = 'post'; f.action = 'index.php';
  var t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = '<?= h(csrf_token()) ?>'; f.appendChild(t);
  var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = action; f.appendChild(a);
  Object.keys(extra || {}).forEach(function(k){
    var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = extra[k]; f.appendChild(i);
  });
  document.body.appendChild(f); f.submit();
}
/* saat */
setInterval(function(){
  var c = $('#clk'); if (!c) return;
  var d = new Date();
  c.textContent = [d.getHours(), d.getMinutes(), d.getSeconds()].map(function(n){ return String(n).padStart(2, '0'); }).join(':');
}, 1000);
/* scroll reveal */
(function(){
  var io = new IntersectionObserver(function(es){
    es.forEach(function(e){ if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { threshold: .08 });
  $$('.reveal').forEach(function(el, i){ el.style.transitionDelay = Math.min(i * 45, 320) + 'ms'; io.observe(el); });
})();
/* çıkış */
(function(){ var l = $('#logoutLink'); if (l) l.addEventListener('click', function(e){ e.preventDefault(); post('logout'); }); })();

if (AUTHED) {
/* ============================ DURUM YOKLAMASI ============================ */
function fmtUp(s){
  s = Math.max(0, Math.floor(s));
  var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), ss = s % 60;
  var p = function(n){ return String(n).padStart(2, '0'); };
  return (d ? d + 'g ' : '') + p(h) + ':' + p(m) + ':' + p(ss);
}
function applyStatus(data){
  var runCount = 0;
  (data.channels || []).forEach(function(c){
    if (c.state === 'running') runCount++;
    var rows = $$('.chan[data-id="' + c.id + '"]');
    rows.forEach(function(row){
      var pill = row.querySelector('[data-role="pill"]');
      if (pill) {
        pill.className = 'pill ' + c.state;
        var tx = pill.querySelector('[data-role="stext"]');
        if (tx) tx.textContent = c.state === 'running' ? 'YAYINDA' : (c.state === 'error' ? 'KESİLDİ' : 'DURDU');
      }
      var up = row.querySelector('[data-role="uptime"]');
      if (up) up.textContent = c.state === 'running' ? fmtUp(c.uptime) : '—';
      var tel = row.querySelector('[data-role="tel"]');
      if (tel) {
        tel.style.display = c.state === 'running' ? '' : 'none';
        var t = c.tel || {};
        var set = function(k, v){ var el = tel.querySelector('[data-t="' + k + '"]'); if (el && v !== null && v !== undefined) el.textContent = v; };
        set('frame', t.frame || 0); set('fps', t.fps != null ? t.fps : '—');
        set('bitrate', t.bitrate != null ? Math.round(t.bitrate) : '—');
        set('speed', t.speed != null ? t.speed + 'x' : '—');
        set('restarts', c.restarts || 0);
      }
      row.classList.toggle('live', c.state === 'running');
    });
  });
  var oa = $('#onairBox'), oc = $('#onairCount');
  if (oa) {
    oa.classList.toggle('live', runCount > 0);
    var lbl = oa.querySelector('.oalbl');
    if (lbl) lbl.textContent = runCount > 0 ? 'ON AIR' : 'YAYIN YOK';
    var dot = oa.querySelector('.oadot');
    if (dot) dot.style.background = runCount > 0 ? 'var(--red)' : '#3c4a68';
  }
  if (oc) oc.textContent = runCount;
  var vt = $('#vuTop'); if (vt) vt.classList.toggle('idle', runCount === 0);
  var st = $('#stRun'); if (st) st.textContent = runCount;
  var sv = data.server || {};
  if (sv.cpu != null) {
    var e1 = $('#sCpu'), e2 = $('#stCpu'), e3 = $('#stCpu2');
    if (e1) e1.textContent = sv.cpu + '%'; if (e2) e2.textContent = sv.cpu + '%'; if (e3) e3.textContent = sv.cpu + '%';
    var b = $('#sCpuBar'); if (b) b.style.width = Math.min(100, sv.cpu) + '%';
  }
  if (sv.mem_total) { var m = $('#stMem'); if (m) m.textContent = humanSize(sv.mem_used) + ' / ' + humanSize(sv.mem_total); }
  var ls = $('#lastSync'); if (ls) ls.innerHTML = '<i></i>' + new Date().toLocaleTimeString('tr-TR');
  /* hero */
  var hero = (data.channels || []).filter(function(c){ return c.state === 'running'; })[0];
  if (hero) {
    var n = $('#heroName'); if (n) n.textContent = hero.name;
    var tg = $('#heroTarget'); if (tg && hero.target) tg.textContent = hero.target;
    var u = $('#heroUp'); if (u) u.textContent = fmtUp(hero.uptime);
    var f = $('#heroFrame'); if (f) f.textContent = hero.tel.frame || 0;
    var fp = $('#heroFps'); if (fp) fp.textContent = hero.tel.fps != null ? hero.tel.fps : '—';
    var br = $('#heroBr'); if (br) br.innerHTML = hero.tel.bitrate != null ? Math.round(hero.tel.bitrate) + '<small> kb/s</small>' : '—';
    var sp = $('#heroSp'); if (sp) sp.innerHTML = hero.tel.speed != null ? hero.tel.speed + '<small>x</small>' : '—';
    var pd = $('#heroPid'); if (pd) pd.textContent = hero.pid;
    WAVE.amp = hero.tel.speed ? Math.min(1, hero.tel.speed) : .45;
    WAVE.on = true;
  } else { WAVE.on = false; WAVE.amp = .12; }
}
function humanSize(b){
  var u = ['B','KB','MB','GB','TB'], i = 0; b = Number(b) || 0;
  while (b >= 1024 && i < 4) { b /= 1024; i++; }
  return b.toFixed(i ? 1 : 0) + ' ' + u[i];
}
function pollStatus(){
  fetch('index.php?action=status', { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' })
    .then(function(r){ return r.json(); }).then(applyStatus).catch(function(){});
}
pollStatus(); setInterval(pollStatus, POLL);

/* ================================ DALGA FORMU ============================ */
var WAVE = { amp: .2, on: false, t: 0 };
(function(){
  var cv = $('#wave'); if (!cv) return;
  var ctx = cv.getContext('2d');
  function resize(){ cv.width = cv.clientWidth * (window.devicePixelRatio || 1); cv.height = 78 * (window.devicePixelRatio || 1); }
  resize(); window.addEventListener('resize', resize);
  function draw(){
    var w = cv.width, hgt = cv.height;
    ctx.clearRect(0, 0, w, hgt);
    WAVE.t += 0.035;
    var layers = [
      { c: 'rgba(255,46,77,.85)', a: 1.0, s: 1.0, f: 2.2 },
      { c: 'rgba(255,176,46,.55)', a: .7, s: 1.5, f: 3.1 },
      { c: 'rgba(32,211,166,.45)', a: .5, s: 2.1, f: 4.4 }
    ];
    layers.forEach(function(L, li){
      ctx.beginPath();
      for (var x = 0; x <= w; x += 3) {
        var p = x / w;
        var y = hgt / 2
          + Math.sin(p * L.f * Math.PI * 2 + WAVE.t * (1 + li * .35)) * hgt * .28 * WAVE.amp * L.a
          + Math.sin(p * 17 + WAVE.t * 2.4) * hgt * .06 * WAVE.amp
          + (Math.random() - .5) * hgt * .035 * (WAVE.on ? WAVE.amp : .2);
        if (x === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      }
      ctx.strokeStyle = L.c; ctx.lineWidth = (window.devicePixelRatio || 1) * (li === 0 ? 1.8 : 1.1);
      ctx.stroke();
    });
    /* orta çizgi */
    ctx.strokeStyle = 'rgba(255,255,255,.06)'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(0, hgt / 2); ctx.lineTo(w, hgt / 2); ctx.stroke();
    requestAnimationFrame(draw);
  }
  draw();
})();

/* ================================= LOGLAR ================================ */
(function(){
  var box = $('#logBox'); if (!box) return;
  var id = $('#logSel') ? $('#logSel').value : '';
  var auto = $('#logAuto'); var timer = null;
  function cls(l){
    if (/error|failed|invalid|no such/i.test(l)) return 'l-err';
    if (/warn/i.test(l)) return 'l-warn';
    if (/opening|output #|stream mapping|press \[q\]/i.test(l)) return 'l-ok';
    return '';
  }
  function esc(s){ return s.replace(/[&<>]/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function load(){
    if (!id) { box.textContent = 'bant seçilmedi'; return; }
    fetch('index.php?action=log&id=' + encodeURIComponent(id), { cache: 'no-store' })
      .then(function(r){ return r.json(); }).then(function(d){
        var stick = box.scrollTop + box.clientHeight >= box.scrollHeight - 40;
        box.innerHTML = (d.lines || []).map(function(l){ return '<span class="' + cls(l) + '">' + esc(l) + '</span>'; }).join('\n');
        if (stick) box.scrollTop = box.scrollHeight;
        var t = d.tel || {};
        var set = function(sel, v){ var e = $(sel); if (e) e.textContent = v; };
        set('#lgFrame', t.frame != null ? t.frame : '—'); set('#lgFps', t.fps != null ? t.fps : '—');
        set('#lgBr', t.bitrate != null ? Math.round(t.bitrate) + ' kb/s' : '—'); set('#lgSp', t.speed != null ? t.speed + 'x' : '—');
        var ls = $('#logState');
        if (ls) {
          var run = (d.lines || []).some(function(l){ return /frame=/.test(l); });
          ls.className = 'pill ' + (run ? 'on' : 'off');
          ls.innerHTML = '<i></i>' + (run ? 'AKIŞ VAR' : 'AKIŞ YOK');
        }
      }).catch(function(){ box.textContent = 'log okunamadı'; });
  }
  function loop(){ if (timer) clearInterval(timer); if (auto && auto.checked) timer = setInterval(load, 2500); }
  if (auto) auto.addEventListener('change', loop);
  load(); loop();
})();
function loadProcs(){ location.reload(); }
function killPid(pid){
  var fd = new FormData();
  fd.set('action', 'kill_pid'); fd.set('pid', pid); fd.set('_token', '<?= h(csrf_token()) ?>');
  fetch('index.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
    .then(function(r){ return r.json(); })
    .then(function(d){ toast(d.msg || 'İşlem tamam', d.ok ? 'ok' : 'err'); setTimeout(function(){ location.reload(); }, 700); })
    .catch(function(){ toast('Süreç sonlandırılamadı.', 'err'); });
}

/* ================================ MEDYA UI =============================== */
(function(){
  var drop = $('#drop'), inp = $('#fileInp');
  if (drop && inp) {
    drop.addEventListener('click', function(){ inp.click(); });
    ['dragenter', 'dragover'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('hover'); }); });
    ['dragleave', 'drop'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('hover'); }); });
    drop.addEventListener('drop', function(e){ inp.files = e.dataTransfer.files; showNames(); });
    inp.addEventListener('change', showNames);
    function showNames(){
      var n = $('#fileNames'); if (!n) return;
      var arr = Array.prototype.slice.call(inp.files).map(function(f){ return f.name + ' (' + humanSize(f.size) + ')'; });
      n.textContent = arr.length ? arr.length + ' dosya: ' + arr.join(', ') : '';
    }
  }
  var s = $('#mSearch');
  if (s) s.addEventListener('input', function(){
    var q = s.value.toLowerCase();
    $$('#mTbl tbody tr').forEach(function(tr){ tr.style.display = (tr.dataset.name || '').indexOf(q) > -1 ? '' : 'none'; });
  });
})();

/* ================================ EDİTÖR ================================= */
(function(){
  var modal = $('#editorModal'), form = $('#editorForm');
  if (!form) return;
  /* kapatma */
  $$('[data-close]').forEach(function(b){ b.addEventListener('click', function(){
    modal.classList.remove('open');
    if (<?= $isNew ? 'true' : 'false' ?>) location.href = 'index.php?page=bantlar';
  }); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.classList.contains('open')) {
    modal.classList.remove('open'); if (<?= $isNew ? 'true' : 'false' ?>) location.href = 'index.php?page=bantlar'; } });

  /* kaynak tipi */
  var st = $('#fSrcType');
  if (st) st.addEventListener('change', function(){
    $('#srcUrlBox').style.display = st.value === 'url' ? '' : 'none';
    $('#srcPlBox').style.display = st.value === 'url' ? 'none' : '';
    buildCmd();
  });
  /* hedef önizleme */
  function targetPrev(){
    var s = ($('#fRtmp') || {}).value || '', k = ($('#fKey') || {}).value || '';
    s = s.replace(/\/+$/, '');
    var el = $('#targetPrev'); if (el) el.textContent = s ? (k ? s + '/' + k.replace(/^\/+/, '') : s) : '—';
  }
  ['#fRtmp', '#fKey'].forEach(function(sel){ var e = $(sel); if (e) e.addEventListener('input', function(){ targetPrev(); buildCmd(); }); });

  /* oynatma listesi */
  var ITEMS = [];
  try { ITEMS = JSON.parse($('#itemsJson').value || '[]'); } catch (e) { ITEMS = []; }
  function dur(s){ s = Number(s) || 0; if (!s) return '—'; var m = Math.floor(s / 60), x = Math.floor(s % 60); return String(m).padStart(2, '0') + ':' + String(x).padStart(2, '0'); }
  function renderList(){
    var box = $('#pList'); if (!box) return;
    if (!ITEMS.length) { box.innerHTML = '<div class="empty">liste boş — soldan medya ekleyin</div>'; }
    else {
      box.innerHTML = ITEMS.map(function(it, i){
        return '<div class="pitem"><span class="no">' + (i + 1) + '</span>' +
          '<span class="nm" title="' + (it.path || it.url || '').replace(/"/g, '') + '">' + (it.name || it.path || it.url) + '</span>' +
          '<span class="dur">' + (it.type === 'url' ? 'URL' : dur(it.duration)) + '</span>' +
          '<button type="button" class="btn xs" data-mv="' + i + ':-1">↑</button>' +
          '<button type="button" class="btn xs" data-mv="' + i + ':1">↓</button>' +
          '<button type="button" class="btn xs danger ghost" data-rm="' + i + '">✕</button></div>';
      }).join('');
    }
    var tot = ITEMS.reduce(function(a, b){ return a + (Number(b.duration) || 0); }, 0);
    var pd = $('#plDur'); if (pd) pd.textContent = ITEMS.length + ' öğe · ' + dur(tot);
    $('#itemsJson').value = JSON.stringify(ITEMS);
    buildCmd();
  }
  document.addEventListener('click', function(e){
    var add = e.target.closest('[data-add]');
    if (add) { try { ITEMS.push(JSON.parse(add.getAttribute('data-add'))); } catch (x) {} renderList(); return; }
    var rm = e.target.closest('[data-rm]');
    if (rm) { ITEMS.splice(Number(rm.getAttribute('data-rm')), 1); renderList(); return; }
    var mv = e.target.closest('[data-mv]');
    if (mv) {
      var p = mv.getAttribute('data-mv').split(':'), i = Number(p[0]), d = Number(p[1]), j = i + d;
      if (j >= 0 && j < ITEMS.length) { var t = ITEMS[i]; ITEMS[i] = ITEMS[j]; ITEMS[j] = t; renderList(); }
    }
  });
  var cb = $('#clearList'); if (cb) cb.addEventListener('click', function(){ if (confirm('Liste boşaltılsın mı?')) { ITEMS = []; renderList(); } });
  var ab = $('#addUrlBtn');
  if (ab) ab.addEventListener('click', function(){
    var u = prompt('Medya/akış URL adresi (mp4, m3u8, rtmp…):');
    if (u && u.trim()) { ITEMS.push({ type: 'url', url: u.trim(), name: u.trim() }); renderList(); }
  });
  var ls = $('#libSearch');
  if (ls) ls.addEventListener('input', function(){
    var q = ls.value.toLowerCase();
    $$('#libList .libitem').forEach(function(el){ el.style.display = (el.dataset.name || '').indexOf(q) > -1 ? '' : 'none'; });
  });
  renderList();

  /* çözünürlük */
  var wh = $('#fWH');
  if (wh) wh.addEventListener('change', function(){
    var p = wh.value.split('x'); $('#fW').value = p[0]; $('#fH').value = p[1];
    var tag = $('.preview .tag'); if (tag) tag.textContent = p[0] + '×' + p[1] + ' · önizleme';
    buildCmd();
  });

  /* logo önizleme */
  var CUR_LOGO = <?= json_encode($E['logo'] ? ('index.php?action=asset&kind=logo&name=' . rawurlencode($E['logo'])) : '') ?>;
  function placeLogo(){
    var img = $('#pvLogo'), no = $('#pvNo'), pv = $('#pv');
    if (!img) return;
    var src = CUR_LOGO;
    if (!src) { img.style.display = 'none'; no.style.display = ''; return; }
    no.style.display = 'none'; img.style.display = ''; img.src = src;
    var scale = Number(($('#rScale') || {}).value || 12);
    var marg = Number(($('#rMar') || {}).value || 0);
    var op = Number(($('#rOp') || {}).value || 100);
    var pos = ($('#posGrid input:checked') || {}).value || 'br';
    img.style.width = scale + '%';
    img.style.opacity = (op / 100).toFixed(2);
    var mp = (marg / Math.max(1, pv.clientWidth)) * 100;
    var L = { l: mp + '%', t: mp + '%', tr: 'none' };
    var map = { tl: [mp, mp], tc: [null, mp], tr: [100 - mp, mp], ml: [mp, null], mc: [null, null], mr: [100 - mp, null], bl: [mp, 100 - mp], bc: [null, 100 - mp], br: [100 - mp, 100 - mp] };
    var m = map[pos] || map.br;
    img.style.left = m[0] === null ? '50%' : m[0] + '%';
    img.style.top = m[1] === null ? '50%' : m[1] + '%';
    var tx = m[0] === null ? '-50%' : (m[0] === 100 - mp ? '-100%' : '0%');
    var ty = m[1] === null ? '-50%' : (m[1] === 100 - mp ? '-100%' : '0%');
    img.style.transform = 'translate(' + tx + ',' + ty + ')';
  }
  $$('#posGrid input').forEach(function(r){ r.addEventListener('change', function(){ placeLogo(); buildCmd(); }); });
  ['#rScale', '#rOp', '#rMar'].forEach(function(sel){
    var e = $(sel); if (!e) return;
    e.addEventListener('input', function(){
      var vS = $('#vScale'), vO = $('#vOp'), vM = $('#vMar');
      if (vS) vS.textContent = $('#rScale').value + '%';
      if (vO) vO.textContent = $('#rOp').value + '%';
      if (vM) vM.textContent = $('#rMar').value + 'px';
      placeLogo(); buildCmd();
    });
  });
  var lf = $('#logoFile');
  if (lf) lf.addEventListener('change', function(){
    if (lf.files && lf.files[0]) {
      if (!/\.(png|jpe?g|webp)$/i.test(lf.files[0].name)) { toast('Logo PNG/JPG/WEBP olmalı.', 'err'); lf.value = ''; return; }
      CUR_LOGO = URL.createObjectURL(lf.files[0]); placeLogo();
      toast('Logo önizlemeye alındı — kaydetmeyi unutmayın.', 'warn');
    }
  });
  placeLogo();

  /* slider etiketleri */
  ['#rVbr|#vVbr| kbps', '#rVol|#vVol|%'].forEach(function(pair){
    var p = pair.split('|'), e = $(p[0]), o = $(p[1]);
    if (e && o) e.addEventListener('input', function(){ o.textContent = e.value + p[2]; buildCmd(); });
  });
  $$('select.inp, input.inp, textarea.inp', form).forEach(function(el){ el.addEventListener('change', buildCmd); });
  $$('.sw input', form).forEach(function(el){ el.addEventListener('change', buildCmd); });

  /* komut üretimi (AJAX) */
  var cmdT = null;
  function buildCmd(){
    var box = $('#cmdBox'); if (!box) return;
    clearTimeout(cmdT);
    cmdT = setTimeout(function(){
      var fd = new FormData(form);
      fd.delete('logo_file'); fd.delete('media');
      fd.set('action', 'build_cmd');           // formun kendi action'ı (channel_save) ezilmemeli
      fd.delete('_token');
      if (CUR_LOGO && CUR_LOGO.indexOf('index.php?action=asset') === 0) fd.set('logo_keep', '1');
      fetch('index.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
        .then(function(r){ return r.json(); }).then(function(d){
          var cs = $('#cmdState');
          if (d.ok) { box.textContent = d.cmd; box.innerHTML = box.innerHTML.replace(/(-filter_complex|\s-i|\s-f flv|\s-map)/g, '<span style="color:#ffb02e">$1</span>'); if (cs) { cs.className = 'pill teal'; cs.innerHTML = '<i></i>komut geçerli'; } }
          else { box.innerHTML = '<span class="err">' + d.error + '</span>'; if (cs) { cs.className = 'pill err'; cs.innerHTML = '<i></i>eksik var'; } }
        }).catch(function(){});
    }, 320);
  }
  var cc = $('#copyCmd');
  if (cc) cc.addEventListener('click', function(){
    var txt = ($('#cmdBox').textContent || '').trim();
    if (!txt) return;
    navigator.clipboard.writeText(txt).then(function(){ toast('ffmpeg komutu panoya kopyalandı.', 'ok'); }, function(){ toast('Kopyalanamadı.', 'err'); });
  });
  buildCmd();
})();
} /* AUTHED */
</script>
</body>
</html>
