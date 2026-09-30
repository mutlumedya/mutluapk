<?php
session_start();

$panel_sifresi = "ZemTv2024!";
$ana_dizin = "C:\\xampp\\htdocs\\ZemTv";
$playlist_yolu = $ana_dizin . "\\playlist.txt";
$bat_yolu = $ana_dizin . "\\yayin.bat"; 

// GİRİŞ KONTROLÜ
if (isset($_POST['sifre_giris'])) {
    if ($_POST['sifre'] === $panel_sifresi) { $_SESSION['oturum_acik'] = true; } 
    else { $hata = "Hatalı şifre!"; }
}
if (isset($_GET['cikis'])) {
    session_destroy(); header("Location: panel.php"); exit;
}
if (!isset($_SESSION['oturum_acik']) || $_SESSION['oturum_acik'] !== true) {
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>ZemTv Panel Giriş</title><style>body { font-family: Arial; text-align: center; margin-top: 100px; background:#1e1e1e; color: #fff; }</style></head><body><h2>ZemTv Yayın Paneli</h2>';
    if(isset($hata)) echo "<p style='color:#e74c3c;'>$hata</p>";
    echo '<form method="POST"><input type="password" name="sifre" required placeholder="Panel Şifresi" style="padding:10px; border-radius:5px; border:none;"> <button type="submit" name="sifre_giris" style="padding:10px; border-radius:5px; background:#3498db; color:#fff; border:none; cursor:pointer;">Giriş</button></form></body></html>';
    exit;
}

// 1. AYARLARI VE BAT DOSYASINI KAYDET
if (isset($_POST['ayarlari_kaydet'])) {
    file_put_contents($playlist_yolu, str_replace("\r\n", "\n", trim($_POST['playlist_icerik'])));

    $secilen_pozisyon = $_POST['logo_pozisyonu'];
    if ($secilen_pozisyon == "sag_ust") { $overlay_kodu = "main_w-overlay_w-50:40"; } 
    elseif ($secilen_pozisyon == "sol_ust") { $overlay_kodu = "50:40"; } 
    elseif ($secilen_pozisyon == "sag_alt") { $overlay_kodu = "main_w-overlay_w-50:main_h-overlay_h-50"; } 
    elseif ($secilen_pozisyon == "sol_alt") { $overlay_kodu = "50:main_h-overlay_h-50"; } 
    else { $overlay_kodu = "main_w-overlay_w-50:40"; }

    // SENİN ORİJİNAL KODUN - Sadece overlay kısmına değişken eklendi.
    $bat_icerik = '@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

title ZemTv_Streamer

cd /d "C:\xampp\htdocs\ZemTv\live"

set "PLAYLIST=C:\xampp\htdocs\ZemTv\playlist.txt"
set "LOGO=C:\xampp\htdocs\ZemTv\logo\logo.png"

if exist index.m3u8 del /q index.m3u8
if exist seg_*.ts del /q seg_*.ts

:MAIN
for /f "usebackq tokens=1,2 delims=|" %%A in ("%PLAYLIST%") do (
    
    set "SRC=%%A"
    set "NAME=%%B"

    cls
    echo ========================================
    echo YAYINDA: !NAME!
    echo KAYNAK: !SRC!
    echo ========================================

    streamlink "!SRC!" best --stdout ^
    | ffmpeg -i pipe:0 ^
    -i "%LOGO%" ^
    -filter_complex "[0:v]scale=1280:720,setsar=1[main];[1:v]scale=240:-2,format=rgba[logo];[main][logo]overlay=' . $overlay_kodu . '[v_final]" ^
    -map "[v_final]" -map 0:a? ^
    -c:v libx264 -preset ultrafast -tune zerolatency -crf 23 ^
    -c:a aac -b:a 128k -ac 2 -ar 44100 ^
    -f hls ^
    -hls_time 4 ^
    -hls_list_size 10 ^
    -hls_flags delete_segments+independent_segments ^
    -hls_segment_filename "seg_%%03d.ts" ^
    -hls_base_url "http://45.158.14.16/ZemTv/live/" ^
    index.m3u8

    echo.
    echo Yayin bitti veya hata alindi. 3 saniye sonra yeniden denenecek...
    timeout /t 3 >nul
)

goto MAIN';

    // Windows satır sonlarını güvene almak için ek düzeltme
    $bat_icerik = str_replace("\r\n", "\n", $bat_icerik);
    $bat_icerik = str_replace("\n", "\r\n", $bat_icerik);
    
    file_put_contents($bat_yolu, $bat_icerik);
    $mesaj = "Ayarlar kaydedildi! Yeni ayarların aktif olması için yayını durdurup başlatın.";
}

// 2. YAYINI BAŞLAT
if (isset($_POST['yayin_baslat'])) {
    exec("taskkill /FI \"WINDOWTITLE eq ZemTv_Streamer*\" /T /F 2>nul");
    exec("taskkill /IM ffmpeg.exe /F 2>nul");
    exec("taskkill /IM streamlink.exe /F 2>nul");
    sleep(1);

    // HATA ÇÖZÜMÜ: C: klasör hatasını engellemek için doğrudan dizine gidip çalıştırıyoruz.
    chdir($ana_dizin);
    pclose(popen('start "ZemTv_Streamer" cmd.exe /c yayin.bat', "r"));
    sleep(2); 
    header("Location: panel.php");
    exit;
}

// 3. YAYINI DURDUR
if (isset($_POST['yayin_durdur'])) {
    exec("taskkill /FI \"WINDOWTITLE eq ZemTv_Streamer*\" /T /F 2>nul");
    exec("taskkill /IM ffmpeg.exe /F 2>nul");
    exec("taskkill /IM streamlink.exe /F 2>nul");
    sleep(1);
    header("Location: panel.php");
    exit;
}

// DURUM KONTROLÜ
exec("tasklist /FI \"IMAGENAME eq ffmpeg.exe\" 2>nul", $task_ciktisi);
$yayin_aktif = false;
foreach ($task_ciktisi as $satir) {
    if (stripos($satir, 'ffmpeg.exe') !== false) {
        $yayin_aktif = true; break;
    }
}

$mevcut_playlist = file_exists($playlist_yolu) ? file_get_contents($playlist_yolu) : "";
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>ZemTv Kontrol Merkezi</title>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #121212; color: #ecf0f1; margin: 0; padding: 20px; }
        .container { max-width: 1300px; margin: auto; display: flex; flex-wrap: wrap; gap: 20px; }
        .sol-panel { flex: 1; min-width: 350px; background: #1e1e1e; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        .sag-panel { flex: 1; min-width: 450px; background: #1e1e1e; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        h2 { margin-top: 0; border-bottom: 2px solid #333; padding-bottom: 10px; color: #fff; }
        .durum { display: inline-block; padding: 8px 15px; border-radius: 20px; font-weight: bold; font-size: 14px; float: right; }
        .aktif { background: #27ae60; color: #fff; box-shadow: 0 0 10px #27ae60; }
        .kapali { background: #e74c3c; color: #fff; box-shadow: 0 0 10px #e74c3c; }
        label { font-weight: bold; margin-top: 15px; display: block; color: #3498db; }
        textarea { width: 100%; height: 250px; background: #2c2c2c; color: #ecf0f1; border: 1px solid #444; padding: 10px; margin-top: 5px; font-family: monospace; resize: vertical; box-sizing: border-box; border-radius:5px;}
        select { width: 100%; padding: 10px; margin-top: 5px; background: #2c2c2c; color: #ecf0f1; border: 1px solid #444; box-sizing: border-box; border-radius:5px;}
        .btn { border: none; padding: 15px; font-size: 16px; cursor: pointer; font-weight: bold; border-radius: 5px; transition: 0.3s; width: 100%; margin-top: 10px;}
        .btn-kaydet { background: #f39c12; color: #fff; margin-top: 20px; }
        .btn-kaydet:hover { background: #d68910; }
        .baslat-durdur-grup { display: flex; gap: 10px; margin-bottom: 20px; }
        .btn-baslat { background: #27ae60; color: #fff; flex: 1;}
        .btn-baslat:hover { background: #219150; }
        .btn-durdur { background: #e74c3c; color: #fff; flex: 1;}
        .btn-durdur:hover { background: #c0392b; }
        .cikis { text-decoration: none; color: #aaa; font-size: 14px; float:right; margin-top:-30px;}
        .basari { background: #2980b9; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .video-container { width: 100%; aspect-ratio: 16/9; background: #000; border: 2px solid #333; border-radius: 8px; overflow: hidden; margin-top: 15px; position:relative;}
        video { width: 100%; height: 100%; }
        .offline-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #777; font-size: 18px; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <!-- SOL PANEL: AYARLAR -->
    <div class="sol-panel">
        <a href="?cikis=1" class="cikis">Çıkış Yap</a>
        <h2>⚙️ Sistem Ayarları</h2>
        <?php if(isset($mesaj)) echo "<div class='basari'>$mesaj</div>"; ?>
        <form method="POST">
            <label>🎬 Playlist Düzenle (playlist.txt)</label>
            <p style="font-size: 12px; color:#aaa; margin-top:2px;">Format: <em>http://link.m3u8|Film Adı</em></p>
            <textarea name="playlist_icerik"><?php echo htmlspecialchars($mevcut_playlist); ?></textarea>
            <label>🖼️ Logo Konumu</label>
            <select name="logo_pozisyonu">
                <option value="sag_ust">Sağ Üst</option>
                <option value="sol_ust">Sol Üst</option>
                <option value="sag_alt">Sağ Alt</option>
                <option value="sol_alt">Sol Alt</option>
            </select>
            <button type="submit" name="ayarlari_kaydet" class="btn btn-kaydet">💾 Ayarları ve Listeyi Kaydet</button>
        </form>
    </div>

    <!-- SAĞ PANEL: KONTROL -->
    <div class="sag-panel">
        <h2>
            📺 Yayın Kontrolü
            <?php if($yayin_aktif): ?>
                <span class="durum aktif">🔴 YAYIN AKTİF</span>
            <?php else: ?>
                <span class="durum kapali">⚫ YAYIN KAPALI</span>
            <?php endif; ?>
        </h2>
        <form method="POST" class="baslat-durdur-grup">
            <button type="submit" name="yayin_baslat" class="btn btn-baslat">▶ YAYINI BAŞLAT</button>
            <button type="submit" name="yayin_durdur" class="btn btn-durdur">⏹ YAYINI DURDUR</button>
        </form>
        <label>👀 Canlı Önizleme (Web Player)</label>
        <div class="video-container">
            <?php if(!$yayin_aktif): ?>
                <div class="offline-text">YAYIN ŞU AN KAPALI</div>
            <?php endif; ?>
            <video id="zem_player" controls autoplay muted></video>
        </div>
        <p style="font-size: 12px; color:#aaa; text-align:center; margin-top:10px;">
            Not: Yayın başladıktan sonra web player'a görüntünün düşmesi <br>HLS segment boyutundan dolayı 10-15 saniye sürebilir.
        </p>
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
                hls.loadSource(hlsUrl); hls.attachMedia(video);
                hls.on(Hls.Events.MANIFEST_PARSED, function() { video.play(); });
                hls.on(Hls.Events.ERROR, function (event, data) {
                    if (data.fatal) {
                        if(data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                            setTimeout(() => { hls.startLoad(); }, 3000);
                        } else if(data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                            hls.recoverMediaError();
                        } else { hls.destroy(); }
                    }
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = hlsUrl; video.addEventListener('loadedmetadata', function() { video.play(); });
            }
        }
    });
</script>
</body>
</html>
