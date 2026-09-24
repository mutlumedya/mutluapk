# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
from datetime import datetime

# ============================================================
# ZEM TV HABER - TEK YAYIN
# WINDOWS SERVER 2022
# ============================================================

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

SOURCE = "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/cizgi-film-tv/playlist/0.m3u8"

RTMP = "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
LOGO = os.path.join(BASE_DIR, "logo.png")

RESTART_DELAY = 5

VIDEO_BITRATE = "2000k"
AUDIO_BITRATE = "96k"

# ============================================================
# EKRAN
# ============================================================

os.system("title ZEM TV HABER - CANLI YAYIN")

print("")
print("==============================================")
print("          ZEM TV HABER")
print("          CANLI YAYIN SISTEMI")
print("==============================================")
print("")

# ============================================================
# FFMPEG KONTROL
# ============================================================

if not os.path.isfile(FFMPEG):
    print("[HATA] FFmpeg bulunamadi:")
    print(FFMPEG)
    input("Enter'a basin...")
    sys.exit(1)

print("[OK] FFmpeg:", FFMPEG)

try:
    test = subprocess.run(
        [FFMPEG, "-version"],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=10
    )

    if test.returncode != 0:
        print("[HATA] FFmpeg calismiyor.")
        print(test.stderr)
        input("Enter'a basin...")
        sys.exit(1)

    print("[OK] FFmpeg calisiyor.")

except Exception as e:
    print("[HATA] FFmpeg kontrol hatasi:", e)
    input("Enter'a basin...")
    sys.exit(1)

# ============================================================
# LOGO
# ============================================================

LOGO_VAR = os.path.isfile(LOGO)

if LOGO_VAR:
    print("[OK] Logo bulundu:", LOGO)
else:
    print("[UYARI] logo.png bulunamadi.")
    print("[BILGI] Logo olmadan devam edilecek.")

# ============================================================
# WINDOWS SAATI
# ============================================================

def windows_time():
    return datetime.now().strftime("%d.%m.%Y %H:%M:%S")


# ============================================================
# YAYIN BASLAT
# ============================================================

def start_stream():

    print("")
    print("==============================================")
    print("           YAYIN BASLATILIYOR")
    print("==============================================")
    print("")

    print("[SAAT]", windows_time())
    print("[M3U8]", SOURCE)
    print("[RTMP]", RTMP)
    print("")

    # --------------------------------------------------------
    # TEMEL VIDEO FILTRESI
    # --------------------------------------------------------

    video_chain = (
        "scale=1280:720,"
        "drawbox=x=0:y=0:w=1280:h=55:"
        "color=black@0.65:t=fill,"
        "drawtext="
        "fontfile='C:/Windows/Fonts/arial.ttf':"
        "text='ZEM TV HABER':"
        "fontcolor=white:"
        "fontsize=25:"
        "x=25:"
        "y=14,"
        "drawtext="
        "fontfile='C:/Windows/Fonts/arial.ttf':"
        "text='CANLI':"
        "fontcolor=red:"
        "fontsize=23:"
        "x=220:"
        "y=15,"
        "drawbox=x=0:y=650:w=1280:h=70:"
        "color=black@0.85:t=fill,"
        "drawtext="
        "fontfile='C:/Windows/Fonts/arial.ttf':"
        "text='ZEM TV HABER':"
        "fontcolor=red:"
        "fontsize=23:"
        "x=20:"
        "y=663,"
        "drawtext="
        "fontfile='C:/Windows/Fonts/arial.ttf':"
        "text='SON DAKIKA - CANLI YAYIN - GUNCEL HABERLER':"
        "fontcolor=white:"
        "fontsize=21:"
        "x=230:"
        "y=664"
    )

    # --------------------------------------------------------
    # LOGO VARSA
    # --------------------------------------------------------

    if LOGO_VAR:

        filter_complex = (
            "[1:v]scale=150:-1[logo];"
            "[0:v][logo]overlay=W-w-25:20[base];"
            "[base]"
            + video_chain +
            "[vout]"
        )

        command = [
            FFMPEG,

            "-hide_banner",

            "-loglevel",
            "info",

            "-reconnect",
            "1",

            "-reconnect_streamed",
            "1",

            "-reconnect_delay_max",
            "5",

            "-user_agent",
            "Mozilla/5.0",

            "-i",
            SOURCE,

            "-loop",
            "1",

            "-i",
            LOGO,

            "-filter_complex",
            filter_complex,

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

            "-b:v",
            VIDEO_BITRATE,

            "-maxrate",
            VIDEO_BITRATE,

            "-bufsize",
            "4000k",

            "-r",
            "25",

            "-g",
            "50",

            "-c:a",
            "aac",

            "-b:a",
            AUDIO_BITRATE,

            "-ar",
            "44100",

            "-ac",
            "2",

            "-flvflags",
            "no_duration_filesize",

            "-f",
            "flv",

            RTMP
        ]

    else:

        command = [
            FFMPEG,

            "-hide_banner",

            "-loglevel",
            "info",

            "-reconnect",
            "1",

            "-reconnect_streamed",
            "1",

            "-reconnect_delay_max",
            "5",

            "-user_agent",
            "Mozilla/5.0",

            "-i",
            SOURCE,

            "-vf",
            video_chain,

            "-map",
            "0:v:0",

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

            "-b:v",
            VIDEO_BITRATE,

            "-maxrate",
            VIDEO_BITRATE,

            "-bufsize",
            "4000k",

            "-r",
            "25",

            "-g",
            "50",

            "-c:a",
            "aac",

            "-b:a",
            AUDIO_BITRATE,

            "-ar",
            "44100",

            "-ac",
            "2",

            "-flvflags",
            "no_duration_filesize",

            "-f",
            "flv",

            RTMP
        ]

    print("[FFmpeg] Baslatiliyor...")
    print("")
    print("[✓] VIDEO AKISI BEKLENIYOR")
    print("[✓] SES AKISI BEKLENIYOR")
    print("[✓] RTMP BAGLANTISI BEKLENIYOR")
    print("")

    try:

        process = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            universal_newlines=True,
            bufsize=1
        )

    except Exception as e:

        print("[HATA] FFmpeg baslatilamadi:")
        print(e)

        return

    aktif = False

    try:

        for line in process.stdout:

            line = line.rstrip()

            if not line:
                continue

            print("[FFmpeg]", line)

            low = line.lower()

            if "frame=" in low and not aktif:

                aktif = True

                print("")
                print("==============================================")
                print("          [✓] VIDEO KARELERI AKIYOR")
                print("          [✓] YAYIN AKTIF")
                print("==============================================")
                print("")

            if "error" in low:

                print("[FFmpeg HATA]", line)

    except Exception as e:

        print("[UYARI] FFmpeg log okuma hatasi:")
        print(e)

    try:
        process.wait()
    except:
        pass

    print("")
    print("[UYARI] FFmpeg kapandi.")
    print("[SISTEM] {} saniye sonra tekrar denenecek.".format(
        RESTART_DELAY
    ))


# ============================================================
# ANA DONGU
# ============================================================

while True:

    now = datetime.now()

    # 03:00 - 04:00 arasi kapali
    if now.hour == 3:

        print("")
        print("[UYKU] 03:00 - 04:00 arasi yayin kapali.")
        print("[SAAT]", windows_time())

        time.sleep(60)

        continue

    start_stream()

    time.sleep(RESTART_DELAY)
