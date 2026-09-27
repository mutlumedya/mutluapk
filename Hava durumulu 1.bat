@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

title ZemTv_Streamer

:: Klasore giris
cd /d "C:\xampp\htdocs\ZemTv\live"

set "PLAYLIST=C:\xampp\htdocs\ZemTv\playlist.txt"
set "LOGO=C:\xampp\htdocs\ZemTv\logo\logo.png"
set "AD_URL=http://45.158.14.16/Reklam/zemtv1.mp4"

:: Eski dosyalari temizle
if exist index.m3u8 del /q index.m3u8
if exist seg_*.ts del /q seg_*.ts

:: Playlist'teki toplam satir sayisini bul
set /a totalLines=0
for /f "usebackq" %%A in ("%PLAYLIST%") do set /a totalLines+=1

:MAIN_LOOP
set /a currentLine=1

:READ_NEXT
if !currentLine! GTR !totalLines! goto MAIN_LOOP

:: İlgili satiri bul ve oku
set /a counter=1
for /f "usebackq tokens=1,2 delims=|" %%A in ("%PLAYLIST%") do (
    if !counter! equ !currentLine! (
        set "SRC=%%A"
        set "NAME=%%B"
    )
    set /a counter+=1
)

:: Yeni bir videoya gecerken süreyi (kaldigimiz yeri) sifirla
set /a OFFSET=0

:PLAY_STREAM
cls
echo ========================================
echo YAYINDA: !NAME!
if !OFFSET! GTR 0 echo DURUM: Reklam bitti, !OFFSET! saniyeden devam ediliyor...
echo KAYNAK: !SRC!
echo ========================================

:: Eger reklamdan donuyorsak kalinan yerden baslamak icin -ss parametresi ayarla
set "SEEK_CMD="
if !OFFSET! GTR 0 set "SEEK_CMD=-ss !OFFSET!"

:: Baslangic zamanini saniye cinsinden al
for /f %%i in ('powershell -command "[int][double]::Parse((Get-Date (Get-Date).ToUniversalTime() -UFormat '%%s'))"') do set T_START=%%i

:: Ana Yayin: -t 1800 ile tam 30 dakika yayin yapmasini soyluyoruz
ffmpeg -loglevel warning !SEEK_CMD! -t 1800 -re -reconnect 1 -reconnect_at_eof 1 -reconnect_streamed 1 -reconnect_delay_max 5 ^
-i "!SRC!" ^
-i "%LOGO%" ^
-filter_complex "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[main];[1:v]scale=180:-2,format=rgba[logo];[main][logo]overlay=20:20,drawtext=text='!NAME!':x=w-tw-20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf,drawtext=text='%%{localtime\:%%H.%%M.%%S}':x=20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf[v_final]" ^
-map "[v_final]" -map 0:a? ^
-c:v libx264 -preset ultrafast -tune zerolatency -crf 22 ^
-g 60 -keyint_min 60 -sc_threshold 0 ^
-threads 2 ^
-c:a aac -b:a 128k -ac 2 ^
-f hls ^
-hls_time 4 ^
-hls_list_size 6 ^
-hls_flags delete_segments+independent_segments+append_list ^
-hls_segment_filename "seg_%%03d.ts" ^
-hls_base_url "http://45.158.14.16/ZemTv/live/" ^
index.m3u8

:: Bitis zamanini al ve gecen sureyi hesapla
for /f %%i in ('powershell -command "[int][double]::Parse((Get-Date (Get-Date).ToUniversalTime() -UFormat '%%s'))"') do set T_END=%%i
set /a T_ELAPSED=T_END - T_START

:: Eger yayin 30 dakikadan (yaklasik 1790 saniye) kisa surduyse video bitmis veya baglanti kopmustur.
:: Bu durumda reklama girmeden bir sonraki filme/yayina gec.
if !T_ELAPSED! LSS 1790 (
    echo.
    echo Parca bitti veya koptu. 3 saniye sonra siradakine geciliyor...
    timeout /t 3 >nul
    set /a currentLine+=1
    goto READ_NEXT
)

:: Eger 30 dakikayi doldurduysak REKLAM girisiyapiyoruz.
cls
echo ========================================
echo 30 DAKIKA DOLDU - REKLAM ARASI GIRIYOR
echo KAYNAK: %AD_URL%
echo ========================================

ffmpeg -loglevel warning -re -i "%AD_URL%" -i "%LOGO%" ^
-filter_complex "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[main];[1:v]scale=180:-2,format=rgba[logo];[main][logo]overlay=20:20,drawtext=text='REKLAM':x=w-tw-20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf,drawtext=text='%%{localtime\:%%H.%%M.%%S}':x=20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf[v_final]" ^
-map "[v_final]" -map 0:a? ^
-c:v libx264 -preset ultrafast -tune zerolatency -crf 22 ^
-g 60 -keyint_min 60 -sc_threshold 0 ^
-threads 2 ^
-c:a aac -b:a 128k -ac 2 ^
-f hls ^
-hls_time 4 ^
-hls_list_size 6 ^
-hls_flags delete_segments+independent_segments+append_list ^
-hls_segment_filename "seg_%%03d.ts" ^
-hls_base_url "http://45.158.14.16/ZemTv/live/" ^
index.m3u8

:: Reklam bitti. Ana yayinin kaldigi yeri 1800 saniye (30 dk) ileri sar
set /a OFFSET+=1800

:: Ayni satiri oynatmaya devam et (Kaldigi yerden)
goto PLAY_STREAM
