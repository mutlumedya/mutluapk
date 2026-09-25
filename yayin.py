# -*- coding: utf-8 -*-

import os
import sys
import subprocess
import urllib.request
from pathlib import Path
from datetime import datetime
import threading
import time
import re

# ============================================================
# GEREKLİ KÜTÜPHANELERİ OTOMATİK KONTROL ET VE İNDİR
# ============================================================
def install_and_import(package):
    try:
        __import__(package)
    except ImportError:
        print(f"Eksik kutuphane tespit edildi: {package}. Otomatik olarak indiriliyor...")
        subprocess.check_call([sys.executable, "-m", "pip", "install", package])

install_and_import("requests")
import requests

# ============================================================
# YAYIN AYARLARI
# ============================================================

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/fluxakasya"
LOGO_URL = "https://i.hizliresim.com/2m4pmeki.png"

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"
FONT = r"C:\Windows\Fonts\arial.ttf"

BASE_DIR = Path(__file__).resolve().parent

LOGO_FILE = BASE_DIR / "zemtv_logo.png"
TITLE_FILE = BASE_DIR / "current_title.txt"

# FİLMLERİN OKUNACAĞI TXT DOSYASI
PLAYLIST_FILE = BASE_DIR / "zemtv_playlist.txt"
file_lock = threading.Lock()

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

def write_file(path, text):
    temp = Path(str(path) + ".tmp")
    try:
        with open(temp, "w", encoding="utf-8", newline="\n") as f:
            f.write(text)
        os.replace(temp, path)
    except Exception as e:
        try:
            if temp.exists():
                temp.unlink()
        except:
            pass
        log("Dosya yazma hatasi: " + str(e))

# ============================================================
# SATIR AYIKLAMA
# Format: URL|İsim
# Örnek: https://x.com/a.m3u8?ID=1|Akasya Durağı 1. Bölüm
# ============================================================

def parse_line(raw_line):
    """
    Satırı (url, title) olarak döndürür.
    Format: URL|İsim
    '|' yoksa title None olur.
    """
    if not raw_line:
        return None, None
    line = raw_line.strip()
    if not line:
        return None, None
    
    if "|" in line:
        parts = line.split("|", 1)
        url = parts[0].strip()
        title = parts[1].strip() if len(parts) > 1 else None
    else:
        url = line
        title = None
    
    # URL'nin geçerli olduğundan emin ol
    if not url.lower().startswith("http"):
        return None, None
    
    return url, title

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
        request = urllib.request.Request(LOGO_URL, headers={"User-Agent": "Mozilla/5.0"})
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
    title_f = ff_path(TITLE_FILE)

    filter_text = (
        "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2[base];"
        "[1:v]scale=250:-1[logo];"
        "[base][logo]overlay=W-w-20:20[v1];"
        
        # Akasya Duragi Kutulari
        "[v1]drawbox=x=0:y=670:w=150:h=50:color=0x003366@1.0:t=fill[v_box1];"
        "[v_box1]drawbox=x=150:y=670:w=100:h=50:color=0x374151@1.0:t=fill[v_box2];"
        
        # Akasya Yazisi
        "[v_box2]drawtext="
        f"fontfile='{font}':text='Akasya':fontcolor=yellow:fontsize=18:x=40:y=673:borderw=1:bordercolor=black[v_t1];"
        
        # Durağı Yazisi
        "[v_t1]drawtext="
        f"fontfile='{font}':text='Durağı':fontcolor=yellow:fontsize=18:x=43:y=693:borderw=1:bordercolor=black[v_t2];"
        
        # Saat Yazisi
        "[v_t2]drawtext="
        f"fontfile='{font}':text='%{{localtime\\:%H\\\\\\:%M}}':fontcolor=white:fontsize=22:x=165:y=683:borderw=2:bordercolor=black[v_t3];"
        
        # Sag Alt Köşede Video İsmi
        "[v_t3]drawtext="
        f"fontfile='{font}':textfile='{title_f}':reload=1:fontcolor=white:fontsize=24:x=W-tw-20:y=H-th-20:box=1:boxcolor=0x111827@0.8:boxborderw=8[vout]"
    )
    return filter_text

# ============================================================
# FFMPEG KOMUTU
# ============================================================

def build_command(video_url):
    filters = create_filter()
    
    headers = []
    if "vidrame" in video_url.lower():
        headers = ["-headers", "Referer: https://vidrame.pro/\r\n"]
    
    command = [
        FFMPEG,
        "-hide_banner", "-loglevel", "info",
        "-analyzeduration", "100000000", 
        "-probesize", "100000000",
        "-fflags", "+genpts+discardcorrupt",
        "-reconnect", "1", "-reconnect_streamed", "1",
        "-reconnect_at_eof", "1", "-reconnect_delay_max", "10",
        "-rw_timeout", "20000000",
        "-user_agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36"
    ] + headers + [
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

def start_stream(video_url, video_title):
    write_file(TITLE_FILE, video_title)
    
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
# ANA DÖNGÜ
# ============================================================

def main():
    print("\nAkasya TV - TXT Tabanli Kesintisiz Yayin Sistemi Baslatiliyor...\n")
    check_files()
    download_logo()

    # Playlist dosyası yoksa boş oluştur
    if not PLAYLIST_FILE.exists():
        try:
            with open(PLAYLIST_FILE, "w", encoding="utf-8", newline="\n") as f:
                f.write("")
            log(f"'{PLAYLIST_FILE.name}' dosyasi bulunamadi, bos olarak olusturuldu.")
            log(f"Yol: {PLAYLIST_FILE}")
        except Exception as e:
            log(f"'{PLAYLIST_FILE.name}' olusturulamadi: {e}")
            sys.exit(1)
    else:
        log(f"'{PLAYLIST_FILE.name}' bulundu: {PLAYLIST_FILE}")

    log("Format: URL|İsim  (ornek: https://x.com/a.m3u8?ID=1|Akasya Durağı 1. Bölüm)")

    time.sleep(2)

    current_index = 0

    while True:
        current_video_url = None
        current_video_title = "Akasya Durağı"
        total_videos = 0
        
        with file_lock:
            if PLAYLIST_FILE.exists():
                with open(PLAYLIST_FILE, "r", encoding="utf-8") as f:
                    lines = [line.strip() for line in f if line.strip()]
                    total_videos = len(lines)
                    
                    if total_videos > 0:
                        if current_index >= total_videos:
                            log("Listenin sonuna gelindi. Yayin kesilmesin diye basa donuluyor...")
                            current_index = 0
                            
                        line_data = lines[current_index]
                        url, title = parse_line(line_data)
                        current_video_url = url
                        if title:
                            current_video_title = title
                        else:
                            current_video_title = "Akasya Durağı"

        if current_video_url:
            if "vidrame.pro" in current_video_url and "master.m3u8" in current_video_url:
                current_video_url = current_video_url.replace("master.m3u8", "1080.txt")
                
            log(f"Oynatiliyor (Sira {current_index + 1}/{total_videos}): [{current_video_title}] -> {current_video_url}")
            
            code = start_stream(current_video_url, current_video_title)
            
            log(f"Film Bitti. Siradaki icerige geciliyor...")
            
            current_index += 1
            time.sleep(2) 
        else:
            log(f"Liste bos. '{PLAYLIST_FILE}' dosyasina video linki ekleyin. Bekleniyor...")
            time.sleep(10)

if __name__ == "__main__":
    main()
