#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import time
import signal
import shutil
import threading
import subprocess
import tempfile
import hashlib
from datetime import datetime

RED = "\033[0;31m"
GREEN = "\033[0;32m"
YELLOW = "\033[1;33m"
BLUE = "\033[0;34m"
CYAN = "\033[0;36m"
MAGENTA = "\033[0;35m"
RESET = "\033[0m"

def log(color, text):
    print(f"{color}[{datetime.now().strftime('%H:%M:%S')}] {text}{RESET}", flush=True)

def check_ffmpeg():
    paths = [
        "ffmpeg.exe",
        "C:\\ffmpeg\\bin\\ffmpeg.exe",
        "C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe"
    ]
    for path in paths:
        if shutil.which(path) or os.path.isfile(path):
            return True
    return False

def get_ffmpeg_path():
    paths = [
        "ffmpeg.exe",
        "C:\\ffmpeg\\bin\\ffmpeg.exe",
        "C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe"
    ]
    for path in paths:
        if shutil.which(path) or os.path.isfile(path):
            return path
    return "ffmpeg.exe"

def install_requests():
    try:
        import requests
        return True
    except ImportError:
        log(YELLOW, "Requests kuruluyor...")
        try:
            subprocess.run([sys.executable, "-m", "pip", "install", "requests"], check=True)
            return True
        except:
            return False

def initial_checks():
    log(BLUE, "Windows VDS Kontrolleri...")
    if not check_ffmpeg():
        log(RED, "FFmpeg bulunamadi!")
        log(YELLOW, "https://www.gyan.dev/ffmpeg/builds/ adresinden indirin")
        return False
    if not install_requests():
        log(RED, "Requests kurulumu basarisiz!")
        return False
    log(GREEN, "✓ Hazir!")
    return True

# ============================================================
# AYARLAR - SADECE VIDEO + LOGO
# ============================================================

WIDTH = 1280
HEIGHT = 720
VIDEO_BITRATE = "2000k"
MAXRATE = "2000k"
BUFSIZE = "4000k"
AUDIO_BITRATE = "96k"
AUDIO_RATE = "44100"
LOGO_WIDTH = 150

# RTMP - KENDI BILGILERINI GIR
RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101"

# ============================================================
# 6 KANAL - SADECE M3U + LOGO
# ============================================================

CHANNELS = [
    {
        "name": "YAYIN 1",
        "key": "zemtv",
        "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u",
        "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/logo.png"
    },
    {
        "name": "YAYIN 2",
        "key": "zemtvaile",
        "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u",
        "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvaile.png"
    },
    {
        "name": "YAYIN 3",
        "key": "zemtvcocuk",
        "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u",
        "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvcocuk.png"
    },
    {
        "name": "YAYIN 4",
        "key": "zemtvaksiyon",
        "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u",
        "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvaksiyon.png"
    },
    {
        "name": "YAYIN 5",
        "key": "zemtvspor",
        "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u",
        "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvspor.png"
    },
    {
        "name": "YAYIN 6",
        "key": "zemtvbelgesel",
        "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u",
        "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvbelgesel.png"
    }
]

# ============================================================
# GLOBAL
# ============================================================

STOP_EVENT = threading.Event()
PROCESSES = {}
PROCESS_LOCK = threading.Lock()
LOGO_CACHE = {}
FFMPEG = get_ffmpeg_path()

def get_output(channel):
    return f"{RTMP_URL}/{channel['key']}"

def download_m3u(url):
    try:
        import requests
        response = requests.get(url, timeout=30, headers={"User-Agent": "Mozilla/5.0"})
        response.raise_for_status()
        return response.text
    except Exception as e:
        log(RED, f"M3U hatasi: {e}")
        return None

def parse_m3u(content):
    if not content:
        return []
    lines = [x.strip() for x in content.splitlines() if x.strip()]
    result = []
    title = None
    for line in lines:
        if line.startswith("#EXTINF"):
            if "," in line:
                title = line.split(",", 1)[1].strip()
            else:
                title = "Yayin"
            continue
        if line.startswith("#"):
            continue
        if line.startswith(("http://", "https://")):
            result.append({"title": title or "Yayin", "url": line})
            title = None
    return result

def get_logo_path(channel):
    logo_url = channel.get("logo")
    if not logo_url:
        return None
    if not logo_url.startswith(("http://", "https://")):
        return logo_url if os.path.isfile(logo_url) else None
    if logo_url in LOGO_CACHE and os.path.isfile(LOGO_CACHE[logo_url]):
        return LOGO_CACHE[logo_url]
    try:
        import requests
        url_hash = hashlib.md5(logo_url.encode()).hexdigest()
        local_path = os.path.join(tempfile.gettempdir(), f"logo_{url_hash}.png")
        response = requests.get(logo_url, timeout=30, headers={"User-Agent": "Mozilla/5.0"})
        response.raise_for_status()
        with open(local_path, 'wb') as f:
            f.write(response.content)
        LOGO_CACHE[logo_url] = local_path
        return local_path
    except Exception as e:
        log(RED, f"Logo indirilemedi: {e}")
        return None

def set_process(name, process):
    with PROCESS_LOCK:
        PROCESSES[name] = process

def remove_process(name):
    with PROCESS_LOCK:
        PROCESSES.pop(name, None)

def stop_process(name):
    with PROCESS_LOCK:
        process = PROCESSES.get(name)
        if not process:
            return
        try:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
        except:
            pass
        PROCESSES.pop(name, None)

def stop_all():
    with PROCESS_LOCK:
        names = list(PROCESSES.keys())
    for name in names:
        stop_process(name)

# ============================================================
# SADE YAYIN - SURE YOK, REKLAM YOK, 7/24
# ============================================================

def play_media(channel, source, media_type="film"):
    name = channel["name"]
    output = get_output(channel)
    logo_path = get_logo_path(channel)
    
    try:
        if logo_path and os.path.isfile(logo_path):
            command = [
                FFMPEG,
                "-hide_banner",
                "-loglevel", "error",
                "-re",
                "-i", source,
                "-loop", "1",
                "-i", logo_path,
                "-filter_complex",
                f"[0:v]scale={WIDTH}:{HEIGHT}:force_original_aspect_ratio=decrease,pad={WIDTH}:{HEIGHT}:(ow-iw)/2:(oh-ih)/2:black[base];[1:v]scale={LOGO_WIDTH}:-1[logo];[base][logo]overlay=W-w-15:15[v]",
                "-map", "[v]",
                "-map", "0:a?",
                "-c:v", "libx264",
                "-preset", "ultrafast",
                "-tune", "zerolatency",
                "-pix_fmt", "yuv420p",
                "-b:v", VIDEO_BITRATE,
                "-maxrate", MAXRATE,
                "-bufsize", BUFSIZE,
                "-g", "120",
                "-c:a", "aac",
                "-b:a", AUDIO_BITRATE,
                "-ar", AUDIO_RATE,
                "-threads", "1",
                "-f", "flv",
                output
            ]
        else:
            command = [
                FFMPEG,
                "-hide_banner",
                "-loglevel", "error",
                "-re",
                "-i", source,
                "-vf", f"scale={WIDTH}:{HEIGHT}:force_original_aspect_ratio=decrease,pad={WIDTH}:{HEIGHT}:(ow-iw)/2:(oh-ih)/2:black",
                "-map", "0:v:0",
                "-map", "0:a?",
                "-c:v", "libx264",
                "-preset", "ultrafast",
                "-tune", "zerolatency",
                "-pix_fmt", "yuv420p",
                "-b:v", VIDEO_BITRATE,
                "-maxrate", MAXRATE,
                "-bufsize", BUFSIZE,
                "-g", "120",
                "-c:a", "aac",
                "-b:a", AUDIO_BITRATE,
                "-ar", AUDIO_RATE,
                "-threads", "1",
                "-f", "flv",
                output
            ]
        
        log(CYAN, f"[{name}] {media_type} oynatiliyor: {source}")
        
        process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        set_process(name, process)
        
        while True:
            if STOP_EVENT.is_set():
                stop_process(name)
                return False
            code = process.poll()
            if code is not None:
                remove_process(name)
                return code == 0
            time.sleep(1)
            
    except Exception as e:
        log(RED, f"[{name}] FFmpeg hata: {e}")
        stop_process(name)
        return False

# ============================================================
# KANAL - SURE YOK, 7/24 CALISIR
# ============================================================

def channel_worker(channel):
    name = channel["name"]
    log(GREEN, f"[{name}] Baslatildi. 7/24 calisacak...")
    
    get_logo_path(channel)
    video_index = 0
    
    while not STOP_EVENT.is_set():
        content = download_m3u(channel["m3u"])
        if not content:
            log(RED, f"[{name}] M3U alinamadi, 30 saniye sonra tekrar...")
            time.sleep(30)
            continue
        
        playlist = parse_m3u(content)
        if not playlist:
            log(RED, f"[{name}] M3U bos, 30 saniye sonra tekrar...")
            time.sleep(30)
            continue
        
        log(GREEN, f"[{name}] {len(playlist)} video bulundu. Surekli yayin...")
        
        # 7/24 surekli oynat - sirayla videolari oynat
        while not STOP_EVENT.is_set():
            # M3U'yu her seferinde tazele
            content = download_m3u(channel["m3u"])
            if content:
                playlist = parse_m3u(content)
                if playlist:
                    # Liste bos degilse video sec
                    if video_index >= len(playlist):
                        video_index = 0
                    
                    video = playlist[video_index]
                    video_index += 1
                    
                    log(GREEN, f"[{name}] 🎬 {video['title']}")
                    play_media(channel, video['url'], "video")
                    continue
            
            # Hata durumunda bekle
            log(YELLOW, f"[{name}] M3U bekleniyor...")
            time.sleep(10)

# ============================================================
# KAPATMA
# ============================================================

def shutdown():
    if STOP_EVENT.is_set():
        return
    log(RED, "Sistem kapatiliyor...")
    STOP_EVENT.set()
    stop_all()
    log(GREEN, "Kapandi.")

def signal_handler(signum, frame):
    shutdown()
    sys.exit(0)

# ============================================================
# BASLANGIC
# ============================================================

def main():
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)
    
    log(BLUE, "=" * 70)
    log(GREEN, "6 KANALLI 7/24 TV PLAYOUT (WINDOWS)")
    log(GREEN, "REKLAM YOK - SURE YOK - SADECE M3U + LOGO")
    log(BLUE, "=" * 70)
    
    if not initial_checks():
        sys.exit(1)
    
    log(GREEN, "FFmpeg hazir")
    log(GREEN, "M3U sistemi hazir")
    log(GREEN, "7/24 SUREKLI YAYIN")
    log(BLUE, "=" * 70)
    
    # Logolari indir
    log(YELLOW, "Logolar indiriliyor...")
    for channel in CHANNELS:
        logo_path = get_logo_path(channel)
        if logo_path:
            log(GREEN, f"{channel['name']} logosu hazir")
        else:
            log(YELLOW, f"{channel['name']} logosu yok")
    
    log(BLUE, "=" * 70)
    
    # 6 kanali baslat
    for channel in CHANNELS:
        threading.Thread(target=channel_worker, args=(channel,), daemon=True).start()
        log(GREEN, f"{channel['name']} AKTIF (7/24)")
        time.sleep(2)
    
    log(BLUE, "=" * 70)
    log(GREEN, "SISTEM AKTIF - 7/24 YAYIN")
    log(BLUE, "=" * 70)
    
    try:
        while not STOP_EVENT.is_set():
            time.sleep(30)
    except KeyboardInterrupt:
        shutdown()

if __name__ == "__main__":
    main()
