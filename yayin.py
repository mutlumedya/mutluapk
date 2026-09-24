# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
import urllib.request
import urllib.error
from datetime import datetime
from pathlib import Path

# ============================================================
# ZEM TV COCUK - M3U8 -> RTMP
# LOGO + SAAT + BILGI BAR + KAYAN YAZI
# WINDOWS VDS
# ============================================================

# ----------------------------
# AYARLAR
# ----------------------------

M3U8_URL = "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/cizgi-film-tv/playlist/0.m3u8"

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"

LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"

CHANNEL_NAME = "ZEM TV COCUK"

SCROLL_TEXT = (
    "ZEM TV COCUK  |  Keyifli seyirler  |  "
    "ZEM MEDYA  |  Turkiye'nin dijital yayini"
)

# FFmpeg yolu
FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

# Calisma klasoru
BASE_DIR = Path(__file__).resolve().parent

LOGO_FILE = BASE_DIR / "zemtv_logo.png"
CLOCK_FILE = BASE_DIR / "zemtv_clock.txt"
INFO_FILE = BASE_DIR / "zemtv_info.txt"
TICKER_FILE = BASE_DIR / "zemtv_ticker.txt"

# Windows Arial
FONT_FILE = r"C:\Windows\Fonts\arial.ttf"

# Video ayarlari
VIDEO_WIDTH = 1280
VIDEO_HEIGHT = 720
VIDEO_FPS = 25

VIDEO_BITRATE = "3000k"
MAXRATE = "3500k"
BUFSIZE = "6000k"

AUDIO_BITRATE = "128k"

# Yeniden baglanma
RESTART_DELAY = 5

# Logo boyutu
LOGO_WIDTH = 150

# ----------------------------
# RENK / GORUNUM
# ----------------------------

INFO_HEIGHT = 58
TICKER_HEIGHT = 48

# ============================================================
# YARDIMCI FONKSIYONLAR
# ============================================================

def log(text):
    now = datetime.now().strftime("%d.%m.%Y %H:%M:%S")
    print(f"[{now}] {text}", flush=True)


def check_ffmpeg():
    if not os.path.isfile(FFMPEG):
        print("")
        print("FFmpeg bulunamadi!")
        print("")
        print("Beklenen:")
        print(FFMPEG)
        print("")
        print("FFmpeg'i C:\\ffmpeg\\bin\\ klasorune koy.")
        print("")
        sys.exit(1)


def download_logo():
    if LOGO_FILE.exists() and LOGO_FILE.stat().st_size > 1000:
        return True

    log("Logo indiriliyor...")

    try:
        request = urllib.request.Request(
            LOGO_URL,
            headers={
                "User-Agent": "Mozilla/5.0"
            }
        )

        with urllib.request.urlopen(request, timeout=20) as response:
            data = response.read()

        if len(data) < 1000:
            raise Exception("Logo dosyasi gecersiz veya cok kucuk.")

        with open(LOGO_FILE, "wb") as f:
            f.write(data)

        log("Logo hazir.")
        return True

    except Exception as e:
        log("Logo indirilemedi: " + str(e))
        return False


def write_text_file(path, text):
    tmp = Path(str(path) + ".tmp")

    try:
        with open(tmp, "w", encoding="utf-8", newline="\n") as f:
            f.write(text)

        os.replace(tmp, path)

    except Exception:
        try:
            if tmp.exists():
                tmp.unlink()
        except Exception:
            pass


def update_overlay_files():
    # Saat
    current_time = datetime.now().strftime("%H:%M:%S")
    current_date = datetime.now().strftime("%d.%m.%Y")

    write_text_file(
        CLOCK_FILE,
        current_time
    )

    # Bilgi
    write_text_file(
        INFO_FILE,
        f"{CHANNEL_NAME}    |    CANLI YAYIN    |    {current_date}"
    )

    # Kayan yazi
    write_text_file(
        TICKER_FILE,
        SCROLL_TEXT
    )


def create_files():
    update_overlay_files()


def make_filter():
    """
    FFmpeg filtre zinciri.

    Logo:
    sag ust

    Saat:
    sol ust

    Bilgi:
    alt kisim ustunde

    Kayan yazi:
    en alt kisim
    """

    # Windows yollarini FFmpeg icin guvenli hale getir
    logo = str(LOGO_FILE).replace("\\", "/")
    font = FONT_FILE.replace("\\", "/")
    clock = str(CLOCK_FILE).replace("\\", "/")
    info = str(INFO_FILE).replace("\\", "/")
    ticker = str(TICKER_FILE).replace("\\", "/")

    # Drive colon'u FFmpeg filtrelerinde sorun cikarmasin
    logo = logo.replace(":", "\\:")
    font = font.replace(":", "\\:")
    clock = clock.replace(":", "\\:")
    info = info.replace(":", "\\:")
    ticker = ticker.replace(":", "\\:")

    filter_complex = (
        # Logo
        f"[1:v]"
        f"scale={LOGO_WIDTH}:-1,"
        f"format=rgba"
        f"[logo];"

        # Ana video + logo
        f"[0:v][logo]"
        f"overlay=W-w-18:18:format=auto"
        f"[v1];"

        # Saat
        f"[v1]"
        f"drawtext="
        f"fontfile='{font}':"
        f"textfile='{clock}':"
        f"reload=25:"
        f"fontsize=30:"
        f"fontcolor=white:"
        f"box=1:"
        f"boxcolor=black@0.55:"
        f"boxborderw=10:"
        f"x=18:"
        f"y=18"
        f"[v2];"

        # Bilgi cubugu
        f"[v2]"
        f"drawbox="
        f"x=0:"
        f"y=H-{TICKER_HEIGHT + INFO_HEIGHT}:"
        f"w=W:"
        f"h={INFO_HEIGHT}:"
        f"color=black@0.72:"
        f"t=fill"
        f"[v3];"

        # Bilgi yazisi
        f"[v3]"
        f"drawtext="
        f"fontfile='{font}':"
        f"textfile='{info}':"
        f"reload=25:"
        f"fontsize=25:"
        f"fontcolor=white:"
        f"x=25:"
        f"y=H-{TICKER_HEIGHT + INFO_HEIGHT}+16"
        f"[v4];"

        # Kayan yazi arka plani
        f"[v4]"
        f"drawbox="
        f"x=0:"
        f"y=H-{TICKER_HEIGHT}:"
        f"w=W:"
        f"h={TICKER_HEIGHT}:"
        f"color=black@0.90:"
        f"t=fill"
        f"[v5];"

        # Kayan yazi
        f"[v5]"
        f"drawtext="
        f"fontfile='{font}':"
        f"textfile='{ticker}':"
        f"reload=25:"
        f"fontsize=26:"
        f"fontcolor=white:"
        f"x='W-mod(t*150\\,W+tw)':"
        f"y=H-{TICKER_HEIGHT}+11"
        f"[vout]"
    )

    return filter_complex


def build_command():
    filter_complex = make_filter()

    command = [
        FFMPEG,

        "-hide_banner",

        # ------------------------------------------------
        # M3U8
        # ------------------------------------------------
        "-reconnect", "1",
        "-reconnect_streamed", "1",
        "-reconnect_delay_max", "10",
        "-rw_timeout", "15000000",

        "-user_agent",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 Chrome/140.0 Safari/537.36",

        "-referer",
        "https://playlist.fasttvcdn.com/",

        "-i",
        M3U8_URL,

        # ------------------------------------------------
        # LOGO
        # ------------------------------------------------
        "-loop", "1",
        "-i",
        str(LOGO_FILE),

        # ------------------------------------------------
        # FILTER
        # ------------------------------------------------
        "-filter_complex",
        filter_complex,

        "-map",
        "[vout]",

        "-map",
        "0:a?",

        # ------------------------------------------------
        # VIDEO
        # ------------------------------------------------
        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-tune",
        "zerolatency",

        "-profile:v",
        "main",

        "-level",
        "4.0",

        "-pix_fmt",
        "yuv420p",

        "-r",
        str(VIDEO_FPS),

        "-s",
        f"{VIDEO_WIDTH}x{VIDEO_HEIGHT}",

        "-b:v",
        VIDEO_BITRATE,

        "-maxrate",
        MAXRATE,

        "-bufsize",
        BUFSIZE,

        "-g",
        "50",

        "-keyint_min",
        "50",

        "-sc_threshold",
        "0",

        # ------------------------------------------------
        # AUDIO
        # ------------------------------------------------
        "-c:a",
        "aac",

        "-b:a",
        AUDIO_BITRATE,

        "-ar",
        "44100",

        "-ac",
        "2",

        # ------------------------------------------------
        # RTMP
        # ------------------------------------------------
        "-f",
        "flv",

        "-flvflags",
        "no_duration_filesize",

        RTMP_URL
    ]

    return command


def run_ffmpeg():
    command = build_command()

    log("FFmpeg baslatiliyor.")
    log("Kaynak:")
    log(M3U8_URL)
    log("RTMP:")
    log(RTMP_URL)

    print("")
    print("--------------------------------------------------")
    print(" ZEM TV COCUK")
    print(" LOGO       : AKTIF")
    print(" SAAT       : AKTIF")
    print(" BILGI BAR  : AKTIF")
    print(" KAYAN YAZI : AKTIF")
    print("--------------------------------------------------")
    print("")

    process = None

    try:
        process = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            stdin=subprocess.DEVNULL,
            universal_newlines=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1
        )

        last_overlay_update = 0

        while True:
            line = process.stdout.readline()

            if line:
                print(line.rstrip(), flush=True)

            # Saat dosyasini her saniye yenile
            now = time.time()

            if now - last_overlay_update >= 1:
                update_overlay_files()
                last_overlay_update = now

            if process.poll() is not None:
                break

        return_code = process.returncode

        log(f"FFmpeg kapandi. Kod: {return_code}")

        return return_code

    except KeyboardInterrupt:
        log("Yayin kullanici tarafindan durduruldu.")

        if process:
            try:
                process.terminate()
                process.wait(timeout=5)
            except Exception:
                try:
                    process.kill()
                except Exception:
                    pass

        return 0

    except Exception as e:
        log("FFmpeg calistirma hatasi: " + str(e))
        return -1


# ============================================================
# ANA DONGU
# ============================================================

def main():

    print("")
    print("==================================================")
    print(" ZEM TV COCUK YAYIN SISTEMI")
    print("==================================================")
    print("")

    check_ffmpeg()

    download_logo()

    create_files()

    log("Sistem hazir.")

    while True:

        # Saat ve overlay dosyalari
        update_overlay_files()

        result = run_ffmpeg()

        if result == 0:
            break

        log(f"Yayin {RESTART_DELAY} saniye sonra yeniden baslatilacak.")

        for i in range(RESTART_DELAY, 0, -1):
            print(
                f"\rYeniden baslatiliyor: {i} saniye ",
                end="",
                flush=True
            )
            time.sleep(1)

        print("")

        update_overlay_files()


if __name__ == "__main__":
    main()
