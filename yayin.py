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
# M3U8 -> FFmpeg -> RTMP
# LOGO + SAAT + BILGI + KAYAN YAZI
# ============================================================

M3U8_URL = (
    "https://playlist.fasttvcdn.com/pl/"
    "rfrk9821hdy9dayo8wfyha/"
    "cizgi-film-tv/playlist/0.m3u8"
)

RTMP_URL = (
    "rtmp://ssh101.bozztv.com:1935/"
    "ssh101/zemtvcocuk"
)

LOGO_URL = (
    "https://raw.githubusercontent.com/"
    "mutlumedya/cine/refs/heads/main/telegram.png"
)

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"
FONT = r"C:\Windows\Fonts\arial.ttf"

BASE = Path(__file__).resolve().parent

LOGO = BASE / "zemtv_logo.png"
CLOCK = BASE / "clock.txt"
INFO = BASE / "info.txt"
TICKER = BASE / "ticker.txt"

WIDTH = 1280
HEIGHT = 720
FPS = 25

# ============================================================
# YARDIMCI
# ============================================================

def yaz(mesaj):
    saat = datetime.now().strftime("%d.%m.%Y %H:%M:%S")
    print(f"[{saat}] {mesaj}", flush=True)


def dosya_yaz(dosya, metin):
    gecici = Path(str(dosya) + ".tmp")

    try:
        with open(gecici, "w", encoding="utf-8") as f:
            f.write(metin)

        os.replace(gecici, dosya)

    except Exception as e:
        print("Dosya yazma hatasi:", e)


def overlay_guncelle():

    simdi = datetime.now()

    saat = simdi.strftime("%H:%M:%S")
    tarih = simdi.strftime("%d.%m.%Y")

    dosya_yaz(
        CLOCK,
        saat
    )

    dosya_yaz(
        INFO,
        "ZEM TV COCUK  |  CANLI YAYIN  |  " + tarih
    )

    dosya_yaz(
        TICKER,
        "ZEM TV COCUK  |  Keyifli seyirler  |  "
        "ZEM MEDYA  |  Turkiye'nin dijital yayini"
    )


# ============================================================
# LOGO
# ============================================================

def logo_indir():

    if LOGO.exists():
        try:
            if LOGO.stat().st_size > 1000:
                yaz("Logo mevcut.")
                return
        except:
            pass

    yaz("Logo indiriliyor...")

    try:

        req = urllib.request.Request(
            LOGO_URL,
            headers={
                "User-Agent": "Mozilla/5.0"
            }
        )

        with urllib.request.urlopen(req, timeout=30) as cevap:

            veri = cevap.read()

        if len(veri) < 1000:
            raise Exception("Logo dosyasi gecersiz.")

        with open(LOGO, "wb") as f:
            f.write(veri)

        yaz("Logo indirildi.")

    except Exception as e:

        yaz("Logo indirilemedi: " + str(e))

        # Logo olmazsa bos PNG olusturulamaz.
        # Bu nedenle mevcut logo yoksa dur.
        sys.exit(1)


# ============================================================
# FFmpeg KONTROL
# ============================================================

def kontrol():

    if not os.path.isfile(FFMPEG):

        print("")
        print("FFmpeg bulunamadi:")
        print(FFMPEG)
        print("")

        sys.exit(1)

    if not os.path.isfile(FONT):

        print("")
        print("Arial font bulunamadi:")
        print(FONT)
        print("")

        sys.exit(1)


# ============================================================
# FILTER
# ============================================================

def filter_olustur():

    logo = str(LOGO).replace("\\", "/")
    font = str(FONT).replace("\\", "/")
    clock = str(CLOCK).replace("\\", "/")
    info = str(INFO).replace("\\", "/")
    ticker = str(TICKER).replace("\\", "/")

    # Windows C: isaretlerini FFmpeg filter icin kacir
    logo = logo.replace(":", "\\:")
    font = font.replace(":", "\\:")
    clock = clock.replace(":", "\\:")
    info = info.replace(":", "\\:")
    ticker = ticker.replace(":", "\\:")

    # ========================================================
    # DIKKAT:
    # W / H KULLANILMIYOR.
    # SENIN FFmpeg SURUMUNDE DRAWBOX BUNU HATA VERIYORDU.
    # ========================================================

    filtre = (

        # ----------------------------------------------------
        # LOGO
        # ----------------------------------------------------

        "[1:v]"
        "scale=150:-1,"
        "format=rgba"
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
        "box=1:"
        "boxcolor=black@0.65:"
        "boxborderw=8:"
        "x=20:"
        "y=20"
        "[v2];"

        # ----------------------------------------------------
        # BILGI BAR
        # 720 - 106 = 614
        # ----------------------------------------------------

        "[v2]"
        "drawbox="
        "x=0:"
        "y=614:"
        "w=1280:"
        "h=58:"
        "color=black@0.78:"
        "t=fill"
        "[v3];"

        # ----------------------------------------------------
        # BILGI YAZISI
        # ----------------------------------------------------

        "[v3]"
        "drawtext="
        f"fontfile='{font}':"
        f"textfile='{info}':"
        "reload=1:"
        "fontsize=24:"
        "fontcolor=white:"
        "x=25:"
        "y=630"
        "[v4];"

        # ----------------------------------------------------
        # KAYAN YAZI BAR
        # 720 - 48 = 672
        # ----------------------------------------------------

        "[v4]"
        "drawbox="
        "x=0:"
        "y=672:"
        "w=1280:"
        "h=48:"
        "color=black@0.92:"
        "t=fill"
        "[v5];"

        # ----------------------------------------------------
        # KAYAN YAZI
        #
        # 1280 sabit kullaniliyor.
        # W KULLANILMIYOR.
        # ----------------------------------------------------

        "[v5]"
        "drawtext="
        f"fontfile='{font}':"
        f"textfile='{ticker}':"
        "reload=1:"
        "fontsize=25:"
        "fontcolor=white:"
        "x=mod(1280-n*0.0-t*150\\,1600)-tw:"
        "y=684"
        "[vout]"
    )

    return filtre


# ============================================================
# FFmpeg KOMUTU
# ============================================================

def komut():

    filtre = filter_olustur()

    return [

        FFMPEG,

        "-hide_banner",

        # ----------------------------------------------------
        # M3U8 BAGLANTI
        # ----------------------------------------------------

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

        # ----------------------------------------------------
        # LOGO
        # ----------------------------------------------------

        "-loop",
        "1",

        "-i",
        str(LOGO),

        # ----------------------------------------------------
        # FILTRE
        # ----------------------------------------------------

        "-filter_complex",
        filtre,

        # ----------------------------------------------------
        # VIDEO
        # ----------------------------------------------------

        "-map",
        "[vout]",

        # ----------------------------------------------------
        # SES
        # ----------------------------------------------------

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
        str(FPS),

        "-s",
        "1280x720",

        "-b:v",
        "3000k",

        "-maxrate",
        "3500k",

        "-bufsize",
        "6000k",

        "-g",
        "50",

        "-keyint_min",
        "50",

        "-sc_threshold",
        "0",

        # ----------------------------------------------------
        # AUDIO
        # ----------------------------------------------------

        "-c:a",
        "aac",

        "-b:a",
        "128k",

        "-ar",
        "48000",

        "-ac",
        "2",

        # ----------------------------------------------------
        # RTMP
        # ----------------------------------------------------

        "-f",
        "flv",

        RTMP_URL
    ]


# ============================================================
# YAYIN
# ============================================================

def yayin_baslat():

    cmd = komut()

    print("")
    print("================================================")
    print("             ZEM TV COCUK")
    print("================================================")
    print("LOGO       : AKTIF")
    print("SAAT       : AKTIF")
    print("BILGI BAR  : AKTIF")
    print("KAYAN YAZI : AKTIF")
    print("================================================")
    print("")
    print("M3U8:")
    print(M3U8_URL)
    print("")
    print("RTMP:")
    print(RTMP_URL)
    print("")
    print("FFmpeg baslatiliyor...")
    print("")

    try:

        p = subprocess.Popen(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            stdin=subprocess.DEVNULL,
            text=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1
        )

        son_guncelleme = 0

        while True:

            satir = p.stdout.readline()

            if satir:
                print(satir.rstrip(), flush=True)

            simdi = time.time()

            if simdi - son_guncelleme >= 1:

                overlay_guncelle()

                son_guncelleme = simdi

            if p.poll() is not None:
                break

        return p.returncode

    except KeyboardInterrupt:

        yaz("Yayin durduruldu.")

        try:
            p.terminate()
        except:
            pass

        return 0

    except Exception as e:

        yaz("Calistirma hatasi: " + str(e))

        return -1


# ============================================================
# ANA
# ============================================================

def main():

    print("")
    print("ZEM TV COCUK YAYIN SISTEMI")
    print("")

    kontrol()

    logo_indir()

    overlay_guncelle()

    while True:

        kod = yayin_baslat()

        if kod == 0:
            break

        yaz(
            "FFmpeg kapandi. "
            "5 saniye sonra yeniden baglanilacak."
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

        overlay_guncelle()


if __name__ == "__main__":
    main()
