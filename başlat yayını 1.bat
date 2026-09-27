@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

title ZemTv_Streamer

:: Klasore giris
cd /d "C:\xampp\htdocs\ZemTv\live"

set "PLAYLIST=C:\xampp\htdocs\ZemTv\playlist.txt"
set "LOGO=C:\xampp\htdocs\ZemTv\logo\logo.png"

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
    -filter_complex "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[main];[1:v]scale=180:-2,format=rgba[logo];[main][logo]overlay=20:20,drawtext=text='!NAME!':x=w-tw-20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf,drawtext=text='%%{localtime\:%%H\:%%M\:%%S}':x=20:y=h-th-20:fontsize=17:fontcolor=white@0.85:box=1:boxcolor=black@0.30:boxborderw=5:fontfile=arial.ttf[v_final]" ^
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

:: Liste bittiginde basa don
goto MAIN
