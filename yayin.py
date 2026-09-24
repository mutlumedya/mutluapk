# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
import urllib.request
from pathlib import Path
from datetime import datetime


# ============================================================
# ZEM TV COCUK
# M3U8 -> RTMP
# LOGO + SAAT + BILGI + KAYAN YAZI
# ============================================================

M3U8_URL = "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/cizgi-film-tv/playlist/0.m3u8"

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"

LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

FONT = r"C:\Windows\Fonts\arial.ttf"

BASE_DIR = Path(__file__).resolve().parent

LOGO_FILE = BASE_DIR / "zemtv_logo.png"
CLOCK_FILE = BASE_DIR / "zemtv_clock.txt"
INFO_FILE = BASE_DIR / "zemtv_info.txt"
TICKER_FILE = BASE_DIR / "zemtv_ticker.txt"


# ============================================================
# YAYIN AYARLARI
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
# EKRAN BILGILERI
# ============================================================

def update_text_files():

    now = datetime.now()

    clock = now.strftime("%H:%M:%S")

    date = now.strftime("%d.%m.%Y")

    info = (
        "ZEM TV COCUK   |   CANLI YAYIN   |   "
        + date
    )

    ticker = (
        "ZEM TV COCUK   |   Keyifli seyirler   |   "
        "ZEM MEDYA   |   Turkiye'nin dijital yayini"
    )

    write_file(CLOCK_FILE, clock)

    write_file(INFO_FILE, info)

    write_file(TICKER_FILE, ticker)


# ============================================================
# LOGO
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
            headers={
                "User-Agent": "Mozilla/5.0"
            }
        )

        with urllib.request.urlopen(
            request,
            timeout=30
        ) as response:

            data = response.read()

        if len(data) < 1000:

            raise Exception(
                "Logo dosyasi gecersiz."
            )

        with open(LOGO_FILE, "wb") as f:

            f.write(data)

        log("Logo indirildi.")

    except Exception as e:

        log(
            "Logo indirilemedi: "
            + str(e)
        )

        sys.exit(1)


# ============================================================
# FILTER YOL HAZIRLAMA
# ============================================================

def ff_path(path):

    value = str(path)

    value = value.replace("\\", "/")

    value = value.replace(":", "\\:")

    return value


# ============================================================
# FFMPEG FILTRE
#
# ONEMLI:
#
# DRAWBOX YOK.
# W YOK.
# H YOK.
# H-106 YOK.
#
# ============================================================

def create_filter():

    font = ff_path(FONT)

    logo = ff_path(LOGO_FILE)

    clock = ff_path(CLOCK_FILE)

    info = ff_path(INFO_FILE)

    ticker = ff_path(TICKER_FILE)


    filter_text = (

        # ----------------------------------------------------
        # LOGO BOYUTU
        # ----------------------------------------------------

        "[1:v]"
        "scale=150:-1"
        "[logo];"


        # ----------------------------------------------------
        # LOGO SAG UST
        # ----------------------------------------------------

        "[0:v][logo]"
        "overlay=1110:20"
        "[v1];"


        # ----------------------------------------------------
        # SAAT SOL UST
        # ----------------------------------------------------

        "[v1]"
        "drawtext="
        f"fontfile='{font}':"
        f"textfile='{clock}':"
        "reload=1:"
        "fontsize=30:"
        "fontcolor=white:"
        "borderw=3:"
        "bordercolor=black:"
        "x=20:"
        "y=20"
        "[v2];"


        # ----------------------------------------------------
        # BILGI
        # ----------------------------------------------------

        "[v2]"
        "drawtext="
        f"fontfile='{font}':"
        f"textfile='{info}':"
        "reload=1:"
        "fontsize=25:"
        "fontcolor=white:"
        "borderw=4:"
        "bordercolor=black:"
        "x=25:"
        "y=625"
        "[v3];"


        # ----------------------------------------------------
        # KAYAN YAZI
        #
        # SABIT 1280 KULLANIYORUZ.
        # W / H KULLANILMIYOR.
        # ----------------------------------------------------

        "[v3]"
        "drawtext="
        f"fontfile='{font}':"
        f"textfile='{ticker}':"
        "reload=1:"
        "fontsize=26:"
        "fontcolor=white:"
        "borderw=4:"
        "bordercolor=black:"
        "x=1280-mod(t*120\\,1700):"
        "y=680"
        "[vout]"

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

        "-loglevel",
        "info",


        # ====================================================
        # M3U8 BAGLANTISI
        # ====================================================

        "-reconnect",
        "1",

        "-reconnect_streamed",
        "1",

        "-reconnect_at_eof",
        "1",

        "-reconnect_delay_max",
        "10",

        "-rw_timeout",
        "15000000",

        "-user_agent",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 Chrome/140.0 Safari/537.36",

        "-referer",
        "https://playlist.fasttvcdn.com/",

        "-i",
        M3U8_URL,


        # ====================================================
        # LOGO
        # ====================================================

        "-loop",
        "1",

        "-i",
        str(LOGO_FILE),


        # ====================================================
        # FILTER
        # ====================================================

        "-filter_complex",
        filters,


        # ====================================================
        # VIDEO
        # ====================================================

        "-map",
        "[vout]",

        "-map",
        "0:a?",

        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-tune",
        "zerolatency",

        "-pix_fmt",
        "yuv420p",

        "-r",
        "25",

        "-s",
        "1280x720",

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


        # ====================================================
        # AUDIO
        # ====================================================

        "-c:a",
        "aac",

        "-b:a",
        AUDIO_BITRATE,

        "-ar",
        "48000",

        "-ac",
        "2",


        # ====================================================
        # RTMP
        # ====================================================

        "-f",
        "flv",

        RTMP_URL

    ]

    return command


# ============================================================
# KONTROL
# ============================================================

def check_files():

    if not os.path.isfile(FFMPEG):

        print("")
        print("FFmpeg bulunamadi:")
        print(FFMPEG)
        print("")

        sys.exit(1)


    if not os.path.isfile(FONT):

        print("")
        print("Arial bulunamadi:")
        print(FONT)
        print("")

        sys.exit(1)


# ============================================================
# YAYIN BASLAT
# ============================================================

def start_stream():

    command = build_command()


    print("")
    print("================================================")
    print("             ZEM TV COCUK")
    print("================================================")
    print("LOGO       : AKTIF")
    print("SAAT       : AKTIF")
    print("BILGI      : AKTIF")
    print("KAYAN YAZI : AKTIF")
    print("================================================")
    print("")


    log("FFmpeg baslatiliyor.")

    log("Kaynak:")
    print(M3U8_URL)

    log("RTMP:")
    print(RTMP_URL)

    print("")


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


        last_update = 0


        while True:

            line = process.stdout.readline()


            if line:

                print(
                    line.rstrip(),
                    flush=True
                )


            current = time.time()


            if current - last_update >= 1:

                update_text_files()

                last_update = current


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

        log(
            "FFmpeg calistirma hatasi: "
            + str(e)
        )

        return -1


# ============================================================
# ANA SISTEM
# ============================================================

def main():

    print("")
    print("ZEM TV COCUK")
    print("Yayin sistemi baslatiliyor...")
    print("")


    check_files()

    download_logo()

    update_text_files()


    while True:

        code = start_stream()


        if code == 0:

            log(
                "Yayin normal olarak durduruldu."
            )

            break


        log(
            "FFmpeg kapandi. Kod: "
            + str(code)
        )

        log(
            "5 saniye sonra yeniden baglanacak."
        )


        for i in range(5, 0, -1):

            print(
                "\rYeniden baglaniyor: "
                + str(i)
                + " saniye...",
                end="",
                flush=True
            )

            time.sleep(1)


        print("")

        update_text_files()


# ============================================================
# CALISTIR
# ============================================================

if __name__ == "__main__":

    main()
