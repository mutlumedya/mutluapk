# ============================================================
# ZEM TV HABER
# WINDOWS VDS + FFMPEG + SSH101
# TEK DOSYA SİSTEM
# ============================================================

import os
import sys
import time
import json
import re
import html
import shutil
import subprocess
import urllib.request
import urllib.parse
import xml.etree.ElementTree as ET
from pathlib import Path
from datetime import datetime

# ============================================================
# AYARLAR
# ============================================================

RTMP_SERVER = "rtmp://ssh101.bozztv.com:1935/ssh101/zentvhaber"

KANAL_ADI = "ZEM TV HABER"

# ZEM TV logo
LOGO_URL = (
    "https://raw.githubusercontent.com/mutlumedya/cine/"
    "refs/heads/main/telegram.png"
)

# Hava durumu
HAVA_SEHRI = "Konya"

# AI SPİKER
TTS_VOICE = "tr-TR-AhmetNeural"

# Haber yenileme
HABER_KONTROL_DK = 5

# Video
VIDEO_WIDTH = 1280
VIDEO_HEIGHT = 720
VIDEO_FPS = 25

# ============================================================
# KLASÖRLER
# ============================================================

BASE_DIR = Path(__file__).resolve().parent

DATA_DIR = BASE_DIR / "zemtv_data"
SES_DIR = DATA_DIR / "ses"
LOGO_DIR = DATA_DIR / "logo"
TEMP_DIR = DATA_DIR / "temp"

for folder in [
    DATA_DIR,
    SES_DIR,
    LOGO_DIR,
    TEMP_DIR
]:
    folder.mkdir(
        parents=True,
        exist_ok=True
    )

LOG_FILE = BASE_DIR / "zemtv_haber.log"

LOGO_FILE = LOGO_DIR / "logo.png"

# ============================================================
# LOG
# ============================================================

def log(text):

    zaman = datetime.now().strftime(
        "%Y-%m-%d %H:%M:%S"
    )

    satir = f"[{zaman}] {text}"

    print(satir)

    try:

        with open(
            LOG_FILE,
            "a",
            encoding="utf-8"
        ) as f:
            f.write(
                satir + "\n"
            )

    except Exception:
        pass


# ============================================================
# PROGRAM BUL
# ============================================================

def program_bul():

    adaylar = [

        r"C:\ffmpeg\bin\ffmpeg.exe",

        r"C:\ffmpeg\ffmpeg.exe",

        "ffmpeg"
    ]

    for aday in adaylar:

        if os.path.isfile(aday):
            return aday

        bulunan = shutil.which(aday)

        if bulunan:
            return bulunan

    return None


FFMPEG = program_bul()

if not FFMPEG:

    log(
        "HATA: FFmpeg bulunamadı!"
    )

    log(
        r"FFmpeg'i C:\ffmpeg\bin\ klasörüne koy."
    )

    input(
        "Kapatmak için ENTER..."
    )

    sys.exit(1)


# ============================================================
# PAKET KONTROL
# ============================================================

def edge_tts_kontrol():

    try:

        import edge_tts

        return edge_tts

    except ImportError:

        log(
            "edge-tts bulunamadı. Kuruluyor..."
        )

        try:

            subprocess.check_call(
                [
                    sys.executable,
                    "-m",
                    "pip",
                    "install",
                    "--upgrade",
                    "edge-tts"
                ]
            )

        except Exception as e:

            log(
                f"edge-tts kurulamadı: {e}"
            )

            return None

        try:

            import edge_tts

            return edge_tts

        except Exception as e:

            log(
                f"edge-tts başlatılamadı: {e}"
            )

            return None


edge_tts = edge_tts_kontrol()


# ============================================================
# USER AGENT
# ============================================================

USER_AGENT = (
    "Mozilla/5.0 "
    "(Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 "
    "(KHTML, like Gecko) "
    "Chrome/120.0 Safari/537.36"
)


# ============================================================
# HTTP
# ============================================================

def indir(url, timeout=8):

    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "*/*"
        }
    )

    with urllib.request.urlopen(
        request,
        timeout=timeout
    ) as response:

        return response.read()


# ============================================================
# LOGO
# ============================================================

def logo_indir():

    if LOGO_FILE.exists():

        if LOGO_FILE.stat().st_size > 100:
            return True

    log(
        "ZEM TV logosu indiriliyor..."
    )

    try:

        data = indir(
            LOGO_URL,
            timeout=10
        )

        with open(
            LOGO_FILE,
            "wb"
        ) as f:

            f.write(data)

        if LOGO_FILE.stat().st_size < 100:

            raise Exception(
                "Logo dosyası geçersiz."
            )

        log(
            "Logo başarıyla indirildi."
        )

        return True

    except Exception as e:

        log(
            f"Logo indirilemedi: {e}"
        )

        return False


# ============================================================
# HTML TEMİZLE
# ============================================================

def temizle(text):

    if not text:
        return ""

    text = html.unescape(
        text
    )

    text = re.sub(
        r"<script.*?</script>",
        " ",
        text,
        flags=re.I | re.S
    )

    text = re.sub(
        r"<style.*?</style>",
        " ",
        text,
        flags=re.I | re.S
    )

    text = re.sub(
        r"<[^>]+>",
        " ",
        text
    )

    text = re.sub(
        r"\s+",
        " ",
        text
    )

    return text.strip()


# ============================================================
# RSS OKU
# ============================================================

def rss_oku(
    kategori,
    url
):

    log(
        f"HABER KONTROL: {kategori}"
    )

    try:

        data = indir(
            url,
            timeout=7
        )

        root = ET.fromstring(
            data
        )

        items = root.findall(
            ".//item"
        )

        haberler = []

        for item in items:

            title = item.findtext(
                "title",
                ""
            )

            description = item.findtext(
                "description",
                ""
            )

            link = item.findtext(
                "link",
                ""
            )

            title = temizle(
                title
            )

            description = temizle(
                description
            )

            if not title:
                continue

            haberler.append(
                {
                    "baslik": title,
                    "aciklama": description,
                    "kategori": kategori,
                    "link": link
                }
            )

        return haberler

    except Exception as e:

        log(
            f"URL HATASI {kategori}: {e}"
        )

        return []


# ============================================================
# HABER KAYNAKLARI
# ============================================================

RSS_LISTESI = [

    (
        "GÜNDEM",
        "https://www.haberturk.com/rss/anasayfa"
    ),

    (
        "TRT SON DAKİKA",
        "https://www.trthaber.com/rss/sondakika.rss"
    ),

    (
        "TRT GÜNDEM",
        "https://www.trthaber.com/rss/gundem.rss"
    ),

    (
        "TRT TÜRKİYE",
        "https://www.trthaber.com/rss/turkiye.rss"
    ),

    (
        "TRT DÜNYA",
        "https://www.trthaber.com/rss/dunya.rss"
    ),

    (
        "TRT EKONOMİ",
        "https://www.trthaber.com/rss/ekonomi.rss"
    ),

    (
        "TRT SPOR",
        "https://www.trthaber.com/rss/spor.rss"
    ),

    (
        "TRT TEKNOLOJİ",
        "https://www.trthaber.com/rss/bilim-teknoloji.rss"
    ),

    (
        "NTV",
        "https://www.ntv.com.tr/son-dakika.rss"
    ),

    (
        "CNN TÜRK",
        "https://www.cnnturk.com/feed/rss/all/news"
    )
]


# ============================================================
# HABER TOPLA
# ============================================================

def haberleri_getir():

    tum_haberler = []

    kullanilan_basliklar = set()

    for kategori, url in RSS_LISTESI:

        haberler = rss_oku(
            kategori,
            url
        )

        for haber in haberler:

            baslik = haber[
                "baslik"
            ].lower()

            baslik = re.sub(
                r"[^a-z0-9ğüşöçıİĞÜŞÖÇ ]",
                "",
                baslik
            )

            baslik = re.sub(
                r"\s+",
                " ",
                baslik
            ).strip()

            if not baslik:
                continue

            if baslik in kullanilan_basliklar:
                continue

            kullanilan_basliklar.add(
                baslik
            )

            tum_haberler.append(
                haber
            )

    log(
        f"{len(tum_haberler)} YENİ HABER EKLENDİ"
    )

    return tum_haberler


# ============================================================
# HABER METNİ
# ============================================================

def spiker_metni(haber):

    baslik = haber[
        "baslik"
    ]

    aciklama = haber[
        "aciklama"
    ]

    # Çok uzun RSS açıklamasını kes
    if len(aciklama) > 600:

        aciklama = aciklama[
            :600
        ]

    if aciklama:

        metin = (
            "ZEM TV Haber. "
            "Günün öne çıkan gelişmesi. "
            f"{baslik}. "
            f"{aciklama}. "
            "Gelişmeler oldukça "
            "aktarmaya devam edeceğiz."
        )

    else:

        metin = (
            "ZEM TV Haber. "
            "Son dakika gelişmesi. "
            f"{baslik}. "
            "Yeni bilgiler geldikçe "
            "sizlere aktarmaya devam edeceğiz."
        )

    return temizle(
        metin
    )


# ============================================================
# AI SES
# ============================================================

def ses_olustur(
    metin,
    dosya
):

    if edge_tts is None:

        return False

    import asyncio

    async def create():

        communicator = (
            edge_tts.Communicate(
                metin,
                TTS_VOICE,
                rate="+0%",
                volume="+0%",
                pitch="+0Hz"
            )
        )

        await communicator.save(
            str(dosya)
        )

    try:

        asyncio.run(
            create()
        )

        return (
            dosya.exists()
            and dosya.stat().st_size > 1000
        )

    except Exception as e:

        log(
            f"AI SES HATASI: {e}"
        )

        return False


# ============================================================
# HAVA DURUMU
# ============================================================

hava_cache = {
    "metin": "HAVA DURUMU",
    "zaman": 0
}


def hava_durumu():

    global hava_cache

    if (
        time.time()
        - hava_cache["zaman"]
        < 600
    ):

        return hava_cache["metin"]

    try:

        geo_url = (
            "https://geocoding-api.open-meteo.com/v1/search?"
            + urllib.parse.urlencode(
                {
                    "name": HAVA_SEHRI,
                    "count": 1,
                    "language": "tr",
                    "format": "json"
                }
            )
        )

        geo = json.loads(
            indir(
                geo_url,
                timeout=5
            ).decode(
                "utf-8",
                errors="ignore"
            )
        )

        results = geo.get(
            "results",
            []
        )

        if not results:

            raise Exception(
                "Şehir bulunamadı"
            )

        latitude = results[0][
            "latitude"
        ]

        longitude = results[0][
            "longitude"
        ]

        weather_url = (
            "https://api.open-meteo.com/v1/forecast?"
            + urllib.parse.urlencode(
                {
                    "latitude": latitude,
                    "longitude": longitude,
                    "current": (
                        "temperature_2m,"
                        "weather_code"
                    ),
                    "daily": (
                        "temperature_2m_max,"
                        "temperature_2m_min"
                    ),
                    "forecast_days": 1,
                    "timezone": "auto"
                }
            )
        )

        data = json.loads(
            indir(
                weather_url,
                timeout=5
            ).decode(
                "utf-8",
                errors="ignore"
            )
        )

        current = data[
            "current"
        ]

        temperature = current[
            "temperature_2m"
        ]

        code = current[
            "weather_code"
        ]

        durumlar = {

            0: "AÇIK",

            1: "AZ BULUTLU",
            2: "PARÇALI BULUTLU",
            3: "KAPALI",

            45: "SİSLİ",
            48: "SİSLİ",

            51: "ÇİSELEME",
            53: "ÇİSELEME",
            55: "ÇİSELEME",

            61: "YAĞMUR",
            63: "YAĞMUR",
            65: "KUVVETLİ YAĞMUR",

            71: "KAR",
            73: "KAR",
            75: "YOĞUN KAR",

            80: "SAĞANAK",
            81: "SAĞANAK",
            82: "KUVVETLİ SAĞANAK",

            95: "FIRTINA",
            96: "FIRTINA",
            99: "FIRTINA"
        }

        durum = durumlar.get(
            code,
            "DEĞİŞKEN"
        )

        minimum = data[
            "daily"
        ][
            "temperature_2m_min"
        ][0]

        maksimum = data[
            "daily"
        ][
            "temperature_2m_max"
        ][0]

        metin = (
            f"{HAVA_SEHRI.upper()} "
            f"{temperature:.0f}°C "
            f"{durum} "
            f"GÜN {minimum:.0f}°/"
            f"{maksimum:.0f}°"
        )

        hava_cache = {
            "metin": metin,
            "zaman": time.time()
        }

        return metin

    except Exception as e:

        log(
            f"HAVA HATASI: {e}"
        )

        return hava_cache[
            "metin"
        ]


# ============================================================
# DÖVİZ / ALTIN
# ============================================================

finans_cache = {
    "metin": (
        "DOLAR -- TL   |   "
        "EURO -- TL   |   "
        "GRAM ALTIN -- TL   |   "
        "ÇEYREK ALTIN -- TL"
    ),
    "zaman": 0
}


def finans():

    global finans_cache

    if (
        time.time()
        - finans_cache["zaman"]
        < 300
    ):

        return finans_cache[
            "metin"
        ]

    try:

        url = (
            "https://finans.truncgil.com/"
            "today.json"
        )

        data = json.loads(
            indir(
                url,
                timeout=7
            ).decode(
                "utf-8",
                errors="ignore"
            )
        )

        def bul(*keys):

            for key in keys:

                if key not in data:
                    continue

                value = data[key]

                if isinstance(
                    value,
                    dict
                ):

                    value = (
                        value.get("Satış")
                        or value.get("Satis")
                        or value.get("Alış")
                        or value.get("Alis")
                    )

                if value:
                    return str(
                        value
                    )

            return "--"

        dolar = bul(
            "USD",
            "Amerikan Doları"
        )

        euro = bul(
            "EUR",
            "Euro"
        )

        gram = bul(
            "Gram Altın",
            "gram-altin"
        )

        ceyrek = bul(
            "Çeyrek Altın",
            "ceyrek-altin"
        )

        metin = (
            f"DOLAR {dolar} TL   |   "
            f"EURO {euro} TL   |   "
            f"GRAM ALTIN {gram} TL   |   "
            f"ÇEYREK ALTIN {ceyrek} TL"
        )

        finans_cache = {
            "metin": metin,
            "zaman": time.time()
        }

        return metin

    except Exception as e:

        log(
            f"FİNANS HATASI: {e}"
        )

        return finans_cache[
            "metin"
        ]


# ============================================================
# FFmpeg TEXT KAÇIŞI
# ============================================================

def ffmpeg_text(text):

    text = str(text)

    text = text.replace(
        "\\",
        "\\\\"
    )

    text = text.replace(
        "'",
        "\\'"
    )

    text = text.replace(
        ":",
        "\\:"
    )

    text = text.replace(
        "%",
        "\\%"
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
# HABER RESMİ YERİNE FFmpeg BACKGROUND
# ============================================================

def haber_yayinla(
    haber
):

    zaman = str(
        int(time.time())
    )

    ses_file = SES_DIR / (
        zaman + ".mp3"
    )

    metin = spiker_metni(
        haber
    )

    log("")
    log(
        f"HABER: {haber['baslik']}"
    )
    log(
        f"KATEGORİ: {haber['kategori']}"
    )
    log(
        f"SPİKER: {metin}"
    )

    # --------------------------------------------------------
    # AI SES
    # --------------------------------------------------------

    if not ses_olustur(
        metin,
        ses_file
    ):

        log(
            "AI ses oluşturulamadı."
        )

        try:

            if ses_file.exists():
                ses_file.unlink()

        except Exception:
            pass

        return False

    # --------------------------------------------------------
    # Bilgiler
    # --------------------------------------------------------

    hava = ffmpeg_text(
        hava_durumu()
    )

    para = ffmpeg_text(
        finans()
    )

    baslik = ffmpeg_text(
        haber["baslik"]
    )

    kategori = ffmpeg_text(
        haber["kategori"]
    )

    font_file = (
        r"C:\Windows\Fonts\arial.ttf"
    )

    if not os.path.exists(
        font_file
    ):

        font_file = (
            r"C:\Windows\Fonts\segoeui.ttf"
        )

    font_file = font_file.replace(
        "\\",
        "/"
    )

    # --------------------------------------------------------
    # FFmpeg FILTER
    # --------------------------------------------------------

    filters = [

        # Arka plan
        (
            "color=c=0x111111:"
            "s=1280x720:"
            "r=25"
            "[bg]"
        ),

        # Logo
        (
            "[1:v]"
            "scale=220:-1"
            "[logo]"
        ),

        (
            "[bg][logo]"
            "overlay="
            "W-w-25:"
            "20"
            "[v1]"
        ),

        # Üst kırmızı bant
        (
            "[v1]"
            "drawbox="
            "x=0:"
            "y=0:"
            "w=1280:"
            "h=95:"
            "color=0xA50000:"
            "t=fill"
            "[v2]"
        ),

        # ZEM TV HABER
        (
            "[v2]"
            "drawtext="
            f"fontfile='{font_file}':"
            "text='ZEM TV HABER':"
            "x=35:"
            "y=22:"
            "fontsize=45:"
            "fontcolor=white"
            "[v3]"
        ),

        # SON GELİŞME
        (
            "[v3]"
            "drawtext="
            f"fontfile='{font_file}':"
            "text='SON GELİŞME':"
            "x=40:"
            "y=150:"
            "fontsize=27:"
            "fontcolor=0xFFD700"
            "[v4]"
        ),

        # Haber başlığı
        (
            "[v4]"
            "drawtext="
            f"fontfile='{font_file}':"
            f"text='{baslik}':"
            "x=45:"
            "y=205:"
            "fontsize=38:"
            "fontcolor=white:"
            "line_spacing=12:"
            "box=1:"
            "boxcolor=0x202020:"
            "boxborderw=25:"
            "enable='between(t,0,99999)'"
            "[v5]"
        ),

        # Alt siyah panel
        (
            "[v5]"
            "drawbox="
            "x=0:"
            "y=570:"
            "w=1280:"
            "h=150:"
            "color=black@0.92:"
            "t=fill"
            "[v6]"
        ),

        # Hava
        (
            "[v6]"
            "drawtext="
            f"fontfile='{font_file}':"
            f"text='{hava}':"
            "x=30:"
            "y=585:"
            "fontsize=23:"
            "fontcolor=white"
            "[v7]"
        ),

        # Kategori
        (
            "[v7]"
            "drawtext="
            f"fontfile='{font_file}':"
            f"text='{kategori}':"
            "x=30:"
            "y=625:"
            "fontsize=23:"
            "fontcolor=0xFFD700"
            "[v8]"
        ),

        # Canlı saat
        (
            "[v8]"
            "drawtext="
            f"fontfile='{font_file}':"
            "text='%{localtime\\:%d.%m.%Y %H\\\\:%M\\\\:%S}':"
            "x=1010:"
            "y=585:"
            "fontsize=21:"
            "fontcolor=white"
            "[v9]"
        ),

        # Alt ticker
        (
            "[v9]"
            "drawtext="
            f"fontfile='{font_file}':"
            f"text='{para}':"
            "x=1280-mod(t*80,w+tw):"
            "y=670:"
            "fontsize=23:"
            "fontcolor=white"
        )
    ]

    filter_complex = ";".join(
        filters
    )

    # --------------------------------------------------------
    # FFmpeg
    # --------------------------------------------------------

    cmd = [

        FFMPEG,

        "-hide_banner",

        "-loglevel",
        "warning",

        "-re",

        # Video background
        "-f",
        "lavfi",

        "-i",
        "color=c=0x111111:s=1280x720:r=25",

        # Logo
        "-loop",
        "1",

        "-i",
        str(LOGO_FILE),

        # Ses
        "-i",
        str(ses_file),

        "-filter_complex",
        filter_complex,

        "-map",
        "[v9]",

        "-map",
        "2:a:0",

        # Video
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

        "-r",
        "25",

        "-g",
        "50",

        "-keyint_min",
        "50",

        "-sc_threshold",
        "0",

        # Audio
        "-c:a",
        "aac",

        "-b:a",
        "128k",

        "-ar",
        "44100",

        "-ac",
        "2",

        # Ses bitince video da bitsin
        "-shortest",

        # RTMP
        "-f",
        "flv",

        "-flvflags",
        "no_duration_filesize",

        RTMP_SERVER
    ]

    log(
        "FFmpeg başlatılıyor..."
    )

    try:

        process = subprocess.Popen(
            cmd,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="ignore"
        )

        stderr = []

        while True:

            satir = process.stderr.readline()

            if satir:

                stderr.append(
                    satir.strip()
                )

            kod = process.poll()

            if kod is not None:
                break

            time.sleep(0.1)

        if process.returncode != 0:

            log(
                "FFmpeg yayın hatası:"
            )

            if stderr:

                for line in stderr[-15:]:

                    if line:
                        log(line)

            return False

        log(
            "Haber yayını tamamlandı."
        )

        return True

    except Exception as e:

        log(
            f"FFmpeg çalıştırma hatası: {e}"
        )

        return False

    finally:

        try:

            if ses_file.exists():
                ses_file.unlink()

        except Exception:
            pass


# ============================================================
# BEKLEME YAYINI
# ============================================================

def bekleme_yayini():

    log(
        "Yeni haber bulunamadı."
    )

    # Basit bilgi haberi
    haber = {
        "baslik": (
            "ZEM TV Haber yayın akışı devam ediyor"
        ),
        "aciklama": (
            "Yeni gelişmeler takip ediliyor. "
            "Son dakika haberleri için "
            "yayınımızı takip etmeye devam edin."
        ),
        "kategori": "CANLI YAYIN",
        "link": ""
    }

    return haber_yayinla(
        haber
    )


# ============================================================
# ANA SİSTEM
# ============================================================

def main():

    print()
    print(
        "================================================"
    )
    print(
        "              ZEM TV HABER"
    )
    print(
        "        OTOMATİK HABER SİSTEMİ"
    )
    print(
        "================================================"
    )
    print()

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

    # Logo
    logo_indir()

    # Haber listesi
    haberler = []

    son_kontrol = 0

    while True:

        try:

            # ------------------------------------------------
            # Haber kontrol
            # ------------------------------------------------

            if (
                not haberler
                or
                time.time()
                - son_kontrol
                >= HABER_KONTROL_DK * 60
            ):

                yeni_haberler = (
                    haberleri_getir()
                )

                if yeni_haberler:

                    haberler.extend(
                        yeni_haberler
                    )

                son_kontrol = (
                    time.time()
                )

            # ------------------------------------------------
            # Haber yayınla
            # ------------------------------------------------

            if haberler:

                haber = haberler.pop(
                    0
                )

                sonuc = haber_yayinla(
                    haber
                )

                if not sonuc:

                    log(
                        "Haber yayınında hata."
                    )

                    time.sleep(3)

            else:

                bekleme_yayini()

            # ------------------------------------------------
            # Liste çok büyümesin
            # ------------------------------------------------

            if len(haberler) > 50:

                haberler = haberler[
                    -50:
                ]

        except KeyboardInterrupt:

            log(
                "Sistem kullanıcı tarafından "
                "durduruldu."
            )

            break

        except Exception as e:

            log(
                f"ANA SİSTEM HATASI: {e}"
            )

            time.sleep(5)


# ============================================================
# BAŞLAT
# ============================================================

if __name__ == "__main__":

    main()
