# ssh101_yayin.py
# Windows VPS için SSH101 yayın scripti (sağlam versiyon)
import subprocess
import sys
import time
import os

# ===================== AYARLAR =====================
RTMP_URL   = "rtmp://ssh101.bozztv.com:1935/ssh101"
STREAM_KEY = "telegram"
VIDEO_URL  = "https://cdn.codenet.lol/streamgo/stremgo123/4864.m3u8"
LOGO_URL   = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"
ALT_YAZI   = "Resmi Telegram Yayını"

# Kaynak yayın için header'lar (403 için gerekli olabilir)
VIDEO_REFERER = "https://codenet.lol/"
USER_AGENT    = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36"

rtmp_server = f"{RTMP_URL}/{STREAM_KEY}"
LOG_FILE    = "yayin.log"

print("=" * 55)
print(" SSH101.com Yayin Baslatiliyor (Windows)")
print("=" * 55)
print(f" Video      : {VIDEO_URL}")
print(f" Logo       : {LOGO_URL}")
print(f" Stream Key : {STREAM_KEY}")
print(f" RTMP       : {rtmp_server}")
print(f" Izleme     : https://ssh101.com/live/{STREAM_KEY}")
print(f" HLS        : https://lbgo.bozztv.com/ssh101/ssh101/{STREAM_KEY}/playlist.m3u8")
print(f" Log        : {LOG_FILE}")
print("=" * 55)

FFMPEG = "ffmpeg"

command = [
    FFMPEG,
    "-hide_banner",
    "-loglevel", "warning",

    # Kaynak video için headers (403 için)
    "-headers",
    f"Referer: {VIDEO_REFERER}\r\nUser-Agent: {USER_AGENT}\r\n",

    # Reconnect ayarları (ağ kopunca yeniden bağlan)
    "-reconnect", "1",
    "-reconnect_streamed", "1",
    "-reconnect_delay_max", "5",

    # m3u8'lerde tüm segment uzantılarına izin ver
    "-allowed_extensions", "ALL",

    "-re",
    "-stream_loop", "-1",
    "-i", VIDEO_URL,

    # Logo için headers
    "-headers", f"User-Agent: {USER_AGENT}\r\n",
    "-i", LOGO_URL,

    "-filter_complex",
    "[0:v]scale=1280:720:force_original_aspect_ratio=decrease,"
    "pad=1280:720:(ow-iw)/2:(oh-ih)/2:black[v0];"
    "[1:v]scale=-1:90[logo];"
    "[v0][logo]overlay=W-w-10:10[v1];"
    f"[v1]drawtext=text='{ALT_YAZI}':fontcolor=white:fontsize=24:"
    "x=(w-text_w)/2:y=h-text_h-20[v]",

    "-map", "[v]",
    "-map", "0:a?",
    "-c:v", "libx264",
    "-preset", "veryfast",
    "-b:v", "4000k",
    "-maxrate", "4000k",
    "-bufsize", "8000k",
    "-pix_fmt", "yuv420p",
    "-g", "50",
    "-c:a", "aac",
    "-b:a", "128k",
    "-ar", "44100",
    "-f", "flv",
    rtmp_server
]

print("\n Yayin baslatiliyor...")
print(" Logo: Sag ust | Alt yazi: " + ALT_YAZI)
print(" Durdurmak icin: Ctrl + C\n")

proc = None
log_fp = None


def baslat():
    global proc, log_fp
    # ffmpeg çıktısını log dosyasına yaz (sorun görürsün)
    log_fp = open(LOG_FILE, "ab")
    creationflags = subprocess.CREATE_NEW_PROCESS_GROUP if os.name == "nt" else 0
    proc = subprocess.Popen(
        command,
        stdout=log_fp,
        stderr=subprocess.STDOUT,
        creationflags=creationflags
    )
    return proc


def durdur():
    global proc, log_fp
    if proc and proc.poll() is None:
        print("\n Yayin durduruluyor...")
        try:
            if os.name == "nt":
                subprocess.call(["taskkill", "/F", "/T", "/PID", str(proc.pid)],
                                stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL)
            else:
                proc.terminate()
        except Exception as e:
            print(" Durdurma hatasi:", e)
    if log_fp:
        try:
            log_fp.close()
        except Exception:
            pass
    print(" Yayin sonlandirildi.")


if __name__ == "__main__":
    try:
        baslat()
        while True:
            time.sleep(30)
            if proc.poll() is not None:
                print(f" Yayin durdu (kod {proc.returncode}), 5 sn sonra yeniden baslatiliyor...")
                print(f" Detay icin {LOG_FILE} dosyasina bak.")
                time.sleep(5)
                baslat()
    except KeyboardInterrupt:
        durdur()
        sys.exit(0)
