@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

:: Eger script kendi icinden hava durumu icin cagirildiysa o bolume atla
if "%~1"=="weather" goto WEATHER_LOOP

title ZemTv_Streamer

:: Klasore giris
cd /d "C:\xampp\htdocs\ZemTv\live"

set "PLAYLIST=C:\xampp\htdocs\ZemTv\playlist.txt"
set "LOGO=C:\xampp\htdocs\ZemTv\logo\logo.png"

:: Arka planda hava durumunu guncelleyen donguyu baslatir
start /b cmd /c "%~f0" weather

:: Eski dosyalari temizle
if exist index.m3u8 del /q index.m3u8

:MAIN
for /f "usebackq tokens=1,2 delims=|" %%A in ("%PLAYLIST%") do (
    
    set "SRC=%%A"
    set "NAME=%%B"

    cls
    echo ========================================
    echo YAYINDA: !NAME!
    echo KAYNAK: !SRC!
    echo ========================================

    ffmpeg -loglevel warning -re -reconnect 1 -reconnect_at_eof 1 -reconnect_streamed 1 -reconnect_delay_max 5 ^
    -i "!SRC!" ^
    -i "%LOGO%" ^
    -filter_complex "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[main];[1:v]scale=180:-2,format=rgba[logo];[main][logo]overlay=20:20,drawtext=text='!NAME!':x=w-tw-20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf,drawtext=text='%%{localtime\:%%H.%%M.%%S}':x=20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf,drawtext=textfile='havadurumu.txt':reload=1:x=w-tw-20:y=20:fontsize=22:fontcolor=white:box=1:boxcolor=black@0.6:boxborderw=8:fontfile=emoji.ttf[v_final]" ^
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

    echo.
    echo Parca bitti. 3 saniye sonra siradakine geciliyor...
    timeout /t 3 >nul
)
goto MAIN


:: =========================================================================
:: ARKA PLAN OTOMATIK HAVA DURUMU DONGUSU (6 Saatte Bir Gercek Veri Ceker)
:: =========================================================================
:WEATHER_LOOP
:: Istedigin sehirleri aralarinda SADECE BOSLUK birakarak asagiya yaz (Turkce karakter kullanmadan):
set "CITIES=Istanbul Ankara Izmir Bursa Antalya Adana Diyarbakir Trabzon Erzurum Konya"

:FETCH_WEATHER
:: 1. ADIM: Internete baglanip guncel sicaklik ve emojileri cek, gecici dosyalara kaydet (Gunde sadece 4 kez calisir)
for %%C in (%CITIES%) do (
    curl -sL "wttr.in/%%C?format=%%t+%%c" > "C:\xampp\htdocs\ZemTv\live\w_%%C.tmp"
)

:: 2. ADIM: Indirilen bu verileri ekranda sirayla 5 saniyede bir dondur
:: Dongunun 6 saat (21600 saniye) surmesi gerektiginden 4320 kere donmesi yeterli. 
:: (5 saniye gosterim * ornegin 10 sehir = 50 saniyelik 1 tur. 6 saat / 50 saniye = yaklasik 432 tur. Biz 432 yazalim.)
for /L %%I in (1,1,432) do (
    for %%C in (%CITIES%) do (
        if exist "C:\xampp\htdocs\ZemTv\live\w_%%C.tmp" (
            <nul set /p="%%C: " > "C:\xampp\htdocs\ZemTv\live\havadurumu.txt"
            type "C:\xampp\htdocs\ZemTv\live\w_%%C.tmp" >> "C:\xampp\htdocs\ZemTv\live\havadurumu.txt"
        )
        timeout /t 5 >nul
    )
)

:: 6 saat bitti, en guncel havalari tekrar cekmek icin donguyu basa sar
goto FETCH_WEATHER
