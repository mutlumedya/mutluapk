#!/usr/bin/env python3
# baba120 relay — Windows Server 2022
# Kurulum:
#   1) FFmpeg:  C:\ffmpeg\bin\ffmpeg.exe  (yol farklıysa FFMPEG'i düzelt)
#   2) cloudflared:  C:\cloudflared\cloudflared.exe  (yol farklıysa CF_BIN'i düzelt)
#   3) python relay.py
#
# Ne yapar:
#  - Worker'ın ham yayınını (?direct=1) alır
#  - Panelde kaydettiğin logo, Bakü saati/tarihi ve film/dizi adı+bölüm katmanını yayına yazar
#  - HLS olarak yayınlar, cloudflared tüneli açar, tünel adresini Worker'a (/api/relay) kendisi kaydeder

import json
import os
import re
import shutil
import subprocess
import sys
import threading
import time
import urllib.request
from datetime import datetime, timedelta, timezone
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

# ===================== AYARLAR =====================
WORKER = "https://ulduztv.dunyanin-yabancisi.workers.dev"  # sonunda / olmasın
ADMIN_KEY = "0"                                # Worker'daki ADMIN_PASS ile aynı
PORT = 8080
W, H = 1280, 720                               # VDS zorlanırsa 854x480 yap
VBR = "2500k"                                  # zorlanırsa 1500k yap
POLL = 3                                       # panel ayarlarını kaç saniyede bir kontrol etsin

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"           # ffmpeg tam yol
CF_BIN = r"C:\cloudflared\cloudflared.exe"     # cloudflared tam yol
# ===================================================

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) baba120-relay"
BAKU = timezone(timedelta(hours=4))            # Azerbaycan saati (UTC+4)
HOME = os.path.join(os.environ.get("USERPROFILE", "C:\\"), "baba120-relay")
HLS = os.path.join(HOME, "hls")

FONTS = [
    r"C:\Windows\Fonts\arial.ttf",
    r"C:\Windows\Fonts\segoeui.ttf",
    r"C:\Windows\Fonts\tahoma.ttf",
    r"C:\Windows\Fonts\calibri.ttf",
]
FONT = next((f for f in FONTS if os.path.exists(f)), None)

CFLAGS = subprocess.CREATE_NO_WINDOW if sys.platform == "win32" else 0


def log(*a):
    print(time.strftime("%H:%M:%S"), *a, flush=True)


def http(url, data=None, headers=None, timeout=15):
    h = {"User-Agent": UA}
    h.update(headers or {})
    req = urllib.request.Request(url, data=data, headers=h)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def write_atomic(path, text):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(text)
    os.replace(tmp, path)


# ---------- Bakü saati dosyası ----------
def clock_loop():
    last = ""
    while True:
        t = datetime.now(BAKU).strftime("%d.%m.%Y  %H:%M:%S")
        if t != last:
            write_atomic(os.path.join(HOME, "clock.txt"), t)
            last = t
        time.sleep(0.2)


# ---------- HLS sunucusu ----------
class Handler(SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, *a):
        pass


def serve():
    srv = ThreadingHTTPServer(("0.0.0.0", PORT), partial(Handler, directory=HLS))
    srv.serve_forever()


# ---------- Tünel (cloudflared) ----------
def register(url):
    try:
        body = json.dumps({"url": url}).encode()
        http(
            WORKER + "/api/relay",
            data=body,
            headers={"Content-Type": "application/json", "X-Admin-Key": ADMIN_KEY},
        )
        log("Tünel Worker'a kaydedildi:", url)
    except Exception as e:
        log("Tünel kaydedilemedi:", e)


def tunnel():
    if not os.path.exists(CF_BIN):
        log("cloudflared yok:", CF_BIN)
        return
    while True:
        p = subprocess.Popen(
            [CF_BIN, "tunnel", "--url", "http://127.0.0.1:%d" % PORT, "--no-autoupdate"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
            creationflags=CFLAGS,
        )
        for line in p.stderr:
            m = re.search(r"https://[a-z0-9-]+\.trycloudflare\.com", line)
            if m:
                register(m.group(0))
        p.wait()
        log("Tünel kapandı, 5 sn sonra yeniden açılıyor")
        time.sleep(5)


# ---------- FFmpeg ----------
def drawtext(inp, out, file, o):
    fs = max(8, int(H * o["size"] / 100))
    a = o["opacity"] / 100
    font = FONT.replace("\\", "/").replace(":", "\\:")
    return (
        "[%s]drawtext=fontfile='%s':textfile='%s':reload=1:fontsize=%d:"
        "fontcolor=white@%.2f:borderw=2:bordercolor=black@%.2f:line_spacing=6:"
        "x=(%d-text_w)*%s/100:y=(%d-text_h)*%s/100[%s]"
        % (inp, font, file, fs, a, a, W, o["x"], H, o["y"], out)
    )


def build_cmd(logo, ov):
    cmd = [
        FFMPEG, "-hide_banner", "-loglevel", "warning",
        "-fflags", "+genpts+discardcorrupt",
        "-user_agent", UA,
        "-reconnect", "1", "-reconnect_streamed", "1", "-reconnect_delay_max", "5",
        "-allowed_extensions", "ALL", "-allowed_segment_extensions", "ALL", "-extension_picky", "0",
        "-i", WORKER + "/live.m3u8?direct=1",
    ]
    if logo:
        cmd += ["-loop", "1", "-framerate", "25", "-i", "logo.img"]

    ch = [
        "[0:v]scale=%d:%d:force_original_aspect_ratio=decrease,"
        "pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=25[v0]" % (W, H, W, H)
    ]
    last = "v0"
    if logo:
        lw = max(8, int(W * logo["size"] / 100) // 2 * 2)
        op = logo["opacity"] / 100
        ch.append("[1:v]scale=%d:-2,format=rgba,colorchannelmixer=aa=%.2f[lg]" % (lw, op))
        ch.append(
            "[%s][lg]overlay=x=(main_w-overlay_w)*%s/100:y=(main_h-overlay_h)*%s/100[v1]"
            % (last, logo["x"], logo["y"])
        )
        last = "v1"
    if FONT and ov["clock"]["on"]:
        ch.append(drawtext(last, "v2", "clock.txt", ov["clock"]))
        last = "v2"
    if FONT and ov["info"]["on"]:
        ch.append(drawtext(last, "v3", "info.txt", ov["info"]))
        last = "v3"

    cmd += [
        "-filter_complex", ";".join(ch),
        "-map", "[%s]" % last, "-map", "0:a:0?",
        "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
        "-b:v", VBR, "-maxrate", VBR, "-bufsize", "5000k",
        "-g", "100", "-keyint_min", "100", "-sc_threshold", "0",
        "-c:a", "aac", "-b:a", "128k", "-ar", "44100", "-ac", "2",
        "-af", "aresample=async=1:first_pts=0",
        "-f", "hls", "-hls_time", "4", "-hls_list_size", "6",
        "-hls_flags", "delete_segments+omit_endlist+independent_segments",
        "-start_number", str(int(time.time() // 4)),
        "-hls_segment_filename", os.path.join(HLS, "s%d.ts"),
        os.path.join(HLS, "live.m3u8"),
    ]
    return cmd


def clear_hls():
    for f in os.listdir(HLS):
        try:
            os.remove(os.path.join(HLS, f))
        except OSError:
            pass


def get_logo(now, cache):
    lg = now.get("logo") or {}
    if not (lg.get("on") and lg.get("url")):
        return None
    u = lg["url"]
    full = u if u.startswith("http") else WORKER + u
    if cache.get("url") != full:
        try:
            data = http(full, timeout=20)
            with open(os.path.join(HOME, "logo.img"), "wb") as f:
                f.write(data)
            cache["url"] = full
        except Exception as e:
            log("Logo indirilemedi:", e)
            cache["url"] = None
            return None
    return lg


def main():
    if not FONT:
        log("UYARI: yazı tipi bulunamadı, saat ve film adı yazılamaz.")
    if not os.path.exists(FFMPEG):
        log("UYARI: FFmpeg bulunamadı:", FFMPEG)
    os.makedirs(HLS, exist_ok=True)
    os.chdir(HOME)
    write_atomic("info.txt", " ")
    for fn in (serve, clock_loop, tunnel):
        threading.Thread(target=fn, daemon=True).start()

    proc = None
    cur_sig = None
    cache = {}
    last_fail = 0

    try:
        while True:
            try:
                now = json.loads(http(WORKER + "/api/now"))
            except Exception as e:
                log("Worker'a ulaşılamadı:", e)
                time.sleep(POLL)
                continue

            ov = now.get("ov")
            if not ov:
                log("Worker'da katman ayarı yok (yamaları uyguladın mı?)")
                time.sleep(POLL)
                continue

            if now.get("running"):
                txt = " "
                if now.get("showInfo", True) and now.get("title"):
                    txt = now["title"] + ("\n" + now["bolum"] if now.get("bolum") else "")
                if ov["info"]["on"]:
                    write_atomic("info.txt", txt)
                logo = get_logo(now, cache)
                sig = json.dumps([logo, ov["clock"]["on"], ov["clock"], ov["info"]["on"], ov["info"], W, H], sort_keys=True)

                dead = proc is None or proc.poll() is not None
                if proc is not None and sig != cur_sig and not dead:
                    log("Ayar değişti, FFmpeg yeniden başlatılıyor")
                    proc.terminate()
                    try:
                        proc.wait(8)
                    except subprocess.TimeoutExpired:
                        proc.kill()
                    dead = True
                if dead and time.time() - last_fail >= 5:
                    clear_hls()
                    log("FFmpeg başlıyor")
                    proc = subprocess.Popen(build_cmd(logo, ov), creationflags=CFLAGS)
                    cur_sig = sig
                    last_fail = time.time()
            else:
                if proc is not None and proc.poll() is None:
                    log("Yayın kapalı, FFmpeg durduruluyor")
                    proc.terminate()
                    proc = None
            time.sleep(POLL)
    except KeyboardInterrupt:
        pass
    finally:
        if proc is not None and proc.poll() is None:
            proc.terminate()


if __name__ == "__main__":
    main()
