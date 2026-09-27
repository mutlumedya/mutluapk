@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

title ZemTv_Streamer

:: ==========================================
:: STREAMLINK OTOMATIK KONTROL VE KURULUM
:: ==========================================
streamlink --version >nul 2>&1
if %errorlevel% neq 0 (
    cls
    echo ========================================================
    echo SİSTEMDE STREAMLINK BULUNAMADI!
    echo OTOMATİK OLARAK İNDİRİLİP KURULUYOR, LÜTFEN BEKLEYİN...
    echo ========================================================
    
    :: GitHub uzerinden en son surumu bulup indirir
    powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; $release = Invoke-RestMethod -Uri 'https://api.github.com/repos/streamlink/windows-builds/releases/latest'; $asset = $release.assets | Where-Object { $_.name -match '\.exe$' } | Select-Object -First 1; Write-Host 'En guncel surum indiriliyor...'; Invoke-WebRequest -Uri $asset.browser_download_url -OutFile 'streamlink_setup.exe'"
    
    echo.
    echo Dosya indirildi. Kurulum yapiliyor, bu islem 1-2 dakika surebilir...
    start /wait streamlink_setup.exe /S
    
    :: Kurulum bitince setup dosyasini temizle
    del streamlink_setup.exe
    
    cls
    echo ========================================================
    echo STREAMLINK KURULUMU BASARIYLA TAMAMLANDI!
    echo Sistemin yeni komutu algilayabilmesi icin bu pencereyi kapatin.
    echo Ardindan bu BAT dosyasina CIFT TIKLAYARAK YENIDEN BASLATIN.
    echo ========================================================
    pause
    exit
)
:: ==========================================

cd /d "C:\xampp\htdocs\ZemTv\live"

set "PLAYLIST=C:\xampp\htdocs\ZemTv\playlist.txt"
set "LOGO=C:\xampp\htdocs\ZemTv\logo\logo.png"

:: FFmpeg filtreleri icin yollar ozel formatta
set "FONT_PATH=C\:/Windows/Fonts/arial.ttf"
set "TICKER_FILE=C\:/xampp/htdocs/ZemTv/haber.txt"
set "HABER_DOSYASI=C:\xampp\htdocs\ZemTv\haber.txt"

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

    :: Guncel haberleri PowerShell ile cek ve haber.txt'ye yaz
    echo Guncel haberler cekiliyor...
    powershell -NoProfile -Command "$rss = Invoke-RestMethod -Uri 'https://www.trthaber.com/sondakika.rss' -ErrorAction SilentlyContinue; if ($rss) { $headlines = ($rss | Select-Object -ExpandProperty title | Select-Object -First 5) -join '   ***   '; $output = '   ***   ' + $headlines + '   ***   '; Out-File -FilePath '%HABER_DOSYASI%' -InputObject $output -Encoding UTF8 } else { Out-File -FilePath '%HABER_DOSYASI%' -InputObject '   ***   Haberler alinamadi, yayin devam ediyor...   ***   ' -Encoding UTF8 }"

    echo Haberler guncellendi. FFmpeg baslatiliyor...

    streamlink "!SRC!" best --stdout ^
    | ffmpeg -i pipe:0 ^
    -i "%LOGO%" ^
    -filter_complex "[0:v]scale=1280:720,setsar=1[main];[1:v]scale=240:-2,format=rgba[logo];[main][logo]overlay=40:40[v1];[v1]drawbox=x=0:y=670:w=1280:h=50:color=0x111827@0.90:t=fill[v2];[v2]drawtext=fontfile='%FONT_PATH%':textfile='%TICKER_FILE%':reload=1:fontcolor=white:fontsize=24:x='1280-mod(t*85\,20000)':y=680:borderw=1:bordercolor=black[v_final]" ^
    -map "[v_final]" -map 0:a? ^
    -c:v libx264 -preset ultrafast -tune zerolatency -crf 23 ^
    -c:a aac -b:a 128k -ac 2 -ar 44100 ^
    -f hls ^
    -hls_time 4 ^
    -hls_list_size 10 ^
    -hls_flags delete_segments+independent_segments ^
    -hls_segment_filename "seg_%%03d.ts" ^
    -hls_base_url "http://104.238.23.196/ZemTv/live/" ^
    index.m3u8

    echo.
    echo Yayin bitti veya hata alindi. 3 saniye sonra siradaki isleme geciliyor...
    timeout /t 3 >nul
)

goto MAIN
