# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
import urllib.request
from pathlib import Path
from datetime import datetime
import threading


# ============================================================
# ZEM TV COCUK
# M3U8 -> RTMP
# LOGO (SAG UST) + DINAMIK ZEM TV/HABERLER + CANLI SAAT + INCE ALT BANT
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
    "https://raw.githubusercontent.com/"
    "mutlumedya/cine/refs/heads/main/telegram.png"
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
# LOG
# ============================================================

def log(text):
    now = datetime.now().strftime("%d.%m.%Y %H:%M:%S")
    print(f"[{now}] {text}", flush=True)


# ============================================================
# DOSYA YAZ
# ============================================================

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
# YAZILARI GUNCELLE
# ============================================================

def update_text_files():
    now = datetime.now()
    date = now.strftime("%d.%m.%Y")

    info = "ZEM TV COCUK | CANLI YAYIN | " + date
    ticker = (
        "ZEM TV COCUK   |   "
        "Keyifli seyirler   |   "
        "ZEM MEDYA   |   "
        "Turkiye'nin dijital yayini   |   "
        "Guncel yayin"
    )

    write_file(INFO_FILE, info)
    write_file(TICKER_FILE, ticker)


# ============================================================
# DOSYALARI GUNCELLEME THREAD
# ============================================================

def text_update_loop():
    while True:
        try:
            update_text_files()
        except Exception as e:
            log("Metin guncelleme hatasi: " + str(e))
        time.sleep(1)


# ============================================================
# LOGO INDIR
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
            LOGO_URL,
            headers={"User-Agent": "Mozilla/5.0"}
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


# ============================================================
# FFMPEG DOSYA YOLU
# ============================================================

def ff_path(path):
    value = str(path)
    value = value.replace("\\", "/")
    value = value.replace(":", "\\:")
    return value


# ============================================================
# FFMPEG FILTER
# ============================================================

def create_filter():
    font = ff_path(FONT)
    logo = ff_path(LOGO_FILE)
    ticker = ff_path(TICKER_FILE)

    # 300 saniye = 5 dakika. Her 5 dakikada bir ilk 25 saniye bant aktif olacak.
    cycle_expr = "mod(t\\,300)"
    active_expr = f"between({cycle_expr},0,25)"

    filter_text = (
        # Ana videoyu standart 1280x720 boyutuna zorla ve formatı sabitle
        "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2[base];"

        # Logonun boyutunu ayarla
        "[1:v]scale=120:-1[logo];"

        # LOGO SAĞ ÜST KÖŞE (20 piksel içeride)
        "[base][logo]overlay=W-w-20:20[v1];"

        # 1. ALT BANT ZEMİNİ VE KAYAN YAZI (Yükseklik 50px, en altta y=670)
        "[v1]drawbox=x=270:y=670:w=1010:h=50:color=0x111827@0.94:t=fill:"
        f"enable='{active_expr}'[v_box];"

        "[v_box]drawtext="
        f"fontfile='{font}':"
        f"textfile='{ticker}':"
        "reload=1:"
        "fontcolor=white:"
        "fontsize=20:"
        "borderw=2:"
        "bordercolor=black:"
        "x='1280 - mod(t*100\\, 1250)':"
        "y=683:"
        f"enable='{active_expr}'[v_ticker];"

        # 2. SOL KISIM ANA ZEMİN (Sabit w=130, y=670, h=50)
        "[v_ticker]drawbox=x=0:y=670:w=130:h=50:color=0x1f2937@1.0:t=fill[v2];"

        # 3. SAAT KUTUSU (Sabit w=140, x=130, y=670, h=50) -> Yazının saatin üstüne geçmesini engeller
        "[v2]drawbox=x=130:y=670:w=140:h=50:color=0x374151@1.0:t=fill[v3];"

        # 4. CANLI SAAT (Sabit)
        "[v3]drawtext="
        f"fontfile='{font}':"
        "text='%{localtime\\:%H\\\\\\:%M}':"
        "fontcolor=white:"
        "fontsize=22:"
        "x=168:"
        "y=683:"
        "borderw=2:"
        "bordercolor=black[v4];"

        # 5. SOL KISIM METNİ: Bant yokken "ZEM TV", bant aktifken kırmızı "HABERLER"
        "[v4]drawtext="
        f"fontfile='{font}':"
        "text='ZEM TV':"
        "fontcolor=white:"
        "fontsize=18:"
        "x=35:"
        "y=685:"
        "borderw=2:"
        "bordercolor=black:"
        f"enable='lte({cycle_expr},0) + gt({cycle_expr},25)'[v_text1];"

        "[v_text1]drawtext="
        f"fontfile='{font}':"
        "text='HABERLER':"
        "fontcolor=red:"
        "fontsize=18:"
        "x=25:"
        "y=685:"
        "borderw=2:"
        "bordercolor=black:"
        f"enable='{active_expr}'[vout]"
    )

    return filter_text


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def build_command():
    filters = create_filter()

    command = [
        FFMPEG,
        "-hide_banner",
        "-loglevel", "info",
        # Yayın kopmalarını ve 1 dakika sonra kapanmayı önleyen kararlılık bayrakları:
        "-fflags", "+genpts+discardcorrupt",
        "-reconnect", "1",
        "-reconnect_streamed", "1",
        "-reconnect_at_eof", "1",
        "-reconnect_delay_max", "10",
        "-rw_timeout", "20000000",
        "-user_agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36",
        "-referer", "https://playlist.fasttvcdn.com/",
        "-i", M3U8_URL,
        "-loop", "1",
        "-i", str(LOGO_FILE),
        "-filter_complex", filters,
        "-map", "[vout]",
        "-map", "0:a?",
        "-c:v", "libx264",
        "-preset", "veryfast",
        "-tune", "zerolatency",
        "-pix_fmt", "yuv420p",
        "-r", str(FPS),
        "-s", "1280x720",
        "-b:v", VIDEO_BITRATE,
        "-maxrate", MAXRATE,
        "-bufsize", BUFSIZE,
        "-g", "50",
        "-keyint_min", "50",
        "-sc_threshold", "0",
        "-c:a", "aac",
        "-b:a", AUDIO_BITRATE,
        "-ar", "48000",
        "-ac", "2",
        "-f", "flv",
        "-flvflags", "no_duration_filesize",
        RTMP_URL
    ]

    return command


# ============================================================
# KONTROL VE BASLATMA
# ============================================================

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
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            stdin=subprocess.DEVNULL,
            text=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1
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
    print("\nZEM TV COCUK - Yayin Sistemi Baslatiliyor...\n")
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
