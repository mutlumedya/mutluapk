# ssh101_yayin.py
# Windows VDS - SSH101 yayın sistemi
# Logo ve yazı konumu korunmuştur.

import subprocess
import sys
import time
import os

# =========================================================
# AYARLAR
# =========================================================

RTMP_URL   = "rtmp://ssh101.bozztv.com:1935/ssh101"
STREAM_KEY = "telegram"

VIDEO_URL  = "http://atakan1983.duckdns.org/patron.php?action=hls&url=https://leq.zirvedesin243.cfd/zirve/mono.m3u8"
LOGO_URL   = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"

ALT_YAZI = "Resmi Telegram Yayını"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/120.0.0.0 Safari/537.36"
)

VIDEO_REFERER = "https://codenet.lol/"

# Windows Arial font
FONT_FILE = "C\\:/Windows/Fonts/arial.ttf"

# FFmpeg
FFMPEG = "ffmpeg"

# Log
LOG_FILE = "yayin.log"

# RTMP adresi
RTMP_SERVER = f"{RTMP_URL}/{STREAM_KEY}"


# =========================================================
# EKRAN
# =========================================================

print("=" * 60)
print(" SSH101 WINDOWS VDS YAYIN BASLATILIYOR")
print("=" * 60)

print(f" Video      : {VIDEO_URL}")
print(f" Logo       : {LOGO_URL}")
print(f" Stream Key : {STREAM_KEY}")
print(f" RTMP       : {RTMP_SERVER}")
print(f" Izleme     : https://ssh101.com/live/{STREAM_KEY}")

print("=" * 60)
print()
print(" Donmayi azaltan FFmpeg ayarlari aktif.")
print(" Logo ve yazi konumu korunmustur.")
print(" Durdurmak icin CTRL+C")
print()


# =========================================================
# FFMPEG KOMUTU
# =========================================================

command = [

    FFMPEG,

    "-hide_banner",
    "-loglevel", "warning",

    # -----------------------------------------------------
    # HLS / M3U8
    # -----------------------------------------------------

    "-allowed_extensions", "ALL",

    "-extension_picky", "0",

    "-protocol_whitelist",
    "file,http,https,tcp,tls,crypto",

    # Bozuk paketleri mümkün olduğunca atla
    "-fflags",
    "+genpts+discardcorrupt",

    # HLS bağlantı zaman aşımı
    "-rw_timeout",
    "15000000",

    # -----------------------------------------------------
    # VIDEO KAYNAK HEADERS
    # -----------------------------------------------------

    "-headers",
    f"Referer: {VIDEO_REFERER}\r\n"
    f"User-Agent: {USER_AGENT}\r\n",

    # -----------------------------------------------------
    # RECONNECT
    # -----------------------------------------------------

    "-reconnect", "1",

    "-reconnect_streamed", "1",

    "-reconnect_at_eof", "1",

    "-reconnect_on_network_error", "1",

    "-reconnect_on_http_error", "4xx,5xx",

    "-reconnect_delay_max", "5",

    # -----------------------------------------------------
    # M3U8 VIDEO
    # -----------------------------------------------------

    # ÖNEMLİ:
    # -re kaldırıldı.
    # Canlı HLS kaynağını FFmpeg kendi hızında takip edecek.

    "-i",
    VIDEO_URL,

    # -----------------------------------------------------
    # LOGO
    # -----------------------------------------------------

    "-headers",
    f"User-Agent: {USER_AGENT}\r\n",

    "-loop", "1",

    "-i",
    LOGO_URL,

    # =====================================================
    # VIDEO FILTRE
    # =====================================================

    "-filter_complex",

    # Kaynak videoyu 1280x720 içine oturt
    "[0:v]"
    "scale=1280:720:"
    "force_original_aspect_ratio=decrease,"
    "pad=1280:720:(ow-iw)/2:(oh-ih)/2:black"
    "[v0];"

    # Logo
    # ESKİ KONUM:
    # sağ üst - 10 px
    # yükseklik 90 px
    "[1:v]"
    "scale=-1:90"
    "[logo];"

    "[v0][logo]"
    "overlay=W-w-10:10"
    "[v1];"

    # Alt yazı
    # Konum AYNI
    # Sadece font 24 -> 20 yapıldı
    f"[v1]"
    f"drawtext="
    f"fontfile='{FONT_FILE}':"
    f"text='{ALT_YAZI}':"
    f"fontcolor=white:"
    f"fontsize=20:"
    f"x=(w-text_w)/2:"
    f"y=h-text_h-20"
    "[v]",

    # =====================================================
    # VIDEO / AUDIO
    # =====================================================

    "-map", "[v]",

    "-map", "0:a?",

    # -----------------------------------------------------
    # H264
    # -----------------------------------------------------

    "-c:v", "libx264",

    # CPU'yu fazla yormadan encode
    "-preset", "veryfast",

    # Canlı yayın için
    "-tune", "zerolatency",

    # -----------------------------------------------------
    # BITRATE
    # -----------------------------------------------------

    "-b:v", "3500k",

    "-maxrate", "4000k",

    "-bufsize", "8000k",

    "-pix_fmt", "yuv420p",

    # 25 FPS varsayımı için 2 saniyelik GOP
    "-g", "50",

    "-keyint_min", "50",

    "-sc_threshold", "0",

    # -----------------------------------------------------
    # AUDIO
    # -----------------------------------------------------

    "-c:a", "aac",

    "-b:a", "128k",

    "-ar", "44100",

    "-ac", "2",

    # -----------------------------------------------------
    # FLV / RTMP
    # -----------------------------------------------------

    "-flvflags",
    "no_duration_filesize",

    "-f", "flv",

    RTMP_SERVER
]


# =========================================================
# PROCESS
# =========================================================

proc = None
log_fp = None


def baslat():

    global proc
    global log_fp

    print()
    print("=" * 60)
    print(" FFMPEG BASLATILIYOR...")
    print("=" * 60)
    print()

    try:

        log_fp = open(
            LOG_FILE,
            "ab"
        )

        # Windows'ta ayrı process grubu
        if os.name == "nt":

            creationflags = (
                subprocess.CREATE_NEW_PROCESS_GROUP
            )

        else:

            creationflags = 0

        proc = subprocess.Popen(

            command,

            stdout=log_fp,

            stderr=subprocess.STDOUT,

            creationflags=creationflags

        )

        print(f" FFmpeg PID : {proc.pid}")
        print(" Yayin aktif.")
        print()

        return proc

    except Exception as e:

        print()
        print(" FFmpeg baslatma hatasi:")
        print(e)
        print()

        return None


# =========================================================
# DURDUR
# =========================================================

def durdur():

    global proc
    global log_fp

    print()
    print("=" * 60)
    print(" YAYIN DURDURULUYOR...")
    print("=" * 60)

    if proc and proc.poll() is None:

        try:

            if os.name == "nt":

                subprocess.call(

                    [
                        "taskkill",
                        "/F",
                        "/T",
                        "/PID",
                        str(proc.pid)
                    ],

                    stdout=subprocess.DEVNULL,

                    stderr=subprocess.DEVNULL

                )

            else:

                proc.terminate()

        except Exception as e:

            print(" Durdurma hatasi:", e)

    if log_fp:

        try:

            log_fp.close()

        except Exception:

            pass

        log_fp = None

    print(" Yayin sonlandirildi.")


# =========================================================
# ANA SISTEM
# =========================================================

if __name__ == "__main__":

    try:

        baslat()

        while True:

            # Her 10 saniyede process kontrolü
            time.sleep(10)

            if proc is None:

                print(
                    " FFmpeg baslatilamadi. "
                    "5 saniye sonra tekrar deneniyor..."
                )

                time.sleep(5)

                baslat()

                continue

            # FFmpeg kapanmış mı?
            if proc.poll() is not None:

                return_code = proc.returncode

                print()
                print(
                    f" FFmpeg durdu! "
                    f"(kod: {return_code})"
                )

                print(
                    " 5 saniye sonra otomatik yeniden baslatilacak..."
                )

                print(
                    f" Log: {LOG_FILE}"
                )

                time.sleep(5)

                baslat()

    except KeyboardInterrupt:

        durdur()

        sys.exit(0)

    except Exception as e:

        print()
        print("=" * 60)
        print(" BEKLENMEYEN HATA")
        print("=" * 60)

        print(e)

        durdur()

        sys.exit(1)
