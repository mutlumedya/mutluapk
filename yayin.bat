# -*- coding: utf-8 -*-

import os
import sys
import subprocess
import urllib.request
import urllib.parse
import json
import ssl
import time
import re
import html
import threading
from datetime import datetime


# ============================================================
#                    ZEM TV HABER
#             OTOMATİK HABER SİSTEMİ
# ============================================================

VERSION = "ZEM TV HABER v2.0"

# ============================================================
# AYARLAR
# ============================================================

RTMP_URL = (
    "rtmp://ssh101.bozztv.com:1935/"
    "ssh101/zentvhaber"
)

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

# Logo
LOGO_URL = (
    "https://raw.githubusercontent.com/"
    "mutlumedya/cine/refs/heads/main/telegram.png"
)

BASE_DIR = os.path.dirname(
    os.path.abspath(__file__)
)

LOGO_FILE = os.path.join(
    BASE_DIR,
    "zem_logo.png"
)

SES_DOSYASI = os.path.join(
    BASE_DIR,
    "spiker.mp3"
)

# Windows Arial
FONT_FILE = r"C:\Windows\Fonts\arial.ttf"

# Hava durumu
HAVA_SEHRI = "Konya"

# Yeni haber kontrolü
HABER_KONTROL_SURESI = 300

# Her haberin yaklaşık yayın süresi
HABER_SURESI = 40

# Haber başına maksimum açıklama
MAX_ACIKLAMA = 650

# 1280x720 yayın
VIDEO_WIDTH = 1280
VIDEO_HEIGHT = 720

# FPS
FPS = 25

# ============================================================
# RSS KAYNAKLARI
# ============================================================

RSS_KAYNAKLARI = [

    # --------------------------------------------------------
    # HABERTÜRK
    # --------------------------------------------------------

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

    # --------------------------------------------------------
    # TRT HABER
    # --------------------------------------------------------

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

    # --------------------------------------------------------
    # NTV
    # --------------------------------------------------------

    (
        "NTV",
        "https://www.ntv.com.tr/son-dakika.rss"
    )
]


# ============================================================
# GLOBAL DEĞİŞKENLER
# ============================================================

haber_listesi = []

gorulen_haberler = set()

son_haber_kontrol = 0

ses_lock = threading.Lock()


# ============================================================
# LOG
# ============================================================

def log(mesaj):

    saat = datetime.now().strftime(
        "%Y-%m-%d %H:%M:%S"
    )

    print(
        f"[{saat}] {mesaj}",
        flush=True
    )


# ============================================================
# SSL
# ============================================================

def ssl_context():

    return ssl._create_unverified_context()


# ============================================================
# PAKET KONTROL
# ============================================================

def paket_kontrol():

    try:

        import edge_tts

        return edge_tts

    except ImportError:

        log(
            "edge-tts bulunamadı."
        )

        log(
            "edge-tts kuruluyor..."
        )

        try:

            subprocess.run(
                [
                    sys.executable,
                    "-m",
                    "pip",
                    "install",
                    "edge-tts"
                ],
                check=True
            )

            import edge_tts

            log(
                "edge-tts başarıyla kuruldu."
            )

            return edge_tts

        except Exception as hata:

            log(
                f"edge-tts kurulamadı: {hata}"
            )

            return None


# ============================================================
# LOGO İNDİR
# ============================================================

def logo_indir():

    if os.path.exists(LOGO_FILE):

        log(
            "Logo zaten mevcut."
        )

        return True

    log(
        "Logo indiriliyor..."
    )

    try:

        istek = urllib.request.Request(
            LOGO_URL,
            headers={
                "User-Agent":
                "Mozilla/5.0"
            }
        )

        with urllib.request.urlopen(
            istek,
            timeout=30,
            context=ssl_context()
        ) as cevap:

            veri = cevap.read()

        if not veri:

            raise Exception(
                "Logo dosyası boş."
            )

        with open(
            LOGO_FILE,
            "wb"
        ) as dosya:

            dosya.write(veri)

        log(
            "Logo başarıyla indirildi."
        )

        return True

    except Exception as hata:

        log(
            f"Logo indirilemedi: {hata}"
        )

        return False


# ============================================================
# METİN TEMİZLE
# ============================================================

def temizle_metin(metin):

    if not metin:

        return ""

    metin = html.unescape(
        metin
    )

    metin = re.sub(
        r"<script.*?</script>",
        " ",
        metin,
        flags=re.IGNORECASE |
        re.DOTALL
    )

    metin = re.sub(
        r"<style.*?</style>",
        " ",
        metin,
        flags=re.IGNORECASE |
        re.DOTALL
    )

    metin = re.sub(
        r"<[^>]+>",
        " ",
        metin
    )

    metin = metin.replace(
        "\r",
        " "
    )

    metin = metin.replace(
        "\n",
        " "
    )

    metin = metin.replace(
        "\t",
        " "
    )

    metin = re.sub(
        r"\s+",
        " ",
        metin
    )

    return metin.strip()


# ============================================================
# RSS OKUMA
# ============================================================

def rss_oku(
    kaynak_adi,
    url
):

    try:

        istek = urllib.request.Request(
            url,
            headers={
                "User-Agent":
                "Mozilla/5.0 "
                "(Windows NT 10.0; Win64; x64) "
                "ZEM-TV-HABER/2.0"
            }
        )

        with urllib.request.urlopen(
            istek,
            timeout=20,
            context=ssl_context()
        ) as cevap:

            veri = cevap.read()

        metin = veri.decode(
            "utf-8",
            errors="ignore"
        )

        # ----------------------------------------------------
        # ITEM
        # ----------------------------------------------------

        itemler = re.findall(
            r"<item\b.*?</item>",
            metin,
            flags=
            re.IGNORECASE |
            re.DOTALL
        )

        # Bazı RSS sistemleri namespace kullanabilir
        if not itemler:

            itemler = re.findall(
                r"<item[^>]*>(.*?)</item>",
                metin,
                flags=
                re.IGNORECASE |
                re.DOTALL
            )

        sonuc = []

        for item in itemler:

            # ------------------------------------------------
            # TITLE
            # ------------------------------------------------

            title_match = re.search(
                r"<title[^>]*>"
                r"(.*?)"
                r"</title>",
                item,
                flags=
                re.IGNORECASE |
                re.DOTALL
            )

            if not title_match:

                continue

            baslik = temizle_metin(
                title_match.group(1)
            )

            if not baslik:

                continue

            # ------------------------------------------------
            # DESCRIPTION
            # ------------------------------------------------

            description_match = re.search(
                r"<description[^>]*>"
                r"(.*?)"
                r"</description>",
                item,
                flags=
                re.IGNORECASE |
                re.DOTALL
            )

            aciklama = ""

            if description_match:

                aciklama = temizle_metin(
                    description_match.group(1)
                )

            # ------------------------------------------------
            # CONTENT
            # ------------------------------------------------

            if not aciklama:

                content_match = re.search(
                    r"<content:encoded[^>]*>"
                    r"(.*?)"
                    r"</content:encoded>",
                    item,
                    flags=
                    re.IGNORECASE |
                    re.DOTALL
                )

                if content_match:

                    aciklama = temizle_metin(
                        content_match.group(1)
                    )

            if not aciklama:

                aciklama = baslik

            # ------------------------------------------------
            # UZUNLUK
            # ------------------------------------------------

            if len(aciklama) > MAX_ACIKLAMA:

                aciklama = (
                    aciklama[:MAX_ACIKLAMA]
                    + "..."
                )

            sonuc.append(
                {
                    "baslik": baslik,
                    "aciklama": aciklama,
                    "kaynak": kaynak_adi
                }
            )

        return sonuc

    except Exception as hata:

        log(
            f"{kaynak_adi} RSS HATASI: {hata}"
        )

        return []


# ============================================================
# HABER TOPLA
# ============================================================

def haberleri_topla():

    global haber_listesi

    yeni_sayisi = 0

    for kaynak_adi, url in RSS_KAYNAKLARI:

        log(
            f"HABER KONTROL: {kaynak_adi}"
        )

        haberler = rss_oku(
            kaynak_adi,
            url
        )

        for haber in haberler:

            baslik = haber[
                "baslik"
            ].strip()

            anahtar = (
                baslik
                .lower()
                .strip()
            )

            if not anahtar:

                continue

            if anahtar in gorulen_haberler:

                continue

            gorulen_haberler.add(
                anahtar
            )

            haber_listesi.append(
                haber
            )

            yeni_sayisi += 1

    log(
        f"{yeni_sayisi} YENİ HABER EKLENDİ"
    )

    # --------------------------------------------------------
    # Haber kuyruğu çok büyümesin
    # --------------------------------------------------------

    if len(haber_listesi) > 500:

        haber_listesi = (
            haber_listesi[-500:]
        )

    # --------------------------------------------------------
    # Görülen haber hafızası
    # --------------------------------------------------------

    if len(gorulen_haberler) > 3000:

        liste = list(
            gorulen_haberler
        )

        liste = liste[-1500:]

        gorulen_haberler.clear()

        for baslik in liste:

            gorulen_haberler.add(
                baslik
            )

    log(
        f"TOPLAM HABER KUYRUĞU: "
        f"{len(haber_listesi)}"
    )


# ============================================================
# SPİKER METNİ
# ============================================================

def spiker_metni(
    haber
):

    baslik = haber[
        "baslik"
    ]

    aciklama = haber[
        "aciklama"
    ]

    # Nokta sonrası fazla boşlukları düzelt
    aciklama = re.sub(
        r"\s+",
        " ",
        aciklama
    ).strip()

    metin = (
        "ZEM TV Haber. "
        "Günün öne çıkan gelişmesi. "
        f"{baslik}. "
        f"{aciklama}. "
        "Gelişmeler oldukça "
        "aktarmaya devam edeceğiz."
    )

    return metin


# ============================================================
# EDGE TTS
# ============================================================

def ses_olustur(
    metin
):

    edge_tts = paket_kontrol()

    if edge_tts is None:

        return False

    with ses_lock:

        try:

            log(
                "Yapay zeka Türkçe spiker "
                "sesi hazırlanıyor..."
            )

            async def kaydet():

                konusma = (
                    edge_tts.Communicate(
                        metin,
                        "tr-TR-AhmetNeural",
                        rate="+0%",
                        volume="+0%"
                    )
                )

                await konusma.save(
                    SES_DOSYASI
                )

            import asyncio

            asyncio.run(
                kaydet()
            )

            if not os.path.exists(
                SES_DOSYASI
            ):

                return False

            boyut = os.path.getsize(
                SES_DOSYASI
            )

            if boyut < 1000:

                return False

            log(
                f"Spiker sesi hazır. "
                f"{boyut} byte"
            )

            return True

        except Exception as hata:

            log(
                f"Spiker sesi hatası: {hata}"
            )

            return False


# ============================================================
# HAVA DURUMU
# ============================================================

def hava_durumu():

    try:

        adres = (
            "https://wttr.in/"
            + urllib.parse.quote(
                HAVA_SEHRI
            )
            + "?format=j1"
        )

        istek = urllib.request.Request(
            adres,
            headers={
                "User-Agent":
                "Mozilla/5.0"
            }
        )

        with urllib.request.urlopen(
            istek,
            timeout=10,
            context=ssl_context()
        ) as cevap:

            veri = json.loads(
                cevap.read().decode(
                    "utf-8",
                    errors="ignore"
                )
            )

        mevcut = (
            veri[
                "current_condition"
            ][0]
        )

        derece = mevcut.get(
            "temp_C",
            "?"
        )

        durum = ""

        try:

            durum = (
                mevcut[
                    "lang_tr"
                ][0]["value"]
            )

        except Exception:

            try:

                durum = (
                    mevcut[
                        "weatherDesc"
                    ][0]["value"]
                )

            except Exception:

                durum = "Hava durumu"


        return (
            f"{HAVA_SEHRI} "
            f"{derece} C "
            f"{durum}"
        )

    except Exception:

        return (
            f"{HAVA_SEHRI} "
            "hava durumu alınamadı"
        )


# ============================================================
# PİYASA
# ============================================================

def piyasa():

    try:

        adres = (
            "https://finans.truncgil.com/"
            "today.json"
        )

        istek = urllib.request.Request(
            adres,
            headers={
                "User-Agent":
                "Mozilla/5.0"
            }
        )

        with urllib.request.urlopen(
            istek,
            timeout=10,
            context=ssl_context()
        ) as cevap:

            veri = json.loads(
                cevap.read().decode(
                    "utf-8",
                    errors="ignore"
                )
            )

        usd = (
            veri.get(
                "USD",
                {}
            ).get(
                "Alış",
                "-"
            )
        )

        eur = (
            veri.get(
                "EUR",
                {}
            ).get(
                "Alış",
                "-"
            )
        )

        gram = (
            veri.get(
                "gram-altin",
                {}
            ).get(
                "Alış",
                "-"
            )
        )

        ceyrek = (
            veri.get(
                "ceyrek-altin",
                {}
            ).get(
                "Alış",
                "-"
            )
        )

        return (
            f"USD {usd}  "
            f"EUR {eur}  "
            f"GRAM ALTIN {gram}  "
            f"ÇEYREK {ceyrek}"
        )

    except Exception:

        return (
            "USD -   "
            "EUR -   "
            "GRAM ALTIN -   "
            "ÇEYREK -"
        )


# ============================================================
# FFMPEG TEXT ESCAPE
# ============================================================

def ffmpeg_text(
    metin
):

    if metin is None:

        return ""

    metin = str(
        metin
    )

    # FFmpeg drawtext için
    metin = metin.replace(
        "\\",
        ""
    )

    metin = metin.replace(
        "'",
        "’"
    )

    metin = metin.replace(
        ":",
        " "
    )

    metin = metin.replace(
        "%",
        "%%"
    )

    metin = metin.replace(
        "\n",
        " "
    )

    metin = metin.replace(
        "\r",
        " "
    )

    return metin.strip()


# ============================================================
# FONT KONTROLÜ
# ============================================================

def font_kontrol():

    if os.path.exists(
        FONT_FILE
    ):

        log(
            f"Arial font bulundu: "
            f"{FONT_FILE}"
        )

        return True

    log(
        "UYARI: Arial fontu bulunamadı."
    )

    return False


# ============================================================
# FFMPEG KOMUTU
# ============================================================

def ffmpeg_komutu(
    haber,
    hava,
    piyasa_bilgi
):

    baslik = ffmpeg_text(
        haber["baslik"]
    )

    kaynak = ffmpeg_text(
        haber["kaynak"]
    )

    hava = ffmpeg_text(
        hava
    )

    piyasa_bilgi = ffmpeg_text(
        piyasa_bilgi
    )

    tarih = ffmpeg_text(
        datetime.now().strftime(
            "%d.%m.%Y %H:%M"
        )
    )

    # --------------------------------------------------------
    # FONT
    #
    # Windows yolu:
    #
    # C:\Windows\Fonts\arial.ttf
    #
    # FFmpeg filter içerisinde:
    #
    # C\:/Windows/Fonts/arial.ttf
    # --------------------------------------------------------

    font = (
        "C\\:/Windows/Fonts/arial.ttf"
    )

    # --------------------------------------------------------
    # FILTER
    # --------------------------------------------------------

    filtre = (

        # ANA ARKA PLAN
        "[0:v]"
        "scale=1280:720,"
        "setsar=1"
        "[base];"

        # LOGO
        "[1:v]"
        "scale=190:-1"
        "[logo];"

        # LOGO SAĞ ÜST
        "[base][logo]"
        "overlay="
        "x=1280-w-25:"
        "y=60"
        "[v1];"

        # ÜST BAR
        "[v1]"
        "drawbox="
        "x=0:"
        "y=0:"
        "w=1280:"
        "h=48:"
        "color=black@0.82:"
        "t=fill"
        "[v2];"

        # TARİH
        "[v2]"
        "drawtext="
        f"fontfile='{font}':"
        f"text='{tarih}':"
        "fontcolor=white:"
        "fontsize=22:"
        "x=20:"
        "y=13"
        "[v3];"

        # HAVA
        "[v3]"
        "drawtext="
        f"fontfile='{font}':"
        f"text='{hava}':"
        "fontcolor=white:"
        "fontsize=20:"
        "x=310:"
        "y=14"
        "[v4];"

        # PİYASA
        "[v4]"
        "drawtext="
        f"fontfile='{font}':"
        f"text='{piyasa_bilgi}':"
        "fontcolor=white:"
        "fontsize=18:"
        "x=590:"
        "y=15"
        "[v5];"

        # ALT PANEL
        "[v5]"
        "drawbox="
        "x=0:"
        "y=535:"
        "w=1280:"
        "h=185:"
        "color=black@0.88:"
        "t=fill"
        "[v6];"

        # ZEM TV HABER
        "[v6]"
        "drawtext="
        f"fontfile='{font}':"
        "text='ZEM TV HABER':"
        "fontcolor=white:"
        "fontsize=30:"
        "x=30:"
        "y=555"
        "[v7];"

        # HABER BAŞLIĞI
        "[v7]"
        "drawtext="
        f"fontfile='{font}':"
        f"text='{baslik}':"
        "fontcolor=white:"
        "fontsize=27:"
        "x=30:"
        "y=600:"
        "line_spacing=7"
        "[v8];"

        # KAYNAK
        "[v8]"
        "drawtext="
        f"fontfile='{font}':"
        f"text='KAYNAK  {kaynak}':"
        "fontcolor=white:"
        "fontsize=18:"
        "x=30:"
        "y=682"
        "[vout]"
    )

    komut = [

        FFMPEG,

        "-hide_banner",

        "-loglevel",
        "warning",

        # ----------------------------------------------------
        # VIDEO ARKA PLAN
        # ----------------------------------------------------

        "-f",
        "lavfi",

        "-i",
        (
            "color="
            "c=0x101722:"
            "s=1280x720:"
            "r=25"
        ),

        # ----------------------------------------------------
        # LOGO
        # ----------------------------------------------------

        "-loop",
        "1",

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
        filtre,

        # ----------------------------------------------------
        # DOĞRU VIDEO ÇIKTISI
        # ----------------------------------------------------

        "-map",
        "[vout]",

        # ----------------------------------------------------
        # SES
        # ----------------------------------------------------

        "-map",
        "2:a",

        # ----------------------------------------------------
        # VIDEO
        # ----------------------------------------------------

        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-tune",
        "zerolatency",

        "-profile:v",
        "main",

        "-level",
        "3.1",

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

        "-b:v",
        "3000k",

        "-maxrate",
        "3500k",

        "-bufsize",
        "7000k",

        # ----------------------------------------------------
        # SES
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
        "aresample=async=1:first_pts=0",

        # ----------------------------------------------------
        # FLV
        # ----------------------------------------------------

        "-flvflags",
        "no_duration_filesize",

        "-f",
        "flv",

        # ----------------------------------------------------
        # RTMP
        # ----------------------------------------------------

        RTMP_URL
    ]

    return komut


# ============================================================
# FFMPEG YAYINI
# ============================================================

def ffmpeg_yayin(
    haber
):

    if not os.path.exists(
        FFMPEG
    ):

        log(
            "FFmpeg bulunamadı!"
        )

        return False

    if not os.path.exists(
        LOGO_FILE
    ):

        log(
            "Logo dosyası bulunamadı!"
        )

        return False

    if not os.path.exists(
        SES_DOSYASI
    ):

        log(
            "Spiker ses dosyası bulunamadı!"
        )

        return False

    log(
        "Hava durumu hazırlanıyor..."
    )

    hava = hava_durumu()

    log(
        f"HAVA: {hava}"
    )

    log(
        "Piyasa verileri hazırlanıyor..."
    )

    piyasa_bilgi = piyasa()

    log(
        f"PİYASA: {piyasa_bilgi}"
    )

    komut = ffmpeg_komutu(
        haber,
        hava,
        piyasa_bilgi
    )

    log(
        "FFmpeg başlatılıyor..."
    )

    try:

        process = subprocess.Popen(

            komut,

            stdout=subprocess.DEVNULL,

            stderr=subprocess.PIPE,

            stdin=subprocess.DEVNULL,

            text=True,

            encoding="utf-8",

            errors="replace"

        )

    except Exception as hata:

        log(
            f"FFmpeg başlatılamadı: {hata}"
        )

        return False

    baslangic = time.time()

    son_hata = ""

    # --------------------------------------------------------
    # FFmpeg OKU
    # --------------------------------------------------------

    while True:

        satir = process.stderr.readline()

        if satir:

            satir = satir.strip()

            if satir:

                log(
                    satir
                )

                son_hata = satir

        kod = process.poll()

        # ----------------------------------------------------
        # FFmpeg KAPANDI
        # ----------------------------------------------------

        if kod is not None:

            log(
                f"FFmpeg kapandı. "
                f"Çıkış kodu: {kod}"
            )

            if son_hata:

                log(
                    f"Son FFmpeg mesajı: "
                    f"{son_hata}"
                )

            return False

        # ----------------------------------------------------
        # HABER SÜRESİ
        # ----------------------------------------------------

        gecen = (
            time.time()
            - baslangic
        )

        if gecen >= HABER_SURESI:

            log(
                "Haber yayın süresi tamamlandı."
            )

            try:

                process.terminate()

                process.wait(
                    timeout=7
                )

            except Exception:

                try:

                    process.kill()

                except Exception:

                    pass

            return True


# ============================================================
# RTMP TESTİ
# ============================================================

def rtmp_bilgi():

    log(
        "RTMP hedefi:"
    )

    log(
        RTMP_URL
    )


# ============================================================
# BAŞLANGIÇ KONTROL
# ============================================================

def sistem_kontrol():

    log(
        "========================================"
    )

    log(
        VERSION
    )

    log(
        "========================================"
    )

    log(
        f"RTMP: {RTMP_URL}"
    )

    log(
        f"FFmpeg: {FFMPEG}"
    )

    log(
        f"Logo: {LOGO_URL}"
    )

    log(
        f"Hava şehri: {HAVA_SEHRI}"
    )

    # --------------------------------------------------------
    # FFmpeg
    # --------------------------------------------------------

    if not os.path.exists(
        FFMPEG
    ):

        log(
            "HATA: FFmpeg bulunamadı!"
        )

        log(
            r"Kontrol: C:\ffmpeg\bin\ffmpeg.exe"
        )

        return False

    log(
        "FFmpeg bulundu."
    )

    # --------------------------------------------------------
    # Font
    # --------------------------------------------------------

    font_kontrol()

    # --------------------------------------------------------
    # Logo
    # --------------------------------------------------------

    if not logo_indir():

        log(
            "Logo alınamadı."
        )

        return False

    # --------------------------------------------------------
    # RTMP
    # --------------------------------------------------------

    rtmp_bilgi()

    return True


# ============================================================
# ANA DÖNGÜ
# ============================================================

def main():

    print("")
    print("")
    print("================================================")
    print("              ZEM TV HABER")
    print("        OTOMATİK HABER SİSTEMİ")
    print("================================================")
    print("")

    # --------------------------------------------------------
    # Sistem kontrol
    # --------------------------------------------------------

    if not sistem_kontrol():

        log(
            "Sistem kontrolü başarısız."
        )

        input(
            "Çıkmak için ENTER..."
        )

        return

    # --------------------------------------------------------
    # edge-tts
    # --------------------------------------------------------

    if paket_kontrol() is None:

        log(
            "Yapay zeka ses sistemi başlatılamadı."
        )

        input(
            "Çıkmak için ENTER..."
        )

        return

    # --------------------------------------------------------
    # İlk haber taraması
    # --------------------------------------------------------

    log(
        "İlk haber taraması başlıyor..."
    )

    haberleri_topla()

    if not haber_listesi:

        log(
            "HİÇ HABER BULUNAMADI!"
        )

        log(
            "RSS bağlantılarını kontrol edin."
        )

        input(
            "Çıkmak için ENTER..."
        )

        return

    # --------------------------------------------------------
    # Başlangıç
    # --------------------------------------------------------

    index = 0

    son_haber_kontrol = time.time()

    log(
        "========================================"
    )

    log(
        "YAYIN SİSTEMİ HAZIR"
    )

    log(
        f"Haber sayısı: {len(haber_listesi)}"
    )

    log(
        "RTMP yayını başlatılıyor..."
    )

    log(
        "========================================"
    )

    # ========================================================
    # SONSUZ DÖNGÜ
    # ========================================================

    while True:

        try:

            # ------------------------------------------------
            # Yeni haber kontrolü
            # ------------------------------------------------

            if (
                time.time()
                - son_haber_kontrol
                >= HABER_KONTROL_SURESI
            ):

                log(
                    "5 dakikalık haber kontrolü başladı."
                )

                haberleri_topla()

                son_haber_kontrol = time.time()

            # ------------------------------------------------
            # Haber yok
            # ------------------------------------------------

            if not haber_listesi:

                log(
                    "Haber kuyruğu boş."
                )

                time.sleep(
                    10
                )

                continue

            # ------------------------------------------------
            # INDEX
            # ------------------------------------------------

            if index >= len(
                haber_listesi
            ):

                index = 0

                log(
                    "Haber kuyruğunun sonuna gelindi."
                )

            # ------------------------------------------------
            # HABER
            # ------------------------------------------------

            haber = haber_listesi[
                index
            ]

            index += 1

            log("")
            log(
                "========================================"
            )

            log(
                f"HABER: {haber['baslik']}"
            )

            log(
                f"KAYNAK: {haber['kaynak']}"
            )

            log(
                "========================================"
            )

            # ------------------------------------------------
            # SPİKER
            # ------------------------------------------------

            metin = spiker_metni(
                haber
            )

            log(
                f"SPİKER: {metin}"
            )

            # ------------------------------------------------
            # SES
            # ------------------------------------------------

            if not ses_olustur(
                metin
            ):

                log(
                    "Ses oluşturulamadı."
                )

                log(
                    "Haber atlanıyor."
                )

                continue

            # ------------------------------------------------
            # YAYIN
            # ------------------------------------------------

            basarili = ffmpeg_yayin(
                haber
            )

            # ------------------------------------------------
            # SONUÇ
            # ------------------------------------------------

            if basarili:

                log(
                    "Haber yayını tamamlandı."
                )

            else:

                log(
                    "Haber yayınlanamadı."
                )

                log(
                    "5 saniye sonra tekrar denenecek."
                )

                time.sleep(
                    5
                )

        # ----------------------------------------------------
        # CTRL+C
        # ----------------------------------------------------

        except KeyboardInterrupt:

            log("")
            log(
                "ZEM TV HABER durduruluyor..."
            )

            break

        # ----------------------------------------------------
        # ANA HATA
        # ----------------------------------------------------

        except Exception as hata:

            log(
                f"ANA SİSTEM HATASI: {hata}"
            )

            log(
                "10 saniye sonra sistem devam edecek."
            )

            time.sleep(
                10
            )


# ============================================================
# PROGRAMI BAŞLAT
# ============================================================

if __name__ == "__main__":

    main()
