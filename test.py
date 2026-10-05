import os
import re
import sys
import subprocess
import shutil

# --- OTOMATİK KURULUM ---
def pip_kur(paket):
    subprocess.run([sys.executable, "-m", "pip", "install", "--upgrade", paket], check=False)

def sistem_paketi_kur(paket):
    """apt-get ile sistem paketi kurar (root gerekir)."""
    try:
        subprocess.run(["apt-get", "update", "-qq"], check=False)
        subprocess.run(["apt-get", "install", "-y", "-qq", paket], check=False)
        return True
    except Exception as e:
        print(f"⚠️ Sistem paketi kurulamadı ({paket}): {e}")
        return False

def gerekli_paketleri_kontrol_et():
    print("🔧 Gerekli paketler kontrol ediliyor...")

    # 1) Python kütüphaneleri
    python_paketleri = {
        "requests": "requests",
        "yt_dlp": "yt-dlp",
    }
    for modul, pip_adi in python_paketleri.items():
        try:
            __import__(modul)
            print(f"✅ {pip_adi} zaten kurulu.")
        except ImportError:
            print(f"📦 {pip_adi} kuruluyor...")
            pip_kur(pip_adi)

    # 2) FFmpeg (sistem paketi)
    if shutil.which("ffmpeg") is None:
        print("📦 FFmpeg kuruluyor (bu biraz sürebilir)...")
        sistem_paketi_kur("ffmpeg")
        if shutil.which("ffmpeg") is None:
            print("❌ FFmpeg kurulamadı! Lütfen manuel kurun: sudo apt-get install ffmpeg")
            sys.exit(1)
        else:
            print("✅ FFmpeg kuruldu.")
    else:
        print("✅ FFmpeg zaten kurulu.")

    # 3) Git (opsiyonel, sunucuda gerekmiyor ama zarar vermez)
    if shutil.which("git") is None:
        print("📦 Git kuruluyor...")
        sistem_paketi_kur("git")

# Bu fonksiyonu import'lardan ÖNCE çalıştır
gerekli_paketleri_kontrol_et()

# --- Şimdi normal import'lar ---
import uuid
import time
import json
import requests
import yt_dlp


# --- AYARLAR ---
HAFIZA_DOSYASI = "hafiza.json"
PLAYLIST_DOSYASI = "playlist.txt"
KAYNAK_URL = "https://raw.githubusercontent.com/kimbumuratyavuz/capcanli/refs/heads/main/sinema.m3u"

# Sunucu ayarları (video yükleme)
SUNUCU_BASE_URL = "http://45.158.14.16/film/"
SUNUCU_USER = ""
SUNUCU_PASS = ""

# Logo ayarları
LOGO_URL = "https://i.hizliresim.com/ko9s4ezf.png"
LOGO_LOCAL = "logo.png"
LOGO_GENISLIK = 80
LOGO_X = 10
LOGO_Y = 10

# Maksimum çalışma süresi: 5 saat
MAX_SURE_SANIYE = 5 * 60 * 60

# Bekleme süresi (her film arasında)
BEKLEME_SANIYE = 60


# --- YARDIMCI FONKSİYONLAR ---
def hafizayi_yukle():
    if os.path.exists(HAFIZA_DOSYASI):
        with open(HAFIZA_DOSYASI, "r", encoding="utf-8") as f:
            return json.load(f)
    return {}


def hafizayi_kaydet(veri):
    with open(HAFIZA_DOSYASI, "w", encoding="utf-8") as f:
        json.dump(veri, f, indent=4, ensure_ascii=False)


def listeyi_cek_ve_ayikla(url):
    r = requests.get(url, timeout=60)
    lines = r.text.splitlines()
    filmler = []

    for i in range(len(lines)):
        if lines[i].startswith("#EXTINF"):
            extinf = lines[i]
            vid_url = lines[i + 1] if (i + 1 < len(lines) and lines[i + 1].startswith("http")) else None

            if vid_url:
                isim_kismi = extinf.split(",")[-1]
                saf_isim = isim_kismi.split("|")[0].strip()
                filmler.append({"isim": saf_isim, "url": vid_url})

    return filmler


def logoyu_indir():
    if os.path.exists(LOGO_LOCAL):
        return True
    try:
        print("🖼️ Logo indiriliyor...")
        r = requests.get(LOGO_URL, timeout=30)
        r.raise_for_status()
        with open(LOGO_LOCAL, "wb") as f:
            f.write(r.content)
        print("✅ Logo indirildi.")
        return True
    except Exception as e:
        print(f"⚠️ Logo indirilemedi: {e}")
        return False


def logoyu_bas(girdi_yolu, cikti_yolu):
    print(f"🎨 Logo basılıyor: {girdi_yolu} → {cikti_yolu}")

    logo_kaynak = LOGO_LOCAL if os.path.exists(LOGO_LOCAL) else LOGO_URL

    komut = [
        "ffmpeg", "-y",
        "-i", girdi_yolu,
        "-i", logo_kaynak,
        "-filter_complex",
        f"[1:v]scale={LOGO_GENISLIK}:-1[logo];[0:v][logo]overlay={LOGO_X}:{LOGO_Y}[outv]",
        "-map", "[outv]",
        "-map", "0:a?",
        "-c:v", "libx264",
        "-preset", "fast",
        "-crf", "23",
        "-c:a", "copy",
        "-movflags", "+faststart",
        cikti_yolu
    ]

    try:
        subprocess.run(komut, check=True, capture_output=True)
        print("✅ Logo başarıyla basıldı.")
        return True
    except subprocess.CalledProcessError as e:
        print(f"❌ FFmpeg hatası: {e.stderr.decode(errors='ignore')[:500]}")
        return False


def videoyu_indir(url, dosya_adi):
    ad_kok = dosya_adi.replace(".mp4", "")
    ham_dosya_sablonu = ad_kok + "_ham.%(ext)s"

    ydl_opts = {
        'format': 'best',
        'outtmpl': ham_dosya_sablonu,
        'quiet': False,
    }

    try:
        with yt_dlp.YoutubeDL(ydl_opts) as ydl:
            ydl.download([url])

        klasor = os.path.dirname(os.path.abspath(dosya_adi)) or "."
        ham_dosyalar = [f for f in os.listdir(klasor) if f.startswith(ad_kok + "_ham")]

        if not ham_dosyalar:
            print("❌ Ham dosya bulunamadı.")
            return False

        ham_yol = os.path.join(klasor, ham_dosyalar[0])
        basarili = logoyu_bas(ham_yol, dosya_adi)

        if os.path.exists(ham_yol):
            os.remove(ham_yol)

        return basarili

    except Exception as e:
        print(f"❌ İndirme hatası: {e}")
        return False


def sunucuya_yukle(dosya_yolu, uzak_dosya_adi):
    hedef_url = SUNUCU_BASE_URL.rstrip("/") + "/" + uzak_dosya_adi
    print(f"☁️ Sunucuya yükleniyor: {hedef_url}")

    try:
        with open(dosya_yolu, "rb") as f:
            r = requests.put(
                hedef_url,
                data=f,
                auth=(SUNUCU_USER, SUNUCU_PASS) if SUNUCU_USER else None,
                timeout=1800
            )

        if r.status_code in (200, 201, 204):
            print(f"✅ Yükleme başarılı: {hedef_url}")
            return hedef_url
        else:
            print(f"❌ Yükleme başarısız! HTTP {r.status_code}: {r.text[:200]}")
            return None

    except Exception as e:
        print(f"❌ Yükleme hatası: {e}")
        return None


def playlist_guncelle(film_ismi, film_url):
    satir = f"{film_url}| {film_ismi}\n"

    if os.path.exists(PLAYLIST_DOSYASI):
        with open(PLAYLIST_DOSYASI, "r", encoding="utf-8") as f:
            icerik = f.read()
        if film_url in icerik:
            print("ℹ️ Bu film zaten playlist'te mevcut, atlanıyor.")
            return

    with open(PLAYLIST_DOSYASI, "a", encoding="utf-8") as f:
        f.write(satir)

    print(f"📝 Playlist güncellendi: {satir.strip()}")


# --- ANA PROGRAM ---
if __name__ == "__main__":
    baslangic_zamani = time.time()

    logoyu_indir()
    hafiza = hafizayi_yukle()

    print("📁 LİSTE KONTROL EDİLİYOR...")
    filmler = listeyi_cek_ve_ayikla(KAYNAK_URL)

    for film in filmler:
        gecen_sure = time.time() - baslangic_zamani
        if gecen_sure > MAX_SURE_SANIYE:
            print("🛑 5 saatlik çalışma süresi doldu. Bot dinlenmeye geçiyor...")
            break

        if film['url'] in hafiza:
            continue

        print(f"\n🎬 YENİ İÇERİK İŞLENİYOR: {film['isim']}")

        guvenli_isim = re.sub(r'[^a-zA-Z0-9]', '', film['isim']) or str(uuid.uuid4())[:8]
        dosya_adi = guvenli_isim + ".mp4"

        if videoyu_indir(film['url'], dosya_adi):
            yuklenen_url = sunucuya_yukle(dosya_adi, dosya_adi)

            if yuklenen_url:
                hafiza[film['url']] = yuklenen_url
                hafizayi_kaydet(hafiza)
                playlist_guncelle(film['isim'], yuklenen_url)
            else:
                print("🔄 Yükleme başarısız, hafızaya eklenmedi. Sonraki döngüde tekrar denenecek.")

            if os.path.exists(dosya_adi):
                os.remove(dosya_adi)

            print(f"⏳ Sıradakine geçmeden önce {BEKLEME_SANIYE} saniye bekleniyor...")
            time.sleep(BEKLEME_SANIYE)
        else:
            print(f"⚠️ {film['isim']} indirilemedi, atlanıyor...")

    print("✅ Otomasyon döngüsü tamamlandı.")
