# -*- coding: utf-8 -*-

import os
import sys
import subprocess
import urllib.request
from pathlib import Path
from datetime import datetime
import threading
import time

# ============================================================
# GEREKLİ KÜTÜPHANELERİ OTOMATİK KONTROL ET VE İNDİR
# ============================================================
def install_and_import(package):
    try:
        __import__(package)
    except ImportError:
        print(f"Eksik kutuphane tespit edildi: {package}. Otomatik olarak indiriliyor...")
        subprocess.check_call([sys.executable, "-m", "pip", "install", package])

install_and_import("feedparser")
install_and_import("requests")

import feedparser
import requests


# ============================================================
# ZEM TV COCUK
# ============================================================

M3U8_URL = (
    "https://playlist.fasttvcdn.com/pl/"
    "rfrk9821hdy9dayo8wfyha/cizgi-film-tv/"
    "playlist/0.m3u8"
)

RTMP_URL = (
    "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"
)

LOGO_URL = (
    "https://i.hizliresim.com/7pcmsgos.png"
)

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"
FONT = r"C:\Windows\Fonts\arial.ttf"

BASE_DIR = Path(__file__).resolve().parent

LOGO_FILE = BASE_DIR / "zemtv_logo.png"
INFO_FILE = BASE_DIR / "zemtv_info.txt"
TICKER_FILE = BASE_DIR / "zemtv_ticker.txt"


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
# İNTERNETTEN CANLI HABER VE ALTIN/GÜMÜŞ ÇEKME
# ============================================================

def fetch_live_news_and_market():
    headlines = []
    
    rss_urls = [
        "https://www.trthaber.com/sondakika.rss",
        "https://www.cnnturk.com/feed/rss/news",
        "https://www.ntv.com.tr/son-dakika.rss",
        "https://www.haberturk.com/rss/manset.xml",
        "https://www.sabah.com.tr/rss/sondakika.xml"
    ]
    
    for url in rss_urls:
        try:
            feed = feedparser.parse(url)
            for entry in feed.entries[:4]:
                title = entry.title.strip()
                if title and title not in headlines:
                    headlines.append(title)
        except Exception as e:
            log(f"RSS haber cekme hatasi ({url}): {e}")

    if not headlines:
        headlines = [
            "ZEM TV Çocuk kuşağı en sevilen çizgi filmlerle kesintisiz yayında.",
            "Türkiye'nin dijital ekranında eğlence ve eğitim dolu saatler devam ediyor.",
            "Minikler için yepyeni maceralar ve eğitici içerikler ekranlarda."
        ]

    gold_price = "6.710,00 TL"
    silver_price = "100,00 TL"
    try:
        res = requests.get("https://api.genelpara.com/embed/altin.json", timeout=5)
        if res.status_code == 200:
            data = res.json()
            if "GA" in data:
                gold_price = data["GA"].get("satis", "6.710,00 TL") + " TL"
            if "AG" in data:
                silver_price = data["AG"].get("satis", "100,00 TL") + " TL"
    except:
        pass

    news_text = "   ***   ".join([f"{i+1}. {h}" for i, h in enumerate(headlines)])
    market_text = f"   |||   CANLI PİYASA -> Gram Altın: {gold_price}   |   Gram Gümüş: {silver_price}   |||   "
    
    return news_text + market_text


# ============================================================
# YAZILARI GÜNCELLEME DÖNGÜSÜ
# ============================================================

def update_text_files():
    now = datetime.now()
    date = now.strftime("%d.%m.%Y")

    info = "ZEM TV COCUK | CANLI YAYIN | " + date
    ticker = fetch_live_news_and_market()

    write_file(INFO_FILE, info)
    write_file(TICKER_FILE, ticker)


def text_update_loop():
    while True:
        try:
            update_text_files()
        except Exception as e:
            log("Metin guncelleme hatasi: " + str(e))
        
        time.sleep(300)


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
    ticker = ff_path(TICKER_FILE)

    filter_text = (
        "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2[base];"
        "[1:v]scale=120:-1[logo];"
        "[base][logo]overlay=W-w-20:20[v1];"

        # 1. Kayan Haber Arka Planı (Orijinal Koyu Gri)
        "[v1]drawbox=x=0:y=670:w=1280:h=50:color=0x111827@0.94:t=fill[v_bg];"

        # 2. Kayan Haber Metni (Beyaz, Sürekli Kayan)
        "[v_bg]drawtext="
        f"fontfile='{font}':"
        f"textfile='{ticker}':"
        "reload=1:"
        "fontcolor=white:"
        "fontsize=20:"
        "borderw=1:"
        "bordercolor=black:"
        "x='1280 - mod(t*85\\, 20000)':"
        "y=683[v_ticker];"

        # 3. ZEM HABER Kutusu (Mavi) ve Saat Kutusu (Orijinal Gri)
        "[v_ticker]drawbox=x=0:y=670:w=130:h=50:color=0x003366@1.0:t=fill[v_box1];"
        "[v_box1]drawbox=x=130:y=670:w=100:h=50:color=0x374151@1.0:t=fill[v_box2];"

        # 4. Üstte ZEM yazısı (Sarı)
        "[v_box2]drawtext="
        f"fontfile='{font}':"
        "text='ZEM':"
        "fontcolor=yellow:"
        "fontsize=18:"
        "x=45:"
        "y=673:"
        "borderw=1:"
        "bordercolor=black[v_t1];"

        # 5. Altta HABER yazısı (Sarı)
        "[v_t1]drawtext="
        f"fontfile='{font}':"
        "text='HABER':"
        "fontcolor=yellow:"
        "fontsize=18:"
        "x=32:"
        "y=693:"
        "borderw=1:"
        "bordercolor=black[v_t2];"

        # 6. Saat metni (Beyaz)
        "[v_t2]drawtext="
        f"fontfile='{font}':"
        "text='%{localtime\\:%H\\\\\\:%M}':"
        "fontcolor=white:"
        "fontsize=22:"
        "x=142:"
        "y=683:"
        "borderw=2:"
        "bordercolor=black[vout]"
    )
    return filter_text


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def build_command():
    filters = create_filter()
    command = [
        FFMPEG,
        "-hide_banner", "-loglevel", "info",
        "-fflags", "+genpts+discardcorrupt",
        "-reconnect", "1", "-reconnect_streamed", "1",
        "-reconnect_at_eof", "1", "-reconnect_delay_max", "10",
        "-rw_timeout", "20000000",
        "-user_agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "-referer", "https://playlist.fasttvcdn.com/",
        "-i", M3U8_URL,
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

def start_stream():
    command = build_command()
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

def main():
    print("\nZEM TV COCUK - Canli Otomatik Haber & Piyasa Sistemi Baslatiliyor...\n")
    check_files()
    download_logo()
    
    update_text_files()

    updater = threading.Thread(target=text_update_loop, daemon=True)
    updater.start()

    while True:
        code = start_stream()
        if code == 0:
            log("Yayin normal olarak durduruldu.")
            break
        log(f"FFmpeg kapandi. Kod: {code}. 5 saniye sonra yeniden baglanacak.")
        time.sleep(5)
        update_text_files()

if __name__ == "__main__":
    main()
