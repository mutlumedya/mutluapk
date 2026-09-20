# -*- coding: utf-8 -*-

import os
import sys
import time
import html
import subprocess
import threading
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor, as_completed

try:
    import requests
except ImportError:
    subprocess.call([sys.executable, "-m", "pip", "install", "requests"])
    import requests


FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

SOURCE_M3U8 = "https://cdn.codenet.lol/streamgo/stremgo123/4864.m3u8"
SOURCE_REFERER = "https://codenet.lol/"
SOURCE_USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36"

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zentvhaber"

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

LOGO_FILE = os.path.join(BASE_DIR, "zemtv_logo.png")
TICKER_FILE = os.path.join(BASE_DIR, "ticker.txt")
TIME_FILE = os.path.join(BASE_DIR, "time.txt")
WEATHER_FILE = os.path.join(BASE_DIR, "weather.txt")
CHANNEL_FILE = os.path.join(BASE_DIR, "channel.txt")
LIVE_FILE = os.path.join(BASE_DIR, "live.txt")
BOTTOM_FILE = os.path.join(BASE_DIR, "bottom.txt")

LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"

FONT_FILE = r"C:\Windows\Fonts\arial.ttf"

CITY = "Konya"

NEWS_REFRESH = 120

RUNNING = True

HEADERS = {
    "User-Agent": SOURCE_USER_AGENT,
    "Accept": "*/*",
    "Accept-Language": "tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7"
}

RSS_SOURCES = [
    ("TRT HABER", "https://www.trthaber.com/manset_articles.rss"),
    ("TRT SON DAKİKA", "https://www.trthaber.com/sondakika_articles.rss"),
    ("TRT GÜNDEM", "https://www.trthaber.com/gundem_articles.rss"),
    ("TRT TÜRKİYE", "https://www.trthaber.com/turkiye_articles.rss"),
    ("TRT DÜNYA", "https://www.trthaber.com/dunya_articles.rss"),
    ("TRT EKONOMİ", "https://www.trthaber.com/ekonomi_articles.rss"),
    ("TRT YAŞAM", "https://www.trthaber.com/yasam_articles.rss"),
    ("TRT TEKNOLOJİ", "https://www.trthaber.com/bilim_teknoloji_articles.rss"),
    ("TRT SAĞLIK", "https://www.trthaber.com/saglik_articles.rss"),
    ("HABERTÜRK", "https://www.haberturk.com/rss"),
    ("HABERTÜRK GÜNDEM", "https://www.haberturk.com/rss/kategori/gundem.xml"),
    ("HABERTÜRK DÜNYA", "https://www.haberturk.com/rss/kategori/dunya.xml"),
    ("HABERTÜRK EKONOMİ", "https://www.haberturk.com/rss/ekonomi.xml"),
    ("HABERTÜRK TEKNOLOJİ", "https://www.haberturk.com/rss/kategori/teknoloji.xml"),
    ("NTV", "https://www.ntv.com.tr/son-dakika.rss"),
    ("GOOGLE KONYA", "https://news.google.com/rss/search?q=Konya&hl=tr&gl=TR&ceid=TR:tr"),
    ("GOOGLE ADANA", "https://news.google.com/rss/search?q=Adana&hl=tr&gl=TR&ceid=TR:tr"),
    ("GOOGLE TÜRKİYE", "https://news.google.com/rss/search?q=Türkiye&hl=tr&gl=TR&ceid=TR:tr")
]


def yaz_atomik(dosya, metin):
    gecici = dosya + ".tmp"

    try:
        with open(
            gecici,
            "w",
            encoding="utf-8",
            newline="\n"
        ) as f:
            f.write(metin)

        for _ in range(10):
            try:
                os.replace(
                    gecici,
                    dosya
                )
                return
            except PermissionError:
                time.sleep(0.1)

        with open(
            dosya,
            "w",
            encoding="utf-8",
            newline="\n"
        ) as f:
            f.write(metin)

        if os.path.exists(gecici):
            try:
                os.remove(gecici)
            except Exception:
                pass

    except Exception:
        try:
            with open(
                dosya,
                "w",
                encoding="utf-8",
                newline="\n"
            ) as f:
                f.write(metin)
        except Exception:
            pass


def temizle(metin):
    if not metin:
        return ""

    metin = html.unescape(
        str(metin)
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

    while "<" in metin and ">" in metin:
        bas = metin.find("<")
        son = metin.find(">", bas)

        if bas == -1 or son == -1:
            break

        metin = (
            metin[:bas]
            + " "
            + metin[son + 1:]
        )

    metin = " ".join(
        metin.split()
    )

    return metin.strip()


def rss_oku(kaynak, url):
    haberler = []

    try:
        cevap = requests.get(
            url,
            headers=HEADERS,
            timeout=8,
            verify=False
        )

        cevap.raise_for_status()

        kok = ET.fromstring(
            cevap.content
        )

        for item in kok.findall(".//item")[:25]:

            baslik_node = item.find("title")

            if baslik_node is None:
                continue

            baslik = temizle(
                baslik_node.text
                or ""
            )

            if not baslik:
                continue

            haberler.append(
                (
                    baslik,
                    kaynak
                )
            )

    except Exception:
        pass

    return haberler


def haberleri_getir():

    tum_haberler = []

    with ThreadPoolExecutor(
        max_workers=12
    ) as executor:

        isler = [
            executor.submit(
                rss_oku,
                kaynak,
                url
            )
            for kaynak, url in RSS_SOURCES
        ]

        for islem in as_completed(isler):

            try:
                sonuc = islem.result()

                if sonuc:
                    tum_haberler.extend(
                        sonuc
                    )

            except Exception:
                pass

    benzersiz = []
    gorulen = set()

    for baslik, kaynak in tum_haberler:

        anahtar = (
            baslik
            .lower()
            .replace(
                " ",
                ""
            )
            .replace(
                ".",
                ""
            )
            .replace(
                ",",
                ""
            )
        )

        if not anahtar:
            continue

        if anahtar in gorulen:
            continue

        gorulen.add(
            anahtar
        )

        benzersiz.append(
            baslik
        )

    if not benzersiz:
        benzersiz = [
            "ZEM TV HABER • Güncel haberler yükleniyor"
        ]

    metin = (
        "     •     ".join(
            benzersiz[:35]
        )
        + "     •     ZEM TV HABER     •     "
    )

    yaz_atomik(
        TICKER_FILE,
        metin
    )

    return len(benzersiz)


def haber_dongusu():

    while RUNNING:

        try:
            adet = haberleri_getir()

            print(
                "Haberler guncellendi:",
                adet
            )

        except Exception as e:
            print(
                "Haber guncelleme hatasi:",
                e
            )

        for _ in range(120):

            if not RUNNING:
                return

            time.sleep(1)


def hava_durumu_getir():

    try:

        url = (
            "https://wttr.in/"
            + CITY
            + "?format=j1"
        )

        cevap = requests.get(
            url,
            headers=HEADERS,
            timeout=8,
            verify=False
        )

        veri = cevap.json()

        mevcut = veri[
            "current_condition"
        ][0]

        sicaklik = mevcut.get(
            "temp_C",
            "?"
        )

        durum = mevcut[
            "weatherDesc"
        ][0]["value"]

        return (
            CITY
            + " "
            + str(sicaklik)
            + " C "
            + durum
        )

    except Exception:

        return (
            CITY
            + " hava bilgisi"
        )


def saat_dongusu():

    while RUNNING:

        try:

            now = time.localtime()

            tarih = time.strftime(
                "%d.%m.%Y",
                now
            )

            saat = time.strftime(
                "%H:%M:%S",
                now
            )

            yaz_atomik(
                TIME_FILE,
                tarih + "    " + saat
            )

            yaz_atomik(
                LIVE_FILE,
                "CANLI YAYIN"
            )

            yaz_atomik(
                CHANNEL_FILE,
                "ZEM TV HABER"
            )

            yaz_atomik(
                BOTTOM_FILE,
                "ZEM TV HABER • Güncel haber başlıkları"
            )

        except Exception:
            pass

        time.sleep(1)


def hava_dongusu():

    while RUNNING:

        try:

            hava = hava_durumu_getir()

            yaz_atomik(
                WEATHER_FILE,
                hava
            )

        except Exception:
            pass

        for _ in range(120):

            if not RUNNING:
                return

            time.sleep(1)


def logo_indir():

    if os.path.exists(
        LOGO_FILE
    ):

        try:

            if os.path.getsize(
                LOGO_FILE
            ) > 100:

                return True

        except Exception:
            pass

    try:

        cevap = requests.get(
            LOGO_URL,
            headers=HEADERS,
            timeout=20,
            verify=False
        )

        cevap.raise_for_status()

        gecici = LOGO_FILE + ".tmp"

        with open(
            gecici,
            "wb"
        ) as f:
            f.write(
                cevap.content
            )

        os.replace(
            gecici,
            LOGO_FILE
        )

        return True

    except Exception as e:

        print(
            "Logo indirilemedi:",
            e
        )

        return False


def ffmpeg_yol(yol):

    yol = os.path.abspath(
        yol
    )

    yol = yol.replace(
        "\\",
        "/"
    )

    yol = yol.replace(
        ":",
        "\\:"
    )

    return yol


def filtre_olustur():

    font = ffmpeg_yol(
        FONT_FILE
    )

    ticker = ffmpeg_yol(
        TICKER_FILE
    )

    saat = ffmpeg_yol(
        TIME_FILE
    )

    hava = ffmpeg_yol(
        WEATHER_FILE
    )

    kanal = ffmpeg_yol(
        CHANNEL_FILE
    )

    canli = ffmpeg_yol(
        LIVE_FILE
    )

    alt = ffmpeg_yol(
        BOTTOM_FILE
    )

    logo = ffmpeg_yol(
        LOGO_FILE
    )

    filtre = (
        "[0:v]"
        "scale=1280:720:force_original_aspect_ratio=decrease,"
        "pad=1280:720:(ow-iw)/2:(oh-ih)/2:black,"
        "setsar=1"
        "[base];"

        "[1:v]"
        "scale=145:-1"
        "[logo];"

        "[base][logo]"
        "overlay=W-w-20:10"
        "[v1];"

        "[v1]"
        "drawbox="
        "x=0:y=0:w=1280:h=68:"
        "color=0x071019:"
        "t=fill"
        "[v2];"

        "[v2]"
        "drawtext="
        "fontfile='" + font + "':"
        "textfile='" + kanal + "':"
        "reload=25:"
        "fontcolor=white:"
        "fontsize=30:"
        "x=25:"
        "y=17"
        "[v3];"

        "[v3]"
        "drawtext="
        "fontfile='" + font + "':"
        "textfile='" + saat + "':"
        "reload=25:"
        "fontcolor=white:"
        "fontsize=19:"
        "x=320:"
        "y=22"
        "[v4];"

        "[v4]"
        "drawtext="
        "fontfile='" + font + "':"
        "textfile='" + hava + "':"
        "reload=25:"
        "fontcolor=white:"
        "fontsize=18:"
        "x=545:"
        "y=22"
        "[v5];"

        "[v5]"
        "drawbox="
        "x=0:y=106:w=1280:h=48:"
        "color=0x101824@0.90:"
        "t=fill"
        "[v6];"

        "[v6]"
        "drawtext="
        "fontfile='" + font + "':"
        "textfile='" + canli + "':"
        "reload=25:"
        "fontcolor=0xff3030:"
        "fontsize=22:"
        "x=25:"
        "y=119"
        "[v7];"

        "[v7]"
        "drawtext="
        "fontfile='" + font + "':"
        "textfile='" + kanal + "':"
        "reload=25:"
        "fontcolor=white:"
        "fontsize=21:"
        "x=210:"
        "y=119"
        "[v8];"

        "[v8]"
        "drawbox="
        "x=0:y=640:w=1280:h=42:"
        "color=0x050505:"
        "t=fill"
        "[v9];"

        "[v9]"
        "drawtext="
        "fontfile='" + font + "':"
        "text='SON DAKIKA':"
        "fontcolor=0xff3030:"
        "fontsize=20:"
        "x=20:"
        "y=650"
        "[v10];"

        "[v10]"
        "drawbox="
        "x=170:y=640:w=1110:h=42:"
        "color=0x0d151d:"
        "t=fill"
        "[v11];"

        "[v11]"
        "drawtext="
        "fontfile='" + font + "':"
        "textfile='" + ticker + "':"
        "reload=25:"
        "fontcolor=white:"
        "fontsize=18:"
        "x=w-mod(t*110\\,w+text_w):"
        "y=650:"
        "expansion=none"
        "[v12];"

        "[v12]"
        "drawbox="
        "x=0:y=682:w=1280:h=38:"
        "color=0x020202:"
        "t=fill"
        "[v13];"

        "[v13]"
        "drawtext="
        "fontfile='" + font + "':"
        "textfile='" + alt + "':"
        "reload=25:"
        "fontcolor=0xc7c7c7:"
        "fontsize=16:"
        "x=25:"
        "y=692"
        "[vout]"
    )

    return filtre


def ffmpeg_baslat():

    filtre = filtre_olustur()

    komut = [
        FFMPEG,

        "-hide_banner",

        "-loglevel",
        "warning",

        "-reconnect",
        "1",

        "-reconnect_streamed",
        "1",

        "-reconnect_delay_max",
        "5",

        "-user_agent",
        SOURCE_USER_AGENT,

        "-referer",
        SOURCE_REFERER,

        "-i",
        SOURCE_M3U8,

        "-loop",
        "1",

        "-i",
        LOGO_FILE,

        "-filter_complex",
        filtre,

        "-map",
        "[vout]",

        "-map",
        "0:a?",

        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-tune",
        "zerolatency",

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
        "3500k",

        "-maxrate",
        "4000k",

        "-bufsize",
        "7000k",

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

        "-flvflags",
        "no_duration_filesize",

        "-f",
        "flv",

        RTMP_URL
    ]

    print()
    print("FFmpeg baslatiliyor...")
    print()

    return subprocess.Popen(
        komut,
        stdin=subprocess.DEVNULL
    )


def dosyalari_hazirla():

    yaz_atomik(
        TICKER_FILE,
        "ZEM TV HABER     •     Güncel haberler yükleniyor     •     "
    )

    yaz_atomik(
        TIME_FILE,
        time.strftime(
            "%d.%m.%Y    %H:%M:%S"
        )
    )

    yaz_atomik(
        WEATHER_FILE,
        "Konya"
    )

    yaz_atomik(
        CHANNEL_FILE,
        "ZEM TV HABER"
    )

    yaz_atomik(
        LIVE_FILE,
        "CANLI YAYIN"
    )

    yaz_atomik(
        BOTTOM_FILE,
        "ZEM TV HABER • Güncel haber başlıkları"
    )


def main():

    global RUNNING

    os.system(
        "title ZEM TV HABER"
    )

    if not os.path.exists(
        FFMPEG
    ):

        print(
            "FFmpeg bulunamadi:"
        )

        print(
            FFMPEG
        )

        input(
            "Kapatmak icin Enter..."
        )

        return

    if not os.path.exists(
        FONT_FILE
    ):

        print(
            "Arial fontu bulunamadi:"
        )

        print(
            FONT_FILE
        )

        input(
            "Kapatmak icin Enter..."
        )

        return

    print(
        "ZEM TV HABER BASLIYOR"
    )

    print(
        "M3U8:",
        SOURCE_M3U8
    )

    print(
        "RTMP:",
        RTMP_URL
    )

    dosyalari_hazirla()

    print(
        "Logo kontrol ediliyor..."
    )

    if not logo_indir():

        print(
            "Logo bulunamadi."
        )

        return

    print(
        "Ilk haberler aliniyor..."
    )

    try:

        adet = haberleri_getir()

        print(
            "Alinan haber:",
            adet
        )

    except Exception as e:

        print(
            "Haber sistemi:",
            e
        )

    threading.Thread(
        target=haber_dongusu,
        daemon=True
    ).start()

    threading.Thread(
        target=saat_dongusu,
        daemon=True
    ).start()

    threading.Thread(
        target=hava_dongusu,
        daemon=True
    ).start()

    proses = None

    while RUNNING:

        try:

            if proses is None:

                proses = ffmpeg_baslat()

                time.sleep(8)

            if proses.poll() is not None:

                print()
                print(
                    "FFmpeg durdu. 5 saniye sonra tekrar baslatiliyor..."
                )

                proses = None

                time.sleep(5)

            time.sleep(2)

        except KeyboardInterrupt:

            RUNNING = False

            if proses:

                try:
                    proses.terminate()
                except Exception:
                    pass

            break

        except Exception as e:

            print(
                "Yayin hatasi:",
                e
            )

            if proses:

                try:
                    proses.terminate()
                except Exception:
                    pass

            proses = None

            time.sleep(5)


if __name__ == "__main__":

    try:
        requests.packages.urllib3.disable_warnings()
    except Exception:
        pass

    main()
```0
