<?php
session_start();

// GÜVENLİK ŞİFRENİZ
$panel_sifresi = "mutlu";

// DOSYA YOLLARI
$playlist_yolu = "C:\\xampp\\htdocs\\ZemTv\\playlist.txt";
$bat_yolu = "C:\\xampp\\htdocs\\ZemTv\\baslat.bat";

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
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>ZemTv Panel</title><style>body { font-family: Arial; text-align: center; margin-top: 100px; background:#f4f4f9; }</style></head><body><h2>ZemTv Yayın Paneli</h2>';
    if(isset($hata)) echo "<p style='color:red;'>$hata</p>";
    echo '<form method="POST"><input type="password" name="sifre" required placeholder="Panel Şifresi" style="padding:10px;"> <button type="submit" name="sifre_giris" style="padding:10px;">Giriş</button></form></body></html>';
    exit;
}

// --- KAYDET VE YENİDEN BAŞLAT İŞLEMİ ---
if (isset($_POST['ayarlari_kaydet'])) {
    
    // 1. Playlist'i Kaydet
    $yeni_playlist = $_POST['playlist_icerik'];
    file_put_contents($playlist_yolu, str_replace("\r\n", "\n", $yeni_playlist)); // Satır boşluklarını düzeltir

    // 2. Logo Pozisyonuna Göre Overlay Kodunu Belirle
    $secilen_pozisyon = $_POST['logo_pozisyonu'];
    
    if ($secilen_pozisyon == "sag_ust") {
        $overlay_kodu = "main_w-overlay_w-50:40";
    } elseif ($secilen_pozisyon == "sol_ust") {
        $overlay_kodu = "50:40";
    } elseif ($secilen_pozisyon == "sag_alt") {
        $overlay_kodu = "main_w-overlay_w-50:main_h-overlay_h-50";
    } elseif ($secilen_pozisyon == "sol_alt") {
        $overlay_kodu = "50:main_h-overlay_h-50";
    } else {
        $overlay_kodu = "main_w-overlay_w-50:40"; // Varsayılan Sağ Üst
    }

    // 3. Bat Dosyasını Yeniden Oluştur (PHP Değişkeni Sadece Overlay'e Eklenir)
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

    // Bat dosyasını kaydet
    file_put_contents($bat_yolu, $bat_icerik);

    // 4. Eski Yayınları Kapat ve Yenisini Başlat
    // Önceki bat penceresini ve ffmpeg/streamlink'i zorla kapatıyoruz.
    exec("taskkill /FI \"WINDOWTITLE eq ZemTv_Streamer*\" /T /F 2>&1");
    exec("taskkill /IM ffmpeg.exe /F 2>&1");
    exec("taskkill /IM streamlink.exe /F 2>&1");
    
    // 2 saniye bekle (portların/dosyaların serbest kalması için)
    sleep(2);

    // Yeni bat dosyasını arka planda yeni bir pencerede çalıştır
    pclose(popen("start \"ZemTv_Streamer\" cmd.exe /c \"$bat_yolu\"", "r"));

    $mesaj = "Ayarlar kaydedildi ve yayın yeni ayarlarla yeniden başlatıldı!";
}

// Dosyadan mevcut playlisti çek
$mevcut_playlist = file_exists($playlist_yolu) ? file_get_contents($playlist_yolu) : "";
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>ZemTv Yayın Paneli</title>
    <style>
        body { font-family: Arial; background: #2c3e50; color: #ecf0f1; margin: 0; padding: 20px; }
        .kutu { max-width: 800px; margin: auto; background: #34495e; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        h2 { margin-top: 0; border-bottom: 2px solid #7f8c8d; padding-bottom: 10px; }
        label { font-weight: bold; margin-top: 15px; display: block; color: #f1c40f; }
        textarea { width: 100%; height: 200px; background: #1a252f; color: #ecf0f1; border: 1px solid #7f8c8d; padding: 10px; margin-top: 5px; font-family: monospace; resize: vertical; box-sizing: border-box;}
        select { width: 100%; padding: 10px; margin-top: 5px; background: #1a252f; color: #ecf0f1; border: 1px solid #7f8c8d; box-sizing: border-box;}
        button { background: #e74c3c; color: white; border: none; padding: 15px; width: 100%; font-size: 18px; margin-top: 20px; cursor: pointer; font-weight: bold; border-radius: 5px; transition: 0.3s; }
        button:hover { background: #c0392b; }
        .cikis { float: right; background: #95a5a6; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-size: 14px; }
        .basari { background: #27ae60; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
    </style>
</head>
<body>

    <div class="kutu">
        <a href="?cikis=1" class="cikis">Çıkış Yap</a>
        <h2>ZemTv Yönetim Paneli</h2>

        <?php if(isset($mesaj)) echo "<div class='basari'>$mesaj</div>"; ?>

        <form method="POST">
            <label>🎬 Playlist (Oynatılacak Listeler)</label>
            <p style="font-size: 12px; color:#bdc3c7; margin-top:2px;">Format: <em>http://yayinlinki.m3u8|Film Adı</em> (Her satıra bir tane)</p>
            <textarea name="playlist_icerik"><?php echo htmlspecialchars($mevcut_playlist); ?></textarea>

            <label>🖼️ Logo Konumu (logo.png)</label>
            <select name="logo_pozisyonu">
                <option value="sag_ust">Sağ Üst (Varsayılan)</option>
                <option value="sol_ust">Sol Üst</option>
                <option value="sag_alt">Sağ Alt</option>
                <option value="sol_alt">Sol Alt</option>
            </select>

            <button type="submit" name="ayarlari_kaydet">Ayarları Kaydet ve Yayını Yenile</button>
            <p style="font-size: 12px; color:#bdc3c7; text-align:center; margin-top:10px;">(Bu butona bastığınızda yayın anlık olarak kesilir ve yeni ayarlarla 3 saniye içinde tekrar başlar.)</p>
        </form>
    </div>

</body>
</html>
