# ============================================================
# SSH101 YAYIN SISTEMI
# Windows VDS + FFmpeg
# Türkiye Saati + Üst Sol Telegram + Üst Sağ Logo
# Alt Bilgi Bandı + Kayan Yazı
# ============================================================

import subprocess
import sys
import time
import os
import threading
from datetime import datetime
from zoneinfo import ZoneInfo


# ============================================================
# AYARLAR
# ============================================================

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101"
STREAM_KEY = "telegram"

VIDEO_URL = "http://atakan1983.duckdns.org/patron.php?action=hls&url=https://leq.zirvedesin243.cfd/zirve/mono.m3u8"

LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"

# Sol üst yazı
UST_SOL_YAZI = "t.me/zemtvapk"

# Alt kayan yazı
BILGI_YAZISI = (
    "ZEM MEDYA SUNAR • YAYINIMIZ DEVAM EDİYOR • "
    "İYİ SEYİRLER • ZEM TV • ZEM MEDYA • bu yayın TEST YAYINI•"
)

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

# Türkiye saati dosyası
SAAT_DOSYASI = "turkiye_saat.txt"

# RTMP
RTMP_SERVER = f"{RTMP_URL}/{STREAM_KEY}"


# ============================================================
# TÜRKİYE SAATİ
# ============================================================

saat_thread_aktif = True


def turkiye_saatini_guncelle():

    """
    Türkiye saatini her saniye günceller.

    Europe/Istanbul UTC+3 kullanır.
    VDS'nin kendi saat ayarından bağımsızdır.
    """

    try:

        istanbul = ZoneInfo("Europe/Istanbul")

    except Exception:

        print("ZoneInfo bulunamadi.")
        print("Python 3.9 veya daha yeni bir Python gerekir.")

        return


    while saat_thread_aktif:

        try:

            simdi = datetime.now(istanbul)

            saat = simdi.strftime("%H:%M")

            # FFmpeg drawtext tarafından okunacak dosya
            with open(
                SAAT_DOSYASI,
                "w",
                encoding="utf-8"
            ) as f:

                f.write(saat)

        except Exception as e:

            print(
                "Saat guncelleme hatasi:",
                e
            )

        time.sleep(1)


# ============================================================
# SAAT DOSYASINI İLK KEZ OLUŞTUR
# ============================================================

try:

    istanbul = ZoneInfo("Europe/Istanbul")

    ilk_saat = datetime.now(
        istanbul
    ).strftime("%H:%M")

except Exception:

    ilk_saat = "00:00"


with open(
    SAAT_DOSYASI,
    "w",
    encoding="utf-8"
) as f:

    f.write(ilk_saat)


# Saat thread'i
threading.Thread(
    target=turkiye_saatini_guncelle,
    daemon=True
).start()


# ============================================================
# EKRAN BİLGİSİ
# ============================================================

print("=" * 70)
print("          SSH101 WINDOWS VDS YAYIN SISTEMI")
print("=" * 70)

print()
print("Video:")
print(VIDEO_URL)

print()
print("Logo:")
print(LOGO_URL)

print()
print("Sol Ust:")
print(UST_SOL_YAZI)

print()
print("Alt Yazi:")
print(BILGI_YAZISI)

print()
print("Stream Key:")
print(STREAM_KEY)

print()
print("RTMP:")
print(RTMP_SERVER)

print()
print("Türkiye Saati:")
print("AKTIF - Europe/Istanbul")

print()
print("Izleme:")
print(f"https://ssh101.com/live/{STREAM_KEY}")

print()
print("=" * 70)
print(" Logo       : Sag ust")
print(" Telegram   : Sol ust")
print(" Saat       : Türkiye saati / canlı")
print(" Bilgi      : Alt bant")
print(" Kayan Yazi : Aktif")
print("=" * 70)

print()
print("Yayini durdurmak icin CTRL+C")
print()


# ============================================================
# FFMPEG KOMUTU
# ============================================================

command = [

    FFMPEG,

    "-hide_banner",

    "-loglevel",
    "warning",


    # ========================================================
    # HLS / M3U8
    # ========================================================

    "-allowed_extensions",
    "ALL",

    "-extension_picky",
    "0",

    "-protocol_whitelist",
    "file,http,https,tcp,tls,crypto",

    "-fflags",
    "+genpts+discardcorrupt",

    "-rw_timeout",
    "15000000",


    # ========================================================
    # KAYNAK HEADERS
    # ========================================================

    "-headers",
    (
        f"Referer: {VIDEO_REFERER}\r\n"
        f"User-Agent: {USER_AGENT}\r\n"
    ),


    # ========================================================
    # RECONNECT
    # ========================================================

    "-reconnect",
    "1",

    "-reconnect_streamed",
    "1",

    "-reconnect_at_eof",
    "1",

    "-reconnect_on_network_error",
    "1",

    "-reconnect_on_http_error",
    "4xx,5xx",

    "-reconnect_delay_max",
    "5",


    # ========================================================
    # VIDEO
    # ========================================================

    "-i",
    VIDEO_URL,


    # ========================================================
    # LOGO
    # ========================================================

    "-headers",
    f"User-Agent: {USER_AGENT}\r\n",

    "-loop",
    "1",

    "-i",
    LOGO_URL,


    # ========================================================
    # FILTER COMPLEX
    # ========================================================

    "-filter_complex",

    (

        # ----------------------------------------------------
        # ANA VIDEO
        # ----------------------------------------------------

        "[0:v]"
        "scale=1280:720:"
        "force_original_aspect_ratio=decrease,"
        "pad=1280:720:"
        "(ow-iw)/2:"
        "(oh-ih)/2:"
        "black"
        "[v0];"


        # ----------------------------------------------------
        # LOGO
        # ----------------------------------------------------

        "[1:v]"
        "scale=-1:90"
        "[logo];"

        "[v0][logo]"
        "overlay=W-w-10:10"
        "[v1];"


        # ====================================================
        # SOL ÜST TELEGRAM YAZISI
        # ====================================================

        "[v1]"
        "drawtext="
        f"fontfile='{FONT_FILE}':"
        f"text='{UST_SOL_YAZI}':"
        "fontcolor=white:"
        "fontsize=20:"
        "borderw=2:"
        "bordercolor=black:"
        "x=10:"
        "y=12"
        "[v2];"


        # ====================================================
        # ALT SİYAH BANT
        # ====================================================

        "[v2]"
        "drawbox="
        "x=0:"
        "y=650:"
        "w=1280:"
        "h=70:"
        "color=black:"
        "t=fill"
        "[v3];"


        # ====================================================
        # SARI BİLGİ KUTUSU
        # ====================================================

        "[v3]"
        "drawbox="
        "x=0:"
        "y=650:"
        "w=75:"
        "h=55:"
        "color=yellow:"
        "t=fill"
        "[v4];"


        # ====================================================
        # BİLGİ YAZISI
        # ====================================================

        "[v4]"
        "drawtext="
        f"fontfile='{FONT_FILE}':"
        "text='BİLGİ':"
        "fontcolor=black:"
        "fontsize=16:"
        "x=12:"
        "y=669"
        "[v5];"


        # ====================================================
        # TÜRKİYE SAATİ
        # ====================================================

        "[v5]"
        "drawtext="
        f"fontfile='{FONT_FILE}':"
        f"textfile='{SAAT_DOSYASI}':"
        "reload=25:"
        "fontcolor=white:"
        "fontsize=22:"
        "x=88:"
        "y=666"
        "[v6];"


        # ====================================================
        # KAYAN YAZI BEYAZ ALANI
        # ====================================================

        "color="
        "c=white:"
        "s=1135x55:"
        "r=25"
        "[tickerbg];"


        # ====================================================
        # KAYAN YAZI
        # ====================================================

        "[tickerbg]"
        "drawtext="
        f"fontfile='{FONT_FILE}':"
        f"text='{BILGI_YAZISI}':"
        "fontcolor=black:"
        "fontsize=18:"
        "x=1135-mod(t*80\\,1135+tw):"
        "y=17"
        "[ticker];"


        # ====================================================
        # KAYAN YAZIYI ALT BANTA KOY
        # ====================================================

        "[v6][ticker]"
        "overlay=145:650"
        "[v]"
    ),


    # ========================================================
    # MAP
    # ========================================================

    "-map",
    "[v]",

    "-map",
    "0:a?",


    # ========================================================
    # VIDEO ENCODE
    # ========================================================

    "-c:v",
    "libx264",

    "-preset",
    "veryfast",

    "-tune",
    "zerolatency",

    "-b:v",
    "3500k",

    "-maxrate",
    "4000k",

    "-bufsize",
    "8000k",

    "-pix_fmt",
    "yuv420p",

    "-g",
    "50",

    "-keyint_min",
    "50",

    "-sc_threshold",
    "0",


    # ========================================================
    # AUDIO
    # ========================================================

    "-c:a",
    "aac",

    "-b:a",
    "128k",

    "-ar",
    "44100",

    "-ac",
    "2",


    # ========================================================
    # RTMP
    # ========================================================

    "-flvflags",
    "no_duration_filesize",

    "-f",
    "flv",

    RTMP_SERVER
]


# ============================================================
# PROCESS
# ============================================================

proc = None
log_fp = None


# ============================================================
# BAŞLAT
# ============================================================

def baslat():

    global proc
    global log_fp

    print()
    print("=" * 70)
    print(" FFMPEG BASLATILIYOR...")
    print("=" * 70)
    print()

    try:

        log_fp = open(
            LOG_FILE,
            "ab"
        )

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


        print(
            f"FFmpeg PID: {proc.pid}"
        )

        print()
        print("YAYIN AKTIF")
        print()

        return proc


    except Exception as e:

        print()
        print("FFmpeg BASLATMA HATASI")
        print()
        print(e)
        print()

        return None


# ============================================================
# DURDUR
# ============================================================

def durdur():

    global proc
    global log_fp
    global saat_thread_aktif

    saat_thread_aktif = False

    print()
    print("=" * 70)
    print(" YAYIN DURDURULUYOR...")
    print("=" * 70)


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

            print(
                "Durdurma hatasi:",
                e
            )


    if log_fp:

        try:

            log_fp.close()

        except Exception:

            pass

        log_fp = None


    print()
    print("Yayin sonlandirildi.")
    print()


# ============================================================
# ANA PROGRAM
# ============================================================

if __name__ == "__main__":

    try:

        # İlk yayını başlat
        baslat()


        # Sürekli kontrol
        while True:

            time.sleep(10)


            # FFmpeg başlatılamadı
            if proc is None:

                print(
                    "FFmpeg baslatilamadi."
                )

                print(
                    "5 saniye sonra tekrar deneniyor..."
                )

                time.sleep(5)

                baslat()

                continue


            # FFmpeg kapandı
            if proc.poll() is not None:

                return_code = proc.returncode

                print()
                print("=" * 70)

                print(
                    f"FFmpeg DURDU! Kod: {return_code}"
                )

                print(
                    "5 saniye sonra otomatik yeniden baslatilacak."
                )

                print(
                    f"Log dosyasi: {LOG_FILE}"
                )

                print("=" * 70)
                print()

                time.sleep(5)

                baslat()


    except KeyboardInterrupt:

        durdur()

        sys.exit(0)


    except Exception as e:

        print()
        print("=" * 70)
        print("BEKLENMEYEN HATA")
        print("=" * 70)

        print(e)

        print()

        durdur()

        sys.exit(1)
