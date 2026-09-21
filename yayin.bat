import os
import sys
import json
import urllib.request
import subprocess

API_URL = "https://api.github.com/repos/obsproject/obs-studio/releases/latest"
DOWNLOAD_DIR = os.path.join(os.environ.get("TEMP", "."), "OBS_Installer.exe")


def get_latest_obs():
    print("OBS'nin güncel sürümü kontrol ediliyor...")

    req = urllib.request.Request(
        API_URL,
        headers={"User-Agent": "Python OBS Installer"}
    )

    with urllib.request.urlopen(req) as response:
        data = json.loads(response.read().decode("utf-8"))

    for asset in data.get("assets", []):
        name = asset.get("name", "")
        url = asset.get("browser_download_url", "")

        if name.lower().endswith(".exe") and "windows" in name.lower():
            return name, url

    # Bazı sürümlerde dosya isminde Windows yazmayabilir
    for asset in data.get("assets", []):
        name = asset.get("name", "")
        url = asset.get("browser_download_url", "")

        if name.lower().endswith(".exe"):
            return name, url

    return None, None


def main():
    print("=" * 55)
    print("        OBS STUDIO OTOMATİK KURULUM")
    print("=" * 55)

    try:
        filename, download_url = get_latest_obs()

        if not download_url:
            print("OBS kurulum dosyası bulunamadı.")
            input("Çıkmak için Enter'a basın...")
            return

        print(f"\nBulunan dosya: {filename}")
        print("İndiriliyor...")
        print(download_url)

        urllib.request.urlretrieve(
            download_url,
            DOWNLOAD_DIR,
            reporthook=download_progress
        )

        print("\n\nİndirme tamamlandı.")
        print("OBS kurulumu başlatılıyor...")

        subprocess.Popen(
            [DOWNLOAD_DIR],
            shell=True
        )

        print("OBS kurulum programı açıldı.")

    except Exception as e:
        print("\nHATA:")
        print(str(e))

    input("\nÇıkmak için Enter'a basın...")


def download_progress(block_num, block_size, total_size):
    if total_size <= 0:
        return

    downloaded = block_num * block_size
    percent = min(downloaded * 100 / total_size, 100)

    bar_length = 40
    filled = int(bar_length * percent / 100)

    bar = "#" * filled + "-" * (bar_length - filled)

    print(
        f"\r[{bar}] %{percent:5.1f}",
        end=""
    )


if __name__ == "__main__":
    main()
