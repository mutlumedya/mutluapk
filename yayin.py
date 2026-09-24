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

def get_ffprobe_path():
    paths = [
        "ffprobe.exe",
        "C:\\ffmpeg\\bin\\ffprobe.exe",
        "C:\\Program Files\\ffmpeg\\bin\\ffprobe.exe"
    ]
    for path in paths:
        if shutil.which(path) or os.path.isfile(path):
            return path
    return "ffprobe.exe"

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
        log(RED, "FFmpeg bulunamadı!")
        log(YELLOW, "https://www.gyan.dev/ffmpeg/builds/ adresinden indirin")
        return False
    if not install_requests():
        log(RED, "Requests kurulumu başarısız!")
        return False
    log(GREEN, "✓ Hazır!")
    return True

CHECK_INTERVAL = 300
RESTART_DELAY = 5
CHANNEL_START_DELAY = 3
NIGHT_START = 3
NIGHT_END = 4
AD_INTERVAL = 30 * 60
AD_DURATION = 5 * 60
CARD_DURATION = 5
WIDTH = 1280
HEIGHT = 720
VIDEO_BITRATE = "2000k"
MAXRATE = "2000k"
BUFSIZE = "4000k"
AUDIO_BITRATE = "96k"
AUDIO_RATE = "44100"
LOGO_WIDTH = 150

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101"

CHANNELS = [
    {"name": "YAYIN 1", "key": "zemtv", "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u", "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/logo.png", "ads": []},
    {"name": "YAYIN 2", "key": "zemtvaile", "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u", "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvaile.png", "ads": []},
    {"name": "YAYIN 3", "key": "zemtvcocuk", "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u", "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvcocuk.png", "ads": []},
    {"name": "YAYIN 4", "key": "zemtvaksiyon", "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u", "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvaksiyon.png", "ads": []},
    {"name": "YAYIN 5", "key": "zemtvspor", "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u", "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvspor.png", "ads": []},
    {"name": "YAYIN 6", "key": "zemtvbelgesel", "m3u": "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u", "logo": "https://raw.githubusercontent.com/mutlumedya/benim/refs/heads/main/zemtvbelgesel.png", "ads": []}
]

STOP_EVENT = threading.Event()
PROCESSES = {}
PROCESS_LOCK = threading.Lock()
LOGO_CACHE = {}
FFMPEG = get_ffmpeg_path()
FFPROBE = get_ffprobe_path()

def get_output(channel):
    return f"{RTMP_URL}/{channel['key']}"

def broadcasting_allowed():
    hour = datetime.now().hour
    return not (NIGHT_START <= hour < NIGHT_END)

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

def get_duration(url):
    try:
        result = subprocess.run([FFPROBE, "-v", "error", "-show_entries", "format=duration", "-of", "default=noprint_wrappers=1:nokey=1", url], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=60)
        value = result.stdout.strip()
        if not value:
            return None
        duration = float(value)
        return duration if duration > 0 else None
    except:
        return None

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

def play_media(channel, source, duration, seek=0, media_type="film"):
    name = channel["name"]
    output = get_output(channel)
    logo_path = get_logo_path(channel)
    try:
        command = [FFMPEG, "-hide_banner", "-loglevel", "error", "-ss", str(seek), "-t", str(duration), "-i", source, "-vf", f"scale={WIDTH}:{HEIGHT}:force_original_aspect_ratio=decrease,pad={WIDTH}:{HEIGHT}:(ow-iw)/2:(oh-ih)/2:black", "-map", "0:v:0", "-map", "0:a?", "-c:v", "libx264", "-preset", "ultrafast", "-tune", "zerolatency", "-pix_fmt", "yuv420p", "-b:v", VIDEO_BITRATE, "-maxrate", MAXRATE, "-bufsize", BUFSIZE, "-g", "120", "-c:a", "aac", "-b:a", AUDIO_BITRATE, "-ar", AUDIO_RATE, "-threads", "1", "-f", "flv", output]
        if logo_path and os.path.isfile(logo_path):
            command = [FFMPEG, "-hide_banner", "-loglevel", "error", "-ss", str(seek), "-t", str(duration), "-i", source, "-loop", "1", "-i", logo_path, "-filter_complex", f"[0:v]scale={WIDTH}:{HEIGHT}:force_original_aspect_ratio=decrease,pad={WIDTH}:{HEIGHT}:(ow-iw)/2:(oh-ih)/2:black[base];[1:v]scale={LOGO_WIDTH}:-1[logo];[base][logo]overlay=W-w-15:15[v]", "-map", "[v]", "-map", "0:a?", "-c:v", "libx264", "-preset", "ultrafast", "-tune", "zerolatency", "-pix_fmt", "yuv420p", "-b:v", VIDEO_BITRATE, "-maxrate", MAXRATE, "-bufsize", BUFSIZE, "-g", "120", "-c:a", "aac", "-b:a", AUDIO_BITRATE, "-ar", AUDIO_RATE, "-threads", "1", "-f", "flv", output]
        log(CYAN, f"[{name}] {media_type.upper()} oynatiliyor...")
        process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        set_process(name, process)
        while True:
            if STOP_EVENT.is_set():
                stop_process(name)
                return False
            if not broadcasting_allowed():
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

def play_card(channel, title, message):
    name = channel["name"]
    output = get_output(channel)
    try:
        command = [FFMPEG, "-hide_banner", "-loglevel", "error", "-f", "lavfi", "-i", f"color=c=black:s={WIDTH}x{HEIGHT}:r=25", "-f", "lavfi", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100", "-vf", f"drawtext=text='{title} - {message}':fontcolor=white:fontsize=38:borderw=3:bordercolor=black:x=(w-text_w)/2:y=(h-text_h)/2", "-map", "0:v", "-map", "1:a", "-t", str(CARD_DURATION), "-c:v", "libx264", "-preset", "ultrafast", "-tune", "zerolatency", "-pix_fmt", "yuv420p", "-b:v", "1000k", "-c:a", "aac", "-b:a", "96k", "-shortest", "-f", "flv", output]
        log(MAGENTA, f"[{name}] KART: {title}")
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
        log(RED, f"[{name}] Kart hatasi: {e}")
        stop_process(name)
        return False

def play_movie(channel, movie, ad_index):
    name = channel["name"]
    title = movie["title"]
    url = movie["url"]
    log(GREEN, f"[{name}] {title}")
    duration = get_duration(url)
    if duration is None:
        log(RED, f"[{name}] Sure okunamadi")
        return False, ad_index
    log(BLUE, f"[{name}] Sure: {duration / 60:.2f} dk")
    position = 0.0
    while position < duration:
        remaining = duration - position
        segment = min(AD_INTERVAL, remaining)
        ok = play_media(channel, url, segment, position, "film")
        if not ok:
            if not broadcasting_allowed():
                return False, ad_index
            time.sleep(RESTART_DELAY)
            continue
        position += segment
        if position < duration:
            ads = channel.get("ads", [])
            if ads:
                ad = ads[ad_index % len(ads)]
                ad_index += 1
                play_card(channel, title, "Reklamlardan sonra devam edecek")
                log(YELLOW, f"[{name}] REKLAM")
                play_media(channel, ad, AD_DURATION, 0, "reklam")
                play_card(channel, title, "Devam ediyor")
            else:
                log(YELLOW, f"[{name}] Reklam yok")
    log(GREEN, f"[{name}] {title} bitti.")
    return True, ad_index

def channel_worker(channel):
    name = channel["name"]
    log(GREEN, f"[{name}] Baslatildi.")
    get_logo_path(channel)
    ad_index = 0
    while not STOP_EVENT.is_set():
        if not broadcasting_allowed():
            stop_process(name)
            time.sleep(20)
            continue
        content = download_m3u(channel["m3u"])
        if not content:
            time.sleep(30)
            continue
        playlist = parse_m3u(content)
        if not playlist:
            log(RED, f"[{name}] M3U bos")
            time.sleep(30)
            continue
        log(GREEN, f"[{name}] {len(playlist)} film bulundu")
        for movie in playlist:
            if STOP_EVENT.is_set():
                break
            if not broadcasting_allowed():
                break
            success, ad_index = play_movie(channel, movie, ad_index)
            if not success:
                if not broadcasting_allowed():
                    break
                time.sleep(RESTART_DELAY)
        if broadcasting_allowed():
            log(BLUE, f"[{name}] Playlist bitti")
            time.sleep(5)

def night_controller():
    stopped = False
    while not STOP_EVENT.is_set():
        hour = datetime.now().hour
        if hour == NIGHT_START and not stopped:
            log(YELLOW, "03:00 YAYINLAR KAPATILIYOR")
            stop_all()
            stopped = True
        elif hour == NIGHT_END and stopped:
            log(GREEN, "04:00 YAYINLAR BASLIYOR")
            stopped = False
        time.sleep(10)

def stop_all():
    with PROCESS_LOCK:
        names = list(PROCESSES.keys())
    for name in names:
        stop_process(name)

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

def main():
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)
    log(BLUE, "=" * 70)
    log(GREEN, "6 KANALLI TV PLAYOUT (WINDOWS)")
    log(BLUE, "=" * 70)
    if not initial_checks():
        sys.exit(1)
    log(GREEN, "FFmpeg hazir")
    log(GREEN, "M3U sistemi hazir")
    log(GREEN, "03:00 - 04:00 yayin molasi")
    log(BLUE, "=" * 70)
    threading.Thread(target=night_controller, daemon=True).start()
    for channel in CHANNELS:
        threading.Thread(target=channel_worker, args=(channel,), daemon=True).start()
        log(GREEN, f"{channel['name']} aktif")
        time.sleep(CHANNEL_START_DELAY)
    log(BLUE, "=" * 70)
    log(GREEN, "SISTEM AKTIF")
    log(BLUE, "=" * 70)
    try:
        while not STOP_EVENT.is_set():
            time.sleep(30)
    except KeyboardInterrupt:
        shutdown()

if __name__ == "__main__":
    main()
