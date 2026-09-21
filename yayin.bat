import os
import urllib.request
import subprocess
import platform

OBS_URL = "https://github.com/obsproject/obs-studio/releases/download/27.2.4/OBS-Studio-27.2.4-Full-Installer-x64.exe"

SAVE_PATH = os.path.join(
    os.environ.get("TEMP", "C:\\Temp"),
    "OBS-Studio-27.2.4-Full-Installer-x64.exe"
)


def progress(block_num, block_size, total_size):
    if total_size <= 0:
        return

    downloaded = block_num * block_size
    percent = min(downloaded * 100 / total_size, 100)

    bar_size = 40
    filled = int(bar_size * percent / 100)

    bar = "#" * filled + "-" * (bar_size - filled)

    print(
        f"\r[{bar}] %{percent:5.1f}",
        end=""
    )


print("=" * 60)
print("       OBS STUDIO 27.2.4 KURULUM")
print("=" * 60)

print()
print("Windows:")
print(platform.platform())

print()
print("OBS 27.2.4 indiriliyor...")
print()

try:

    os.makedirs(os.path.dirname(SAVE_PATH), exist_ok=True)

    urllib.request.urlretrieve(
        OBS_URL,
        SAVE_PATH,
        reporthook=progress
    )

    print("\n")
    print("İndirme tamamlandı.")
    print("Kurulum başlatılıyor...")
    print()

    subprocess.Popen(
        [SAVE_PATH],
        shell=True
    )

    print("OBS kurulum penceresi açıldı.")

except Exception as e:

    print()
    print("HATA OLUŞTU:")
    print(str(e))

input("\nÇıkmak için Enter'a basın...")
