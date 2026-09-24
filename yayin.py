# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
from datetime import datetime

# ============================================================
# ZEM TV COCUK
# WINDOWS SERVER 2022
# ============================================================

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

SOURCE = "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/cizgi-film-tv/playlist/0.m3u8"

RTMP = "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"

RESTART_DELAY = 5

VIDEO_BITRATE = "2000k"
AUDIO_BITRATE = "96k"

# ============================================================
# BASLIK
# ============================================================

os.system("title ZEM TV COCUK - CANLI YAYIN")

print("")
print("==============================================")
print("             ZEM TV COCUK")
print("             CANLI YAYIN")
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

print("[OK] FFmpeg bulundu:")
print(FFMPEG)

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

    print("[HATA] FFmpeg kontrol hatasi:")
    print(e)

    input("Enter'a basin...")
    sys.exit(1)


# ============================================================
# SAAT
# ============================================================

def saat():

    return datetime.now().strftime("%d.%m.%Y %H:%M:%S")


# ============================================================
# YAYIN BASLAT
# ============================================================

def yayin_baslat():

    print("")
    print("==============================================")
    print("           YAYIN BASLATILIYOR")
    print("==============================================")
    print("")

    print("[SAAT]", saat())
    print("[M3U8]", SOURCE)
    print("[RTMP]", RTMP)
    print("")

    # ========================================================
    # SADE VIDEO FILTRE
    #
    # FONT DOSYASI YOK
    # FILTER_COMPLEX YOK
    # LOGO YOK
    # ========================================================

    VIDEO_FILTER = (
        "scale=1280:720,"
        "drawbox=x=0:y=0:w=1280:h=55:color=black@0.70:t=fill,"
        "drawtext=text='ZEM TV COCUK':fontcolor=white:fontsize=25:x=25:y=14,"
        "drawtext=text='CANLI':fontcolor=red:fontsize=23:x=235:y=15,"
        "drawbox=x=0:y=650:w=1280:h=70:color=black@0.85:t=fill,"
        "drawtext=text='ZEM TV COCUK':fontcolor=red:fontsize=23:x=20:y=663,"
        "drawtext=text='CIZGI FILM - CANLI YAYIN':fontcolor=white:fontsize=21:x=230:y=664"
    )

    # ========================================================
    # FFMPEG
    # ========================================================

    command = [

        FFMPEG,

        "-hide_banner",

        "-loglevel",
        "info",

        # HLS tekrar baglanma
        "-reconnect",
        "1",

        "-reconnect_streamed",
        "1",

        "-reconnect_delay_max",
        "10",

        # User Agent
        "-user_agent",
        "Mozilla/5.0",

        # Kaynak
        "-i",
        SOURCE,

        # Video filtre
        "-vf",
        VIDEO_FILTER,

        # Video
        "-map",
        "0:v:0",

        # Ses varsa al
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

        # Ses
        "-c:a",
        "aac",

        "-b:a",
        AUDIO_BITRATE,

        "-ar",
        "44100",

        "-ac",
        "2",

        # RTMP
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

    # ========================================================
    # FFmpeg LOG
    # ========================================================

    try:

        for line in process.stdout:

            line = line.rstrip()

            if not line:
                continue

            print("[FFmpeg]", line)

            low = line.lower()

            # Video frame tespit
            if "frame=" in low and not aktif:

                aktif = True

                print("")
                print("==============================================")
                print("          [✓] VIDEO KARELERI AKIYOR")
                print("          [✓] YAYIN AKTIF")
                print("          [✓] ZEM TV COCUK")
                print("==============================================")
                print("")

            # RTMP baglantisi
            if "flv" in low and "opening" in low:

                print("[✓] RTMP CIKISI ACILIYOR...")

            # Hata
            if "error" in low:

                print("[FFmpeg HATA]", line)

    except Exception as e:

        print("[UYARI] FFmpeg log hatasi:")
        print(e)

    try:

        process.wait()

    except:

        pass

    print("")
    print("==============================================")
    print("[UYARI] FFmpeg kapandi.")
    print("[SISTEM] {} saniye sonra yeniden baslatilacak.".format(
        RESTART_DELAY
    ))
    print("==============================================")


# ============================================================
# ANA DONGU
# ============================================================

while True:

    now = datetime.now()

    # 03:00 - 04:00 yayin kapali
    if now.hour == 3:

        print("")
        print("[UYKU] 03:00 - 04:00 arasi yayin kapali.")
        print("[SAAT]", saat())

        time.sleep(60)

        continue

    yayin_baslat()

    time.sleep(RESTART_DELAY)
