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
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ZemTv Giriş</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #0f0f0f 0%, #1a1a2e 100%); color: #fff; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
            .login-box { background: rgba(30, 30, 46, 0.95); padding: 40px 30px; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.6); width: 100%; max-width: 400px; text-align: center; border: 1px solid rgba(255,255,255,0.05); }
            .logo { font-size: 32px; font-weight: 800; background: linear-gradient(135deg, #3498db, #9b59b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 8px; letter-spacing: 1px; }
            .subtitle { color: #888; font-size: 14px; margin-bottom: 30px; }
            input { width: 100%; padding: 16px; margin-bottom: 15px; border-radius: 10px; border: 1px solid #333; background: #252540; color: #fff; font-size: 16px; transition: 0.2s; }
            input:focus { outline: none; border-color: #3498db; background: #2a2a4a; }
            button { width: 100%; padding: 16px; border-radius: 10px; border: none; background: linear-gradient(135deg, #3498db, #2980b9); color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.2s; text-transform: uppercase; letter-spacing: 1px; }
            button:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(52, 152, 219, 0.3); }
            button:active { transform: translateY(0); }
            .hata { background: rgba(231, 76, 60, 0.15); color: #e74c3c; padding: 12px; border-radius: 8px; margin-bottom: 15px; font-size: 14px; border: 1px solid rgba(231, 76, 60, 0.3); }
        </style>
    </head>
    <body>
        <div class="login-box">
            <div class="logo">ZemTv</div>
            <div class="subtitle">Yayın Kontrol Paneli</div>
            <?php if (isset($hata)) echo "<div class='hata'>$hata</div>"; ?>
            <form method="POST">
                <input type="password" name="sifre" required placeholder="Panel Şifresi" autofocus>
                <button type="submit" name="sifre_giris">Giriş Yap</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- 1. AYARLARI VE BAT DOSYASINI KAYDETME ---
if (isset($_POST['ayarlari_kaydet'])) {
    // Playlist'i kaydet
    file_put_contents($playlist_yolu, str_replace("\r\n", "\n", trim($_POST['playlist_icerik'])));

    // BAT dosyası içeriği (senin verdiğin içerik, Windows satır sonu ile)
    $bat_satirlar = [
        '@echo off',
        'chcp 65001 >nul',
        'setlocal EnableDelayedExpansion',
        '',
        'title ZemTv_Streamer',
        '',
        ':: Klasore giris',
        'cd /d "C:\xampp\htdocs\ZemTv\live"',
        '',
        'set "PLAYLIST=C:\xampp\htdocs\ZemTv\playlist.txt"',
        'set "LOGO=C:\xampp\htdocs\ZemTv\logo\logo.png"',
        '',
        ':: Eski dosyalari temizle',
        'if exist index.m3u8 del /q index.m3u8',
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
        '    ffmpeg -loglevel warning -re -reconnect 1 -reconnect_at_eof 1 -reconnect_streamed 1 -reconnect_delay_max 5 ^',
        '    -i "!SRC!" ^',
        '    -i "%LOGO%" ^',
        '    -filter_complex "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[main];[1:v]scale=180:-2,format=rgba[logo];[main][logo]overlay=20:20,drawtext=text=\'!NAME!\':x=w-tw-20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf[v_final]" ^',
        '    -map "[v_final]" -map 0:a? ^',
        '    -c:v libx264 -preset ultrafast -tune zerolatency -crf 22 ^',
        '    -g 60 -keyint_min 60 -sc_threshold 0 ^',
        '    -threads 2 ^',
        '    -c:a aac -b:a 128k -ac 2 ^',
        '    -f hls ^',
        '    -hls_time 4 ^',
        '    -hls_list_size 6 ^',
        '    -hls_flags delete_segments+independent_segments+append_list ^',
        '    -hls_segment_filename "seg_%%03d.ts" ^',
        '    -hls_base_url "http://45.158.14.16/ZemTv/live/" ^',
        '    index.m3u8',
        '',
        '    echo.',
        '    echo Parca bitti. 3 saniye sonra siradakine geciliyor...',
        '    timeout /t 3 >nul',
        ')',
        '',
        ':: Liste bittiginde basa don',
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
    <title>ZemTv Panel</title>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        * { box-sizing: border-box; }
        :root {
            --bg: #0f0f1a;
            --panel-bg: #1a1a2e;
            --panel-bg-2: #252540;
            --primary: #3498db;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --text: #ecf0f1;
            --text-dim: #888;
            --border: #2a2a4a;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-image: radial-gradient(circle at 20% 0%, rgba(52, 152, 219, 0.08) 0%, transparent 50%),
                              radial-gradient(circle at 80% 100%, rgba(155, 89, 182, 0.08) 0%, transparent 50%);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: rgba(26, 26, 46, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            background: linear-gradient(135deg, #3498db, #9b59b6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: 0.5px;
        }
        .cikis {
            text-decoration: none;
            color: #aaa;
            background: var(--panel-bg-2);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
            border: 1px solid var(--border);
        }
        .cikis:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

        .container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        @media (min-width: 900px) {
            .container { grid-template-columns: 1fr 1fr; }
        }

        .panel {
            background: var(--panel-bg);
            padding: 22px;
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .panel-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin: 0 0 18px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .durum {
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 18px;
            letter-spacing: 1px;
            transition: 0.3s;
        }
        .durum .nokta {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        .aktif { background: rgba(39, 174, 96, 0.15); color: #2ecc71; border: 1px solid rgba(39, 174, 96, 0.4); }
        .aktif .nokta { background: #2ecc71; box-shadow: 0 0 10px #2ecc71; }
        .kapali { background: rgba(231, 76, 60, 0.15); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.4); }
        .kapali .nokta { background: #e74c3c; box-shadow: 0 0 10px #e74c3c; animation: none; }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }

        .baslat-durdur-grup {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 22px;
        }

        .btn {
            border: none;
            padding: 16px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 700;
            border-radius: 12px;
            transition: 0.2s;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #fff;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn:active { transform: translateY(0); }
        .btn-baslat { background: linear-gradient(135deg, #27ae60, #229954); }
        .btn-baslat:hover { box-shadow: 0 10px 20px rgba(39, 174, 96, 0.3); }
        .btn-durdur { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .btn-durdur:hover { box-shadow: 0 10px 20px rgba(231, 76, 60, 0.3); }
        .btn-kaydet { background: linear-gradient(135deg, #f39c12, #d68910); width: 100%; margin-top: 18px; }
        .btn-kaydet:hover { box-shadow: 0 10px 20px rgba(243, 156, 18, 0.3); }

        label {
            font-weight: 600;
            margin-top: 18px;
            display: block;
            color: var(--text);
            font-size: 14px;
            margin-bottom: 8px;
        }

        textarea, select {
            width: 100%;
            background: var(--panel-bg-2);
            color: var(--text);
            border: 1px solid var(--border);
            padding: 14px;
            box-sizing: border-box;
            border-radius: 10px;
            font-size: 15px;
            transition: 0.2s;
            font-family: inherit;
        }
        textarea:focus, select:focus { outline: none; border-color: var(--primary); background: #2a2a4a; }
        textarea {
            height: 200px;
            font-family: 'Consolas', 'Monaco', monospace;
            resize: vertical;
            line-height: 1.5;
        }

        .basari {
            background: rgba(52, 152, 219, 0.15);
            color: #5dade2;
            padding: 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            text-align: center;
            font-size: 14px;
            border: 1px solid rgba(52, 152, 219, 0.3);
            font-weight: 600;
        }

        .video-container {
            width: 100%;
            aspect-ratio: 16/9;
            background: #000;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-top: 8px;
            position: relative;
        }
        video { width: 100%; height: 100%; object-fit: contain; display: block; }
        .offline-text {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            color: #555;
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            width: 100%;
            letter-spacing: 2px;
        }
        .info-text {
            font-size: 12px;
            color: var(--text-dim);
            margin-top: 10px;
            text-align: center;
        }
        .info-text.left { text-align: left; margin-top: 6px; margin-bottom: 0; }

        .hint {
            font-size: 12px;
            color: var(--text-dim);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>⚡ ZemTv Panel</h1>
    <a href="?cikis=1" class="cikis">Çıkış</a>
</div>

<div class="container">

    <!-- YAYIN KONTROL PANELİ -->
    <div class="panel">
        <h3 class="panel-title">🎛️ Yayın Kontrol</h3>

        <?php if ($yayin_aktif): ?>
            <div class="durum aktif"><span class="nokta"></span> YAYIN AKTİF</div>
        <?php else: ?>
            <div class="durum kapali"><span class="nokta"></span> YAYIN KAPALI</div>
        <?php endif; ?>

        <form method="POST" class="baslat-durdur-grup">
            <button type="submit" name="yayin_baslat" class="btn btn-baslat">▶ Başlat</button>
            <button type="submit" name="yayin_durdur" class="btn btn-durdur">⏹ Durdur</button>
        </form>

        <label>👀 Canlı Önizleme</label>
        <div class="video-container">
            <?php if (!$yayin_aktif): ?>
                <div class="offline-text">YAYIN KAPALI</div>
            <?php endif; ?>
            <video id="zem_player" controls autoplay muted playsinline></video>
        </div>
        <p class="info-text">Yayının player'a düşmesi 10-15 saniye sürebilir.</p>
    </div>

    <!-- AYARLAR PANELİ -->
    <div class="panel">
        <h3 class="panel-title">⚙️ Ayarlar</h3>

        <?php if (isset($mesaj)) echo "<div class='basari'>✓ $mesaj</div>"; ?>

        <form method="POST">
            <label>🎬 Playlist Düzenle</label>
            <textarea name="playlist_icerik" placeholder="http://link.m3u8|Film Adı"><?php echo htmlspecialchars($mevcut_playlist); ?></textarea>
            <p class="info-text left hint">💡 Format: <code>http://link.m3u8|Film Adı</code> — Her satıra bir yayın</p>

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
                hls.on(Hls.Events.MANIFEST_PARSED, function() { video.play().catch(()=>{}); });
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
                video.addEventListener('loadedmetadata', function() { video.play().catch(()=>{}); });
            }
        }
    });
</script>
</body>
</html>
