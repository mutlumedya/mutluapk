# -*- coding: utf-8 -*-

import os
import sys
import time
import html
import ssl
import subprocess
import urllib.parse
import threading
import xml.etree.ElementTree as ET
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

try:
    import requests
except ImportError:
    subprocess.call([sys.executable, "-m", "pip", "install", "requests"])
    import requests


RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zentvhaber"

FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

SOURCE_M3U8 = "https://cdn.codenet.lol/streamgo/stremgo123/4864.m3u8"
SOURCE_REFERER = "https://codenet.lol/"
SOURCE_USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/128.0.0.0 Safari/537.36"
)

LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"
LOGO_FILE = "zemtv_logo.png"

FONT_FILE = r"C:\Windows\Fonts\arial.ttf"

CITY = "Konya"

WIDTH = 1280
HEIGHT = 720
FPS = 25

VIDEO_BITRATE = "3500k"
MAXRATE = "4000k"
BUFSIZE = "7000k"

NEWS_REFRESH = 120
NEWS_TIMEOUT = 8

RUNNING = True

news_lock = threading.Lock()

ticker_news = [
    "ZEM TV HABER",
    "Güncel gelişmeler takip ediliyor"
]

last_news_update = 0


RSS_SOURCES = [
    (
        "TRT HABER",
        "https://www.trthaber.com/manset_articles.rss"
    ),
    (
        "TRT SON DAKİKA",
        "https://www.trthaber.com/sondakika_articles.rss"
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
        "TRT YAŞAM",
        "https://www.trthaber.com/yasam_articles.rss"
    ),
    (
        "TRT TEKNOLOJİ",
        "https://www.trthaber.com/bilim_teknoloji_articles.rss"
    ),
    (
        "TRT SAĞLIK",
        "https://www.trthaber.com/saglik_articles.rss"
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
        "HABERTÜRK DÜNYA",
        "https://www.haberturk.com/rss/kategori/dunya.xml"
    ),
    (
        "HABERTÜRK EKONOMİ",
        "https://www.haberturk.com/rss/ekonomi.xml"
    ),
    (
        "HABERTÜRK TEKNOLOJİ",
        "https://www.haberturk.com/rss/kategori/teknoloji.xml"
    )
]


HEADERS = {
    "User-Agent": SOURCE_USER_AGENT,
    "Accept": "*/*",
    "Accept-Language": "tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7"
}


def temizle(text):

    if not text:
        return ""

    text = html.unescape(str(text))

    text = text.replace(
        "\r",
        " "
    )

    text = text.replace(
        "\n",
        " "
    )

    text = text.replace(
        "\t",
        " "
    )

    while "<" in text and ">" in text:

        eski = text

        start = text.find("<")
        end = text.find(">", start)

        if start == -1 or end == -1:
            break

        text = (
            text[:start]
            + " "
            + text[end + 1:]
        )

        if text == eski:
            break

    text = " ".join(
        text.split()
    )

    return text.strip()


def ffmpeg_text(text):

    text = temizle(text)

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

    text = text.replace(
        ";",
        ","
    )

    return text


def indir_logo():

    if os.path.exists(
        LOGO_FILE
    ):
        return True

    try:

        response = requests.get(
            LOGO_URL,
            headers=HEADERS,
            timeout=20,
            verify=False
        )

        response.raise_for_status()

        with open(
            LOGO_FILE,
            "wb"
        ) as file:

            file.write(
                response.content
            )

        return True

    except Exception:

        return False


def rss_oku(source_name, url):

    try:

        response = requests.get(
            url,
            headers=HEADERS,
            timeout=NEWS_TIMEOUT,
            verify=False
        )

        response.raise_for_status()

        root = ET.fromstring(
            response.content
        )

        result = []

        for item in root.findall(
            ".//item"
        )[:30]:

            title_node = item.find(
                "title"
            )

            if title_node is None:
                continue

            title = temizle(
                title_node.text or ""
            )

            if not title:
                continue

            pub_node = item.find(
                "pubDate"
            )

            pubdate = ""

            if pub_node is not None:
                pubdate = temizle(
                    pub_node.text or ""
                )

            result.append({
                "title": title,
                "source": source_name,
                "date": pubdate
            })

        return result

    except Exception:

        return []


def haberleri_guncelle():

    global ticker_news
    global last_news_update

    collected = []

    with ThreadPoolExecutor(
        max_workers=14
    ) as executor:

        futures = []

        for source_name, url in RSS_SOURCES:

            futures.append(
                executor.submit(
                    rss_oku,
                    source_name,
                    url
                )
            )

        for future in as_completed(
            futures
        ):

            try:

                result = future.result()

                if result:
                    collected.extend(
                        result
                    )

            except Exception:
                pass

    unique = []
    seen = set()

    for item in collected:

        title = item["title"]

        key = (
            title
            .lower()
            .replace(
                " ",
                ""
            )
        )

        if key in seen:
            continue

        seen.add(key)

        unique.append(
            item
        )

    if unique:

        with news_lock:

            ticker_news = [
                item["title"]
                for item in unique[:35]
            ]

            last_news_update = time.time()


def haber_thread():

    global RUNNING

    while RUNNING:

        try:

            haberleri_guncelle()

        except Exception:
            pass

        for _ in range(
            NEWS_REFRESH
        ):

            if not RUNNING:
                break

            time.sleep(1)


def hava_durumu():

    try:

        url = (
            "https://wttr.in/"
            + urllib.parse.quote(CITY)
            + "?format=j1"
        )

        response = requests.get(
            url,
            headers=HEADERS,
            timeout=7,
            verify=False
        )

        data = response.json()

        current = data[
            "current_condition"
        ][0]

        temp = current.get(
            "temp_C",
            "?"
        )

        condition = current[
            "weatherDesc"
        ][0]["value"]

        return (
            CITY
            + " "
            + str(temp)
            + " C "
            + condition
        )

    except Exception:

        return (
            CITY
            + " hava bilgisi"
        )


def kayan_yazi():

    with news_lock:

        items = list(
            ticker_news
        )

    if not items:

        return (
            "ZEM TV HABER • "
            "SON DAKİKA GELİŞMELERİ • "
        )

    text = "     •     ".join(
        items[:30]
    )

    return (
        text
        + "     •     ZEM TV HABER     •     "
    )


def filter_complex():

    font = (
        FONT_FILE
        .replace(
            "\\",
            "/"
        )
        .replace(
            ":",
            "\\:"
        )
    )

    logo = (
        os.path.abspath(
            LOGO_FILE
        )
        .replace(
            "\\",
            "/"
        )
        .replace(
            ":",
            "\\:"
        )
    )

    now = datetime.now()

    date_text = now.strftime(
        "%d.%m.%Y"
    )

    time_text = now.strftime(
        "%H:%M:%S"
    )

    weather = ffmpeg_text(
        hava_durumu()
    )

    ticker = ffmpeg_text(
        kayan_yazi()
    )

    filters = []

    filters.append(
        "drawbox="
        "x=0:y=0:w=1280:h=68:"
        "color=0x071019:"
        "t=fill"
    )

    filters.append(
        "drawbox="
        "x=0:y=68:w=1280:h=38:"
        "color=0x101a24:"
        "t=fill"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='ZEM TV HABER':"
        "fontcolor=white:"
        "fontsize=30:"
        "x=25:"
        "y=17"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='" + date_text + "':"
        "fontcolor=white:"
        "fontsize=19:"
        "x=320:"
        "y=21"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='" + time_text + "':"
        "fontcolor=white:"
        "fontsize=19:"
        "x=430:"
        "y=21"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='" + weather + "':"
        "fontcolor=white:"
        "fontsize=18:"
        "x=545:"
        "y=21"
    )

    filters.append(
        "drawbox="
        "x=0:y=106:w=1280:h=48:"
        "color=0x101a24@0.90:"
        "t=fill"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='CANLI YAYIN':"
        "fontcolor=0xff3030:"
        "fontsize=22:"
        "x=25:"
        "y=119"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='ZEM TV HABER':"
        "fontcolor=white:"
        "fontsize=21:"
        "x=210:"
        "y=119"
    )

    filters.append(
        "drawbox="
        "x=0:y=640:w=1280:h=42:"
        "color=0x050505:"
        "t=fill"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='SON DAKİKA':"
        "fontcolor=0xff3030:"
        "fontsize=20:"
        "x=20:"
        "y=650"
    )

    filters.append(
        "drawbox="
        "x=170:y=640:w=1110:h=42:"
        "color=0x0d151d:"
        "t=fill"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='" + ticker + "':"
        "fontcolor=white:"
        "fontsize=18:"
        "x=180:"
        "y=650:"
        "enable='gte(t,0)'"
    )

    filters.append(
        "drawbox="
        "x=0:y=682:w=1280:h=38:"
        "color=0x020202:"
        "t=fill"
    )

    filters.append(
        "drawtext="
        "fontfile='" + font + "':"
        "text='ZEM TV HABER • Güncel haber başlıkları':"
        "fontcolor=0xc7c7c7:"
        "fontsize=16:"
        "x=25:"
        "y=692"
    )

    filters.append(
        "movie='" + logo + "'"
        "[lg];"
        "[lg]scale=145:-1[logo];"
        "[base][logo]overlay=W-w-20:10[vout]"
    )

    base_filters = ",".join(
        filters[:-1]
    )

    final_filter = (
        "[0:v]"
        + base_filters
        + "[base];"
        + filters[-1]
    )

    return final_filter


def ffmpeg_baslat():

    filter_graph = filter_complex()

    command = [

        FFMPEG,

        "-hide_banner",

        "-loglevel",
        "warning",

        "-re",

        "-headers",
        (
            "Referer: "
            + SOURCE_REFERER
            + "\r\n"
            "User-Agent: "
            + SOURCE_USER_AGENT
            + "\r\n"
        ),

        "-i",
        SOURCE_M3U8,

        "-filter_complex",
        filter_graph,

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
        str(FPS),

        "-g",
        "50",

        "-keyint_min",
        "50",

        "-sc_threshold",
        "0",

        "-b:v",
        VIDEO_BITRATE,

        "-maxrate",
        MAXRATE,

        "-bufsize",
        BUFSIZE,

        "-c:a",
        "aac",

        "-b:a",
        "128k",

        "-ar",
        "44100",

        "-ac",
        "2",

        "-af",
        "aresample=async=1:min_hard_comp=0.100:first_pts=0",

        "-f",
        "flv",

        RTMP_URL
    ]

    return subprocess.Popen(
        command,
        stdin=subprocess.DEVNULL
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

        return

    if not os.path.exists(
        FONT_FILE
    ):

        print(
            "Arial bulunamadi:"
        )

        print(
            FONT_FILE
        )

        return

    print(
        "ZEM TV HABER"
    )

    print(
        "M3U8:"
    )

    print(
        SOURCE_M3U8
    )

    print(
        "RTMP:"
    )

    print(
        RTMP_URL
    )

    print(
        "Logo indiriliyor..."
    )

    if not indir_logo():

        print(
            "Logo indirilemedi."
        )

    print(
        "Haber kaynaklari kontrol ediliyor..."
    )

    haberleri_guncelle()

    print(
        "Haber sayisi:",
        len(ticker_news)
    )

    thread = threading.Thread(
        target=haber_thread,
        daemon=True
    )

    thread.start()

    process = None

    while RUNNING:

        try:

            if process is None:

                print(
                    "FFmpeg yayin baslatiliyor..."
                )

                process = ffmpeg_baslat()

                time.sleep(5)

            if process.poll() is not None:

                print(
                    "FFmpeg durdu. Yeniden baslatiliyor..."
                )

                process = None

                time.sleep(5)

            time.sleep(2)

        except KeyboardInterrupt:

            RUNNING = False

            break

        except Exception as error:

            print(
                "HATA:",
                error
            )

            process = None

            time.sleep(5)

    if process:

        try:
            process.terminate()
        except Exception:
            pass


if __name__ == "__main__":

    try:

        requests.packages.urllib3.disable_warnings()

    except Exception:
        pass

    main()
