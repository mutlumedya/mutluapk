#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import time
import threading
import subprocess
import importlib.util
from datetime import datetime


# ============================================================
# RENKLER
# ============================================================

RED = "\033[91m"
GREEN = "\033[92m"
YELLOW = "\033[93m"
BLUE = "\033[94m"
RESET = "\033[0m"


def log(color, text):
    print(f"{color}{text}{RESET}", flush=True)


# ============================================================
# PROGRAM KLASÖRÜ
# ============================================================

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

ERROR_LOG = os.path.join(BASE_DIR, "ffmpeg_error.log")


# ============================================================
# GEREKLİ PYTHON PAKETLERİ
# ============================================================

def paket_kontrol_ve_kur():

    gerekli_paketler = {
        "requests": "requests"
    }

    for modul, paket in gerekli_paketler.items():

        if importlib.util.find_spec(modul) is not None:
            log(GREEN, f"✓ Python paketi hazır: {paket}")
            continue

        log(YELLOW, f"! {paket} bulunamadı. Otomatik kuruluyor...")

        try:

            subprocess.check_call(
                [
                    sys.executable,
                    "-m",
                    "pip",
                    "install",
                    "--upgrade",
                    paket
                ]
            )

            log(GREEN, f"✓ {paket} başarıyla kuruldu.")

        except Exception as e:

            log(
                RED,
                f"✗ {paket} kurulamadı: {e}"
            )

            return False

    return True


# ============================================================
# REQUESTS
# ============================================================

if not paket_kontrol_ve_kur():
    input("\nEnter'a basarak çıkabilirsiniz...")
    sys.exit(1)


import requests


# ============================================================
# GENEL AYARLAR
# ============================================================

CHECK_INTERVAL = 300

RESTART_DELAY = 5

START_DELAY = 3


# ============================================================
# WINDOWS SAATİ
#
# datetime.now() doğrudan Windows sunucunun
# yerel saatini kullanır.
# ============================================================

STOP_HOUR = 3
START_HOUR = 4


def windows_saati():

    return datetime.now()


def windows_saat_metni():

    return windows_saati().strftime("%d.%m.%Y %H:%M:%S")


# ============================================================
# ÇÖZÜNÜRLÜK
# ============================================================

VIDEO_WIDTH = 1280
VIDEO_HEIGHT = 720


# ============================================================
# VIDEO
# ============================================================

VIDEO_BITRATE = "2000k"

MAXRATE = "2000k"

BUFSIZE = "4000k"


# ============================================================
# SES
# ============================================================

AUDIO_BITRATE = "96k"


# ============================================================
# LOGO
# ============================================================

LOGO_WIDTH = 150


# ============================================================
# RTMP SUNUCU
# ============================================================

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101"


# ============================================================
# YAYIN KEYLERİ
#
# BURAYA KENDİ YETKİLİ KEYLERİNİ YAZ
# ============================================================

STREAM_KEYS = [

    "YAYIN_1_KEY",

    "YAYIN_2_KEY",

    "YAYIN_3_KEY",

    "YAYIN_4_KEY",

    "YAYIN_5_KEY",

    "YAYIN_6_KEY",

]


# ============================================================
# GITHUB RAW M3U
#
# Her dosyanın içerisinde M3U8 adresi bulunmalı.
# ============================================================

STREAMS = [

    {
        "name": "YAYIN 1",
        "key": STREAM_KEYS[0],
        "m3u": "https://raw.githubusercontent.com/KULLANICI/REPO/main/yayin1.m3u",
        "logo": "logo1.png",
    },

    {
        "name": "YAYIN 2",
        "key": STREAM_KEYS[1],
        "m3u": "https://raw.githubusercontent.com/KULLANICI/REPO/main/yayin2.m3u",
        "logo": "logo2.png",
    },

    {
        "name": "YAYIN 3",
        "key": STREAM_KEYS[2],
        "m3u": "https://raw.githubusercontent.com/KULLANICI/REPO/main/yayin3.m3u",
        "logo": "logo3.png",
    },

    {
        "name": "YAYIN 4",
        "key": STREAM_KEYS[3],
        "m3u": "https://raw.githubusercontent.com/KULLANICI/REPO/main/yayin4.m3u",
        "logo": "logo4.png",
    },

    {
        "name": "YAYIN 5",
        "key": STREAM_KEYS[4],
        "m3u": "https://raw.githubusercontent.com/KULLANICI/REPO/main/yayin5.m3u",
        "logo": "logo5.png",
    },

    {
        "name": "YAYIN 6",
        "key": STREAM_KEYS[5],
        "m3u": "https://raw.githubusercontent.com/KULLANICI/REPO/main/yayin6.m3u",
        "logo": "logo6.png",
    },

]


# ============================================================
# PROCESSLER
# ============================================================

processes = {}

process_lock = threading.Lock()


# ============================================================
# FFMPEG BUL
# ============================================================

def ffmpeg_bul():

    # Önce PATH
    try:

        result = subprocess.run(
            ["where", "ffmpeg"],
            capture_output=True,
            text=True,
            timeout=5
        )

        if result.returncode == 0:

            yollar = result.stdout.splitlines()

            if yollar:

                yol = yollar[0].strip()

                if os.path.isfile(yol):
                    return yol

    except Exception:
        pass


    # Yaygın Windows yolları
    adaylar = [

        r"C:\ffmpeg\bin\ffmpeg.exe",

        r"C:\Program Files\ffmpeg\bin\ffmpeg.exe",

        r"C:\Program Files (x86)\ffmpeg\bin\ffmpeg.exe",

        os.path.join(BASE_DIR, "ffmpeg.exe"),

        os.path.join(BASE_DIR, "bin", "ffmpeg.exe"),

    ]


    for yol in adaylar:

        if os.path.isfile(yol):
            return yol


    return None


# ============================================================
# LOGO KONTROL
# ============================================================

def logo_yolu(stream):

    logo = stream.get("logo")

    if not logo:
        return None

    # Mutlak yol
    if os.path.isabs(logo):

        if os.path.isfile(logo):
            return logo

        return None


    # .py dosyasının bulunduğu klasörden ara
    yol = os.path.join(BASE_DIR, logo)

    if os.path.isfile(yol):

        return yol

    return None


# ============================================================
# RTMP
# ============================================================

def get_rtmp_url(stream):

    return f"{RTMP_URL}/{stream['key']}"


# ============================================================
# WINDOWS SAATİNE GÖRE YAYIN KONTROLÜ
# ============================================================

def yayinlar_acik_mi():

    # Windows'un kendi yerel saati
    hour = datetime.now().hour

    if STOP_HOUR <= hour < START_HOUR:

        return False

    return True


# ============================================================
# GITHUB RAW M3U OKU
# ============================================================

def m3u8_bul(raw_url):

    try:

        response = requests.get(
            raw_url,
            timeout=20,
            headers={
                "User-Agent": "Mozilla/5.0 Windows FFmpeg"
            }
        )

        response.raise_for_status()

        lines = response.text.splitlines()

        urls = []


        for line in lines:

            line = line.strip()

            if not line:
                continue

            if line.startswith("#"):
                continue

            if (
                line.startswith("http://")
                or
                line.startswith("https://")
            ):

                urls.append(line)


        # Önce M3U8
        for url in urls:

            if ".m3u8" in url.lower():

                return url


        # M3U8 uzantısı yoksa ilk HTTP URL
        if urls:

            return urls[0]


        return None


    except Exception as e:

        log(
            RED,
            f"[M3U] Okuma hatası: {e}"
        )

        return None


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def ffmpeg_komutu(stream, source, ffmpeg_path):

    output = get_rtmp_url(stream)

    logo = logo_yolu(stream)


    # ========================================================
    # LOGOLU
    # ========================================================

    if logo:

        filter_complex = (

            f"[0:v]"
            f"scale={VIDEO_WIDTH}:{VIDEO_HEIGHT}:"
            f"force_original_aspect_ratio=decrease,"
            f"pad={VIDEO_WIDTH}:{VIDEO_HEIGHT}:"
            f"(ow-iw)/2:(oh-ih)/2:black"
            f"[base];"

            f"[1:v]"
            f"scale={LOGO_WIDTH}:-1"
            f"[logo];"

            f"[base][logo]"
            f"overlay=W-w-15:15"
            f"[v]"

        )


        command = [

            ffmpeg_path,

            "-hide_banner",

            "-loglevel",
            "warning",

            # HTTP/HLS bağlantı kopmalarına karşı
            "-reconnect",
            "1",

            "-reconnect_streamed",
            "1",

            "-reconnect_at_eof",
            "1",

            "-reconnect_delay_max",
            "10",

            # Kaynak
            "-i",
            source,

            # Logo
            "-loop",
            "1",

            "-i",
            logo,

            # Video filtre
            "-filter_complex",
            filter_complex,

            # Video
            "-map",
            "[v]",

            # Ses varsa
            "-map",
            "0:a?",

            # H264
            "-c:v",
            "libx264",

            "-preset",
            "ultrafast",

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

            # Keyframe
            "-g",
            "120",

            "-keyint_min",
            "120",

            # Ses
            "-c:a",
            "aac",

            "-b:a",
            AUDIO_BITRATE,

            "-ar",
            "44100",

            # CPU
            "-threads",
            "1",

            # RTMP
            "-f",
            "flv",

            output
        ]


        return command


    # ========================================================
    # LOGOSUZ
    # ========================================================

    command = [

        ffmpeg_path,

        "-hide_banner",

        "-loglevel",
        "warning",

        "-reconnect",
        "1",

        "-reconnect_streamed",
        "1",

        "-reconnect_at_eof",
        "1",

        "-reconnect_delay_max",
        "10",

        "-i",
        source,

        "-vf",

        (
            f"scale={VIDEO_WIDTH}:{VIDEO_HEIGHT}:"
            f"force_original_aspect_ratio=decrease,"
            f"pad={VIDEO_WIDTH}:{VIDEO_HEIGHT}:"
            f"(ow-iw)/2:(oh-ih)/2:black"
        ),

        "-map",
        "0:v:0",

        "-map",
        "0:a?",

        "-c:v",
        "libx264",

        "-preset",
        "ultrafast",

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

        "-g",
        "120",

        "-keyint_min",
        "120",

        "-c:a",
        "aac",

        "-b:a",
        AUDIO_BITRATE,

        "-ar",
        "44100",

        "-threads",
        "1",

        "-f",
        "flv",

        output
    ]


    return command


# ============================================================
# FFMPEG DURDUR
# ============================================================

def durdur(name):

    with process_lock:

        proc = processes.get(name)

        if proc is None:
            return


        try:

            if proc.poll() is None:

                log(
                    YELLOW,
                    f"[{name}] Durduruluyor..."
                )

                proc.terminate()


                try:

                    proc.wait(timeout=5)


                except subprocess.TimeoutExpired:

                    proc.kill()

                    proc.wait()


        except Exception:

            pass


        processes.pop(name, None)


# ============================================================
# TÜM YAYINLARI DURDUR
# ============================================================

def tumunu_durdur():

    for stream in STREAMS:

        durdur(stream["name"])


# ============================================================
# FFMPEG HATASINI DOSYAYA YAZ
# ============================================================

def hata_logla(name, error_text):

    try:

        with open(
            ERROR_LOG,
            "a",
            encoding="utf-8"
        ) as f:

            f.write("\n")
            f.write("=" * 80)
            f.write("\n")

            f.write(
                f"{datetime.now()} - {name}\n"
            )

            f.write(error_text)

            f.write("\n")

    except Exception:

        pass


# ============================================================
# TEK YAYIN
# ============================================================

def yayin_worker(stream):

    name = stream["name"]

    son_m3u8 = None


    while True:

        # ====================================================
        # GECE MOLASI
        # ====================================================

        if not yayinlar_acik_mi():

            durdur(name)

            log(
                YELLOW,
                f"[{name}] Windows saati: "
                f"{windows_saat_metni()} - Gece molası"
            )

            time.sleep(20)

            continue


        # ====================================================
        # M3U OKU
        # ====================================================

        source = m3u8_bul(
            stream["m3u"]
        )


        if not source:

            log(
                RED,
                f"[{name}] M3U8 bulunamadı."
            )

            time.sleep(30)

            continue


        # ====================================================
        # KAYNAK DEĞİŞTİ
        # ====================================================

        if source != son_m3u8:

            log(
                GREEN,
                f"[{name}] Yeni M3U8 bulundu:"
            )

            log(
                BLUE,
                source
            )

            durdur(name)

            son_m3u8 = source


        # ====================================================
        # ZATEN ÇALIŞIYOR MU?
        # ====================================================

        with process_lock:

            proc = processes.get(name)

            if proc is not None:

                if proc.poll() is None:

                    time.sleep(CHECK_INTERVAL)

                    continue


        # ====================================================
        # FFMPEG
        # ====================================================

        ffmpeg_path = ffmpeg_bul()


        if not ffmpeg_path:

            log(
                RED,
                f"[{name}] FFmpeg bulunamadı!"
            )

            time.sleep(30)

            continue


        # ====================================================
        # KOMUT
        # ====================================================

        try:

            command = ffmpeg_komutu(
                stream,
                source,
                ffmpeg_path
            )


            log(
                GREEN,
                f"[{name}] ▶ FFmpeg başlıyor..."
            )

            log(
                BLUE,
                f"[{name}] Windows saati: "
                f"{windows_saat_metni()}"
            )

            log(
                BLUE,
                f"[{name}] → "
                f"{get_rtmp_url(stream)}"
            )


            # =================================================
            # LOG DOSYASI
            # =================================================

            error_file = open(
                ERROR_LOG,
                "a",
                encoding="utf-8"
            )


            error_file.write("\n")
            error_file.write("=" * 80)
            error_file.write("\n")

            error_file.write(
                f"{datetime.now()} - {name} FFmpeg başlatıldı\n"
            )


            # =================================================
            # FFMPEG BAŞLAT
            # =================================================

            proc = subprocess.Popen(

                command,

                stdout=subprocess.DEVNULL,

                stderr=error_file,

                creationflags=(
                    subprocess.CREATE_NO_WINDOW
                    if os.name == "nt"
                    else 0
                )

            )


            with process_lock:

                processes[name] = proc


            # =================================================
            # FFMPEG KONTROL
            # =================================================

            while True:

                # Gece oldu
                if not yayinlar_acik_mi():

                    durdur(name)

                    break


                # FFmpeg kapandı
                if proc.poll() is not None:

                    log(
                        YELLOW,
                        f"[{name}] FFmpeg kapandı."
                    )

                    with process_lock:

                        processes.pop(
                            name,
                            None
                        )


                    try:
                        error_file.close()
                    except Exception:
                        pass


                    log(
                        YELLOW,
                        f"[{name}] "
                        f"{RESTART_DELAY} saniye sonra yeniden..."
                    )


                    time.sleep(
                        RESTART_DELAY
                    )

                    break


                time.sleep(5)


        except Exception as e:

            hata_logla(
                name,
                str(e)
            )


            log(
                RED,
                f"[{name}] HATA: {e}"
            )


            durdur(name)


            time.sleep(
                RESTART_DELAY
            )


# ============================================================
# ANA PROGRAM
# ============================================================

def main():

    log(BLUE, "")
    log(BLUE, "=" * 70)

    log(
        GREEN,
        "       6'Lı GITHUB M3U → FFMPEG SİSTEMİ"
    )

    log(BLUE, "=" * 70)


    # ========================================================
    # WINDOWS SAATİ
    # ========================================================

    log(
        BLUE,
        f"Windows yerel saati: {windows_saat_metni()}"
    )


    # ========================================================
    # FFMPEG
    # ========================================================

    ffmpeg_path = ffmpeg_bul()


    if ffmpeg_path:

        log(
            GREEN,
            f"✓ FFmpeg bulundu:"
        )

        log(
            BLUE,
            ffmpeg_path
        )


    else:

        log(
            RED,
            "✗ FFmpeg bulunamadı!"
        )

        log(
            YELLOW,
            "FFmpeg'i C:\\ffmpeg\\bin\\ffmpeg.exe"
        )

        log(
            YELLOW,
            "konumuna kurabilir veya PATH'e ekleyebilirsiniz."
        )

        input(
            "\nEnter'a basarak çıkabilirsiniz..."
        )

        return


    # ========================================================
    # FFMPEG SÜRÜM KONTROL
    # ========================================================

    try:

        subprocess.run(

            [
                ffmpeg_path,
                "-version"
            ],

            stdout=subprocess.DEVNULL,

            stderr=subprocess.DEVNULL,

            check=True

        )

        log(
            GREEN,
            "✓ FFmpeg çalışıyor"
        )


    except Exception as e:

        log(
            RED,
            f"✗ FFmpeg çalıştırılamadı: {e}"
        )

        return


    # ========================================================
    # GITHUB KONTROL
    # ========================================================

    try:

        response = requests.get(

            "https://raw.githubusercontent.com/",

            timeout=10

        )

        response.raise_for_status()


        log(
            GREEN,
            "✓ GitHub bağlantısı hazır"
        )


    except Exception as e:

        log(
            RED,
            f"✗ GitHub bağlantısı başarısız: {e}"
        )

        return


    # ========================================================
    # LOGO KONTROL
    # ========================================================

    log(
        YELLOW,
        "\nLogo kontrolü:"
    )


    for stream in STREAMS:

        logo = logo_yolu(stream)


        if logo:

            log(
                GREEN,
                f"✓ {stream['name']} → {logo}"
            )

        else:

            log(
                YELLOW,
                f"! {stream['name']} → "
                f"{stream['logo']} bulunamadı"
            )


    # ========================================================
    # YAYINLARI BAŞLAT
    # ========================================================

    log(
        BLUE,
        "\nYayınlar başlatılıyor..."
    )


    for stream in STREAMS:

        thread = threading.Thread(

            target=yayin_worker,

            args=(stream,),

            daemon=True

        )


        thread.start()


        time.sleep(
            START_DELAY
        )


    # ========================================================
    # DURUM
    # ========================================================

    log(BLUE, "")
    log(BLUE, "=" * 70)

    log(
        GREEN,
        "✓ 6 yayın sistemi aktif"
    )

    log(
        GREEN,
        "✓ Her yayın ayrı Raw GitHub M3U"
    )

    log(
        GREEN,
        "✓ M3U içinden M3U8 otomatik bulunuyor"
    )

    log(
        GREEN,
        "✓ Logo sistemi aktif"
    )

    log(
        GREEN,
        "✓ Windows'un kendi saati kullanılıyor"
    )

    log(
        GREEN,
        "✓ 03:00 - 04:00 Windows saatine göre mola"
    )

    log(
        GREEN,
        "✓ FFmpeg kapanırsa otomatik restart"
    )

    log(
        GREEN,
        "✓ M3U8 değişirse yeniden başlatma"
    )

    log(
        GREEN,
        "✓ FFmpeg hata kayıtları:"
    )

    log(
        BLUE,
        ERROR_LOG
    )

    log(BLUE, "=" * 70)


    # ========================================================
    # ANA DÖNGÜ
    # ========================================================

    try:

        while True:

            time.sleep(60)


    except KeyboardInterrupt:

        log(
            RED,
            "\n⛔ Sistem kapatılıyor..."
        )


        tumunu_durdur()


        log(
            GREEN,
            "✓ Tüm FFmpeg işlemleri kapatıldı."
        )


# ============================================================
# BAŞLAT
# ============================================================

if __name__ == "__main__":

    main()
