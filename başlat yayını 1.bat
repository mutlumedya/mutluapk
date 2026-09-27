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

    echo Guncel haberler cekiliyor...
    powershell -NoProfile -Command "$rss = Invoke-RestMethod -Uri 'https://www.trthaber.com/sondakika.rss' -ErrorAction SilentlyContinue; if ($rss) { $headlines = ($rss | Select-Object -ExpandProperty title | Select-Object -First 5) -join '   ***   '; $output = '   ***   ' + $headlines + '   ***   '; [System.IO.File]::WriteAllText('C:\xampp\htdocs\ZemTv\haber.txt', $output) } else { [System.IO.File]::WriteAllText('C:\xampp\htdocs\ZemTv\haber.txt', '   ***   Haberler alinamadi...   ***   ') }"

    echo Haberler guncellendi. FFmpeg baslatiliyor...

    :: Yollar yutulmasin diye C\\:/ seklinde korumaya alindi ve siralama ZEM HABER altindan kayacak sekilde yapildi
    ffmpeg -re -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 10 -i "!SRC!" ^
    -i "%LOGO%" ^
    -filter_complex "[0:v]scale=1280:720,setsar=1[main];[1:v]scale=240:-2,format=rgba[logo];[main][logo]overlay=40:40[v1];[v1]drawbox=x=0:y=670:w=1280:h=50:color=0x111827@0.94:t=fill[v2];[v2]drawtext=fontfile='C\\:/Windows/Fonts/arial.ttf':textfile='C\\:/xampp/htdocs/ZemTv/haber.txt':reload=1:fontcolor=white:fontsize=24:x='1280-mod(t*85\,20000)':y=680:borderw=1:bordercolor=black[v3];[v3]drawbox=x=0:y=670:w=130:h=50:color=0x003366@1.0:t=fill[v4];[v4]drawtext=fontfile='C\\:/Windows/Fonts/arial.ttf':text='ZEM':fontcolor=yellow:fontsize=18:x=45:y=673:borderw=1:bordercolor=black[v5];[v5]drawtext=fontfile='C\\:/Windows/Fonts/arial.ttf':text='HABER':fontcolor=yellow:fontsize=18:x=32:y=693:borderw=1:bordercolor=black[v_final]" ^
    -map "[v_final]" -map 0:a:0 ^
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
    echo Yayin bitti veya hata alindi. 3 saniye sonra siradaki isleme geciliyor...
    timeout /t 3 >nul
)

goto MAIN
