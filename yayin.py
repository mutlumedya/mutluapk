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
# YAYIN VE GITHUB AYARLARI
# ============================================================

GITHUB_M3U_URL = "https://raw.githubusercontent.com/mutlumedya/mutluapk/refs/heads/main/Akasya.m3u"
RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/fluxakasya"
LOGO_URL = "https://i.hizliresim.com/2m4pmeki.png"

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"
FONT = r"C:\Windows\Fonts\arial.ttf"

BASE_DIR = Path(__file__).resolve().parent

LOGO_FILE = BASE_DIR / "zemtv_logo.png"
TITLE_FILE = BASE_DIR / "current_title.txt"

# FİLMLERİN KAYDEDİLECEĞİ VE OKUNACAĞI TXT DOSYASI
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
# URL AYIKLAMA
# Sadece gerçek URL kısmını alır, başlık vs. varsa atar.
# Örnek: "http://x.com/a.m3u8?ID=1 | Akasya 1. Bölüm"
#     -> "http://x.com/a.m3u8?ID=1"
# ============================================================

def extract_url(raw_line):
    if not raw_line:
        return None
    line = raw_line.strip()
    if not line:
        return None
    
    # Önce '|' varsa ilk parçayı al
    if "|" in line:
        line = line.split("|")[0].strip()
    
    # Boşluk varsa ilk parçayı al (URL'de boşluk olmaz)
    if " " in line:
        line = line.split(" ")[0].strip()
    
    # http ile başlamıyorsa geçersiz
    if not line.lower().startswith("http"):
        return None
    
    return line

def extract_title(raw_line):
    """M3U satırındaki başlık kısmını (varsa) alır, yoksa None döner."""
    if not raw_line:
        return None
    line = raw_line.strip()
    if "|" in line:
        parts = line.split("|", 1)
        if len(parts) > 1:
            title = parts[1].strip()
            if title:
                return title
    return None

# ============================================================
# M3U ÇEKME VE GÜNCELLEME DÖNGÜSÜ
# M3U'da ne varsa AYNEN alır, kafasına göre isim uydurmaz.
# ============================================================

def fetch_m3u_and_update_playlist_file():
    valid_extensions = ['.m3u8', '.mp4', '.mkv', '.ts', '.avi', '.txt']
    
    while True:
        try:
            log("GitHub M3U listesi kontrol ediliyor...")
            res = requests.get(GITHUB_M3U_URL, timeout=10)
            if res.status_code == 200:
                raw_lines = res.text.splitlines()
                
                with file_lock:
                    # Mevcut URL'leri topla (tekrar eklememek için)
                    existing_urls = set()
                    if PLAYLIST_FILE.exists():
                        with open(PLAYLIST_FILE, "r", encoding="utf-8") as f:
                            for line in f:
                                line = line.strip()
                                if not line:
                                    continue
                                # Satırdaki URL kısmını çıkar
                                u = extract_url(line)
                                if u:
                                    existing_urls.add(u)
                                else:
                                    # 'isim|url' formatında ise url kısmını al
                                    if "|" in line:
                                        existing_urls.add(line.split("|", 1)[1].strip())

                    new_items = 0
                    
                    with open(PLAYLIST_FILE, "a", encoding="utf-8", newline="\n") as f:
                        for raw_line in raw_lines:
                            line = raw_line.strip()
                            if not line:
                                continue
                            
                            # Yorum/EXTINF satırlarını atla
                            if line.startswith("#"):
                                continue
                            
                            # Satırda http geçmiyorsa atla
                            if "http" not in line.lower():
                                continue
                            
                            # URL'yi ayıkla
                            url = extract_url(line)
                            if not url:
                                continue
                            
                            # Uzantı/workers.dev kontrolü
                            lower_url = url.lower()
                            if not (any(ext in lower_url for ext in valid_extensions) or "workers.dev" in lower_url):
                                continue
                            
                            # Zaten varsa atla
                            if url in existing_urls:
                                continue
                            
                            # M3U'daki satırda başlık var mı?
                            title = extract_title(line)
                            
                            if title:
                                # 'Başlık|URL' formatında yaz
                                f.write(f"{title}|{url}\n")
                            else:
                                # Sadece URL yaz
                                f.write(f"{url}\n")
                            
                            existing_urls.add(url)
                            new_items += 1
                
                if new_items > 0:
                    log(f"{new_items} yeni gecerli video linki 'zemtv_playlist.txt' dosyasina eklendi.")
        except Exception as e:
            log("M3U guncelleme hatasi: " + str(e))
        
        time.sleep(900)

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
        
        # Sag Alt Köşede M3U'dan Çekilen Film/Video İsmi
        "[v_t3]drawtext="
        f"fontfile='{font}':textfile='{title_f}':reload=1:fontcolor=white:fontsize=24:x=W-tw-20:y=H-th-20:box=1:boxcolor=0x111827@0.8:boxborderw=8[vout]"
    )
    return filter_text

# ============================================================
# FFMPEG KOMUTU  (OTOMATİK ALGILAMA)
# ============================================================

def build_command(video_url):
    filters = create_filter()
    
    # Sadece vidrame linkleri için referer ekle
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

    # Arka planda M3U güncelleme döngüsünü başlat
    threading.Thread(target=fetch_m3u_and_update_playlist_file, daemon=True).start()

    time.sleep(5)

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
                        
                        # Playlist satırı iki formatta olabilir:
                        # 1) "Başlık|URL"
                        # 2) "URL"
                        if "|" in line_data:
                            parts = line_data.split("|", 1)
                            current_video_title = parts[0].strip()
                            current_video_url = extract_url(parts[1])
                        else:
                            current_video_url = extract_url(line_data)
                            # Başlık yoksa varsayılan kalsın
                            if not current_video_title:
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
            log(f"Liste tamamen bos veya '{PLAYLIST_FILE.name}' henuz olusturulmadi. Bekleniyor...")
            time.sleep(10)

if __name__ == "__main__":
    main()
