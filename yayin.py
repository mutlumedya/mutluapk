# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
import importlib.util
from datetime import datetime


# ============================================================
# OTOMATİK PYTHON PAKET KONTROLÜ
# ============================================================

def paket_kur(modul, paket=None):

    if paket is None:
        paket = modul

    if importlib.util.find_spec(modul) is not None:
        return True

    print(f"[PAKET] {paket} bulunamadı. Otomatik kuruluyor...")

    try:
        subprocess.check_call([
            sys.executable,
            "-m",
            "pip",
            "install",
            paket
        ])

        print(f"[OK] {paket} kuruldu.")
        return True

    except Exception as e:

        print(f"[HATA] {paket} kurulamadı: {e}")
        return False


if not paket_kur("requests"):

    input("Enter'a basarak çık...")
    sys.exit(1)


import requests


# ============================================================
# AYARLAR
# ============================================================

BASE_DIR = os.path.dirname(
    os.path.abspath(__file__)
)


# ============================================================
# YAYIN KAYNAĞI
# ============================================================

M3U8_URL = "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/cizgi-film-tv/playlist/0.m3u8"


# ============================================================
# RTMP SUNUCU
# ============================================================

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"


# ============================================================
# LOGO
#
# logo.png dosyasını .py dosyasının yanına koy.
# ============================================================

LOGO = os.path.join(
    BASE_DIR,
    "logo.png"
)


# ============================================================
# ÇÖZÜNÜRLÜK
# ============================================================

WIDTH = 1280
HEIGHT = 720


# ============================================================
# VIDEO
# ============================================================

VIDEO_BITRATE = "2500k"
MAXRATE = "2500k"
BUFSIZE = "5000k"


# ============================================================
# SES
# ============================================================

AUDIO_BITRATE = "128k"


# ============================================================
# KAYAN YAZI
# ============================================================

KAYAN_YAZI = (
    "ZEM TV HABER  •  "
    "Günün gelişmeleri  •  "
    "Son dakika haberleri  •  "
    "ZEM TV"
)


# ============================================================
# YAZI FONTU
# ============================================================

FONT = r"C:\Windows\Fonts\arial.ttf"


# ============================================================
# FFMPEG BUL
# ============================================================

def ffmpeg_bul():

    adaylar = [

        r"C:\ffmpeg\bin\ffmpeg.exe",

        r"C:\Program Files\ffmpeg\bin\ffmpeg.exe",

        r"C:\Program Files (x86)\ffmpeg\bin\ffmpeg.exe",

        os.path.join(BASE_DIR, "ffmpeg.exe"),

    ]


    # PATH
    try:

        result = subprocess.run(
            ["where", "ffmpeg"],
            capture_output=True,
            text=True
        )

        if result.returncode == 0:

            satirlar = result.stdout.splitlines()

            for satir in satirlar:

                yol = satir.strip()

                if os.path.isfile(yol):
                    return yol

    except Exception:
        pass


    for yol in adaylar:

        if os.path.isfile(yol):

            return yol


    return None


# ============================================================
# WINDOWS SAATİ
# ============================================================

def windows_saati():

    return datetime.now().strftime(
        "%d.%m.%Y  %H:%M:%S"
    )


# ============================================================
# WINDOWS SAATİNİ KONTROL ET
# ============================================================

def windows_saat_format():

    return datetime.now().strftime(
        "%H:%M:%S"
    )


# ============================================================
# FFMPEG FILTER ESCAPE
# ============================================================

def ffmpeg_escape(text):

    text = text.replace("\\", "\\\\")
    text = text.replace(":", "\\:")
    text = text.replace("'", "\\'")
    text = text.replace(",", "\\,")
    text = text.replace("[", "\\[")
    text = text.replace("]", "\\]")

    return text


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def ffmpeg_komutu(ffmpeg):

    # --------------------------------------------------------
    # FONT
    # --------------------------------------------------------

    font = FONT

    if not os.path.isfile(font):

        font = r"C:\Windows\Fonts\arial.ttf"


    font = font.replace("\\", "/")


    # --------------------------------------------------------
    # KAYAN YAZI
    # --------------------------------------------------------

    kayan = ffmpeg_escape(
        KAYAN_YAZI
    )


    # --------------------------------------------------------
    # FILTER
    # --------------------------------------------------------

    filtre = (

        # Video boyutlandır
        f"[0:v]"
        f"scale={WIDTH}:{HEIGHT}:"
        f"force_original_aspect_ratio=decrease,"
        f"pad={WIDTH}:{HEIGHT}:"
        f"(ow-iw)/2:(oh-ih)/2:black"
        f"[base];"


        # ----------------------------------------------------
        # LOGO
        # ----------------------------------------------------

        f"[1:v]"
        f"scale=150:-1"
        f"[logo];"


        f"[base][logo]"
        f"overlay=W-w-20:20"
        f"[logoout];"


        # ----------------------------------------------------
        # ÜST BAŞLIK
        # ----------------------------------------------------

        f"[logoout]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='ZEM TV HABER':"
        f"fontcolor=white:"
        f"fontsize=24:"
        f"box=1:"
        f"boxcolor=0x00000099:"
        f"boxborderw=8:"
        f"x=20:"
        f"y=20"
        f"[ust];"


        # ----------------------------------------------------
        # CANLI YAZISI
        # ----------------------------------------------------

        f"[ust]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='CANLI':"
        f"fontcolor=white:"
        f"fontsize=22:"
        f"box=1:"
        f"boxcolor=0xD0000099:"
        f"boxborderw=8:"
        f"x=20:"
        f"y=55"
        f"[canli];"


        # ----------------------------------------------------
        # WINDOWS SAATİ
        #
        # %{localtime} FFmpeg tarafından sistem saatinden
        # okunur.
        # ----------------------------------------------------

        f"[canli]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='%{{localtime\\:%H\\\\:%M\\\\:%S}}':"
        f"fontcolor=white:"
        f"fontsize=24:"
        f"box=1:"
        f"boxcolor=0x000000AA:"
        f"boxborderw=8:"
        f"x=W-tw-20:"
        f"y=HEIGHT-th-20"
        f"[saat];"


        # ----------------------------------------------------
        # ALT KAYAN BAR
        # ----------------------------------------------------

        f"[saat]"
        f"drawbox="
        f"x=0:"
        f"y=H-65:"
        f"w=W:"
        f"h=65:"
        f"color=0x000000CC:"
        f"t=fill"
        f"[bar];"


        # ----------------------------------------------------
        # KAYAN YAZI
        # ----------------------------------------------------

        f"[bar]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{kayan}':"
        f"fontcolor=white:"
        f"fontsize=25:"
        f"x='W-mod(180*t\\,(W+tw+180))':"
        f"y=H-48"
        f"[ticker]"
    )


    # ========================================================
    # KOMUT
    # ========================================================

    command = [

        ffmpeg,

        "-hide_banner",

        "-loglevel",
        "warning",


        # ----------------------------------------------------
        # HLS / HTTP KOPMA
        # ----------------------------------------------------

        "-reconnect",
        "1",

        "-reconnect_streamed",
        "1",

        "-reconnect_at_eof",
        "1",

        "-reconnect_delay_max",
        "10",


        # ----------------------------------------------------
        # HLS BUFFER
        # ----------------------------------------------------

        "-fflags",
        "+genpts",

        "-flags",
        "low_delay",


        # ----------------------------------------------------
        # M3U8
        # ----------------------------------------------------

        "-i",
        M3U8_URL,


        # ----------------------------------------------------
        # LOGO
        # ----------------------------------------------------

        "-loop",
        "1",

        "-i",
        LOGO,


        # ----------------------------------------------------
        # FILTER
        # ----------------------------------------------------

        "-filter_complex",
        filtre,


        # ----------------------------------------------------
        # VIDEO
        # ----------------------------------------------------

        "-map",
        "[ticker]",


        # ----------------------------------------------------
        # SES
        # ----------------------------------------------------

        "-map",
        "0:a?",


        # ----------------------------------------------------
        # CODEC
        # ----------------------------------------------------

        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-tune",
        "zerolatency",

        "-pix_fmt",
        "yuv420p",


        # ----------------------------------------------------
        # BITRATE
        # ----------------------------------------------------

        "-b:v",
        VIDEO_BITRATE,

        "-maxrate",
        MAXRATE,

        "-bufsize",
        BUFSIZE,


        # ----------------------------------------------------
        # KEYFRAME
        # ----------------------------------------------------

        "-g",
        "50",

        "-keyint_min",
        "50",

        "-sc_threshold",
        "0",


        # ----------------------------------------------------
        # SES
        # ----------------------------------------------------

        "-c:a",
        "aac",

        "-b:a",
        AUDIO_BITRATE,

        "-ar",
        "44100",

        "-ac",
        "2",


        # ----------------------------------------------------
        # THREAD
        # ----------------------------------------------------

        "-threads",
        "2",


        # ----------------------------------------------------
        # FLV
        # ----------------------------------------------------

        "-f",
        "flv",

        RTMP_URL

    ]


    return command


# ============================================================
# LOGO OLUŞTUR / KONTROL
# ============================================================

def logo_kontrol():

    if os.path.isfile(LOGO):

        print(
            f"[OK] Logo bulundu: {LOGO}"
        )

        return True


    print(
        "[UYARI] logo.png bulunamadı."
    )

    print(
        "Logo olmadan devam edilemiyor."
    )

    return False


# ============================================================
# YAYINI ÇALIŞTIR
# ============================================================

def yayin_baslat(ffmpeg):

    while True:

        print("")
        print("=" * 70)

        print(
            "[ZEM TV] Yayın başlatılıyor..."
        )

        print(
            "[ZEM TV] Windows saati:",
            windows_saati()
        )

        print(
            "[ZEM TV] Kaynak:",
            M3U8_URL
        )

        print(
            "[ZEM TV] RTMP:",
            RTMP_URL
        )

        print("=" * 70)


        komut = ffmpeg_komutu(
            ffmpeg
        )


        try:

            process = subprocess.Popen(

                komut,

                stdout=subprocess.DEVNULL,

                stderr=subprocess.PIPE,

                text=True,

                encoding="utf-8",

                errors="replace",

                creationflags=(
                    subprocess.CREATE_NO_WINDOW
                    if os.name == "nt"
                    else 0
                )

            )


            # ------------------------------------------------
            # FFMPEG HATALARINI OKU
            # ------------------------------------------------

            while True:

                satir = process.stderr.readline()


                if satir:

                    satir = satir.strip()

                    if satir:

                        print(
                            "[FFmpeg]",
                            satir
                        )


                if process.poll() is not None:

                    break


                time.sleep(0.1)


            kod = process.returncode


            print("")
            print(
                f"[UYARI] FFmpeg kapandı. Kod: {kod}"
            )

            print(
                "[UYARI] 5 saniye sonra yeniden başlatılıyor..."
            )


        except KeyboardInterrupt:

            print("")
            print(
                "[ZEM TV] Yayın kapatılıyor..."
            )

            try:
                process.terminate()
            except Exception:
                pass

            break


        except Exception as e:

            print(
                "[HATA]",
                e
            )


        time.sleep(5)


# ============================================================
# ANA PROGRAM
# ============================================================

def main():

    print("")
    print("=" * 70)

    print(
        "              ZEM TV TEKLİ YAYIN SİSTEMİ"
    )

    print("=" * 70)


    # --------------------------------------------------------
    # WINDOWS SAATİ
    # --------------------------------------------------------

    print(
        "[SİSTEM] Windows saati:",
        windows_saati()
    )


    # --------------------------------------------------------
    # FFMPEG
    # --------------------------------------------------------

    ffmpeg = ffmpeg_bul()


    if not ffmpeg:

        print("")
        print(
            "[HATA] FFmpeg bulunamadı!"
        )

        print(
            "FFmpeg'i C:\\ffmpeg\\bin\\ffmpeg.exe"
        )

        print(
            "konumuna koyabilirsiniz."
        )

        input(
            "\nEnter'a basarak çık..."
        )

        return


    print(
        "[OK] FFmpeg:",
        ffmpeg
    )


    # --------------------------------------------------------
    # LOGO
    # --------------------------------------------------------

    if not logo_kontrol():

        print(
            "[BİLGİ] Logo bulunamadığı için logosuz devam ediliyor."
        )


        # Logo yoksa boş bir logo girişi kullanmak yerine
        # filtreyi logosuz oluşturmak gerekir.
        # Bu nedenle logo oluşturulur.

        try:

            from PIL import Image

        except Exception:

            print(
                "[BİLGİ] Logo dosyası bulunamadı."
            )

    # --------------------------------------------------------
    # TEST
    # --------------------------------------------------------

    try:

        subprocess.run(
            [
                ffmpeg,
                "-version"
            ],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=True
        )

        print(
            "[OK] FFmpeg çalışıyor."
        )


    except Exception as e:

        print(
            "[HATA] FFmpeg test edilemedi:",
            e
        )

        return


    # --------------------------------------------------------
    # YAYIN
    # --------------------------------------------------------

    yayin_baslat(
        ffmpeg
    )


# ============================================================
# BAŞLAT
# ============================================================

if __name__ == "__main__":

    main()
