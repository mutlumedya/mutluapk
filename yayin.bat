import os
import sys
import re
import ssl
import html
import json
import time
import shutil
import asyncio
import subprocess
import threading
import urllib.request
import urllib.parse
import xml.etree.ElementTree as ET
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

RTMP_URL = "rtmp://ssh101.bozztv.com:1935/ssh101/zentvhaber"
FFMPEG = r"C:\ffmpeg\bin\ffmpeg.exe"

LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/cine/refs/heads/main/telegram.png"
LOGO_FILE = "zemtv_logo.png"

VOICE_FILE = "zemtv_voice.mp3"
MEDIA_FILE = "zemtv_media.mp4"
IMAGE_FILE = "zemtv_image.jpg"

CITY = "Konya"

WIDTH = 1280
HEIGHT = 720
FPS = 25

NEWS_DURATION = 45
RSS_TIMEOUT = 6
RSS_REFRESH = 180

RSS_SOURCES = [
    ("TRT HABER MANŞET", "https://www.trthaber.com/manset_articles.rss"),
    ("TRT HABER SON DAKİKA", "https://www.trthaber.com/sondakika_articles.rss"),
    ("TRT HABER GÜNDEM", "https://www.trthaber.com/gundem_articles.rss"),
    ("TRT HABER TÜRKİYE", "https://www.trthaber.com/turkiye_articles.rss"),
    ("TRT HABER DÜNYA", "https://www.trthaber.com/dunya_articles.rss"),
    ("TRT HABER EKONOMİ", "https://www.trthaber.com/ekonomi_articles.rss"),
    ("TRT HABER YAŞAM", "https://www.trthaber.com/yasam_articles.rss"),
    ("TRT HABER TEKNOLOJİ", "https://www.trthaber.com/bilim_teknoloji_articles.rss"),
    ("TRT HABER SAĞLIK", "https://www.trthaber.com/saglik_articles.rss"),
    ("HABERTÜRK MANŞET", "https://www.haberturk.com/rss/manset.xml"),
    ("HABERTÜRK GÜNDEM", "https://www.haberturk.com/rss/kategori/gundem.xml"),
    ("HABERTÜRK EKONOMİ", "https://www.haberturk.com/rss/ekonomi.xml"),
    ("HABERTÜRK DÜNYA", "https://www.haberturk.com/rss/kategori/dunya.xml"),
    ("HABERTÜRK TEKNOLOJİ", "https://www.haberturk.com/rss/kategori/teknoloji.xml"),
    ("HABERTÜRK VİDEO", "https://www.haberturk.com/rss/kategori/video.xml"),
]

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 Chrome/128.0 Safari/537.36"
    ),
    "Accept": "*/*",
    "Accept-Language": "tr-TR,tr;q=0.9,en;q=0.8",
}

news_list = []
news_lock = threading.Lock()
played_titles = set()
running = True


def install_package(package):
    try:
        __import__(package.replace("-", "_"))
        return True
    except ImportError:
        try:
            subprocess.check_call([
                sys.executable,
                "-m",
                "pip",
                "install",
                package
            ])
            return True
        except Exception:
            return False


def setup_packages():
    ok = True

    if not install_package("edge-tts"):
        ok = False

    if not install_package("requests"):
        ok = False

    if not install_package("beautifulsoup4"):
        ok = False

    return ok


setup_packages()

import requests
from bs4 import BeautifulSoup
import edge_tts


def ssl_context():
    return ssl._create_unverified_context()


def clean_text(value):
    if not value:
        return ""

    value = html.unescape(value)
    value = re.sub(r"<script.*?</script>", " ", value, flags=re.I | re.S)
    value = re.sub(r"<style.*?</style>", " ", value, flags=re.I | re.S)
    value = re.sub(r"<[^>]+>", " ", value)
    value = value.replace("\r", " ")
    value = value.replace("\n", " ")
    value = value.replace("\t", " ")
    value = re.sub(r"\s+", " ", value)

    return value.strip()


def safe_filename(text):
    text = re.sub(r'[\\/:*?"<>|]+', "_", text)
    text = re.sub(r"\s+", "_", text)
    return text[:100]


def http_get(url, timeout=RSS_TIMEOUT):
    response = requests.get(
        url,
        headers=HEADERS,
        timeout=timeout,
        verify=False
    )
    response.raise_for_status()
    return response.content


def get_node_text(item, name):
    node = item.find(name)

    if node is not None:
        return node.text or ""

    return ""


def get_media_from_rss(item):
    image = ""
    video = ""

    for element in item.iter():
        tag = element.tag.lower()

        if "thumbnail" in tag or "content" in tag:
            url = element.attrib.get("url", "")

            if url:
                mime = element.attrib.get("type", "").lower()

                if "video" in mime or url.lower().endswith((
                    ".mp4",
                    ".m3u8",
                    ".webm"
                )):
                    if not video:
                        video = url

                elif not image:
                    image = url

        if "enclosure" in tag:
            url = element.attrib.get("url", "")
            mime = element.attrib.get("type", "").lower()

            if url:
                if "video" in mime or url.lower().endswith((
                    ".mp4",
                    ".m3u8",
                    ".webm"
                )):
                    video = url
                elif "image" in mime:
                    image = url

    return image, video


def parse_rss(source_name, rss_url):
    try:
        data = http_get(rss_url, RSS_TIMEOUT)

        root = ET.fromstring(data)

        items = root.findall(".//item")

        result = []

        for item in items[:30]:
            title = clean_text(
                get_node_text(item, "title")
            )

            if not title:
                continue

            description = clean_text(
                get_node_text(item, "description")
            )

            link = clean_text(
                get_node_text(item, "link")
            )

            image, video = get_media_from_rss(item)

            result.append({
                "source": source_name,
                "title": title,
                "description": description,
                "link": link,
                "image": image,
                "video": video
            })

        return result

    except Exception:
        return []


def collect_news():
    all_news = []

    with ThreadPoolExecutor(max_workers=12) as executor:
        futures = []

        for source, url in RSS_SOURCES:
            futures.append(
                executor.submit(
                    parse_rss,
                    source,
                    url
                )
            )

        for future in as_completed(futures):
            try:
                data = future.result()

                if data:
                    all_news.extend(data)

            except Exception:
                pass

    unique = []
    seen = set()

    for item in all_news:
        key = re.sub(
            r"\W+",
            "",
            item["title"].lower()
        )

        if key in seen:
            continue

        seen.add(key)
        unique.append(item)

    with news_lock:
        news_list.clear()
        news_list.extend(unique)

    return unique


def get_page_data(url):
    if not url:
        return "", "", ""

    try:
        response = requests.get(
            url,
            headers=HEADERS,
            timeout=10,
            verify=False
        )

        response.raise_for_status()

        soup = BeautifulSoup(
            response.text,
            "html.parser"
        )

        image = ""
        video = ""
        description = ""

        meta_image = soup.find(
            "meta",
            attrs={
                "property": "og:image"
            }
        )

        if meta_image:
            image = meta_image.get("content", "")

        if not image:
            meta_image = soup.find(
                "meta",
                attrs={
                    "name": "twitter:image"
                }
            )

            if meta_image:
                image = meta_image.get(
                    "content",
                    ""
                )

        meta_description = soup.find(
            "meta",
            attrs={
                "property": "og:description"
            }
        )

        if meta_description:
            description = meta_description.get(
                "content",
                ""
            )

        if not description:
            meta_description = soup.find(
                "meta",
                attrs={
                    "name": "description"
                }
            )

            if meta_description:
                description = meta_description.get(
                    "content",
                    ""
                )

        for meta in soup.find_all("meta"):
            prop = (
                meta.get("property", "")
                or meta.get("name", "")
            ).lower()

            content = meta.get(
                "content",
                ""
            )

            if not content:
                continue

            if (
                "video" in prop
                and (
                    ".mp4" in content.lower()
                    or ".m3u8" in content.lower()
                    or "video" in content.lower()
                )
            ):
                video = content
                break

        if not video:
            for source in soup.find_all(
                "source"
            ):
                src = source.get("src", "")

                if not src:
                    continue

                if (
                    ".mp4" in src.lower()
                    or ".m3u8" in src.lower()
                    or "video" in source.get(
                        "type",
                        ""
                    ).lower()
                ):
                    video = src
                    break

        if not video:
            for video_tag in soup.find_all(
                "video"
            ):
                src = video_tag.get(
                    "src",
                    ""
                )

                if src:
                    video = src
                    break

        image = urllib.parse.urljoin(
            url,
            image
        )

        video = urllib.parse.urljoin(
            url,
            video
        )

        return (
            image,
            video,
            clean_text(description)
        )

    except Exception:
        return "", "", ""


def download_file(url, filename):
    if not url:
        return False

    try:
        response = requests.get(
            url,
            headers=HEADERS,
            timeout=20,
            verify=False,
            stream=True
        )

        response.raise_for_status()

        with open(
            filename,
            "wb"
        ) as file:
            for chunk in response.iter_content(
                1024 * 64
            ):
                if chunk:
                    file.write(chunk)

        if os.path.getsize(filename) < 1000:
            os.remove(filename)
            return False

        return True

    except Exception:
        try:
            if os.path.exists(filename):
                os.remove(filename)
        except Exception:
            pass

        return False


def find_media(news):
    image = news.get("image", "")
    video = news.get("video", "")
    description = news.get("description", "")

    page_image = ""
    page_video = ""
    page_description = ""

    if news.get("link"):
        (
            page_image,
            page_video,
            page_description
        ) = get_page_data(
            news["link"]
        )

    if page_image:
        image = page_image

    if page_video:
        video = page_video

    if page_description and len(
        page_description
    ) > len(description):
        description = page_description

    if video:
        video = urllib.parse.unquote(
            video
        )

        if video.lower().startswith(
            "//"
        ):
            video = "https:" + video

    if image:
        image = urllib.parse.unquote(
            image
        )

    news["image"] = image
    news["video"] = video
    news["description"] = description

    return news


def download_news_media(news):
    try:
        if news.get("video"):
            video_url = news["video"]

            if video_url.lower().startswith(
                "http"
            ):
                if download_file(
                    video_url,
                    MEDIA_FILE
                ):
                    return "video"

    except Exception:
        pass

    try:
        if news.get("image"):
            if download_file(
                news["image"],
                IMAGE_FILE
            ):
                return "image"

    except Exception:
        pass

    return None


def wrap_text(text, max_chars):
    words = text.split()

    lines = []
    current = ""

    for word in words:
        if len(current) + len(word) + 1 <= max_chars:
            if current:
                current += " "

            current += word
        else:
            if current:
                lines.append(current)

            current = word

    if current:
        lines.append(current)

    return lines


def escape_drawtext(text):
    text = clean_text(text)

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


def create_voice(news):
    title = news["title"]
    description = news.get(
        "description",
        ""
    )

    if not description:
        description = (
            "Haberin ayrıntıları "
            "ZEM TV Haber ekranlarında."
        )

    description = description[:850]

    text = (
        "ZEM TV Haber. "
        f"{title}. "
        f"{description}. "
        "Gelişmeleri aktarmaya devam edeceğiz."
    )

    async def generate():
        communicate = edge_tts.Communicate(
            text,
            "tr-TR-AhmetNeural",
            rate="-2%",
            volume="+0%"
        )

        await communicate.save(
            VOICE_FILE
        )

    try:
        asyncio.run(
            generate()
        )
        return os.path.exists(
            VOICE_FILE
        )
    except Exception:
        return False


def get_weather():
    try:
        url = (
            "https://wttr.in/"
            + urllib.parse.quote(CITY)
            + "?format=j1"
        )

        response = requests.get(
            url,
            headers=HEADERS,
            timeout=6,
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
            f"{CITY} {temp} C {condition}"
        )

    except Exception:
        return f"{CITY} hava bilgisi"


def get_ticker():
    with news_lock:
        titles = [
            item["title"]
            for item in news_list[:12]
        ]

    if not titles:
        return (
            "ZEM TV HABER • "
            "SON DAKİKA GELİŞMELERİ • "
        )

    return (
        "     •     ".join(
            titles
        )
        + "     •     ZEM TV HABER     •     "
    )


def create_placeholder():
    if os.path.exists(IMAGE_FILE):
        return True

    try:
        subprocess.run(
            [
                FFMPEG,
                "-y",
                "-f",
                "lavfi",
                "-i",
                "color=c=0x18212b:s=1280x480",
                "-frames:v",
                "1",
                IMAGE_FILE
            ],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )

        return os.path.exists(
            IMAGE_FILE
        )

    except Exception:
        return False


def download_logo():
    if os.path.exists(LOGO_FILE):
        return True

    return download_file(
        LOGO_URL,
        LOGO_FILE
    )


def make_filter(news, media_type):
    font = FONT_FILE.replace(
        "\\",
        "/"
    ).replace(
        ":",
        "\\:"
    )

    logo = os.path.abspath(
        LOGO_FILE
    ).replace(
        "\\",
        "/"
    ).replace(
        ":",
        "\\:"
    )

    title = escape_drawtext(
        news["title"]
    )

    source = escape_drawtext(
        news["source"]
    )

    description = clean_text(
        news.get(
            "description",
            ""
        )
    )

    if not description:
        description = (
            "Haberin ayrıntıları "
            "ZEM TV Haber ekranlarında."
        )

    description_lines = wrap_text(
        description,
        72
    )[:3]

    if len(title) > 90:
        title = title[:87] + "..."

    title_lines = wrap_text(
        title,
        54
    )[:2]

    weather = escape_drawtext(
        get_weather()
    )

    date_text = datetime.now().strftime(
        "%d.%m.%Y"
    )

    time_text = datetime.now().strftime(
        "%H:%M"
    )

    ticker = escape_drawtext(
        get_ticker()
    )

    filters = []

    filters.append(
        "drawbox=x=0:y=0:w=1280:h=70:"
        "color=0x08111b:t=fill"
    )

    filters.append(
        f"drawtext=fontfile='{font}':"
        f"text='ZEM TV HABER':"
        f"fontcolor=white:"
        f"fontsize=30:"
        f"x=25:y=18"
    )

    filters.append(
        f"drawtext=fontfile='{font}':"
        f"text='{date_text}  {time_text}':"
        f"fontcolor=white:"
        f"fontsize=21:"
        f"x=330:y=22"
    )

    filters.append(
        f"drawtext=fontfile='{font}':"
        f"text='{weather}':"
        f"fontcolor=white:"
        f"fontsize=21:"
        f"x=540:y=22"
    )

    filters.append(
        "drawbox=x=0:y=70:w=1280:h=470:"
        "color=0x101923@0.35:t=fill"
    )

    y = 555

    for line in title_lines:
        filters.append(
            f"drawtext=fontfile='{font}':"
            f"text='{escape_drawtext(line)}':"
            f"fontcolor=white:"
            f"fontsize=29:"
            f"x=25:y={y}"
        )

        y += 34

    y += 6

    for line in description_lines:
        filters.append(
            f"drawtext=fontfile='{font}':"
            f"text='{escape_drawtext(line)}':"
            f"fontcolor=0xe0e0e0:"
            f"fontsize=19:"
            f"x=25:y={y}"
        )

        y += 24

    filters.append(
        f"drawtext=fontfile='{font}':"
        f"text='KAYNAK  {source}':"
        f"fontcolor=0xb8c4cf:"
        f"fontsize=17:"
        f"x=25:y=655"
    )

    filters.append(
        "drawbox=x=0:y=682:w=1280:h=38:"
        "color=0x050505:t=fill"
    )

    filters.append(
        f"drawtext=fontfile='{font}':"
        f"text='{ticker}':"
        f"fontcolor=white:"
        f"fontsize=18:"
        f"x=w-mod(t*110\\,tw+1280):"
        f"y=692"
    )

    filters.append(
        f"movie='{logo}'"
        "[logo];"
        "[logo]scale=150:-1[logo2];"
        "[base][logo2]overlay=W-w-20:12[vout]"
    )

    base = ",".join(
        filters[:-1]
    )

    final_logo = filters[-1]

    final_filter = (
        f"[0:v]{base}[base];"
        f"{final_logo}"
    )

    return final_filter


def run_ffmpeg_image(news):
    if not os.path.exists(IMAGE_FILE):
        create_placeholder()

    filter_complex = make_filter(
        news,
        "image"
    )

    command = [
        FFMPEG,
        "-hide_banner",
        "-loglevel",
        "warning",
        "-y",

        "-loop",
        "1",
        "-i",
        IMAGE_FILE,

        "-i",
        VOICE_FILE,

        "-filter_complex",
        filter_complex,

        "-map",
        "[vout]",

        "-map",
        "1:a",

        "-t",
        str(NEWS_DURATION),

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

        "-b:v",
        "3000k",

        "-maxrate",
        "3500k",

        "-bufsize",
        "6000k",

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

        RTMP_URL
    ]

    return subprocess.run(
        command
    ).returncode


def run_ffmpeg_video(news):
    filter_complex = make_filter(
        news,
        "video"
    )

    command = [
        FFMPEG,
        "-hide_banner",
        "-loglevel",
        "warning",
        "-y",

        "-stream_loop",
        "-1",

        "-i",
        MEDIA_FILE,

        "-i",
        VOICE_FILE,

        "-filter_complex",
        filter_complex,

        "-map",
        "[vout]",

        "-map",
        "1:a",

        "-t",
        str(NEWS_DURATION),

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

        "-shortest",

        "-f",
        "flv",

        RTMP_URL
    ]

    return subprocess.run(
        command
    ).returncode


def prepare_news(news):
    news = find_media(
        news
    )

    media_type = download_news_media(
        news
    )

    return media_type


def choose_news():
    with news_lock:
        available = list(
            news_list
        )

    if not available:
        return None

    fresh = []

    for item in available:
        key = item["title"].strip().lower()

        if key not in played_titles:
            fresh.append(item)

    if not fresh:
        played_titles.clear()
        fresh = available

    item = fresh[0]

    played_titles.add(
        item["title"].strip().lower()
    )

    return item


def cleanup_files():
    for filename in [
        VOICE_FILE,
        MEDIA_FILE,
        IMAGE_FILE
    ]:
        try:
            if os.path.exists(filename):
                os.remove(filename)
        except Exception:
            pass


def rss_updater():
    global running

    while running:
        try:
            collect_news()
        except Exception:
            pass

        for _ in range(
            RSS_REFRESH
        ):
            if not running:
                return

            time.sleep(1)


def main():
    global running

    os.system("title ZEM TV HABER")

    if not os.path.exists(
        FFMPEG
    ):
        print(
            "FFmpeg bulunamadı: "
            + FFMPEG
        )
        return

    if not os.path.exists(
        FONT_FILE
    ):
        print(
            "Arial bulunamadı: "
            + FONT_FILE
        )
        return

    if not download_logo():
        print(
            "Logo indirilemedi."
        )
        return

    print(
        "ZEM TV HABER başlatılıyor..."
    )

    collect_news()

    if not news_list:
        print(
            "RSS kaynaklarından haber alınamadı."
        )

    updater = threading.Thread(
        target=rss_updater,
        daemon=True
    )

    updater.start()

    while running:
        try:
            news = choose_news()

            if not news:
                time.sleep(5)
                continue

            print(
                "HABER: "
                + news["title"]
            )

            news = find_media(
                news
            )

            media_type = prepare_news(
                news
            )

            if not create_voice(
                news
            ):
                time.sleep(3)
                continue

            if media_type == "video":
                result = run_ffmpeg_video(
                    news
                )

                if result != 0:
                    cleanup_files()

                    if os.path.exists(
                        IMAGE_FILE
                    ):
                        pass
                    else:
                        create_placeholder()

                    result = run_ffmpeg_image(
                        news
                    )

            elif media_type == "image":
                result = run_ffmpeg_image(
                    news
                )

            else:
                create_placeholder()

                result = run_ffmpeg_image(
                    news
                )

            cleanup_files()

            time.sleep(2)

        except KeyboardInterrupt:
            running = False
            break

        except Exception as e:
            print(
                "HATA:",
                e
            )
            time.sleep(5)

    cleanup_files()


if __name__ == "__main__":
    main()
```0
