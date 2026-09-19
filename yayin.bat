# ============================================================
# ZEM TV HABER
# TEK DOSYA HABER + AI SES + FFmpeg + SSH101 RTMP
# Windows VDS
# ============================================================

import os
import sys
import time
import json
import re
import html
import hashlib
import subprocess
import urllib.request
import urllib.parse
import xml.etree.ElementTree as ET
from datetime import datetime
from pathlib import Path

# ============================================================
# AYARLAR
# ============================================================

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101"
STREAM_KEY = "telegram"

RTMP_SERVER = f"{RTMP_URL}/{STREAM_KEY}"

# ZEM TV HABER LOGOSU
# Buraya kendi Zem TV Haber logo PNG adresini koyabilirsin.
LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"

# FFmpeg
FFMPEG = "ffmpeg"

# Windows Arial
FONT_FILE = "C:/Windows/Fonts/arial.ttf"

# Yayın çözünürlüğü
WIDTH = 1280
HEIGHT = 720
FPS = 25

# Video bitrate
VIDEO_BITRATE = "3500k"

# Haber kontrol
HABER_KONTROL_SURESI = 120

# Her haber yaklaşık kaç saniye
HABER_SURESI = 35

# ============================================================
# KLASÖRLER
# ============================================================

BASE_DIR = Path(__file__).resolve().parent

DATA_DIR = BASE_DIR / "zemtv_data"
AUDIO_DIR = DATA_DIR / "audio"
IMAGE_DIR = DATA_DIR / "images"
LOG_DIR = DATA_DIR / "logs"

for folder in [DATA_DIR, AUDIO_DIR, IMAGE_DIR, LOG_DIR]:
    folder.mkdir(parents=True, exist_ok=True)

HABER_DB = DATA_DIR / "haber_db.json"

LOG_FILE = LOG_DIR / "zemtv_haber.log"

# ============================================================
# RSS KAYNAKLARI
# ============================================================

RSS_KAYNAKLARI = [

    ("GÜNDEM", "https://www.haberturk.com/rss/manset.xml"),

    ("TÜRKİYE", "https://www.trthaber.com/manset_articles.rss"),

    ("EKONOMİ", "https://www.haberturk.com/rss/ekonomi.xml"),

    ("DÜNYA", "https://www.haberturk.com/rss/dunya.xml"),

    ("SPOR", "https://www.haberturk.com/rss/spor.xml"),

    ("TEKNOLOJİ", "https://www.haberturk.com/rss/teknoloji.xml"),
]

# ============================================================
# HTTP AYARLARI
# ============================================================

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 "
    "(KHTML, like Gecko) "
    "Chrome/120.0.0.0 Safari/537.36"
)

# ============================================================
# GLOBAL
# ============================================================

ffmpeg_proc = None
haberler = []
haber_index = 0

kur_usd = "--"
kur_eur = "--"
kur_altin = "--"

# ============================================================
# LOG
# ============================================================

def log(yazi):

    zaman = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    mesaj = f"[{zaman}] {yazi}"

    print(mesaj)

    try:

        with open(
            LOG_FILE,
            "a",
            encoding="utf-8"
        ) as f:

            f.write(mesaj + "\n")

    except:
        pass


# ============================================================
# URL İNDİR
# ============================================================

def indir(url, timeout=20):

    try:

        req = urllib.request.Request(
            url,
            headers={
                "User-Agent": USER_AGENT
            }
        )

        with urllib.request.urlopen(
            req,
            timeout=timeout
        ) as response:

            return response.read()

    except Exception as e:

        log(f"URL HATASI: {e}")

        return None


# ============================================================
# TEMİZ METİN
# ============================================================

def temizle(text):

    if not text:
        return ""

    text = html.unescape(text)

    text = re.sub(
        r"<[^>]+>",
        " ",
        text
    )

    text = text.replace(
        "\n",
        " "
    )

    text = re.sub(
        r"\s+",
        " ",
        text
    )

    return text.strip()


# ============================================================
# HABER ID
# ============================================================

def haber_id(baslik):

    return hashlib.sha256(
        baslik.lower()
        .strip()
        .encode("utf-8")
    ).hexdigest()


# ============================================================
# HABER DB
# ============================================================

def db_yukle():

    if not HABER_DB.exists():

        return []

    try:

        with open(
            HABER_DB,
            "r",
            encoding="utf-8"
        ) as f:

            return json.load(f)

    except:

        return []


def db_kaydet(db):

    try:

        with open(
            HABER_DB,
            "w",
            encoding="utf-8"
        ) as f:

            json.dump(
                db[-2000:],
                f,
                ensure_ascii=False,
                indent=2
            )

    except Exception as e:

        log(f"DB KAYIT HATASI: {e}")


# ============================================================
# RSS HABERLERİ
# ============================================================

def haberleri_topla():

    global haberler

    db = db_yukle()

    yeni_haberler = []

    for kategori, rss_url in RSS_KAYNAKLARI:

        log(
            f"HABER KONTROL: {kategori}"
        )

        data = indir(
            rss_url
        )

        if not data:
            continue

        try:

            root = ET.fromstring(data)

        except Exception as e:

            log(
                f"RSS OKUMA HATASI: {e}"
            )

            continue

        items = root.findall(
            ".//item"
        )

        for item in items[:20]:

            title = item.findtext(
                "title"
            )

            description = item.findtext(
                "description"
            )

            link = item.findtext(
                "link"
            )

            title = temizle(
                title
            )

            description = temizle(
                description
            )

            if not title:
                continue

            hid = haber_id(
                title
            )

            if hid in db:
                continue

            haber = {

                "id": hid,

                "kategori": kategori,

                "baslik": title,

                "aciklama": description,

                "link": link or "",

                "tarih":
                    datetime.now().strftime(
                        "%d.%m.%Y %H:%M"
                    )
            }

            yeni_haberler.append(
                haber
            )

            db.append(
                hid
            )

    db_kaydet(
        db
    )

    if yeni_haberler:

        # En eski haber önce yayınlansın
        yeni_haberler.reverse()

        haberler.extend(
            yeni_haberler
        )

        log(
            f"{len(yeni_haberler)} YENİ HABER EKLENDİ"
        )

    else:

        log(
            "Yeni haber bulunamadı."
        )


# ============================================================
# HABER METNİ
# ============================================================

def haber_metni(haber):

    baslik = haber["baslik"]

    aciklama = haber.get(
        "aciklama",
        ""
    )

    kategori = haber.get(
        "kategori",
        "GÜNDEM"
    )

    # Açıklama yoksa başlık üzerinden
    if len(aciklama) < 20:

        metin = (
            f"{baslik}. "
            f"{kategori} başlığında yaşanan gelişmeler "
            f"yakından takip ediliyor. "
            f"Yeni gelişmeler oldukça aktarmaya devam edeceğiz."
        )

    else:

        # Çok uzun açıklamayı kısalt
        if len(aciklama) > 450:

            aciklama = aciklama[:450]

        metin = (
            f"{baslik}. "
            f"{aciklama}. "
            f"ZEM TV Haber gelişmeleri takip ediyor."
        )

    return metin


# ============================================================
# WINDOWS TTS
# ============================================================

def ses_olustur(metin, output_wav):

    """
    Windows SpeechSynthesizer kullanılır.

    Eğer VDS'de Türkçe ses yüklüyse
    otomatik olarak Türkçe sesi seçmeye çalışır.
    """

    txt_file = DATA_DIR / (
        "tts_" +
        hashlib.md5(
            metin.encode("utf-8")
        ).hexdigest() +
        ".txt"
    )

    try:

        with open(
            txt_file,
            "w",
            encoding="utf-8"
        ) as f:

            f.write(metin)

        ps = f"""
Add-Type -AssemblyName System.Speech

$text = Get-Content -Raw -Encoding UTF8 "{txt_file}"

$s = New-Object System.Speech.Synthesis.SpeechSynthesizer

$s.Rate = 0
$s.Volume = 100

$voices = $s.GetInstalledVoices()

$turkce = $voices |
    Where-Object {{
        $_.VoiceInfo.Culture.Name -like "tr-*"
    }} |
    Select-Object -First 1

if ($turkce) {{
    $s.SelectVoice(
        $turkce.VoiceInfo.Name
    )
}}

$s.SetOutputToWaveFile(
    "{output_wav}"
)

$s.Speak($text)

$s.Dispose()
"""

        result = subprocess.run(

            [
                "powershell",
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-Command",
                ps
            ],

            capture_output=True,

            text=True

        )

        if result.returncode != 0:

            log(
                "TTS HATASI: " +
                result.stderr
            )

            return False

        return output_wav.exists()

    except Exception as e:

        log(
            f"TTS HATASI: {e}"
        )

        return False


# ============================================================
# TCMB KURLARI
# ============================================================

def kurlari_getir():

    global kur_usd
    global kur_eur

    try:

        url = (
            "https://www.tcmb.gov.tr/"
            "kurlar/today.xml"
        )

        data = indir(
            url
        )

        if not data:
            return

        root = ET.fromstring(
            data
        )

        usd = None
        eur = None

        for currency in root.findall(
            "Currency"
        ):

            code = currency.attrib.get(
                "CurrencyCode"
            )

            selling = currency.findtext(
                "ForexSelling"
            )

            if code == "USD":
                usd = selling

            if code == "EUR":
                eur = selling

        if usd:
            kur_usd = usd

        if eur:
            kur_eur = eur

        log(
            f"KURLAR USD={kur_usd} EUR={kur_eur}"
        )

    except Exception as e:

        log(
            f"KUR HATASI: {e}"
        )


# ============================================================
# ALTBANT METNİ
# ============================================================

def ticker_metni():

    return (
        f"ZEM TV HABER  •  "
        f"DOLAR {kur_usd} TL  •  "
        f"EURO {kur_eur} TL  •  "
        f"SON DAKİKA GELİŞMELER  •  "
        f"ZEM MEDYA"
    )


# ============================================================
# LOGOYU İNDİR
# ============================================================

def logo_hazirla():

    logo_file = DATA_DIR / "zemtv_logo.png"

    if logo_file.exists():

        return logo_file

    log(
        "ZEM TV logosu indiriliyor..."
    )

    data = indir(
        LOGO_URL
    )

    if data:

        try:

            with open(
                logo_file,
                "wb"
            ) as f:

                f.write(data)

            return logo_file

        except:
            pass

    return None


# ============================================================
# HABER GÖRSELİ
# ============================================================

def haber_gorseli(haber):

    """
    Harici haber görsellerini kopyalamak yerine
    haber başlığından ZEM TV Haber grafik ekranı oluşturulur.

    Bu nedenle dışarıdan haber fotoğrafı almak zorunda kalmaz.
    """

    try:

        from PIL import Image, ImageDraw, ImageFont

    except ImportError:

        log(
            "Pillow bulunamadı. "
            "pip install pillow"
        )

        return None

    output = IMAGE_DIR / (
        haber["id"] + ".png"
    )

    if output.exists():

        return output

    img = Image.new(
        "RGB",
        (WIDTH, HEIGHT),
        (15, 15, 15)
    )

    draw = ImageDraw.Draw(
        img
    )

    try:

        font_big = ImageFont.truetype(
            FONT_FILE.replace(
                "/",
                "\\"
            ),
            42
        )

        font_small = ImageFont.truetype(
            FONT_FILE.replace(
                "/",
                "\\"
            ),
            25
        )

    except:

        font_big = None
        font_small = None

    # Üst bant
    draw.rectangle(
        [0, 0, WIDTH, 95],
        fill=(120, 0, 0)
    )

    draw.text(
        (35, 25),
        "ZEM TV HABER",
        fill="white",
        font=font_big
    )

    # Kategori
    draw.rectangle(
        [40, 145, 250, 195],
        fill=(220, 0, 0)
    )

    draw.text(
        (60, 155),
        haber["kategori"],
        fill="white",
        font=font_small
    )

    # Başlık
    baslik = haber["baslik"]

    # Başlığı satırlara böl
    words = baslik.split()

    lines = []
    current = ""

    for word in words:

        test = (
            current + " " + word
        ).strip()

        if len(test) > 42:

            lines.append(
                current
            )

            current = word

        else:

            current = test

    if current:
        lines.append(
            current
        )

    y = 250

    for line in lines[:5]:

        draw.text(
            (60, y),
            line,
            fill="white",
            font=font_big
        )

        y += 65

    # Tarih
    draw.text(
        (60, 590),
        datetime.now().strftime(
            "%d.%m.%Y %H:%M"
        ),
        fill=(220, 220, 220),
        font=font_small
    )

    # Alt bant
    draw.rectangle(
        [0, 650, WIDTH, HEIGHT],
        fill=(0, 0, 0)
    )

    draw.text(
        (30, 670),
        "ZEM TV HABER • Gelişmeler aktarılıyor",
        fill="white",
        font=font_small
    )

    img.save(
        output
    )

    return output


# ============================================================
# HABER VİDEOSU OLUŞTUR
# ============================================================

def haber_videosu_olustur(
    haber
):

    logo = logo_hazirla()

    image = haber_gorseli(
        haber
    )

    if not image:

        return None

    metin = haber_metni(
        haber
    )

    audio = AUDIO_DIR / (
        haber["id"] + ".wav"
    )

    if not audio.exists():

        log(
            "Yapay zeka spiker sesi hazırlanıyor..."
        )

        if not ses_olustur(
            metin,
            audio
        ):

            log(
                "Ses oluşturulamadı."
            )

            return None

    output = DATA_DIR / (
        haber["id"] + ".mp4"
    )

    if output.exists():

        return output

    # --------------------------------------------------------
    # FFmpeg filtreleri
    # --------------------------------------------------------

    filter_parts = [

        "[0:v]scale=1280:720[base]"
    ]

    input_args = [

        FFMPEG,

        "-y",

        "-loop",
        "1",

        "-i",
        str(image),

        "-i",
        str(audio)
    ]

    if logo:

        input_args += [

            "-loop",
            "1",

            "-i",
            str(logo)
        ]

        filter_complex = (

            "[0:v]"
            "scale=1280:720"
            "[base];"

            "[2:v]"
            "scale=-1:85"
            "[logo];"

            "[base][logo]"
            "overlay=W-w-15:15"
            "[v1];"

            "[v1]"
            "drawbox="
            "x=0:y=650:w=1280:h=70:"
            "color=black@0.92:t=fill"
            "[v2];"

            "[v2]"
            "drawtext="
            f"fontfile='{FONT_FILE}':"
            "text='ZEM TV HABER':"
            "fontcolor=white:"
            "fontsize=20:"
            "x=20:y=665"
            "[v3]"
        )

        input_args += [

            "-filter_complex",
            filter_complex,

            "-map",
            "[v3]",

            "-map",
            "1:a"
        ]

    else:

        filter_complex = (

            "[0:v]"
            "scale=1280:720"
            "[v1];"

            "[v1]"
            "drawbox="
            "x=0:y=650:w=1280:h=70:"
            "color=black@0.92:t=fill"
            "[v2];"

            "[v2]"
            "drawtext="
            f"fontfile='{FONT_FILE}':"
            "text='ZEM TV HABER':"
            "fontcolor=white:"
            "fontsize=20:"
            "x=20:y=665"
            "[v3]"
        )

        input_args += [

            "-filter_complex",
            filter_complex,

            "-map",
            "[v3]",

            "-map",
            "1:a"
        ]

    input_args += [

        "-t",
        str(HABER_SURESI),

        "-r",
        str(FPS),

        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-tune",
        "zerolatency",

        "-b:v",
        VIDEO_BITRATE,

        "-maxrate",
        "4000k",

        "-bufsize",
        "8000k",

        "-pix_fmt",
        "yuv420p",

        "-g",
        "50",

        "-c:a",
        "aac",

        "-b:a",
        "128k",

        "-ar",
        "44100",

        "-ac",
        "2",

        "-shortest",

        str(output)
    ]

    log(
        "Haber videosu oluşturuluyor..."
    )

    try:

        result = subprocess.run(
            input_args,
            capture_output=True,
            text=True
        )

        if result.returncode != 0:

            log(
                "VIDEO HATASI:"
            )

            log(
                result.stderr[-2000:]
            )

            return None

        return output

    except Exception as e:

        log(
            f"VIDEO OLUŞTURMA HATASI: {e}"
        )

        return None


# ============================================================
# HABERİ SSH101'E GÖNDER
# ============================================================

def haberi_yayinla(video_file):

    global ffmpeg_proc

    if not video_file:

        return

    log(
        "SSH101'e haber gönderiliyor..."
    )

    command = [

        FFMPEG,

        "-hide_banner",

        "-loglevel",
        "warning",

        "-re",

        "-stream_loop",
        "-1",

        "-i",
        str(video_file),

        "-map",
        "0:v:0",

        "-map",
        "0:a:0",

        "-c:v",
        "copy",

        "-c:a",
        "aac",

        "-b:a",
        "128k",

        "-ar",
        "44100",

        "-ac",
        "2",

        "-flvflags",
        "no_duration_filesize",

        "-f",
        "flv",

        RTMP_SERVER
    ]

    try:

        ffmpeg_proc = subprocess.Popen(

            command,

            stdout=subprocess.PIPE,

            stderr=subprocess.STDOUT,

            creationflags=(
                subprocess.CREATE_NEW_PROCESS_GROUP
                if os.name == "nt"
                else 0
            )
        )

        log(
            f"YAYIN AKTİF PID={ffmpeg_proc.pid}"
        )

        # Haber süresi kadar bekle
        # + birkaç saniye güvenlik payı

        baslangic = time.time()

        while time.time() - baslangic < HABER_SURESI:

            if ffmpeg_proc.poll() is not None:

                log(
                    "FFmpeg erken kapandı."
                )

                break

            time.sleep(1)

        # Haberi kapat

        if ffmpeg_proc.poll() is None:

            try:

                ffmpeg_proc.terminate()

                ffmpeg_proc.wait(
                    timeout=5
                )

            except:

                try:

                    ffmpeg_proc.kill()

                except:
                    pass

        ffmpeg_proc = None

    except Exception as e:

        log(
            f"RTMP HATASI: {e}"
        )


# ============================================================
# HABER YAYIN DÖNGÜSÜ
# ============================================================

def haber_yayin_dongusu():

    global haber_index

    while True:

        try:

            # Liste boşsa haberleri getir
            if not haberler:

                haberleri_topla()

                if not haberler:

                    log(
                        "Haber yok. "
                        "30 saniye bekleniyor."
                    )

                    time.sleep(30)

                    continue

            # Sıradaki haber
            if haber_index >= len(haberler):

                haber_index = 0

                haberler.clear()

                haberleri_topla()

                continue

            haber = haberler[
                haber_index
            ]

            haber_index += 1

            log("")
            log(
                "================================"
            )

            log(
                f"HABER: {haber['baslik']}"
            )

            log(
                f"KATEGORİ: {haber['kategori']}"
            )

            log(
                "================================"
            )

            # Video oluştur
            video = haber_videosu_olustur(
                haber
            )

            if video:

                haberi_yayinla(
                    video
                )

            # Belirli sayıda haberden sonra
            # RSS tekrar kontrol

            if haber_index % 3 == 0:

                haberleri_topla()

        except Exception as e:

            log(
                f"HABER DÖNGÜSÜ HATASI: {e}"
            )

            time.sleep(10)


# ============================================================
# KUR DÖNGÜSÜ
# ============================================================

def kur_dongusu():

    while True:

        try:

            kurlari_getir()

        except Exception as e:

            log(
                f"KUR DÖNGÜ HATASI: {e}"
            )

        # 15 dakika
        time.sleep(900)


# ============================================================
# BAŞLANGIÇ
# ============================================================

def main():

    log("")
    log("=" * 65)
    log("              ZEM TV HABER")
    log("=" * 65)
    log("")
    log(
        f"RTMP: {RTMP_SERVER}"
    )

    log(
        f"FFMPEG: {FFMPEG}"
    )

    log(
        f"LOGO: {LOGO_URL}"
    )

    log(
        "Sistem başlatılıyor..."
    )

    # FFmpeg kontrol
    try:

        result = subprocess.run(
            [
                FFMPEG,
                "-version"
            ],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        )

        if result.returncode != 0:

            log(
                "FFmpeg çalışmıyor."
            )

            return

    except:

        log(
            "FFmpeg bulunamadı."
        )

        log(
            "FFMPEG değişkenini kontrol et."
        )

        return

    # Logo
    logo_hazirla()

    # İlk kurlar
    kurlari_getir()

    # İlk haberler
    haberleri_topla()

    # Haber yayınını başlat
    try:

        haber_yayin_dongusu()

    except KeyboardInterrupt:

        log(
            "CTRL+C - sistem kapatılıyor."
        )

    finally:

        if ffmpeg_proc:

            try:

                ffmpeg_proc.terminate()

            except:
                pass

        log(
            "ZEM TV HABER KAPATILDI."
        )


# ============================================================
# PROGRAM
# ============================================================

if __name__ == "__main__":

    main()
