# -*- coding: utf-8 -*-

import os
import sys
import subprocess
import urllib.request
from pathlib import Path
from datetime import datetime
import time

# ============================================================
# YAYIN VE GITHUB AYARLARI
# ============================================================

# BURAYA TEKLİ M3U8 YAYIN LİNKİNİ GİRİN
SINGLE_M3U8_URL = "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/dizi-tv/playlist/0.m3u8"

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/fluxtv"

LOGO_URL = "https://i.hizliresim.com/2m4pmeki.png"

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"
FONT = r"C:\Windows\Fonts\arial.ttf"

BASE_DIR = Path(__file__).resolve().parent
LOGO_FILE = BASE_DIR / "zemtv_logo.png"

# ============================================================
# VIDEO AYARLARI
# ============================================================

WIDTH = 1280
HEIGHT = 720
FPS = 25

VIDEO_BITRATE = "3000k"
MAXRATE = "3500k"
BUFSIZE = "6000k"
AUDIO_BITRATE = "128k"

# ============================================================
# LOG & DOSYA İŞLEMLERİ
# ============================================================

def log(text):
    now = datetime.now().strftime("%d.%m.%Y %H:%M:%S")
    print(f"[{now}] {text}", flush=True)

# ============================================================
# LOGO İNDİR
# ============================================================

def download_logo():
    if LOGO_FILE.exists():
        try:
            if LOGO_FILE.stat().st_size > 1000:
                log("Logo mevcut.")
                return
        except:
            pass
    log("Logo indiriliyor...")
    try:
        request = urllib.request.Request(
            LOGO_URL, headers={"User-Agent": "Mozilla/5.0"}
        )
        with urllib.request.urlopen(request, timeout=30) as response:
            data = response.read()
        if len(data) < 1000:
            raise Exception("Logo dosyasi gecersiz.")
        with open(LOGO_FILE, "wb") as f:
            f.write(data)
        log("Logo indirildi.")
    except Exception as e:
        log("Logo indirilemedi: " + str(e))
        sys.exit(1)

def ff_path(path):
    value = str(path)
    return value.replace("\\", "/").replace(":", "\\:")


# ============================================================
# FFMPEG FİLTRE
# ============================================================

def create_filter():
    font = ff_path(FONT)
    logo = ff_path(LOGO_FILE)

    # Haber bandı ve kutular kaldırıldı, sol üste Telegram adresi, sol alta saat eklendi
    filter_text = (
        "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2[base];"
        "[1:v]scale=250:-1[logo];"
        "[base][logo]overlay=W-w-20:20[v1];"
        "[v1]drawtext="
        f"fontfile='{font}':"
        "text='t.me/zemtvapk':"
        "fontcolor=white:"
        "fontsize=24:"
        "x=20:"
        "y=20:"
        "borderw=2:"
        "bordercolor=black[v2];"
        "[v2]drawtext="
        f"fontfile='{font}':"
        "text='%{localtime\\:%H\\\\\\:%M}':"
        "fontcolor=white:"
        "fontsize=28:"
        "x=30:"
        "y=670:"
        "borderw=2:"
        "bordercolor=black[vout]"
    )
    return filter_text


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def build_command(video_url):
    filters = create_filter()
    
    command = [
        FFMPEG,
        "-hide_banner", "-loglevel", "info",
        "-analyzeduration", "100000000", 
        "-probesize", "100000000",
        "-fflags", "+genpts+discardcorrupt",
        "-reconnect", "1", "-reconnect_streamed", "1",
        "-reconnect_at_eof", "1", "-reconnect_delay_max", "10",
        "-rw_timeout", "20000000",
        "-user_agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36",
        "-headers", "Referer: https://vidrame.pro/\r\n",
        "-i", video_url,
        "-loop", "1", "-i", str(LOGO_FILE),
        "-filter_complex", filters,
        "-map", "[vout]", "-map", "0:a?",
        "-c:v", "libx264", "-preset", "veryfast", "-tune", "zerolatency",
        "-pix_fmt", "yuv420p", "-r", str(FPS), "-s", "1280x720",
        "-b:v", VIDEO_BITRATE, "-maxrate", MAXRATE, "-bufsize", BUFSIZE,
        "-g", "50", "-keyint_min", "50", "-sc_threshold", "0",
        "-c:a", "aac", "-b:a", AUDIO_BITRATE, "-ar", "48000", "-ac", "2",
        "-f", "flv", "-flvflags", "no_duration_filesize",
        RTMP_URL
    ]
    return command


def check_files():
    if not os.path.isfile(FFMPEG):
        print(f"\nFFmpeg bulunamadi:\n{FFMPEG}\n")
        sys.exit(1)
    if not os.path.isfile(FONT):
        print(f"\nArial bulunamadi:\n{FONT}\n")
        sys.exit(1)

def start_stream(video_url):
    command = build_command(video_url)
    log("FFmpeg baslatiliyor.")
    process = None
    try:
        process = subprocess.Popen(
            command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            stdin=subprocess.DEVNULL, text=True, encoding="utf-8",
            errors="replace", bufsize=1
        )
        while True:
            line = process.stdout.readline()
            if line:
                print(line.rstrip(), flush=True)
            if process.poll() is not None:
                break
        return process.returncode
    except KeyboardInterrupt:
        log("Yayin durduruluyor...")
        if process:
            try:
                process.terminate()
            except:
                pass
        return 0
    except Exception as e:
        log("FFmpeg calistirma hatasi: " + str(e))
        return -1


# ============================================================
# ANA DÖNGÜ (TEKLİ YAYIN)
# ============================================================

def main():
    print("\nZEM TV - Tekli M3U8 Kesintisiz Yayin Sistemi Baslatiliyor...\n")
    check_files()
    download_logo()

    time.sleep(2)

    # Yayın koparsa veya hata verirse otomatik olarak yeniden başlatır
    while True:
        log(f"Yayin Oynatiliyor: {SINGLE_M3U8_URL}")
        
        start_stream(SINGLE_M3U8_URL)
        
        log(f"Yayin sonlandi veya koptu. 5 saniye icinde yeniden baslatiliyor...")
        time.sleep(5)

if __name__ == "__main__":
    main()
