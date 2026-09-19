# ============================================================
# ZEM TV HABER - OTOMATİK 7/24 HABER YAYINI
# ============================================================
# Windows Server / VDS
# Python 3.10+
#
# Özellikler:
# - Türkçe AI spiker
# - Otomatik RSS haber toplama
# - RSS kaynakları paralel kontrol edilir
# - Bir RSS çökerse diğerleri çalışmaya devam eder
# - Konya hava durumu
# - USD / EUR / Gram Altın / Çeyrek Altın
# - ZEM TV logo
# - Haber kaynağı ekranda görünür
# - FFmpeg ile RTMP yayını
# - SSH101 RTMP
# - Pillow YOK
# - Gerekli Python paketi otomatik kurulur
# ============================================================

import os
import sys
import time
import json
import html
import re
import ssl
import urllib.request
import urllib.parse
import subprocess
import threading
import xml.etree.ElementTree as ET

from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed


# ============================================================
# AYARLAR
# ============================================================

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zentvhaber"

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

LOGO_URL = (
    "https://raw.githubusercontent.com/"
    "mutlumedya/cine/refs/heads/main/telegram.png"
)

LOGO_FILE = "zemtv_logo.png"
SES_DOSYASI = "zemtv_spiker.mp3"

FONT_FILE = r"C:\Windows\Fonts\arial.ttf"

HAVA_SEHRI = "Konya"

# Haber kontrol aralığı
HABER_KONTROL_SURESI = 300

# Her haber yaklaşık kaç saniye ekranda kalacak
HABER_SURESI = 40

# Haber açıklaması maksimum uzunluğu
MAX_ACIKLAMA = 650

# Video
WIDTH = 1280
HEIGHT = 720
FPS = 25

# RSS bağlantısı için maksimum bekleme
RSS_TIMEOUT = 5

# Aynı anda kaç RSS kontrol edilsin
RSS_WORKERS = 10


# ============================================================
# RSS KAYNAKLARI
# ============================================================

RSS_KAYNAKLARI = [

    # -----------------------------
    # HABERTÜRK
    # -----------------------------

    (
        "HABERTÜRK",
        "https://www.haberturk.com/rss"
    ),

    (
        "HABERTÜRK MANŞET",
        "https://www.haberturk.com/rss/manset.xml"
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


    # -----------------------------
    # TRT HABER
    # -----------------------------

    (
        "TRT HABER",
        "https://www.trthaber.com/manset_articles.rss"
    ),

    (
        "TRT HABER SON DAKİKA",
        "https://www.trthaber.com/sondakika_articles.rss"
    ),

    (
        "TRT HABER GÜNDEM",
        "https://www.trthaber.com/gundem_articles.rss"
    ),

    (
        "TRT HABER TÜRKİYE",
        "https://www.trthaber.com/turkiye_articles.rss"
    ),

    (
        "TRT HABER DÜNYA",
        "https://www.trthaber.com/dunya_articles.rss"
    ),

    (
        "TRT HABER EKONOMİ",
        "https://www.trthaber.com/ekonomi_articles.rss"
    ),

    (
        "TRT HABER SPOR",
        "https://www.trthaber.com/spor_articles.rss"
    ),

    (
        "TRT HABER YAŞAM",
        "https://www.trthaber.com/yasam_articles.rss"
    ),

    (
        "TRT HABER SAĞLIK",
        "https://www.trthaber.com/saglik_articles.rss"
    ),

    (
        "TRT HABER KÜLTÜR SANAT",
        "https://www.trthaber.com/kultur_sanat_articles.rss"
    ),

    (
        "TRT HABER BİLİM TEKNOLOJİ",
        "https://www.trthaber.com/bilim_teknoloji_articles.rss"
    ),


    # -----------------------------
    # NTV
    # -----------------------------

    (
        "NTV",
        "https://www.ntv.com.tr/son-dakika.rss"
    ),
]


# ============================================================
# GENEL DEĞİŞKENLER
# ============================================================

haberler = []
haber_lock = threading.Lock()

son_haber_basliklari = set()

yayindaki_haber = None

program_calisiyor = True


# ============================================================
# RENK / YAZI TEMİZLEME
# ============================================================

def temizle_html(metin):

    if not metin:
        return ""

    metin = html.unescape(metin)

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

    metin = metin.replace("\n", " ")
    metin = metin.replace("\r", " ")
    metin = metin.replace("\t", " ")

    metin = re.sub(
        r"\s+",
        " ",
        metin
    )

    return metin.strip()


# ============================================================
# BAŞLIK TEMİZLE
# ============================================================

def temizle_baslik(baslik):

    baslik = temizle_html(baslik)

    baslik = baslik.replace(
        "Son Dakika:",
        ""
    )

    baslik = baslik.strip()

    return baslik


# ============================================================
# SSL
# ============================================================

def ssl_context():

    return ssl._create_unverified_context()


# ============================================================
# HTTP İSTEK
# ============================================================

def http_get(url, timeout=RSS_TIMEOUT):

    headers = {
        "User-Agent":
            "Mozilla/5.0 "
            "(Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 "
            "(KHTML, like Gecko) "
            "Chrome/126.0 Safari/537.36",

        "Accept":
            "application/rss+xml, "
            "application/xml, "
            "text/xml, "
            "*/*",

        "Connection":
            "close"
    }

    request = urllib.request.Request(
        url,
        headers=headers
    )

    with urllib.request.urlopen(
        request,
        timeout=timeout,
        context=ssl_context()
    ) as response:

        return response.read()


# ============================================================
# RSS OKU
# ============================================================

def rss_oku(kaynak_adi, url):

    print(
        f"[{datetime.now().strftime('%H:%M:%S')}] "
        f"HABER KONTROL: {kaynak_adi}"
    )

    try:

        data = http_get(
            url,
            RSS_TIMEOUT
        )

        root = ET.fromstring(data)

        bulunan = []

        # RSS item
        items = root.findall(".//item")

        # Atom ihtimali
        if not items:

            items = root.findall(
                ".//{http://www.w3.org/2005/Atom}entry"
            )

        for item in items:

            title = ""

            title_node = item.find("title")

            if title_node is not None:

                title = (
                    title_node.text
                    or ""
                )

            if not title:

                title_node = item.find(
                    "{http://www.w3.org/2005/Atom}title"
                )

                if title_node is not None:

                    title = (
                        title_node.text
                        or ""
                    )

            title = temizle_baslik(
                title
            )

            if not title:
                continue


            # ------------------------------------------------
            # DESCRIPTION
            # ------------------------------------------------

            description = ""

            desc_node = item.find(
                "description"
            )

            if desc_node is not None:

                description = (
                    desc_node.text
                    or ""
                )


            # content:encoded
            if not description:

                for child in item:

                    if child.tag.endswith(
                        "encoded"
                    ):

                        description = (
                            child.text
                            or ""
                        )

                        break


            # Atom summary
            if not description:

                summary = item.find(
                    "{http://www.w3.org/2005/Atom}summary"
                )

                if summary is not None:

                    description = (
                        summary.text
                        or ""
                    )


            description = temizle_html(
                description
            )


            # ------------------------------------------------
            # ÇOK UZUN AÇIKLAMA
            # ------------------------------------------------

            if len(description) > MAX_ACIKLAMA:

                description = (
                    description[:MAX_ACIKLAMA]
                    + "..."
                )


            # ------------------------------------------------
            # LINK
            # ------------------------------------------------

            link = ""

            link_node = item.find(
                "link"
            )

            if link_node is not None:

                link = (
                    link_node.text
                    or ""
                )


            if not link:

                atom_link = item.find(
                    "{http://www.w3.org/2005/Atom}link"
                )

                if atom_link is not None:

                    link = atom_link.attrib.get(
                        "href",
                        ""
                    )


            bulunan.append(
                {
                    "kaynak": kaynak_adi,
                    "baslik": title,
                    "aciklama": description,
                    "link": link
                }
            )


        print(
            f"[{datetime.now().strftime('%H:%M:%S')}] "
            f"{kaynak_adi}: {len(bulunan)} haber bulundu."
        )

        return bulunan


    except Exception as e:

        print(
            f"[{datetime.now().strftime('%H:%M:%S')}] "
            f"{kaynak_adi} RSS HATASI: {e}"
        )

        return []


# ============================================================
# TÜM RSS'LERİ PARALEL OKU
# ============================================================

def haberleri_topla():

    print()
    print(
        "=============================================="
    )

    print(
        "PARALEL RSS HABER TARAMASI BAŞLIYOR"
    )

    print(
        f"Toplam RSS: {len(RSS_KAYNAKLARI)}"
    )

    print(
        f"RSS timeout: {RSS_TIMEOUT} saniye"
    )

    print(
        "=============================================="
    )

    yeni_haberler = []

    # --------------------------------------------------------
    # AYNI ANDA RSS KONTROLÜ
    # --------------------------------------------------------

    with ThreadPoolExecutor(
        max_workers=RSS_WORKERS
    ) as executor:

        futureler = {}

        for kaynak, url in RSS_KAYNAKLARI:

            future = executor.submit(
                rss_oku,
                kaynak,
                url
            )

            futureler[future] = kaynak


        for future in as_completed(
            futureler
        ):

            kaynak = futureler[future]

            try:

                sonuc = future.result()

                if sonuc:

                    yeni_haberler.extend(
                        sonuc
                    )

            except Exception as e:

                print(
                    f"{kaynak} THREAD HATASI: {e}"
                )


    # --------------------------------------------------------
    # TEKRAR EDEN BAŞLIKLARI TEMİZLE
    # --------------------------------------------------------

    benzersiz = []

    gorulen = set()

    for haber in yeni_haberler:

        baslik = haber[
            "baslik"
        ].strip().lower()

        if not baslik:
            continue

        if baslik in gorulen:
            continue

        gorulen.add(
            baslik
        )

        benzersiz.append(
            haber
        )


    # --------------------------------------------------------
    # ESKİ HABERLERİ TAMAMEN TEKRAR OYNATMA
    # --------------------------------------------------------

    with haber_lock:

        global haberler

        haberler = benzersiz


    print()
    print(
        f"TOPLAM BENZERSİZ HABER: "
        f"{len(benzersiz)}"
    )

    print(
        "=============================================="
    )

    return benzersiz


# ============================================================
# LOGO İNDİR
# ============================================================

def logo_indir():

    if os.path.exists(LOGO_FILE):

        print(
            "Logo dosyası zaten mevcut."
        )

        return True


    print(
        "Logo indiriliyor..."
    )

    try:

        request = urllib.request.Request(
            LOGO_URL,
            headers={
                "User-Agent":
                    "Mozilla/5.0"
            }
        )

        with urllib.request.urlopen(
            request,
            timeout=20,
            context=ssl_context()
        ) as response:

            data = response.read()


        with open(
            LOGO_FILE,
            "wb"
        ) as f:

            f.write(data)


        if os.path.getsize(
            LOGO_FILE
        ) < 100:

            print(
                "Logo dosyası geçersiz."
            )

            return False


        print(
            "Logo başarıyla indirildi."
        )

        return True


    except Exception as e:

        print(
            f"Logo indirme hatası: {e}"
        )

        return False


# ============================================================
# GEREKLİ PAKETLER
# ============================================================

def edge_tts_kontrol():

    try:

        import edge_tts

        return True

    except ImportError:

        print(
            "edge-tts bulunamadı."
        )

        print(
            "edge-tts kuruluyor..."
        )

        try:

            subprocess.check_call(
                [
                    sys.executable,
                    "-m",
                    "pip",
                    "install",
                    "edge-tts"
                ]
            )

            print(
                "edge-tts kuruldu."
            )

            return True

        except Exception as e:

            print(
                f"edge-tts kurulum hatası: {e}"
            )

            return False


# ============================================================
# SPİKER METNİ
# ============================================================

def spiker_metni(haber):

    baslik = haber[
        "baslik"
    ]

    aciklama = haber[
        "aciklama"
    ]

    if not aciklama:

        aciklama = (
            "Detaylar haber merkezlerinden "
            "gelen bilgilerle takip ediliyor."
        )


    metin = (
        "ZEM TV Haber. "
        "Günün öne çıkan gelişmesi. "
        f"{baslik}. "
        f"{aciklama}. "
        "Gelişmeler oldukça aktarmaya devam edeceğiz."
    )

    return metin


# ============================================================
# SES OLUŞTUR
# ============================================================

async def ses_olustur_async(metin):

    import edge_tts

    communicate = edge_tts.Communicate(
        metin,
        "tr-TR-AhmetNeural",
        rate="+0%",
        volume="+0%"
    )

    await communicate.save(
        SES_DOSYASI
    )


def ses_olustur(metin):

    try:

        import asyncio

        asyncio.run(
            ses_olustur_async(
                metin
            )
        )

        return True

    except Exception as e:

        print(
            f"Ses oluşturma hatası: {e}"
        )

        return False


# ============================================================
# HAVA DURUMU
# ============================================================

def hava_durumu():

    try:

        sehir = urllib.parse.quote(
            HAVA_SEHRI
        )

        url = (
            f"https://wttr.in/{sehir}"
            "?format=j1"
        )

        data = http_get(
            url,
            5
        )

        obj = json.loads(
            data.decode(
                "utf-8",
                errors="ignore"
            )
        )

        current = obj[
            "current_condition"
        ][0]

        sicaklik = current.get(
            "temp_C",
            "?"
        )

        durum = current.get(
            "weatherDesc",
            [
                {
                    "value":
                        "Bilinmiyor"
                }
            ]
        )[0]["value"]


        return (
            f"{HAVA_SEHRI} "
            f"{durum} "
            f"{sicaklik}°C"
        )


    except Exception as e:

        print(
            f"Hava durumu alınamadı: {e}"
        )

        return (
            f"{HAVA_SEHRI} "
            "hava bilgisi alınamıyor"
        )


# ============================================================
# PİYASA
# ============================================================

def piyasa():

    try:

        url = (
            "https://finans.truncgil.com/today.json"
        )

        data = http_get(
            url,
            5
        )

        obj = json.loads(
            data.decode(
                "utf-8",
                errors="ignore"
            )
        )


        usd = "—"
        eur = "—"
        gram = "—"
        ceyrek = "—"


        if "USD" in obj:

            usd = (
                obj["USD"].get(
                    "Alış",
                    "—"
                )
            )


        if "EUR" in obj:

            eur = (
                obj["EUR"].get(
                    "Alış",
                    "—"
                )
            )


        if "gram-altin" in obj:

            gram = (
                obj["gram-altin"].get(
                    "Alış",
                    "—"
                )
            )


        if "ceyrek-altin" in obj:

            ceyrek = (
                obj["ceyrek-altin"].get(
                    "Alış",
                    "—"
                )
            )


        return (
            f"USD {usd}   "
            f"EUR {eur}   "
            f"GRAM {gram}   "
            f"ÇEYREK {ceyrek}"
        )


    except Exception as e:

        print(
            f"Piyasa bilgisi alınamadı: {e}"
        )

        return (
            "USD —   "
            "EUR —   "
            "GRAM —   "
            "ÇEYREK —"
        )


# ============================================================
# FFMPEG TEXT TEMİZLEME
# ============================================================

def ffmpeg_text(text):

    if text is None:

        return ""


    text = str(text)


    # HTML vs.
    text = temizle_html(
        text
    )


    # FFmpeg drawtext özel karakterleri
    text = text.replace(
        "\\",
        ""
    )

    text = text.replace(
        "'",
        "’"
    )

    text = text.replace(
        ":",
        " "
    )

    text = text.replace(
        "%",
        "%%"
    )

    text = text.replace(
        "[",
        "("
    )

    text = text.replace(
        "]",
        ")"
    )


    return text


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def ffmpeg_komutu(haber):

    baslik = ffmpeg_text(
        haber["baslik"]
    )

    kaynak = ffmpeg_text(
        haber["kaynak"]
    )

    tarih = datetime.now().strftime(
        "%d.%m.%Y"
    )

    saat = datetime.now().strftime(
        "%H:%M"
    )

    hava = ffmpeg_text(
        hava_durumu()
    )

    market = ffmpeg_text(
        piyasa()
    )


    # --------------------------------------------------------
    # HABER BAŞLIĞINI EKRANA SIĞDIR
    # --------------------------------------------------------

    if len(baslik) > 100:

        baslik = (
            baslik[:97]
            + "..."
        )


    # --------------------------------------------------------
    # AÇIKLAMA
    # --------------------------------------------------------

    aciklama = ffmpeg_text(
        haber.get(
            "aciklama",
            ""
        )
    )

    if not aciklama:

        aciklama = (
            "Gelişmeler oldukça "
            "aktarmaya devam edeceğiz."
        )


    if len(aciklama) > 280:

        aciklama = (
            aciklama[:277]
            + "..."
        )


    # --------------------------------------------------------
    # WINDOWS FONT PATH
    # --------------------------------------------------------

    font = FONT_FILE.replace(
        "\\",
        "/"
    )

    font = font.replace(
        ":",
        "\\:"
    )


    # --------------------------------------------------------
    # LOGO
    # --------------------------------------------------------

    logo_path = os.path.abspath(
        LOGO_FILE
    )

    logo_path = logo_path.replace(
        "\\",
        "/"
    )

    logo_path = logo_path.replace(
        ":",
        "\\:"
    )


    # --------------------------------------------------------
    # FILTER GRAPH
    # --------------------------------------------------------

    filter_complex = (
        f"[0:v]"
        f"drawtext="
        f"fontfile='{font}':"
        f"text='ZEM TV HABER':"
        f"fontcolor=white:"
        f"fontsize=30:"
        f"x=35:"
        f"y=25,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{tarih}  {saat}':"
        f"fontcolor=white:"
        f"fontsize=24:"
        f"x=35:"
        f"y=70,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{hava}':"
        f"fontcolor=white:"
        f"fontsize=23:"
        f"x=35:"
        f"y=112,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{market}':"
        f"fontcolor=white:"
        f"fontsize=21:"
        f"x=35:"
        f"y=153,"
        
        f"drawbox="
        f"x=0:"
        f"y=200:"
        f"w=1280:"
        f"h=2:"
        f"color=0x2f4054:"
        f"t=fill,"
        
        f"drawbox="
        f"x=0:"
        f"y=535:"
        f"w=1280:"
        f"h=185:"
        f"color=black@0.92:"
        f"t=fill,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{baslik}':"
        f"fontcolor=white:"
        f"fontsize=34:"
        f"x=35:"
        f"y=555:"
        f"enable='between(t,0,9999)',"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{aciklama}':"
        f"fontcolor=white:"
        f"fontsize=23:"
        f"x=35:"
        f"y=610:"
        f"enable='between(t,0,9999)',"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='KAYNAK: {kaynak}':"
        f"fontcolor=0xcccccc:"
        f"fontsize=20:"
        f"x=35:"
        f"y=680,"
        
        f"[v0]null[vbase];"
        
        f"[1:v]"
        f"scale=190:-1"
        f"[logo];"
        
        f"[vbase][logo]"
        f"overlay="
        f"W-w-25:"
        f"60:"
        f"format=auto"
        f"[vout]"
    )


    # --------------------------------------------------------
    # NOT:
    #
    # Üstteki zincirin güvenli olması için aşağıdaki alternatif
    # filter kullanılmaktadır.
    # --------------------------------------------------------

    filter_complex = (
        f"[0:v]"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='ZEM TV HABER':"
        f"fontcolor=white:"
        f"fontsize=30:"
        f"x=35:"
        f"y=25,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{tarih}  {saat}':"
        f"fontcolor=white:"
        f"fontsize=24:"
        f"x=35:"
        f"y=70,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{hava}':"
        f"fontcolor=white:"
        f"fontsize=23:"
        f"x=35:"
        f"y=112,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{market}':"
        f"fontcolor=white:"
        f"fontsize=21:"
        f"x=35:"
        f"y=153,"
        
        f"drawbox="
        f"x=0:"
        f"y=200:"
        f"w=1280:"
        f"h=2:"
        f"color=0x2f4054:"
        f"t=fill,"
        
        f"drawbox="
        f"x=0:"
        f"y=535:"
        f"w=1280:"
        f"h=185:"
        f"color=black@0.92:"
        f"t=fill,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{baslik}':"
        f"fontcolor=white:"
        f"fontsize=34:"
        f"x=35:"
        f"y=555,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='{aciklama}':"
        f"fontcolor=white:"
        f"fontsize=23:"
        f"x=35:"
        f"y=610,"
        
        f"drawtext="
        f"fontfile='{font}':"
        f"text='KAYNAK  {kaynak}':"
        f"fontcolor=0xcccccc:"
        f"fontsize=20:"
        f"x=35:"
        f"y=680"
        
        f"[base];"
        
        f"[1:v]"
        f"scale=190:-1"
        f"[logo];"
        
        f"[base][logo]"
        f"overlay=W-w-25:60"
        f"[vout]"
    )


    cmd = [

        FFMPEG,

        "-hide_banner",

        "-loglevel",
        "info",

        "-y",


        # ----------------------------------------------------
        # ANA GÖRÜNTÜ
        # ----------------------------------------------------

        "-f",
        "lavfi",

        "-i",
        f"color=c=0x101722:s={WIDTH}x{HEIGHT}:r={FPS}",


        # ----------------------------------------------------
        # LOGO
        # ----------------------------------------------------

        "-stream_loop",
        "-1",

        "-i",
        LOGO_FILE,


        # ----------------------------------------------------
        # SES
        # ----------------------------------------------------

        "-i",
        SES_DOSYASI,


        # ----------------------------------------------------
        # FILTER
        # ----------------------------------------------------

        "-filter_complex",
        filter_complex,


        # ----------------------------------------------------
        # VIDEO
        # ----------------------------------------------------

        "-map",
        "[vout]",

        "-map",
        "2:a",


        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-tune",
        "zerolatency",

        "-profile:v",
        "main",

        "-pix_fmt",
        "yuv420p",

        "-r",
        str(FPS),

        "-g",
        "50",

        "-keyint_min",
        "50",

        "-sc_threshold",
        "0",

        "-b:v",
        "3000k",

        "-maxrate",
        "3500k",

        "-bufsize",
        "6000k",


        # ----------------------------------------------------
        # AUDIO
        # ----------------------------------------------------

        "-c:a",
        "aac",

        "-b:a",
        "128k",

        "-ar",
        "44100",

        "-ac",
        "2",

        "-af",
        "aresample=async=1",


        # ----------------------------------------------------
        # RTMP
        # ----------------------------------------------------

        "-f",
        "flv",

        "-flvflags",
        "no_duration_filesize",

        RTMP_URL
    ]


    return cmd


# ============================================================
# FFMPEG BAŞLAT
# ============================================================

def ffmpeg_baslat(haber):

    cmd = ffmpeg_komutu(
        haber
    )


    print()
    print(
        "=============================================="
    )

    print(
        "FFMPEG BAŞLATILIYOR"
    )

    print(
        f"Haber: {haber['baslik']}"
    )

    print(
        f"Kaynak: {haber['kaynak']}"
    )

    print(
        f"RTMP: {RTMP_URL}"
    )

    print(
        "=============================================="
    )


    try:

        process = subprocess.Popen(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            universal_newlines=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1
        )


        baslangic = time.time()


        while True:

            line = process.stdout.readline()

            if line:

                print(
                    "[FFMPEG]",
                    line.strip()
                )


            # ------------------------------------------------
            # HATA / ÇIKIŞ
            # ------------------------------------------------

            if process.poll() is not None:

                print(
                    "FFmpeg kapandı."
                )

                break


            # ------------------------------------------------
            # HABER SÜRESİ
            # ------------------------------------------------

            if (
                time.time()
                - baslangic
                >= HABER_SURESI
            ):

                print(
                    f"{HABER_SURESI} saniye doldu."
                )

                print(
                    "Yeni haber hazırlanıyor..."
                )

                try:

                    process.terminate()

                    process.wait(
                        timeout=5
                    )

                except Exception:

                    try:

                        process.kill()

                    except Exception:

                        pass

                break


        return True


    except Exception as e:

        print(
            f"FFmpeg başlatma hatası: {e}"
        )

        return False


# ============================================================
# HABER SEÇ
# ============================================================

def haber_sec():

    with haber_lock:

        mevcut = list(
            haberler
        )


    if not mevcut:

        return None


    # --------------------------------------------------------
    # ÖNCE YENİ HABER BUL
    # --------------------------------------------------------

    yeni = []

    for haber in mevcut:

        key = (
            haber["baslik"]
            .strip()
            .lower()
        )

        if key not in son_haber_basliklari:

            yeni.append(
                haber
            )


    if yeni:

        haber = yeni[0]

    else:

        # Hepsi oynatıldıysa
        # eski listeyi yeniden başlat

        son_haber_basliklari.clear()

        haber = mevcut[0]


    son_haber_basliklari.add(
        haber["baslik"]
        .strip()
        .lower()
    )


    return haber


# ============================================================
# SÜREKLİ RSS GÜNCELLEME THREAD
# ============================================================

def rss_thread():

    global program_calisiyor

    while program_calisiyor:

        try:

            haberleri_topla()

        except Exception as e:

            print(
                f"RSS THREAD HATASI: {e}"
            )


        print()
        print(
            f"Bir sonraki RSS taraması "
            f"{HABER_KONTROL_SURESI} saniye sonra."
        )


        # ----------------------------------------------------
        # 1 saniyelik beklemeler ile durdurulabilir thread
        # ----------------------------------------------------

        for _ in range(
            HABER_KONTROL_SURESI
        ):

            if not program_calisiyor:

                break

            time.sleep(1)


# ============================================================
# KLASÖR / DOSYA KONTROL
# ============================================================

def sistem_kontrol():

    print()
    print(
        "=============================================="
    )

    print(
        "ZEM TV HABER v3.0"
    )

    print(
        "=============================================="
    )

    print(
        f"RTMP: {RTMP_URL}"
    )

    print(
        f"FFmpeg: {FFMPEG}"
    )

    print(
        f"Logo: {LOGO_URL}"
    )

    print(
        f"Hava şehri: {HAVA_SEHRI}"
    )

    print(
        "=============================================="
    )


    # --------------------------------------------------------
    # FFMPEG
    # --------------------------------------------------------

    if not os.path.exists(
        FFMPEG
    ):

        print()
        print(
            "HATA: FFmpeg bulunamadı!"
        )

        print(
            FFMPEG
        )

        return False


    print(
        "FFmpeg bulundu."
    )


    # --------------------------------------------------------
    # FONT
    # --------------------------------------------------------

    if os.path.exists(
        FONT_FILE
    ):

        print(
            f"Arial font bulundu: {FONT_FILE}"
        )

    else:

        print(
            "UYARI: Arial font bulunamadı."
        )

        print(
            "Windows font klasörü kontrol edilecek."
        )


    # --------------------------------------------------------
    # EDGE TTS
    # --------------------------------------------------------

    if not edge_tts_kontrol():

        return False


    # --------------------------------------------------------
    # LOGO
    # --------------------------------------------------------

    if not logo_indir():

        print(
            "UYARI: Logo indirilemedi."
        )

        return False


    # --------------------------------------------------------
    # LOGO BOYUTU
    # --------------------------------------------------------

    try:

        size = os.path.getsize(
            LOGO_FILE
        )

        print(
            f"Logo boyutu: {size} byte"
        )

    except Exception:

        pass


    return True


# ============================================================
# ANA PROGRAM
# ============================================================

def main():

    global program_calisiyor


    print()
    print(
        "##############################################"
    )

    print(
        "#                                            #"
    )

    print(
        "#              ZEM TV HABER                 #"
    )

    print(
        "#              7/24 OTOMATİK                #"
    )

    print(
        "#                                            #"
    )

    print(
        "##############################################"
    )


    # --------------------------------------------------------
    # SİSTEM KONTROL
    # --------------------------------------------------------

    if not sistem_kontrol():

        print()
        print(
            "Sistem kontrolü başarısız."
        )

        input(
            "Çıkmak için Enter..."
        )

        return


    # --------------------------------------------------------
    # İLK HABER TARAMASI
    # --------------------------------------------------------

    print()
    print(
        "İlk haber taraması başlıyor..."
    )


    haberleri_topla()


    # --------------------------------------------------------
    # RSS THREAD
    # --------------------------------------------------------

    thread = threading.Thread(
        target=rss_thread,
        daemon=True
    )

    thread.start()


    # --------------------------------------------------------
    # ANA YAYIN DÖNGÜSÜ
    # --------------------------------------------------------

    while True:

        try:

            haber = haber_sec()


            # ------------------------------------------------
            # HABER YOKSA BEKLE
            # ------------------------------------------------

            if haber is None:

                print()
                print(
                    "Henüz haber alınamadı."
                )

                print(
                    "5 saniye sonra tekrar denenecek."
                )

                time.sleep(5)

                continue


            global yayindaki_haber

            yayindaki_haber = haber


            print()
            print(
                "=============================================="
            )

            print(
                "YENİ HABER"
            )

            print(
                f"Kaynak : {haber['kaynak']}"
            )

            print(
                f"Başlık : {haber['baslik']}"
            )

            print(
                "=============================================="
            )


            # ------------------------------------------------
            # SPİKER
            # ------------------------------------------------

            metin = spiker_metni(
                haber
            )


            print()
            print(
                "SPİKER METNİ:"
            )

            print(
                metin
            )


            print()
            print(
                "Ses oluşturuluyor..."
            )


            if not ses_olustur(
                metin
            ):

                print(
                    "Ses oluşturulamadı."
                )

                time.sleep(3)

                continue


            print(
                "Ses hazır."
            )


            # ------------------------------------------------
            # FFMPEG
            # ------------------------------------------------

            ffmpeg_baslat(
                haber
            )


            # ------------------------------------------------
            # DOSYA TEMİZLİĞİ
            # ------------------------------------------------

            try:

                if os.path.exists(
                    SES_DOSYASI
                ):

                    os.remove(
                        SES_DOSYASI
                    )

            except Exception:

                pass


            # ------------------------------------------------
            # KISA BEKLEME
            # ------------------------------------------------

            time.sleep(1)


        except KeyboardInterrupt:

            print()
            print(
                "Program kullanıcı tarafından durduruldu."
            )

            program_calisiyor = False

            break


        except Exception as e:

            print()
            print(
                "ANA PROGRAM HATASI:"
            )

            print(
                e
            )

            print(
                "10 saniye sonra devam edilecek."
            )

            time.sleep(10)


# ============================================================
# PROGRAMI ÇALIŞTIR
# ============================================================

if __name__ == "__main__":

    try:

        main()

    except KeyboardInterrupt:

        print()
        print(
            "ZEM TV HABER kapatıldı."
        )

    except Exception as e:

        print()
        print(
            "KRİTİK HATA:"
        )

        print(
            e
        )

        input(
            "Kapatmak için Enter..."
        )
