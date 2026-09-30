<?php
session_start();

// GÜVENLİK ŞİFRENİZ
$panel_sifresi = "ZemTv2024!";

// DOSYA YOLLARI (Kendi sisteminize göre kontrol edin)
$ana_dizin = "C:\\xampp\\htdocs\\ZemTv";
$playlist_yolu = $ana_dizin . "\\playlist.txt";
$bat_yolu = $ana_dizin . "\\baslat.bat";

// --- OTURUM KONTROLÜ ---
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
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>ZemTv Panel Giriş</title><style>body { font-family: Arial; text-align: center; margin-top: 100px; background:#1e1e1e; color: #fff; }</style></head><body><h2>ZemTv Yayın Paneli</h2>';
    if(isset($hata)) echo "<p style='color:#e74c3c;'>$hata</p>";
    echo '<form method="POST"><input type="password" name="sifre" required placeholder="Panel Şifresi" style="padding:10px; border-radius:5px; border:none;"> <button type="submit" name="sifre_giris" style="padding:10px; border-radius:5px; background:#3498db; color:#fff; border:none; cursor:pointer;">Giriş</button></form></body></html>';
    exit;
}

// --- YAYIN KONTROL İŞLEMLERİ ---

// 1. Ayarları Kaydet
if (isset($_POST['ayarlari_kaydet'])) {
    $yeni_playlist = $_POST['playlist_icerik'];
    file_put_contents($playlist_yolu, str_replace("\r\n", "\n", $yeni_playlist));

    $secilen_pozisyon = $_POST['logo_pozisyonu'];
    if ($secilen_pozisyon == "sag_ust") { $overlay_kodu = "main_w-overlay_w-50:40"; } 
    elseif ($secilen_pozisyon == "sol_ust") { $overlay_kodu = "50:40"; } 
    elseif ($secilen_pozisyon == "sag_alt") { $overlay_kodu = "main_w-overlay_w-50:main_h-overlay_h-50"; } 
    elseif ($secilen_pozisyon == "sol_alt") { $overlay_kodu = "50:main_h-overlay_h-50"; } 
    else { $overlay_kodu = "main_w-overlay_w-50:40"; }

    // Bat dosyasını oluştur (Sizin verdiğiniz kod)
    $bat_icerik = <<<EOT
@echo off
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
    -filter_complex "[0:v]scale=1280:720,setsar=1[main];[1:v]scale=240:-2,format=rgba[logo];[main][logo]overlay={$overlay_kodu}[v_final]" ^
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

goto MAIN
EOT;

    file_put_contents($bat_yolu, $bat_icerik);
    $mesaj = "Ayarlar kaydedildi! Değişikliklerin yansıması için yayını durdurup yeniden başlatın.";
}

// 2. Yayını Başlat
if (isset($_POST['yayin_baslat'])) {
    // "The system cannot find the file C:" hatasını önleyen güvenli başlatma kodu
    $komut = 'start "ZemTv_Streamer" /D "' . $ana_dizin . '" "baslat.bat"';
    pclose(popen($komut, "r"));
    sleep(2); // Başlaması için 2 saniye bekle
    header("Location: panel.php");
    exit;
}

// 3. Yayını Durdur
if (isset($_POST['yayin_durdur'])) {
    exec("taskkill /FI \"WINDOWTITLE eq ZemTv_Streamer*\" /T /F 2>nul");
    exec("taskkill /IM ffmpeg.exe /F 2>nul");
    exec("taskkill /IM streamlink.exe /F 2>nul");
    sleep(1);
    header("Location: panel.php");
    exit;
}

// --- YAYIN DURUMUNU KONTROL ET ---
exec("tasklist /FI \"IMAGENAME eq ffmpeg.exe\" 2>nul", $task_ciktisi);
$yayin_aktif = false;
foreach ($task_ciktisi as $satir) {
    if (stripos($satir, 'ffmpeg.exe') !== false) {
        $yayin_aktif = true;
        break;
    }
}

// Dosyadan mevcut playlisti çek
$mevcut_playlist = file_exists($playlist_yolu) ? file_get_contents($playlist_yolu) : "";
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>ZemTv Kontrol Merkezi</title>
    <!-- Video Oynatıcı için HLS.js kütüphanesi -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #121212; color: #ecf0f1; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: auto; display: flex; flex-wrap: wrap; gap: 20px; }
        .sol-panel { flex: 1; min-width: 300px; background: #1e1e1e; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        .sag-panel { flex: 1; min-width: 400px; background: #1e1e1e; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        h2 { margin-top: 0; border-bottom: 2px solid #333; padding-bottom: 10px; color: #fff; }
        
        /* Durum Badge'leri */
        .durum { display: inline-block; padding: 8px 15px; border-radius: 20px; font-weight: bold; font-size: 14px; float: right; }
        .aktif { background: #27ae60; color: #fff; box-shadow: 0 0 10px #27ae60; }
        .kapali { background: #e74c3c; color: #fff; box-shadow: 0 0 10px #e74c3c; }

        /* Form Elemanları */
        label { font-weight: bold; margin-top: 15px; display: block; color: #3498db; }
        textarea { width: 100%; height: 200px; background: #2c2c2c; color: #ecf0f1; border: 1px solid #444; padding: 10px; margin-top: 5px; font-family: monospace; resize: vertical; box-sizing: border-box; border-radius:5px;}
        select { width: 100%; padding: 10px; margin-top: 5px; background: #2c2c2c; color: #ecf0f1; border: 1px solid #444; box-sizing: border-box; border-radius:5px;}
        
        /* Butonlar */
        .btn { border: none; padding: 12px; font-size: 16px; cursor: pointer; font-weight: bold; border-radius: 5px; transition: 0.3s; width: 100%; margin-top: 10px;}
        .btn-kaydet { background: #f39c12; color: #fff; margin-top: 20px; }
        .btn-kaydet:hover { background: #d68910; }
        .btn-baslat { background: #27ae60; color: #fff; }
        .btn-baslat:hover { background: #219150; }
        .btn-durdur { background: #e74c3c; color: #fff; }
        .btn-durdur:hover { background: #c0392b; }
        .cikis { text-decoration: none; color: #aaa; font-size: 14px; float:right; margin-top:-30px;}
        .basari { background: #2980b9; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }

        /* Video Oynatıcı */
        .video-container { width: 100%; aspect-ratio: 16/9; background: #000; border: 2px solid #333; border-radius: 8px; overflow: hidden; margin-top: 15px; }
        video { width: 100%; height: 100%; }
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
            <label>🎬 Playlist (Oynatılacak Listeler)</label>
            <p style="font-size: 12px; color:#aaa; margin-top:2px;">Format: <em>http://link.m3u8|Film Adı</em></p>
            <textarea name="playlist_icerik"><?php echo htmlspecialchars($mevcut_playlist); ?></textarea>

            <label>🖼️ Logo Konumu (logo.png)</label>
            <select name="logo_pozisyonu">
                <option value="sag_ust">Sağ Üst (Varsayılan)</option>
                <option value="sol_ust">Sol Üst</option>
                <option value="sag_alt">Sağ Alt</option>
                <option value="sol_alt">Sol Alt</option>
            </select>

            <button type="submit" name="ayarlari_kaydet" class="btn btn-kaydet">💾 Sadece Ayarları Kaydet</button>
        </form>
    </div>

    <!-- SAĞ PANEL: KONTROL VE OYNATICI -->
    <div class="sag-panel">
        <h2>
            📺 Canlı Yayın Kontrolü
            <?php if($yayin_aktif): ?>
                <span class="durum aktif">🔴 YAYIN AKTİF</span>
            <?php else: ?>
                <span class="durum kapali">⚫ YAYIN KAPALI</span>
            <?php endif; ?>
        </h2>

        <form method="POST" style="display: flex; gap: 10px; margin-bottom: 20px;">
            <button type="submit" name="yayin_baslat" class="btn btn-baslat">▶ YAYINI BAŞLAT</button>
            <button type="submit" name="yayin_durdur" class="btn btn-durdur">⏹ YAYINI DURDUR</button>
        </form>

        <label>👀 Canlı Önizleme</label>
        <div class="video-container">
            <video id="zem_player" controls autoplay muted></video>
        </div>
        <p style="font-size: 12px; color:#aaa; text-align:center;">
            (Yayın başladığında görüntü buraya 10-15 saniye gecikmeli düşebilir.)
        </p>

    </div>
</div>

<script>
    // Canlı Yayın Oynatıcı Kurulumu (HLS.js)
    document.addEventListener("DOMContentLoaded", () => {
        const video = document.getElementById('zem_player');
        
        // Önbelleği önlemek için sonuna rastgele sayı ekliyoruz
        const hlsUrl = 'live/index.m3u8?t=' + new Date().getTime(); 

        // Eğer yayın aktifse (PHP'den gelen bilgi) Player'ı çalıştır
        const yayinAktifMi = <?php echo $yayin_aktif ? 'true' : 'false'; ?>;

        if (yayinAktifMi) {
            if (Hls.isSupported()) {
                const hls = new Hls({
                    debug: false,
                    manifestLoadingTimeOut: 20000,
                });
                hls.loadSource(hlsUrl);
                hls.attachMedia(video);
                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    video.play();
                });
                
                // Yayın koparsa kendini yenilemeye çalışması için
                hls.on(Hls.Events.ERROR, function (event, data) {
                    if (data.fatal) {
                        switch (data.type) {
                            case Hls.ErrorTypes.NETWORK_ERROR:
                                console.log("Yayın koptu, yeniden bağlanılıyor...");
                                hls.startLoad();
                                break;
                            case Hls.ErrorTypes.MEDIA_ERROR:
                                hls.recoverMediaError();
                                break;
                            default:
                                hls.destroy();
                                break;
                        }
                    }
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = hlsUrl;
                video.addEventListener('loadedmetadata', function() {
                    video.play();
                });
            }
        }
    });
</script>

</body>
</html>
