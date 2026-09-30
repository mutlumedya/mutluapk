<?php
session_start();

// --- GÜVENLİK ---
$panel_sifresi = "ZemTv2024!";

// --- DOSYA YOLLARI ---
$ana_dizin = "C:\\xampp\\htdocs\\ZemTv";
$playlist_yolu = $ana_dizin . "\\playlist.txt";
$bat_yolu = $ana_dizin . "\\yayin.bat";

// --- GİRİŞ KONTROLÜ ---
if (isset($_POST['sifre_giris'])) {
    if ($_POST['sifre'] === $panel_sifresi) {
        $_SESSION['oturum_acik'] = true;
    } else {
        $hata = "Hatalı şifre!";
    }
}
if (isset($_GET['cikis'])) {
    session_destroy();
    header("Location: panel.php");
    exit;
}
if (!isset($_SESSION['oturum_acik']) || $_SESSION['oturum_acik'] !== true) {
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>ZemTv Giriş</title><style>body { font-family: sans-serif; background:#121212; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin:0;} .login-box { background: #1e1e1e; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); width: 90%; max-width: 400px; text-align: center; } input, button { width: 100%; padding: 15px; margin-top: 15px; border-radius: 8px; border: none; box-sizing: border-box; font-size: 16px; } input { background: #2c2c2c; color: #fff; } button { background: #3498db; color: #fff; cursor: pointer; font-weight: bold; } button:hover { background: #2980b9; }</style></head><body><div class="login-box"><h2>ZemTv Panel</h2>';
    if (isset($hata)) echo "<p style='color:#e74c3c;'>$hata</p>";
    echo '<form method="POST"><input type="password" name="sifre" required placeholder="Panel Şifresi"><button type="submit" name="sifre_giris">Giriş Yap</button></form></div></body></html>';
    exit;
}

// --- 1. AYARLARI VE BAT DOSYASINI KAYDETME ---
if (isset($_POST['ayarlari_kaydet'])) {
    // Playlist'i kaydet
    file_put_contents($playlist_yolu, str_replace("\r\n", "\n", trim($_POST['playlist_icerik'])));

    // Logo pozisyonunu seç
    $secilen_pozisyon = $_POST['logo_pozisyonu'] ?? 'sag_ust';
    switch ($secilen_pozisyon) {
        case "sol_ust":
            $overlay_kodu = "50:40";
            break;
        case "sag_alt":
            $overlay_kodu = "main_w-overlay_w-50:main_h-overlay_h-50";
            break;
        case "sol_alt":
            $overlay_kodu = "50:main_h-overlay_h-50";
            break;
        case "sag_ust":
        default:
            $overlay_kodu = "main_w-overlay_w-50:40";
            break;
    }

    // BAT dosyası içeriği (Windows satır sonu \r\n ile)
    $bat_satirlar = [
        '@echo off',
        'chcp 65001 >nul',
        'setlocal EnableDelayedExpansion',
        '',
        'title ZemTv_Streamer',
        '',
        'cd /d "C:\xampp\htdocs\ZemTv\live"',
        '',
        'set "PLAYLIST=C:\xampp\htdocs\ZemTv\playlist.txt"',
        'set "LOGO=C:\xampp\htdocs\ZemTv\logo\logo.png"',
        '',
        'if exist index.m3u8 del /q index.m3u8',
        'if exist seg_*.ts del /q seg_*.ts',
        '',
        ':MAIN',
        'for /f "usebackq tokens=1,2 delims=|" %%A in ("%PLAYLIST%") do (',
        '    ',
        '    set "SRC=%%A"',
        '    set "NAME=%%B"',
        '',
        '    cls',
        '    echo ========================================',
        '    echo YAYINDA: !NAME!',
        '    echo KAYNAK: !SRC!',
        '    echo ========================================',
        '',
        '    streamlink "!SRC!" best --stdout ^',
        '    | ffmpeg -i pipe:0 ^',
        '    -i "%LOGO%" ^',
        '    -filter_complex "[0:v]scale=1280:720,setsar=1[main];[1:v]scale=240:-2,format=rgba[logo];[main][logo]overlay=' . $overlay_kodu . '[v_final]" ^',
        '    -map "[v_final]" -map 0:a? ^',
        '    -c:v libx264 -preset ultrafast -tune zerolatency -crf 23 ^',
        '    -c:a aac -b:a 128k -ac 2 -ar 44100 ^',
        '    -f hls ^',
        '    -hls_time 4 ^',
        '    -hls_list_size 10 ^',
        '    -hls_flags delete_segments+independent_segments ^',
        '    -hls_segment_filename "seg_%%03d.ts" ^',
        '    -hls_base_url "http://45.158.14.16/ZemTv/live/" ^',
        '    index.m3u8',
        '',
        '    echo.',
        '    echo Yayin bitti veya hata alindi. 3 saniye sonra yeniden denenecek...',
        '    timeout /t 3 >nul',
        ')',
        '',
        'goto MAIN',
    ];

    $bat_icerik = implode("\r\n", $bat_satirlar);
    file_put_contents($bat_yolu, $bat_icerik);
    $mesaj = "Ayarlar başarıyla kaydedildi! Yayına yansıması için durdurup tekrar başlatın.";
}

// --- ZORLA KAPATMA FONKSİYONU ---
function yayini_kapat() {
    exec('taskkill /F /T /FI "WINDOWTITLE eq ZemTv_Streamer*" 2>nul');
    exec('taskkill /F /T /IM ffmpeg.exe 2>nul');
    exec('taskkill /F /T /IM streamlink.exe 2>nul');
    exec('taskkill /F /T /IM cmd.exe /FI "WINDOWTITLE eq ZemTv_Streamer*" 2>nul');
}

// --- 2. YAYINI BAŞLAT ---
if (isset($_POST['yayin_baslat'])) {
    yayini_kapat();
    sleep(1);
    chdir($ana_dizin);
    pclose(popen('start "ZemTv_Streamer" cmd.exe /c yayin.bat', "r"));
    sleep(2);
    header("Location: panel.php");
    exit;
}

// --- 3. YAYINI DURDUR ---
if (isset($_POST['yayin_durdur'])) {
    yayini_kapat();
    sleep(1);
    header("Location: panel.php");
    exit;
}

// --- DURUM KONTROLÜ ---
exec('tasklist /FI "IMAGENAME eq ffmpeg.exe" 2>nul', $task_ciktisi);
$yayin_aktif = false;
foreach ($task_ciktisi as $satir) {
    if (stripos($satir, 'ffmpeg.exe') !== false) {
        $yayin_aktif = true;
        break;
    }
}

$mevcut_playlist = file_exists($playlist_yolu) ? file_get_contents($playlist_yolu) : "";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZemTv Mobil Panel</title>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        :root { --bg: #121212; --panel-bg: #1e1e1e; --primary: #3498db; --success: #27ae60; --danger: #e74c3c; --text: #ecf0f1; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 15px; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        .header h2 { margin: 0; font-size: 1.5rem; color: #fff; }
        .cikis { text-decoration: none; color: #aaa; background: #333; padding: 8px 15px; border-radius: 5px; font-size: 14px; }
        .container { display: flex; flex-direction: column; gap: 20px; max-width: 1200px; margin: auto; }
        @media (min-width: 900px) { .container { flex-direction: row; } .panel { flex: 1; } }
        .panel { background: var(--panel-bg); padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); }
        .durum { padding: 8px 15px; border-radius: 20px; font-weight: bold; font-size: 12px; display: inline-block; text-align: center; width: 100%; box-sizing: border-box; margin-bottom: 15px;}
        .aktif { background: var(--success); color: #fff; box-shadow: 0 0 10px var(--success); }
        .kapali { background: var(--danger); color: #fff; box-shadow: 0 0 10px var(--danger); }
        label { font-weight: bold; margin-top: 15px; display: block; color: var(--primary); font-size: 14px; }
        textarea, select { width: 100%; background: #2c2c2c; color: var(--text); border: 1px solid #444; padding: 12px; margin-top: 5px; box-sizing: border-box; border-radius: 8px; font-size: 15px; }
        textarea { height: 180px; font-family: monospace; resize: vertical; }
        .btn { border: none; padding: 16px; font-size: 16px; cursor: pointer; font-weight: bold; border-radius: 8px; transition: 0.2s; width: 100%; margin-top: 15px; text-transform: uppercase; }
        .btn-kaydet { background: #f39c12; color: #fff; }
        .btn-kaydet:hover { background: #d68910; }
        .baslat-durdur-grup { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
        @media (min-width: 500px) { .baslat-durdur-grup { flex-direction: row; } }
        .btn-baslat { background: var(--success); color: #fff; flex: 1; margin-top: 0;}
        .btn-baslat:hover { background: #219150; }
        .btn-durdur { background: var(--danger); color: #fff; flex: 1; margin-top: 0;}
        .btn-durdur:hover { background: #c0392b; }
        .basari { background: #2980b9; color: white; padding: 15px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-size: 14px; }
        .video-container { width: 100%; aspect-ratio: 16/9; background: #000; border: 2px solid #333; border-radius: 10px; overflow: hidden; margin-top: 10px; position:relative; }
        video { width: 100%; height: 100%; object-fit: contain; }
        .offline-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #777; font-size: 16px; font-weight: bold; text-align: center; width: 100%; }
        .info-text { font-size: 12px; color: #aaa; margin-top: 5px; text-align: center; }
    </style>
</head>
<body>

<div class="header" style="max-width: 1200px; margin: 0 auto 20px auto; width: 100%;">
    <h2>ZemTv Panel</h2>
    <a href="?cikis=1" class="cikis">Çıkış</a>
</div>

<div class="container">
    <div class="panel">
        <?php if ($yayin_aktif): ?>
            <div class="durum aktif">🔴 YAYIN AKTİF</div>
        <?php else: ?>
            <div class="durum kapali">⚫ YAYIN KAPALI</div>
        <?php endif; ?>

        <form method="POST" class="baslat-durdur-grup">
            <button type="submit" name="yayin_baslat" class="btn btn-baslat">▶ Başlat</button>
            <button type="submit" name="yayin_durdur" class="btn btn-durdur">⏹ Kapat</button>
        </form>

        <label>👀 Canlı Önizleme</label>
        <div class="video-container">
            <?php if (!$yayin_aktif): ?>
                <div class="offline-text">YAYIN ŞU AN KAPALI</div>
            <?php endif; ?>
            <video id="zem_player" controls autoplay muted playsinline></video>
        </div>
        <p class="info-text">Yayının player'a düşmesi 10-15 saniye sürebilir.</p>
    </div>

    <div class="panel">
        <?php if (isset($mesaj)) echo "<div class='basari'>$mesaj</div>"; ?>
        <form method="POST">
            <label>🎬 Playlist Düzenle (playlist.txt)</label>
            <p class="info-text" style="text-align: left;">Format: http://link.m3u8|Film Adı</p>
            <textarea name="playlist_icerik"><?php echo htmlspecialchars($mevcut_playlist); ?></textarea>

            <label>🖼️ Logo Konumu</label>
            <select name="logo_pozisyonu">
                <option value="sag_ust">Sağ Üst</option>
                <option value="sol_ust">Sol Üst</option>
                <option value="sag_alt">Sağ Alt</option>
                <option value="sol_alt">Sol Alt</option>
            </select>

            <button type="submit" name="ayarlari_kaydet" class="btn btn-kaydet">💾 Ayarları Kaydet</button>
        </form>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        const video = document.getElementById('zem_player');
        const hlsUrl = 'live/index.m3u8?t=' + new Date().getTime();
        const yayinAktifMi = <?php echo $yayin_aktif ? 'true' : 'false'; ?>;

        if (yayinAktifMi) {
            if (Hls.isSupported()) {
                const hls = new Hls({ debug: false, manifestLoadingTimeOut: 20000 });
                hls.loadSource(hlsUrl);
                hls.attachMedia(video);
                hls.on(Hls.Events.MANIFEST_PARSED, function() { video.play(); });
                hls.on(Hls.Events.ERROR, function (event, data) {
                    if (data.fatal) {
                        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                            setTimeout(() => { hls.startLoad(); }, 3000);
                        } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                            hls.recoverMediaError();
                        } else {
                            hls.destroy();
                        }
                    }
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = hlsUrl;
                video.addEventListener('loadedmetadata', function() { video.play(); });
            }
        }
    });
</script>
</body>
</html>
