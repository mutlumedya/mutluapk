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

install_and_import("feedparser")
install_and_import("requests")

import feedparser
import requests


# ============================================================
# YAYIN VE GITHUB AYARLARI
# ============================================================

GITHUB_M3U_URL = "https://raw.githubusercontent.com/mutlumedya/mutluapk/refs/heads/main/goals.m3u"

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zemtv"

LOGO_URL = "https://i.hizliresim.com/ko9s4ezf.png"

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"
FONT = r"C:\Windows\Fonts\arial.ttf"

BASE_DIR = Path(__file__).resolve().parent

LOGO_FILE = BASE_DIR / "zemtv_logo.png"
INFO_FILE = BASE_DIR / "zemtv_info.txt"
TICKER_FILE = BASE_DIR / "zemtv_ticker.txt"

PLAYLIST_FILE = BASE_DIR / "zemtv_playlist.txt"
BLACKLIST_FILE = BASE_DIR / "zemtv_blacklist.txt"  # 404 alan URL'ler buraya

file_lock = threading.Lock()
blacklist_lock = threading.Lock()
blacklist = set()


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
# URL TEMİZLEME VE DOĞRULAMA
# ============================================================

def clean_url(url):
    """
    M3U satirlarindan gelen URL'leri temizler.
    Ornek: 'https://x.com/a.mkv MobLand'  -> 'https://x.com/a.mkv'
    Ornek: 'https://x.com/a.mkv|Pulse'    -> 'https://x.com/a.mkv'
    Ornek: 'https://x.com/a.mkv Pulse'    -> 'https://x.com/a.mkv'
    """
    if not url:
        return ""
    url = url.strip()
    # | sonrasini at
    if "|" in url:
        url = url.split("|")[0].strip()
    # URL sadece gecerli karakterler icermeli; bosluk gorunce kes
    if " " in url:
        url = url.split(" ")[0].strip()
    # Sondaki gorunmez karakterleri temizle
    url = url.strip().strip('"').strip("'")
    return url


def is_valid_video_url(url):
    """URL video uzantisi ile bitiyor mu?"""
    if not url:
        return False
    valid_ext = ('.m3u8', '.mp4', '.mkv', '.ts', '.avi', '.txt', '.m4s', '.mov', '.mpg', '.mpeg')
    lower = url.lower().split("?")[0]
    return lower.endswith(valid_ext)


def check_url_accessible(url, timeout=8):
    """HTTP HEAD (basarisizsa GET) ile URL erisilebilir mi kontrol et."""
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36",
        "Referer": "https://vidrame.pro/",
    }
    try:
        r = requests.head(url, headers=headers, timeout=timeout, allow_redirects=True)
        if r.status_code == 200:
            return True
        # Bazi sunucular HEAD'i desteklemez, GET dene (stream etmeden)
        if r.status_code in (403, 405, 501):
            r = requests.get(url, headers=headers, timeout=timeout, stream=True, allow_redirects=True)
            try:
                if r.status_code == 200:
                    return True
            finally:
                r.close()
        return False
    except Exception:
        return False


def add_to_blacklist(url):
    with blacklist_lock:
        if url in blacklist:
            return
        blacklist.add(url)
        try:
            with open(BLACKLIST_FILE, "a", encoding="utf-8") as f:
                f.write(url + "\n")
        except Exception:
            pass
    log(f"KARA LISTEYE eklendi (erisilemez): {url}")


def load_blacklist():
    global blacklist
    if BLACKLIST_FILE.exists():
        try:
            with open(BLACKLIST_FILE, "r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if line:
                        blacklist.add(line)
            log(f"{len(blacklist)} URL kara listeden yuklendi.")
        except Exception as e:
            log("Kara liste yukleme hatasi: " + str(e))


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
# YAZILARI VE PLAYLIST DOSYASINI GÜNCELLEME DÖNGÜLERİ
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


def parse_m3u(text):
    """
    M3U iceriginden URL'leri cikartir.
    #EXTINF satirlarini atlar, sadece http ile baslayan satirlari alir.
    """
    urls = []
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line:
            continue
        if line.startswith("#"):
            continue
        # URL'yi temizle
        u = clean_url(line)
        if u.startswith("http://") or u.startswith("https://"):
            if is_valid_video_url(u):
                urls.append(u)
    return urls


def fetch_m3u_and_update_playlist_file():
    while True:
        try:
            log("GitHub M3U listesi kontrol ediliyor...")
            res = requests.get(GITHUB_M3U_URL, timeout=15)
            if res.status_code != 200:
                log(f"M3U indirilemedi. HTTP {res.status_code}")
                time.sleep(900)
                continue

            all_urls = parse_m3u(res.text)
            log(f"M3U'dan {len(all_urls)} gecerli URL bulundu.")

            new_items = 0
            with file_lock:
                existing_urls = set()
                if PLAYLIST_FILE.exists():
                    with open(PLAYLIST_FILE, "r", encoding="utf-8") as f:
                        for line in f:
                            line = line.strip()
                            if line:
                                existing_urls.add(line)

                with open(PLAYLIST_FILE, "a", encoding="utf-8", newline="\n") as f:
                    for link in all_urls:
                        if link in existing_urls:
                            continue
                        # Kara listede olanlari ekleme
                        with blacklist_lock:
                            if link in blacklist:
                                continue
                        # Erisilebilirlik kontrolu (playlist'e eklemeden once)
                        if check_url_accessible(link):
                            f.write(link + "\n")
                            existing_urls.add(link)
                            new_items += 1
                        else:
                            add_to_blacklist(link)

            if new_items > 0:
                log(f"{new_items} yeni gecerli video linki 'zemtv_playlist.txt' dosyasina eklendi.")
            else:
                log("Yeni gecerli link yok.")

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
        "[1:v]scale=250:-1[logo];"
        "[base][logo]overlay=W-w-20:20[v1];"
        "[v1]drawbox=x=0:y=670:w=1280:h=50:color=0x111827@0.94:t=fill[v_bg];"
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
        "[v_ticker]drawbox=x=0:y=670:w=130:h=50:color=0x003366@1.0:t=fill[v_box1];"
        "[v_box1]drawbox=x=130:y=670:w=100:h=50:color=0x374151@1.0:t=fill[v_box2];"
        "[v_box2]drawtext="
        f"fontfile='{font}':"
        "text='ZEM':"
        "fontcolor=yellow:"
        "fontsize=18:"
        "x=45:"
        "y=673:"
        "borderw=1:"
        "bordercolor=black[v_t1];"
        "[v_t1]drawtext="
        f"fontfile='{font}':"
        "text='HABER':"
        "fontcolor=yellow:"
        "fontsize=18:"
        "x=32:"
        "y=693:"
        "borderw=1:"
        "bordercolor=black[v_t2];"
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

def build_command(video_url):
    filters = create_filter()

    lower = video_url.lower()
    if lower.endswith(".txt") or ".m3u8" in lower or lower.endswith(".m3u8"):
        format_args = [
            "-allowed_extensions",
            "jpg,jpeg,png,txt,ts,m3u8,mp4,mkv,avi,m4s,m4v,mpg,mpeg,mpegts,mov,ogg,vob,wav",
            "-f", "hls"
        ]
    else:
        format_args = []

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
        "-headers", "Referer: https://vidrame.pro/\r\n"
    ] + format_args + [
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
# ANA DÖNGÜ (TXT DOSYASI ÜZERİNDEN OYNATMA)
# ============================================================

def load_playlist():
    """Playlist dosyasini oku, kara listedekileri atla."""
    if not PLAYLIST_FILE.exists():
        return []
    with file_lock:
        with open(PLAYLIST_FILE, "r", encoding="utf-8") as f:
            lines = [clean_url(line) for line in f if line.strip()]
    # Kara listedekileri cikar
    with blacklist_lock:
        lines = [u for u in lines if u and u not in blacklist]
    return lines


def main():
    print("\nZEM TV COCUK - TXT Tabanli Kesintisiz Yayin Sistemi Baslatiliyor...\n")
    check_files()
    load_blacklist()
    download_logo()
    update_text_files()

    threading.Thread(target=text_update_loop, daemon=True).start()
    threading.Thread(target=fetch_m3u_and_update_playlist_file, daemon=True).start()

    time.sleep(5)

    current_index = 0

    while True:
        playlist = load_playlist()
        total_videos = len(playlist)

        if total_videos == 0:
            log(f"Liste tamamen bos veya '{PLAYLIST_FILE.name}' henuz olusturulmadi. Bekleniyor...")
            time.sleep(10)
            continue

        if current_index >= total_videos:
            log("Listenin sonuna gelindi. Yayin kesilmesin diye basa donuluyor...")
            current_index = 0

        current_video = playlist[current_index]

        # URL temizle ve dogrula
        current_video = clean_url(current_video)
        if not current_video or not is_valid_video_url(current_video):
            log(f"Gecersiz URL atlandi: {current_video}")
            current_index += 1
            continue

        # vidrame ozel donusum
        if "vidrame.pro" in current_video and "master.m3u8" in current_video:
            current_video = current_video.replace("master.m3u8", "1080.txt")

        # Oynatmadan ONCE erisilebilirlik kontrolu
        if not check_url_accessible(current_video):
            log(f"URL erisilemez (404/403). Kara listeye aliniyor ve atlaniyor: {current_video}")
            add_to_blacklist(current_video)
            current_index += 1
            time.sleep(1)
            continue

        log(f"Oynatiliyor (Sira {current_index + 1}/{total_videos}): {current_video}")

        code = start_stream(current_video)

        # FFmpeg basarisizsa (404 vs) kara listeye ekle
        if code != 0:
            log(f"FFmpeg hata kodu ile cikti: {code}. URL kara listeye aliniyor.")
            add_to_blacklist(current_video)
        else:
            log("FFmpeg normal sekilde bitti.")

        log("Film Bitti. Siradaki icerige geciliyor...")

        current_index += 1
        time.sleep(2)


if __name__ == "__main__":
    main()
