# ============================================================
# ZEM TV HABER - OTOMATIK HABER KANALI
# WINDOWS VDS + FFMPEG + SSH101 RTMP
# TEK DOSYA
# ============================================================

import os
import sys
import time
import json
import re
import html
import ssl
import shutil
import subprocess
import urllib.request
import urllib.parse
import xml.etree.ElementTree as ET
import asyncio
from pathlib import Path
from datetime import datetime

# ============================================================
# AYARLAR
# ============================================================

RTMP_SERVER = "rtmp://ssh101.bozztv.com:1935/ssh101/zentvhaber"

KANAL_ADI = "ZEM TV HABER"

# Logo
LOGO_URL = (
    "https://raw.githubusercontent.com/mutlumedya/cine/"
    "refs/heads/main/telegram.png"
)

# Şehir
HAVA_SEHRI = "Konya"

# AI SPİKER
TTS_VOICE = "tr-TR-AhmetNeural"

# Haber kontrol sıklığı
HABER_KONTROL_SANIYE = 300

# ============================================================
# KLASÖRLER
# ============================================================

BASE_DIR = Path(__file__).resolve().parent

DATA_DIR = BASE_DIR / "zemtv_data"
SES_DIR = DATA_DIR / "ses"
LOGO_DIR = DATA_DIR / "logo"

for klasor in [
    DATA_DIR,
    SES_DIR,
    LOGO_DIR
]:
    klasor.mkdir(
        parents=True,
        exist_ok=True
    )

LOG_FILE = BASE_DIR / "zemtv_haber.log"
LOGO_FILE = LOGO_DIR / "logo.png"

# ============================================================
# SSL
# ============================================================

SSL_CONTEXT = ssl._create_unverified_context()

# ============================================================
# LOG
# ============================================================

def log(mesaj):

    zaman = datetime.now().strftime(
        "%Y-%m-%d %H:%M:%S"
    )

    satir = f"[{zaman}] {mesaj}"

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
# FFMPEG BUL
# ============================================================

def ffmpeg_bul():

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


FFMPEG = ffmpeg_bul()

if not FFMPEG:

    log("FFmpeg bulunamadı.")
    log(r"FFmpeg C:\ffmpeg\bin\ffmpeg.exe konumunda olmalı.")

    input(
        "Kapatmak için ENTER..."
    )

    sys.exit(1)


# ============================================================
# EDGE TTS
# ============================================================

def edge_tts_kur():

    try:

        import edge_tts

        return edge_tts

    except ImportError:

        log(
            "edge-tts bulunamadı. Otomatik kuruluyor..."
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

            import edge_tts

            log(
                "edge-tts kurulumu tamamlandı."
            )

            return edge_tts

        except Exception as e:

            log(
                f"edge-tts kurulamadı: {e}"
            )

            return None


edge_tts = edge_tts_kur()


# ============================================================
# USER AGENT
# ============================================================

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 "
    "(KHTML, like Gecko) "
    "Chrome/120.0 Safari/537.36"
)


# ============================================================
# INTERNETTEN VERİ AL
# ============================================================

def internetten_al(
    url,
    timeout=10
):

    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "*/*"
        }
    )

    with urllib.request.urlopen(
        request,
        timeout=timeout,
        context=SSL_CONTEXT
    ) as response:

        return response.read()


# ============================================================
# LOGO
# ============================================================

def logo_hazirla():

    if LOGO_FILE.exists():

        try:

            if LOGO_FILE.stat().st_size > 1000:
                return True

        except Exception:
            pass

    log(
        "ZEM TV logosu indiriliyor..."
    )

    try:

        veri = internetten_al(
            LOGO_URL,
            15
        )

        with open(
            LOGO_FILE,
            "wb"
        ) as f:

            f.write(veri)

        log(
            "Logo indirildi."
        )

        return True

    except Exception as e:

        log(
            f"Logo indirilemedi: {e}"
        )

        return False


# ============================================================
# METİN TEMİZLE
# ============================================================

def temizle(
    metin
):

    if not metin:
        return ""

    metin = html.unescape(
        metin
    )

    metin = re.sub(
        r"<script.*?</script>",
        " ",
        metin,
        flags=re.I | re.S
    )

    metin = re.sub(
        r"<style.*?</style>",
        " ",
        metin,
        flags=re.I | re.S
    )

    metin = re.sub(
        r"<[^>]+>",
        " ",
        metin
    )

    metin = re.sub(
        r"\s+",
        " ",
        metin
    )

    return metin.strip()


# ============================================================
# FFmpeg TEXT TEMİZLE
# ============================================================

def ff_text(
    metin,
    maksimum=110
):

    metin = temizle(
        metin
    )

    # FFmpeg filtresini bozabilecek karakterler
    metin = metin.replace(
        "\n",
        " "
    )

    metin = metin.replace(
        "\r",
        " "
    )

    metin = metin.replace(
        ":",
        " - "
    )

    metin = metin.replace(
        "'",
        ""
    )

    metin = metin.replace(
        "%",
        ""
    )

    metin = metin.replace(
        "[",
        "("
    )

    metin = metin.replace(
        "]",
        ")"
    )

    metin = re.sub(
        r"\s+",
        " ",
        metin
    ).strip()

    if len(metin) > maksimum:

        metin = (
            metin[:maksimum - 3]
            + "..."
        )

    return metin


# ============================================================
# RSS KAYNAKLARI
# ============================================================

RSS_LISTESI = [

    # HABERTÜRK
    (
        "HABERTÜRK",
        "https://www.haberturk.com/rss/manset.xml"
    ),

    (
        "HABERTÜRK",
        "https://www.haberturk.com/rss"
    ),

    (
        "HABERTÜRK GÜNDEM",
        "https://www.haberturk.com/rss/kategori/gundem.xml"
    ),

    (
        "HABERTÜRK EKONOMİ",
        "https://www.haberturk.com/rss/ekonomi.xml"
    ),

    (
        "HABERTÜRK DÜNYA",
        "https://www.haberturk.com/rss/kategori/dunya.xml"
    ),

    (
        "HABERTÜRK TEKNOLOJİ",
        "https://www.haberturk.com/rss/kategori/teknoloji.xml"
    ),

    # TRT HABER - GÜNCEL RESMİ RSS
    (
        "TRT SON DAKİKA",
        "https://www.trthaber.com/sondakika_articles.rss"
    ),

    (
        "TRT MANŞET",
        "https://www.trthaber.com/manset_articles.rss"
    ),

    (
        "TRT GÜNDEM",
        "https://www.trthaber.com/gundem_articles.rss"
    ),

    (
        "TRT TÜRKİYE",
        "https://www.trthaber.com/turkiye_articles.rss"
    ),

    (
        "TRT DÜNYA",
        "https://www.trthaber.com/dunya_articles.rss"
    ),

    (
        "TRT EKONOMİ",
        "https://www.trthaber.com/ekonomi_articles.rss"
    ),

    (
        "TRT SPOR",
        "https://www.trthaber.com/spor_articles.rss"
    ),

    (
        "TRT YAŞAM",
        "https://www.trthaber.com/yasam_articles.rss"
    ),

    (
        "TRT SAĞLIK",
        "https://www.trthaber.com/saglik_articles.rss"
    ),

    (
        "TRT BİLİM TEKNOLOJİ",
        "https://www.trthaber.com/bilim_teknoloji_articles.rss"
    ),

    (
        "TRT KÜLTÜR",
        "https://www.trthaber.com/kultur_sanat_articles.rss"
    ),

    # NTV
    (
        "NTV",
        "https://www.ntv.com.tr/son-dakika.rss"
    )
]


# ============================================================
# RSS OKU
# ============================================================

def rss_oku(
    kaynak,
    url
):

    log(
        f"HABER KONTROL: {kaynak}"
    )

    try:

        veri = internetten_al(
            url,
            10
        )

        root = ET.fromstring(
            veri
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
                    "kaynak": kaynak,
                    "link": link
                }
            )

        return haberler

    except Exception as e:

        log(
            f"URL HATASI {kaynak}: {e}"
        )

        return []


# ============================================================
# HABER TOPLA
# ============================================================

def haberleri_topla():

    tum_haberler = []

    basliklar = set()

    for kaynak, url in RSS_LISTESI:

        haberler = rss_oku(
            kaynak,
            url
        )

        for haber in haberler:

            kontrol = (
                haber["baslik"]
                .lower()
            )

            kontrol = re.sub(
                r"\s+",
                " ",
                kontrol
            ).strip()

            if kontrol in basliklar:
                continue

            basliklar.add(
                kontrol
            )

            tum_haberler.append(
                haber
            )

    log(
        f"{len(tum_haberler)} YENİ HABER EKLENDİ"
    )

    return tum_haberler


# ============================================================
# SPİKER METNİ
# ============================================================

def spiker_metni(
    haber
):

    baslik = temizle(
        haber["baslik"]
    )

    aciklama = temizle(
        haber["aciklama"]
    )

    if len(aciklama) > 550:

        aciklama = aciklama[
            :550
        ]

        son_nokta = aciklama.rfind(
            "."
        )

        if son_nokta > 250:

            aciklama = (
                aciklama[
                    :son_nokta + 1
                ]
            )

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

    return metin


# ============================================================
# AI SES
# ============================================================

def ses_olustur(
    metin,
    dosya
):

    if edge_tts is None:

        return False

    async def yap():

        communicator = edge_tts.Communicate(
            metin,
            TTS_VOICE,
            rate="+0%",
            volume="+0%",
            pitch="+0Hz"
        )

        await communicator.save(
            str(dosya)
        )

    try:

        asyncio.run(
            yap()
        )

        if (
            dosya.exists()
            and dosya.stat().st_size > 1000
        ):

            return True

        return False

    except Exception as e:

        log(
            f"SES HATASI: {e}"
        )

        return False


# ============================================================
# HAVA DURUMU
# ============================================================

hava_cache = {
    "metin": "KONYA HAVA DURUMU",
    "zaman": 0
}


def hava_durumu():

    global hava_cache

    if (
        time.time()
        - hava_cache["zaman"]
        < 600
    ):

        return hava_cache[
            "metin"
        ]

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
            internetten_al(
                geo_url,
                7
            ).decode(
                "utf-8",
                errors="ignore"
            )
        )

        sonuc = geo.get(
            "results",
            []
        )

        if not sonuc:

            raise Exception(
                "Şehir bulunamadı"
            )

        lat = sonuc[0][
            "latitude"
        ]

        lon = sonuc[0][
            "longitude"
        ]

        hava_url = (
            "https://api.open-meteo.com/v1/forecast?"
            + urllib.parse.urlencode(
                {
                    "latitude": lat,
                    "longitude": lon,
                    "current": (
                        "temperature_2m,"
                        "weather_code"
                    ),
                    "daily": (
                        "temperature_2m_min,"
                        "temperature_2m_max"
                    ),
                    "forecast_days": 1,
                    "timezone": "auto"
                }
            )
        )

        data = json.loads(
            internetten_al(
                hava_url,
                7
            ).decode(
                "utf-8",
                errors="ignore"
            )
        )

        current = data[
            "current"
        ]

        sicaklik = float(
            current[
                "temperature_2m"
            ]
        )

        kod = int(
            current[
                "weather_code"
            ]
        )

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
            kod,
            "DEĞİŞKEN"
        )

        minimum = float(
            data["daily"][
                "temperature_2m_min"
            ][0]
        )

        maksimum = float(
            data["daily"][
                "temperature_2m_max"
            ][0]
        )

        metin = (
            f"{HAVA_SEHRI.upper()} "
            f"{sicaklik:.0f}C "
            f"{durum} "
            f"GÜN {minimum:.0f}C/"
            f"{maksimum:.0f}C"
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
# DÖVİZ ALTIN
# ============================================================

finans_cache = {
    "metin": (
        "DOLAR -- TL | "
        "EURO -- TL | "
        "GRAM ALTIN -- TL | "
        "CEYREK ALTIN -- TL"
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
            internetten_al(
                url,
                7
            ).decode(
                "utf-8",
                errors="ignore"
            )
        )

        def bul(
            *isimler
        ):

            for isim in isimler:

                if isim not in data:
                    continue

                value = data[
                    isim
                ]

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
            f"DOLAR {dolar} TL | "
            f"EURO {euro} TL | "
            f"GRAM ALTIN {gram} TL | "
            f"CEYREK ALTIN {ceyrek} TL"
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
# FFmpeg METİN
# ============================================================

def filter_text(
    text
):

    text = str(
        text
    )

    text = text.replace(
        "\\",
        ""
    )

    text = text.replace(
        "'",
        ""
    )

    text = text.replace(
        ":",
        " - "
    )

    text = text.replace(
        "%",
        ""
    )

    text = text.replace(
        "[",
        "("
    )

    text = text.replace(
        "]",
        ")"
    )

    text = re.sub(
        r"\s+",
        " ",
        text
    ).strip()

    return text


# ============================================================
# FFmpeg İLE HABER YAYINI
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

    baslik = filter_text(
        haber["baslik"]
    )

    kategori = filter_text(
        haber.get(
            "kaynak",
            "GÜNDEM"
        )
    )

    hava = filter_text(
        hava_durumu()
    )

    piyasa = filter_text(
        finans()
    )

    tarih = datetime.now().strftime(
        "%d.%m.%Y %H-%M"
    )

    log("")
    log(
        f"HABER: {haber['baslik']}"
    )
    log(
        f"KAYNAK: {haber.get('kaynak', '')}"
    )

    metin = spiker_metni(
        haber
    )

    log(
        f"SPİKER: {metin}"
    )

    # --------------------------------------------------------
    # SES
    # --------------------------------------------------------

    if not ses_olustur(
        metin,
        ses_file
    ):

        log(
            "AI ses oluşturulamadı."
        )

        return False

    # --------------------------------------------------------
    # FFmpeg metin uzunluğu
    # --------------------------------------------------------

    if len(baslik) > 85:

        baslik = (
            baslik[:82]
            + "..."
        )

    # --------------------------------------------------------
    # FILTER
    #
    # ÖNEMLİ:
    # fontfile yok
    # localtime yok
    # yüzde yok
    # iki nokta yok
    #
    # Böylece Windows FFmpeg parser
    # sorunları ortadan kaldırılıyor.
    # --------------------------------------------------------

    filter_complex = (
        "[0:v]"

        # Üst kırmızı bant
        "drawbox="
        "x=0:"
        "y=0:"
        "w=1280:"
        "h=95:"
        "color=0xA50000:"
        "t=fill,"

        # Kanal adı
        "drawtext="
        "text='ZEM TV HABER':"
        "x=35:"
        "y=22:"
        "fontsize=46:"
        "fontcolor=white,"

        # Son gelişme
        "drawtext="
        "text='SON GELISME':"
        "x=45:"
        "y=145:"
        "fontsize=28:"
        "fontcolor=yellow,"

        # Haber
        f"drawtext="
        f"text='{baslik}':"
        "x=45:"
        "y=205:"
        "fontsize=38:"
        "fontcolor=white:"
        "box=1:"
        "boxcolor=0x202020:"
        "boxborderw=25,"

        # Alt panel
        "drawbox="
        "x=0:"
        "y=570:"
        "w=1280:"
        "h=150:"
        "color=black@0.90:"
        "t=fill,"

        # Hava
        f"drawtext="
        f"text='{hava}':"
        "x=30:"
        "y=585:"
        "fontsize=23:"
        "fontcolor=white,"

        # Kategori
        f"drawtext="
        f"text='{kategori}':"
        "x=30:"
        "y=625:"
        "fontsize=23:"
        "fontcolor=yellow,"

        # Tarih/saat
        f"drawtext="
        f"text='{tarih}':"
        "x=1060:"
        "y=585:"
        "fontsize=19:"
        "fontcolor=white,"

        # Piyasa bandı
        f"drawtext="
        f"text='{piyasa}':"
        "x=30:"
        "y=670:"
        "fontsize=22:"
        "fontcolor=white"
    )

    # --------------------------------------------------------
    # Logo input varsa
    # --------------------------------------------------------

    logo_var = (
        LOGO_FILE.exists()
        and LOGO_FILE.stat().st_size > 1000
    )

    if logo_var:

        filter_complex = (
            "[1:v]"
            "scale=220:-1"
            "[logo];"

            "[0:v][logo]"
            "overlay="
            "W-w-25:20"
            "[withlogo];"

            "[withlogo]"
            + filter_complex[
                filter_complex.find(
                    "[0:v]"
                ) + 5:
            ]
        )

        # Yukarıdaki zinciri tekrar doğru kur
        filter_complex = (
            "[1:v]"
            "scale=220:-1"
            "[logo];"

            "[0:v][logo]"
            "overlay=W-w-25:20"
            "[base];"

            "[base]"
            "drawbox=x=0:y=0:w=1280:h=95:"
            "color=0xA50000:t=fill,"
            "drawtext=text='ZEM TV HABER':"
            "x=35:y=22:fontsize=46:"
            "fontcolor=white,"
            "drawtext=text='SON GELISME':"
            "x=45:y=145:fontsize=28:"
            "fontcolor=yellow,"
            f"drawtext=text='{baslik}':"
            "x=45:y=205:fontsize=38:"
            "fontcolor=white:"
            "box=1:boxcolor=0x202020:"
            "boxborderw=25,"
            "drawbox=x=0:y=570:w=1280:h=150:"
            "color=black@0.90:t=fill,"
            f"drawtext=text='{hava}':"
            "x=30:y=585:fontsize=23:"
            "fontcolor=white,"
            f"drawtext=text='{kategori}':"
            "x=30:y=625:fontsize=23:"
            "fontcolor=yellow,"
            f"drawtext=text='{tarih}':"
            "x=1060:y=585:fontsize=19:"
            "fontcolor=white,"
            f"drawtext=text='{piyasa}':"
            "x=30:y=670:fontsize=22:"
            "fontcolor=white"
        )

        cmd = [

            FFMPEG,

            "-hide_banner",

            "-loglevel",
            "error",

            # Sonsuz renkli video
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
            "0:v",

            "-map",
            "2:a:0",

            "-c:v",
            "libx264",

            "-preset",
            "veryfast",

            "-tune",
            "zerolatency",

            "-b:v",
            "3000k",

            "-maxrate",
            "3500k",

            "-bufsize",
            "7000k",

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

            "-c:a",
            "aac",

            "-b:a",
            "128k",

            "-ar",
            "44100",

            "-ac",
            "2",

            "-shortest",

            "-f",
            "flv",

            RTMP_SERVER
        ]

    else:

        # Logosuz güvenli yedek
        filter_complex = (
            "[0:v]"
            "drawbox=x=0:y=0:w=1280:h=95:"
            "color=0xA50000:t=fill,"
            "drawtext=text='ZEM TV HABER':"
            "x=35:y=22:fontsize=46:"
            "fontcolor=white,"
            "drawtext=text='SON GELISME':"
            "x=45:y=145:fontsize=28:"
            "fontcolor=yellow,"
            f"drawtext=text='{baslik}':"
            "x=45:y=205:fontsize=38:"
            "fontcolor=white:"
            "box=1:boxcolor=0x202020:"
            "boxborderw=25,"
            "drawbox=x=0:y=570:w=1280:h=150:"
            "color=black@0.90:t=fill,"
            f"drawtext=text='{hava}':"
            "x=30:y=585:fontsize=23:"
            "fontcolor=white,"
            f"drawtext=text='{kategori}':"
            "x=30:y=625:fontsize=23:"
            "fontcolor=yellow,"
            f"drawtext=text='{tarih}':"
            "x=1060:y=585:fontsize=19:"
            "fontcolor=white,"
            f"drawtext=text='{piyasa}':"
            "x=30:y=670:fontsize=22:"
            "fontcolor=white"
        )

        cmd = [

            FFMPEG,

            "-hide_banner",

            "-loglevel",
            "error",

            "-f",
            "lavfi",

            "-i",
            "color=c=0x111111:s=1280x720:r=25",

            "-i",
            str(ses_file),

            "-filter_complex",
            filter_complex,

            "-map",
            "0:v",

            "-map",
            "1:a:0",

            "-c:v",
            "libx264",

            "-preset",
            "veryfast",

            "-tune",
            "zerolatency",

            "-b:v",
            "3000k",

            "-maxrate",
            "3500k",

            "-bufsize",
            "7000k",

            "-pix_fmt",
            "yuv420p",

            "-r",
            "25",

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

            "-f",
            "flv",

            RTMP_SERVER
        ]

    # --------------------------------------------------------
    # FFmpeg başlat
    # --------------------------------------------------------

    log(
        "FFmpeg başlatılıyor..."
    )

    try:

        process = subprocess.run(
            cmd,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace"
        )

        if process.returncode != 0:

            log(
                "FFmpeg yayın hatası:"
            )

            hata = process.stderr.strip()

            if hata:

                for satir in hata.splitlines()[
                    -20:
                ]:

                    log(
                        satir
                    )

            return False

        log(
            "Yayın bölümü tamamlandı."
        )

        return True

    except Exception as e:

        log(
            f"FFmpeg çalıştırılamadı: {e}"
        )

        return False

    finally:

        try:

            if ses_file.exists():

                ses_file.unlink()

        except Exception:
            pass


# ============================================================
# BEKLEME HABERİ
# ============================================================

def bekleme_haberi():

    return {
        "baslik": (
            "ZEM TV Haber yayın akışı devam ediyor"
        ),
        "aciklama": (
            "Yeni gelişmeler takip ediliyor. "
            "Son dakika haberleri geldikçe "
            "yayın akışımıza eklenecektir."
        ),
        "kaynak": "ZEM TV",
        "link": ""
    }


# ============================================================
# ANA SİSTEM
# ============================================================

def main():

    print()
    print(
        "===================================================="
    )
    print(
        "                 ZEM TV HABER"
    )
    print(
        "             OTOMATİK HABER SİSTEMİ"
    )
    print(
        "===================================================="
    )
    print()

    log(
        f"RTMP: {RTMP_SERVER}"
    )

    log(
        f"FFmpeg: {FFMPEG}"
    )

    log(
        f"Logo: {LOGO_URL}"
    )

    log(
        "Sistem başlatılıyor..."
    )

    # Logo
    logo_hazirla()

    haber_listesi = []

    son_kontrol = 0

    while True:

        try:

            # ------------------------------------------------
            # Haber kontrol
            # ------------------------------------------------

            if (
                not haber_listesi
                or
                time.time()
                - son_kontrol
                >= HABER_KONTROL_SANIYE
            ):

                yeni = haberleri_topla()

                if yeni:

                    haber_listesi.extend(
                        yeni
                    )

                son_kontrol = time.time()

            # ------------------------------------------------
            # Yayın
            # ------------------------------------------------

            if haber_listesi:

                haber = haber_listesi.pop(
                    0
                )

                basarili = haber_yayinla(
                    haber
                )

                if not basarili:

                    log(
                        "Haber yayınlanamadı."
                    )

                    time.sleep(3)

            else:

                bekleme = bekleme_haberi()

                haber_yayinla(
                    bekleme
                )

            # ------------------------------------------------
            # Fazla haber birikmesini önle
            # ------------------------------------------------

            if len(haber_listesi) > 50:

                haber_listesi = haber_listesi[
                    -50:
                ]

        except KeyboardInterrupt:

            log(
                "ZEM TV Haber durduruldu."
            )

            break

        except Exception as e:

            log(
                f"ANA HATA: {e}"
            )

            time.sleep(5)


# ============================================================
# BAŞLAT
# ============================================================

if __name__ == "__main__":

    main()
