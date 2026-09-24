# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
import urllib.request
from datetime import datetime

# ============================================================
# ZEM TV HABER - TEK YAYIN
# Windows Server 2022
# ============================================================

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

SOURCE = "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/cizgi-film-tv/playlist/0.m3u8"

RTMP = "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"

LOGO = os.path.join(os.path.dirname(os.path.abspath(__file__)), "logo.png")

WIDTH = 1280
HEIGHT = 720

VIDEO_BITRATE = "2000k"
AUDIO_BITRATE = "96k"

RESTART_DELAY = 5

# ============================================================
# RENKLER
# ============================================================

WHITE = "white"
RED = "red"
BLACK = "black"
YELLOW = "yellow"

# ============================================================
# BAŞLANGIÇ
# ============================================================

os.system("title ZEM TV HABER - CANLI YAYIN")

print("")
print("==============================================")
print("          ZEM TV HABER")
print("          CANLI YAYIN SISTEMI")
print("==============================================")
print("")

# ============================================================
# FFmpeg KONTROL
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
        print("[HATA] FFmpeg baslatilamadi.")
        print(test.stderr)
        input("Enter'a basin...")
        sys.exit(1)

    first_line = test.stdout.splitlines()[0] if test.stdout else "FFmpeg"
    print("[OK]", first_line)

except Exception as e:
    print("[HATA] FFmpeg kontrol hatasi:", e)
    input("Enter'a basin...")
    sys.exit(1)

# ============================================================
# LOGO KONTROL
# ============================================================

if os.path.isfile(LOGO):
    print("[OK] Logo bulundu:")
    print(LOGO)
else:
    print("[UYARI] logo.png bulunamadi.")
    print("Logo olmadan devam edilecek.")

# ============================================================
# WINDOWS SAATI
# ============================================================

print("[OK] Windows saati:", datetime.now().strftime("%d.%m.%Y %H:%M:%S"))

# ============================================================
# M3U8 KONTROL
# ============================================================

print("")
print("[KONTROL] M3U8 kaynagi kontrol ediliyor...")
print(SOURCE)

try:
    req = urllib.request.Request(
        SOURCE,
        headers={
            "User-Agent": "Mozilla/5.0"
        }
    )

    with urllib.request.urlopen(req, timeout=15) as response:
        data = response.read(2048)

    if data:
        print("[OK] M3U8 kaynagina ulasildi.")
    else:
        print("[UYARI] M3U8 bos cevap verdi.")

except Exception as e:
    print("[UYARI] M3U8 kontrolu basarisiz:")
    print(str(e))
    print("[BILGI] FFmpeg yine de yayini baslatmayi deneyecek.")

# ============================================================
# FFmpeg FILTER
# ============================================================

def build_filter():

    filters = []

    # --------------------------------------------------------
    # Logo
    # --------------------------------------------------------

    if os.path.isfile(LOGO):

        logo_path = LOGO.replace("\\", "/")
        logo_path = logo_path.replace(":", "\\:")

        filters.append(
            "movie='{}'[logo]".format(logo_path)
        )

        filters.append(
            "[logo]scale=150:-1[lg]"
        )

        filters.append(
            "[0:v][lg]overlay=W-w-25:25[v1]"
        )

        current = "[v1]"

    else:
        current = "[0:v]"

    # --------------------------------------------------------
    # ZEM TV HABER
    # --------------------------------------------------------

    filters.append(
        "{}drawtext="
        "fontfile='C\\:/Windows/Fonts/arial.ttf':"
        "text='ZEM TV HABER':"
        "fontcolor=white:"
        "fontsize=25:"
        "box=1:"
        "boxcolor=black@0.65:"
        "boxborderw=8:"
        "x=25:"
        "y=25"
        "[v2]".format(current)
    )

    # --------------------------------------------------------
    # CANLI
    # --------------------------------------------------------

    filters.append(
        "[v2]drawtext="
        "fontfile='C\\:/Windows/Fonts/arial.ttf':"
        "text='CANLI':"
        "fontcolor=red:"
        "fontsize=22:"
        "box=1:"
        "boxcolor=black@0.70:"
        "boxborderw=7:"
        "x=25:"
        "y=72"
        "[v3]"
    )

    # --------------------------------------------------------
    # WINDOWS SAATI
    # --------------------------------------------------------

    filters.append(
        "[v3]drawtext="
        "fontfile='C\\:/Windows/Fonts/arial.ttf':"
        "text='%{localtime\\:%d.%m.%Y %H\\\\:%M\\\\:%S}':"
        "fontcolor=white:"
        "fontsize=21:"
        "box=1:"
        "boxcolor=black@0.70:"
        "boxborderw=7:"
        "x=25:"
        "y=112"
        "[v4]"
    )

    # --------------------------------------------------------
    # ALT HABER BANDI
    # --------------------------------------------------------

    filters.append(
        "[v4]drawbox="
        "x=0:"
        "y=650:"
        "w=iw:"
        "h=70:"
        "color=black@0.80:"
        "t=fill"
        "[v5]"
    )

    # --------------------------------------------------------
    # ZEM TV
    # --------------------------------------------------------

    filters.append(
        "[v5]drawtext="
        "fontfile='C\\:/Windows/Fonts/arial.ttf':"
        "text='ZEM TV HABER':"
        "fontcolor=red:"
        "fontsize=24:"
        "x=25:"
        "y=663"
        "[v6]"
    )

    # --------------------------------------------------------
    # ALT YAZI
    # --------------------------------------------------------

    filters.append(
        "[v6]drawtext="
        "fontfile='C\\:/Windows/Fonts/arial.ttf':"
        "text='SON DAKIKA  |  ZEM TV HABER  |  CANLI YAYIN  |  Guncel haberler ve gelismeler':"
        "fontcolor=white:"
        "fontsize=21:"
        "x=230:"
        "y=665"
        "[vout]"
    )

    return ";".join(filters)

# ============================================================
# YAYIN BASLAT
# ============================================================

def start_stream():

    print("")
    print("==============================================")
    print("           YAYIN BASLATILIYOR")
    print("==============================================")
    print("")
    print("[KAYNAK]")
    print(SOURCE)
    print("")
    print("[RTMP]")
    print(RTMP)
    print("")
    print("[VIDEO] 1280x720")
    print("[VIDEO BITRATE]", VIDEO_BITRATE)
    print("[AUDIO BITRATE]", AUDIO_BITRATE)
    print("")

    filter_complex = build_filter()

    command = [
        FFMPEG,

        "-hide_banner",

        "-reconnect", "1",
        "-reconnect_streamed", "1",
        "-reconnect_delay_max", "5",

        "-user_agent",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 Chrome/140.0 Safari/537.36",

        "-i",
        SOURCE,

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

        "-s",
        "1280x720",

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

        "-f",
        "flv",

        RTMP
    ]

    print("[FFmpeg] Baslatiliyor...")
    print("")
    print("[✓] VIDEO AKISI BEKLENIYOR...")
    print("[✓] SES AKISI BEKLENIYOR...")
    print("[✓] RTMP BAGLANTISI BEKLENIYOR...")
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

        print("")
        print("[HATA] FFmpeg baslatilamadi:")
        print(e)
        return

    started = False

    try:

        for line in process.stdout:

            line = line.rstrip()

            if not line:
                continue

            lower = line.lower()

            # FFmpeg loglarini goster
            print("[FFmpeg]", line)

            if "frame=" in lower and not started:
                started = True

                print("")
                print("==============================================")
                print("[✓] VIDEO KARELERI AKIYOR")
                print("[✓] YAYIN AKTIF")
                print("==============================================")
                print("")

    except Exception as e:

        print("[UYARI] FFmpeg okuma hatasi:", e)

    finally:

        try:
            process.wait()
        except:
            pass

    print("")
    print("==============================================")
    print("[UYARI] FFmpeg kapandi.")
    print("==============================================")

    return process.returncode

# ============================================================
# ANA DONGU
# ============================================================

while True:

    now = datetime.now()

    # 03:00 - 04:00 arasi yayin kapali
    if now.hour == 3:

        print("")
        print("[UYKU] 03:00-04:00 arasi yayin kapali.")
        print("[BILGI] Windows saati:",
              now.strftime("%d.%m.%Y %H:%M:%S"))

        time.sleep(60)
        continue

    print("")
    print("[SISTEM] Windows saati:",
          now.strftime("%d.%m.%Y %H:%M:%S"))

    result = start_stream()

    print("")
    print("[SISTEM] {} saniye sonra tekrar denenecek.".format(
        RESTART_DELAY
    ))

    time.sleep(RESTART_DELAY)
