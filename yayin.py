# -*- coding: utf-8 -*-

import os
import sys
import time
import subprocess
import importlib.util
import threading
from datetime import datetime


# ============================================================
# ZEM TV TEKLİ YAYIN SİSTEMİ
# ============================================================
#
# ÖZELLİKLER
#
# ✓ M3U8 → RTMP
# ✓ Windows sistem saati
# ✓ Sağ üst logo
# ✓ Üst başlık
# ✓ CANLI yazısı
# ✓ Alt kayan yazı
# ✓ FFmpeg otomatik bulunur
# ✓ Requests otomatik kurulur
# ✓ FFmpeg durumunu gösterir
# ✓ Video / ses akışını kontrol eder
# ✓ Yayın koparsa otomatik yeniden başlatır
#
# ============================================================


# ============================================================
# AYARLAR
# ============================================================

BASE_DIR = os.path.dirname(
    os.path.abspath(__file__)
)


# ------------------------------------------------------------
# M3U8 KAYNAĞI
# ------------------------------------------------------------

M3U8_URL = (
    "https://playlist.fasttvcdn.com/pl/rfrk9821hdy9dayo8wfyha/cizgi-film-tv/playlist/0.m3u8"
)


# ------------------------------------------------------------
# RTMP ÇIKIŞI
# ------------------------------------------------------------

RTMP_URL = (
    "rtmp://ssh101.bozztv.com:1935/ssh101/zemtvcocuk"
)


# ------------------------------------------------------------
# LOGO
# ------------------------------------------------------------
#
# logo.png dosyasını yayin.py dosyasının yanına koy.
#

LOGO_FILE = os.path.join(
    BASE_DIR,
    "logo.png"
)


# ------------------------------------------------------------
# ÇÖZÜNÜRLÜK
# ------------------------------------------------------------

WIDTH = 1280
HEIGHT = 720


# ------------------------------------------------------------
# VIDEO
# ------------------------------------------------------------

VIDEO_BITRATE = "2500k"
MAXRATE = "2500k"
BUFSIZE = "5000k"


# ------------------------------------------------------------
# SES
# ------------------------------------------------------------

AUDIO_BITRATE = "128k"


# ------------------------------------------------------------
# KAYAN YAZI
# ------------------------------------------------------------

TICKER_TEXT = (
    "ZEM TV HABER  •  "
    "Günün gelişmeleri  •  "
    "Son dakika haberleri  •  "
    "ZEM TV"
)


# ------------------------------------------------------------
# YAZI FONTU
# ------------------------------------------------------------

FONT_FILE = r"C:\Windows\Fonts\arial.ttf"


# ------------------------------------------------------------
# LOGO BOYUTU
# ------------------------------------------------------------

LOGO_WIDTH = 155


# ------------------------------------------------------------
# RESTART
# ------------------------------------------------------------

RESTART_DELAY = 5


# ============================================================
# RENKLER
# ============================================================

RED = "\033[91m"
GREEN = "\033[92m"
YELLOW = "\033[93m"
BLUE = "\033[94m"
CYAN = "\033[96m"
WHITE = "\033[97m"
RESET = "\033[0m"


def log(color, text):

    print(
        f"{color}{text}{RESET}",
        flush=True
    )


# ============================================================
# PYTHON PAKETİ OTOMATİK KUR
# ============================================================

def install_package(module_name, package_name=None):

    if package_name is None:
        package_name = module_name


    if importlib.util.find_spec(
        module_name
    ) is not None:

        return True


    log(
        YELLOW,
        f"[PAKET] {package_name} bulunamadı."
    )

    log(
        YELLOW,
        f"[PAKET] {package_name} otomatik kuruluyor..."
    )


    try:

        subprocess.check_call(
            [
                sys.executable,
                "-m",
                "pip",
                "install",
                package_name
            ]
        )


        log(
            GREEN,
            f"[OK] {package_name} kuruldu."
        )

        return True


    except Exception as e:

        log(
            RED,
            f"[HATA] {package_name} kurulamadı: {e}"
        )

        return False


# ============================================================
# REQUESTS
# ============================================================

if not install_package(
    "requests"
):

    input(
        "\nEnter'a basarak çık..."
    )

    sys.exit(1)


import requests


# ============================================================
# WINDOWS SAATİ
# ============================================================

def windows_saati():

    return datetime.now().strftime(
        "%d.%m.%Y %H:%M:%S"
    )


# ============================================================
# FFMPEG BUL
# ============================================================

def find_ffmpeg():

    # --------------------------------------------------------
    # PATH
    # --------------------------------------------------------

    try:

        result = subprocess.run(
            ["where", "ffmpeg"],
            capture_output=True,
            text=True,
            timeout=5
        )


        if result.returncode == 0:

            for line in result.stdout.splitlines():

                path = line.strip()

                if os.path.isfile(path):

                    return path


    except Exception:

        pass


    # --------------------------------------------------------
    # WINDOWS YOLLARI
    # --------------------------------------------------------

    candidates = [

        r"C:\ffmpeg\bin\ffmpeg.exe",

        r"C:\Program Files\ffmpeg\bin\ffmpeg.exe",

        r"C:\Program Files (x86)\ffmpeg\bin\ffmpeg.exe",

        os.path.join(
            BASE_DIR,
            "ffmpeg.exe"
        ),

        os.path.join(
            BASE_DIR,
            "bin",
            "ffmpeg.exe"
        )

    ]


    for path in candidates:

        if os.path.isfile(path):

            return path


    return None


# ============================================================
# FFMPEG TEST
# ============================================================

def test_ffmpeg(ffmpeg):

    try:

        result = subprocess.run(

            [
                ffmpeg,
                "-version"
            ],

            stdout=subprocess.PIPE,

            stderr=subprocess.PIPE,

            text=True,

            timeout=10

        )


        if result.returncode == 0:

            return True


    except Exception:

        pass


    return False


# ============================================================
# DOSYA KONTROL
# ============================================================

def check_files():

    if not os.path.isfile(
        LOGO_FILE
    ):

        log(
            YELLOW,
            "[UYARI] logo.png bulunamadı."
        )

        log(
            YELLOW,
            "[BİLGİ] Logo olmadan yayın devam edecek."
        )

        return False


    log(
        GREEN,
        f"[OK] Logo bulundu: {LOGO_FILE}"
    )

    return True


# ============================================================
# FFMPEG TEXT ESCAPE
# ============================================================

def escape_text(text):

    text = str(text)

    text = text.replace(
        "\\",
        "\\\\"
    )

    text = text.replace(
        ":",
        "\\:"
    )

    text = text.replace(
        "'",
        "\\'"
    )

    text = text.replace(
        ",",
        "\\,"
    )

    text = text.replace(
        "[",
        "\\["
    )

    text = text.replace(
        "]",
        "\\]"
    )

    return text


# ============================================================
# FFMPEG FONT YOLU
# ============================================================

def get_font():

    if os.path.isfile(
        FONT_FILE
    ):

        return FONT_FILE.replace(
            "\\",
            "/"
        )


    return (
        "C:/Windows/Fonts/arial.ttf"
    )


# ============================================================
# FILTER
# ============================================================

def create_filter(
    logo_enabled
):

    font = get_font()

    ticker = escape_text(
        TICKER_TEXT
    )


    # ========================================================
    # LOGOLU
    # ========================================================

    if logo_enabled:

        filter_text = (

            # VIDEO BOYUTU
            f"[0:v]"
            f"scale={WIDTH}:{HEIGHT}:"
            f"force_original_aspect_ratio=decrease,"
            f"pad={WIDTH}:{HEIGHT}:"
            f"(ow-iw)/2:(oh-ih)/2:black"
            f"[base];"


            # LOGO
            f"[1:v]"
            f"scale={LOGO_WIDTH}:-1"
            f"[logo];"


            # LOGO SAĞ ÜST
            f"[base][logo]"
            f"overlay=W-w-20:20"
            f"[logoout];"


            # ZEM TV HABER
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
            f"[title];"


            # CANLI
            f"[title]"
            f"drawtext="
            f"fontfile='{font}':"
            f"text='CANLI':"
            f"fontcolor=white:"
            f"fontsize=21:"
            f"box=1:"
            f"boxcolor=0xD00000DD:"
            f"boxborderw=7:"
            f"x=20:"
            f"y=57"
            f"[live];"


            # ALT BAR
            f"[live]"
            f"drawbox="
            f"x=0:"
            f"y=H-68:"
            f"w=W:"
            f"h=68:"
            f"color=black@0.78:"
            f"t=fill"
            f"[bar];"


            # KAYAN YAZI
            f"[bar]"
            f"drawtext="
            f"fontfile='{font}':"
            f"text='{ticker}':"
            f"fontcolor=white:"
            f"fontsize=25:"
            f"x='W-mod(220*t\\,(W+tw+220))':"
            f"y=H-48"
            f"[ticker];"


            # WINDOWS SAATİ
            f"[ticker]"
            f"drawtext="
            f"fontfile='{font}':"
            f"text='%{{localtime\\:%H\\\\:%M\\\\:%S}}':"
            f"fontcolor=white:"
            f"fontsize=24:"
            f"box=1:"
            f"boxcolor=black@0.80:"
            f"boxborderw=7:"
            f"x=W-tw-20:"
            f"y=H-105"
            f"[out]"
        )


        return filter_text


    # ========================================================
    # LOGOSUZ
    # ========================================================

    filter_text = (

        f"[0:v]"
        f"scale={WIDTH}:{HEIGHT}:"
        f"force_original_aspect_ratio=decrease,"
        f"pad={WIDTH}:{HEIGHT}:"
        f"(ow-iw)/2:(oh-ih)/2:black"
        f"[base];"


        # BAŞLIK
        f"[base]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='ZEM TV HABER':"
        f"fontcolor=white:"
        f"fontsize=24:"
        f"box=1:"
        f"boxcolor=black@0.65:"
        f"boxborderw=8:"
        f"x=20:"
        f"y=20"
        f"[title];"


        # CANLI
        f"[title]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='CANLI':"
        f"fontcolor=white:"
        f"fontsize=21:"
        f"box=1:"
        f"boxcolor=0xD00000DD:"
        f"boxborderw=7:"
        f"x=20:"
        f"y=57"
        f"[live];"


        # ALT BAR
        f"[live]"
        f"drawbox="
        f"x=0:"
        f"y=H-68:"
        f"w=W:"
        f"h=68:"
        f"color=black@0.78:"
        f"t=fill"
        f"[bar];"


        # KAYAN YAZI
        f"[bar]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{ticker}':"
        f"fontcolor=white:"
        f"fontsize=25:"
        f"x='W-mod(220*t\\,(W+tw+220))':"
        f"y=H-48"
        f"[ticker];"


        # SAAT
        f"[ticker]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='%{{localtime\\:%H\\\\:%M\\\\:%S}}':"
        f"fontcolor=white:"
        f"fontsize=24:"
        f"box=1:"
        f"boxcolor=black@0.80:"
        f"boxborderw=7:"
        f"x=W-tw-20:"
        f"y=H-105"
        f"[out]"
    )


    return filter_text


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def build_command(
    ffmpeg,
    logo_enabled
):

    filter_complex = create_filter(
        logo_enabled
    )


    command = [

        ffmpeg,


        # ----------------------------------------------------
        # GENEL
        # ----------------------------------------------------

        "-hide_banner",

        "-loglevel",
        "info",


        # ----------------------------------------------------
        # HTTP / HLS
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
        # HLS
        # ----------------------------------------------------

        "-fflags",
        "+genpts",

        "-rw_timeout",
        "15000000",


        # ----------------------------------------------------
        # KAYNAK
        # ----------------------------------------------------

        "-i",
        M3U8_URL

    ]


    # --------------------------------------------------------
    # LOGO
    # --------------------------------------------------------

    if logo_enabled:

        command.extend([

            "-loop",
            "1",

            "-i",
            LOGO_FILE

        ])


    # --------------------------------------------------------
    # FILTER
    # --------------------------------------------------------

    command.extend([

        "-filter_complex",
        filter_complex,

        "-map",
        "[out]",

        "-map",
        "0:a?"

    ])


    # --------------------------------------------------------
    # VIDEO
    # --------------------------------------------------------

    command.extend([

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

    ])


    # --------------------------------------------------------
    # SES
    # --------------------------------------------------------

    command.extend([

        "-c:a",
        "aac",

        "-b:a",
        AUDIO_BITRATE,

        "-ar",
        "44100",

        "-ac",
        "2"

    ])


    # --------------------------------------------------------
    # RTMP
    # --------------------------------------------------------

    command.extend([

        "-f",
        "flv",

        RTMP_URL

    ])


    return command


# ============================================================
# FFMPEG ÇALIŞTIR
# ============================================================

def start_stream(
    ffmpeg,
    logo_enabled
):

    command = build_command(
        ffmpeg,
        logo_enabled
    )


    log(
        BLUE,
        ""
    )

    log(
        BLUE,
        "=" * 70
    )

    log(
        GREEN,
        "YAYIN BAŞLATILIYOR"
    )

    log(
        BLUE,
        f"Windows saati : {windows_saati()}"
    )

    log(
        BLUE,
        f"M3U8          : {M3U8_URL}"
    )

    log(
        BLUE,
        f"RTMP          : {RTMP_URL}"
    )

    log(
        BLUE,
        "=" * 70
    )


    try:

        process = subprocess.Popen(

            command,

            stdout=subprocess.PIPE,

            stderr=subprocess.STDOUT,

            text=True,

            encoding="utf-8",

            errors="replace",

            bufsize=1,

            creationflags=(
                subprocess.CREATE_NO_WINDOW
                if os.name == "nt"
                else 0
            )

        )


    except Exception as e:

        log(
            RED,
            f"[HATA] FFmpeg başlatılamadı: {e}"
        )

        return False


    # ========================================================
    # DURUM DEĞİŞKENLERİ
    # ========================================================

    video_found = False
    audio_found = False
    output_found = False
    frame_found = False
    active_message = False

    start_time = time.time()


    # ========================================================
    # FFmpeg ÇIKTISI
    # ========================================================

    try:

        while True:

            line = process.stdout.readline()


            if line:

                line = line.strip()


                # --------------------------------------------
                # VIDEO
                # --------------------------------------------

                lower = line.lower()


                if (
                    "video:" in lower
                    or
                    "stream #0:0" in lower
                    or
                    "h264" in lower
                ):

                    if not video_found:

                        video_found = True

                        log(
                            GREEN,
                            "[✓ VIDEO AKIŞI BULUNDU]"
                        )


                # --------------------------------------------
                # AUDIO
                # --------------------------------------------

                if (
                    "audio:" in lower
                    or
                    "aac" in lower
                ):

                    if not audio_found:

                        audio_found = True

                        log(
                            GREEN,
                            "[✓ SES AKIŞI BULUNDU]"
                        )


                # --------------------------------------------
                # RTMP OUTPUT
                # --------------------------------------------

                if (
                    "output #0" in lower
                    or
                    "flv" in lower
                ):

                    if not output_found:

                        output_found = True

                        log(
                            GREEN,
                            "[✓ RTMP ÇIKIŞI AÇILDI]"
                        )


                # --------------------------------------------
                # FRAME
                # --------------------------------------------

                if (
                    "frame=" in lower
                    or
                    "fps=" in lower
                ):

                    if not frame_found:

                        frame_found = True

                        log(
                            GREEN,
                            "[✓ VIDEO KARELERİ AKIYOR]"
                        )


                # --------------------------------------------
                # YAYIN AKTİF
                # --------------------------------------------

                if (
                    frame_found
                    and
                    output_found
                    and
                    not active_message
                ):

                    active_message = True

                    elapsed = int(
                        time.time() - start_time
                    )

                    log(
                        GREEN,
                        ""
                    )

                    log(
                        GREEN,
                        "=========================================="
                    )

                    log(
                        GREEN,
                        "       [✓ YAYIN AKTİF]"
                    )

                    log(
                        GREEN,
                        "       [✓ RTMP BAĞLANTISI AKTİF]"
                    )

                    log(
                        GREEN,
                        "       [✓ VIDEO AKIŞI AKTİF]"
                    )

                    log(
                        GREEN,
                        f"       [✓ ÇALIŞMA SÜRESİ: {elapsed} sn]"
                    )

                    log(
                        GREEN,
                        "=========================================="
                    )


                # --------------------------------------------
                # HATA
                # --------------------------------------------

                if (
                    "error" in lower
                    or
                    "failed" in lower
                    or
                    "invalid" in lower
                    or
                    "connection refused" in lower
                ):

                    log(
                        RED,
                        "[FFmpeg HATA] "
                        + line
                    )


                # --------------------------------------------
                # ÖNEMLİ FFmpeg MESAJLARI
                # --------------------------------------------

                elif (
                    "input #" in lower
                    or
                    "stream mapping" in lower
                    or
                    "opening" in lower
                    or
                    "frame=" in lower
                ):

                    print(
                        "[FFmpeg]",
                        line,
                        flush=True
                    )


            # ------------------------------------------------
            # PROCESS KAPANDI
            # ------------------------------------------------

            if process.poll() is not None:

                break


            time.sleep(
                0.05
            )


    except KeyboardInterrupt:

        log(
            YELLOW,
            "[BİLGİ] Yayın kapatılıyor..."
        )

        try:

            process.terminate()

        except Exception:

            pass

        return True


    return_code = process.returncode


    log(
        RED,
        ""
    )

    log(
        RED,
        "=========================================="
    )

    log(
        RED,
        f"[✗ YAYIN KAPANDI] FFmpeg kodu: {return_code}"
    )

    log(
        RED,
        "=========================================="
    )


    return False


# ============================================================
# ANA PROGRAM
# ============================================================

def main():

    log(
        CYAN,
        ""
    )

    log(
        CYAN,
        "======================================================"
    )

    log(
        CYAN,
        "              ZEM TV TEKLİ YAYIN"
    )

    log(
        CYAN,
        "======================================================"
    )


    # --------------------------------------------------------
    # WINDOWS SAATİ
    # --------------------------------------------------------

    log(
        BLUE,
        f"[SİSTEM] Windows saati: {windows_saati()}"
    )


    # --------------------------------------------------------
    # FFMPEG
    # --------------------------------------------------------

    ffmpeg = find_ffmpeg()


    if not ffmpeg:

        log(
            RED,
            "[HATA] FFmpeg bulunamadı!"
        )

        log(
            YELLOW,
            r"Beklenen konum: C:\ffmpeg\bin\ffmpeg.exe"
        )

        input(
            "\nEnter'a basarak çık..."
        )

        return


    log(
        GREEN,
        f"[OK] FFmpeg: {ffmpeg}"
    )


    # --------------------------------------------------------
    # FFMPEG TEST
    # --------------------------------------------------------

    if not test_ffmpeg(
        ffmpeg
    ):

        log(
            RED,
            "[HATA] FFmpeg çalıştırılamıyor!"
        )

        return


    log(
        GREEN,
        "[OK] FFmpeg çalışıyor."
    )


    # --------------------------------------------------------
    # LOGO
    # --------------------------------------------------------

    logo_enabled = os.path.isfile(
        LOGO_FILE
    )


    if logo_enabled:

        log(
            GREEN,
            f"[OK] Logo bulundu: {LOGO_FILE}"
        )

    else:

        log(
            YELLOW,
            "[UYARI] logo.png bulunamadı."
        )

        log(
            YELLOW,
            "[BİLGİ] Yayın logosuz başlayacak."
        )


    # --------------------------------------------------------
    # FONT
    # --------------------------------------------------------

    if os.path.isfile(
        FONT_FILE
    ):

        log(
            GREEN,
            f"[OK] Font bulundu: {FONT_FILE}"
        )

    else:

        log(
            YELLOW,
            "[UYARI] Arial fontu bulunamadı."
        )


    # --------------------------------------------------------
    # M3U8 KONTROL
    # --------------------------------------------------------

    log(
        BLUE,
        "[TEST] M3U8 bağlantısı kontrol ediliyor..."
    )


    try:

        response = requests.get(

            M3U8_URL,

            headers={
                "User-Agent":
                "Mozilla/5.0"
            },

            timeout=10,

            stream=True

        )


        if response.status_code == 200:

            log(
                GREEN,
                "[✓ M3U8 ERİŞİLEBİLİYOR]"
            )

        else:

            log(
                YELLOW,
                f"[UYARI] M3U8 HTTP kodu: "
                f"{response.status_code}"
            )


        response.close()


    except Exception as e:

        log(
            RED,
            f"[UYARI] M3U8 kontrolü başarısız: {e}"
        )

        log(
            YELLOW,
            "[BİLGİ] FFmpeg yine de başlatılacak."
        )


    # --------------------------------------------------------
    # YAYINI BAŞLAT
    # --------------------------------------------------------

    while True:

        result = start_stream(
            ffmpeg,
            logo_enabled
        )


        if result:

            break


        log(
            YELLOW,
            ""
        )

        log(
            YELLOW,
            f"[RESTART] {RESTART_DELAY} saniye sonra "
            f"yayın yeniden başlatılacak..."
        )


        for i in range(
            RESTART_DELAY,
            0,
            -1
        ):

            print(
                f"\r[RESTART] {i} saniye...",
                end="",
                flush=True
            )

            time.sleep(1)


        print("")


# ============================================================
# BAŞLAT
# ============================================================

if __name__ == "__main__":

    try:

        main()

    except KeyboardInterrupt:

        log(
            YELLOW,
            "\n[SİSTEM] Program kullanıcı tarafından kapatıldı."
        )

    except Exception as e:

        log(
            RED,
            f"\n[FATAL HATA] {e}"
        )

        input(
            "\nEnter'a basarak çık..."
        )
