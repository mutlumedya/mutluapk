#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import subprocess
import requests
import sys
import time

# ============================================================
# AYARLAR
# ============================================================

M3U_URL = "https://raw.githubusercontent.com/mutlumedya/yayin2/refs/heads/main/action.m3u"

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101"
STREAM_KEY = "zemtv"

RTMP_OUTPUT = f"{RTMP_URL}/{STREAM_KEY}"


# ============================================================
# M3U'DAN İLK MEDYA URL'SİNİ BUL
# ============================================================

def get_media_url():

    print("[1] GitHub M3U indiriliyor...")

    try:
        response = requests.get(
            M3U_URL,
            timeout=30,
            headers={
                "User-Agent": "Mozilla/5.0"
            }
        )

        response.raise_for_status()

    except Exception as e:
        print(f"[HATA] M3U indirilemedi: {e}")
        sys.exit(1)

    lines = response.text.splitlines()

    for line in lines:

        line = line.strip()

        if not line:
            continue

        if line.startswith("#"):
            continue

        if line.startswith("http://") or line.startswith("https://"):

            print("[2] Kaynak bulundu:")
            print(line)

            return line

    print("[HATA] M3U içerisinde medya URL'si bulunamadı.")
    sys.exit(1)


# ============================================================
# FFPROBE TEST
# ============================================================

def test_source(url):

    print("[3] FFprobe kaynak testi...")

    command = [
        "ffprobe",
        "-v", "error",
        "-show_entries",
        "format=duration",
        "-of",
        "default=noprint_wrappers=1:nokey=1",
        url
    ]

    result = subprocess.run(
        command,
        capture_output=True,
        text=True
    )

    if result.returncode != 0:

        print("[HATA] FFprobe kaynağı açamadı.")
        print(result.stderr)

        sys.exit(1)

    print("[OK] FFprobe kaynağı açtı.")

    if result.stdout.strip():
        print("Süre:", result.stdout.strip())


# ============================================================
# FFMPEG
# ============================================================

def start_ffmpeg(source):

    print("[4] FFmpeg başlatılıyor...")
    print("RTMP:", RTMP_OUTPUT)

    command = [

        "ffmpeg",

        "-hide_banner",

        "-re",

        "-i",
        source,

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

        "-vf",
        "scale=1280:720:force_original_aspect_ratio=decrease,"
        "pad=1280:720:(ow-iw)/2:(oh-ih)/2:black",

        "-b:v",
        "2000k",

        "-maxrate",
        "2000k",

        "-bufsize",
        "4000k",

        "-g",
        "120",

        "-c:a",
        "aac",

        "-b:a",
        "96k",

        "-ar",
        "44100",

        "-f",
        "flv",

        RTMP_OUTPUT
    ]

    print("")
    print("FFmpeg çalışıyor...")
    print("Durdurmak için CTRL+C")
    print("")

    process = subprocess.Popen(
        command
    )

    try:

        process.wait()

    except KeyboardInterrupt:

        print("")
        print("Yayın durduruluyor...")

        process.terminate()

        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()


# ============================================================
# ANA
# ============================================================

def main():

    print("=" * 60)
    print("     TEK YAYIN / REKLAMSIZ FFMPEG TESTİ")
    print("=" * 60)

    # FFmpeg
    try:
        subprocess.run(
            ["ffmpeg", "-version"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=True
        )
    except:
        print("[HATA] FFmpeg kurulu değil.")
        sys.exit(1)

    # FFprobe
    try:
        subprocess.run(
            ["ffprobe", "-version"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=True
        )
    except:
        print("[HATA] FFprobe kurulu değil.")
        sys.exit(1)

    source = get_media_url()

    test_source(source)

    start_ffmpeg(source)


if __name__ == "__main__":
    main()
