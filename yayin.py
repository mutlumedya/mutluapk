#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import subprocess
import sys
import time
import os
import requests
import signal
import logging
from datetime import datetime
from pathlib import Path

# ===================== LOG AYARLARI =====================
LOG_DIR = os.path.join(os.getcwd(), 'logs')
os.makedirs(LOG_DIR, exist_ok=True)

log_file = os.path.join(LOG_DIR, f'yayin_{datetime.now().strftime("%Y%m%d")}.log')

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(log_file, encoding='utf-8'),
        logging.StreamHandler()
    ]
)

# ===================== RENKLİ ÇIKTI =====================
class Colors:
    RED = '\033[0;31m'
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    BLUE = '\033[0;34m'
    NC = '\033[0m'

def print_colored(color, text):
    print(f"{color}{text}{Colors.NC}")

# ===================== CATCAST AYARLARI =====================
RTMP_URL = "rtmp://s.catcast.tv/live"
STREAM_KEY = "mutluhub1?key=27610_50990_253571354411efe7"
rtmp_server = f"{RTMP_URL}/{STREAM_KEY}"

VIDEO_URL = "https://cdn.codenet.lol/streamgo/stremgo123/4865.m3u8"
LOGO_URL = "https://raw.githubusercontent.com/mutlumedya/yayin/refs/heads/main/logo1.png"

# ===================== KONFIGÜRASYON =====================
CONFIG = {
    'restart_delay': 3,
    'max_restarts': 999999,
    'log_interval': 30
}

# ===================== PAKET KURULUMU =====================
def is_termux():
    return 'TERMUX_VERSION' in os.environ or '/data/data/com.termux' in os.environ

def check_and_install_packages():
    """Gerekli paketleri kontrol et ve kur"""
    logging.info("📦 Paketler kontrol ediliyor...")
    
    try:
        import requests
        logging.info("✅ requests zaten yüklü")
    except ImportError:
        logging.info("📥 requests yükleniyor...")
        subprocess.run([sys.executable, "-m", "pip", "install", "requests"], check=True)
    
    # FFmpeg kontrolü
    try:
        result = subprocess.run(['ffmpeg', '-version'], capture_output=True, text=True)
        if result.returncode == 0:
            version = result.stdout.split()[2] if result.stdout else "bilinmiyor"
            logging.info(f"✅ FFmpeg mevcut: {version}")
        else:
            logging.warning("⚠️ FFmpeg bulunamadı!")
            if is_termux():
                subprocess.run(['pkg', 'install', '-y', 'ffmpeg'], check=True)
    except:
        logging.warning("⚠️ FFmpeg bulunamadı!")
        if is_termux():
            subprocess.run(['pkg', 'install', '-y', 'ffmpeg'], check=True)

# ===================== LOGO İNDİRME =====================
def download_logo():
    """Logo dosyasını indir"""
    try:
        if LOGO_URL.startswith('http'):
            response = requests.get(LOGO_URL, timeout=30)
            response.raise_for_status()
            with open('logo1.png', 'wb') as f:
                f.write(response.content)
            logging.info("✅ Logo indirildi")
            return True
    except Exception as e:
        logging.warning(f"⚠️ Logo indirme hatası: {e}")
    return False

# ===================== YAYIN BAŞLAT =====================
def start_stream():
    """Yayını başlat"""
    logging.info("=" * 50)
    logging.info("🎬 Video: " + VIDEO_URL)
    logging.info("🔑 Stream Key: " + STREAM_KEY)
    logging.info("=" * 50)
    
    # Logoyu indir
    logo_exists = download_logo()
    
    # FFmpeg komutu - DOĞRU SİNTAX
    # ÖNCE linki test et
    logging.info("🔍 Link test ediliyor...")
    test_cmd = ['ffmpeg', '-i', VIDEO_URL, '-t', '5', '-f', 'null', '-']
    try:
        test_result = subprocess.run(test_cmd, capture_output=True, text=True, timeout=10)
        if test_result.returncode != 0:
            logging.error(f"❌ Link çalışmıyor! Hata: {test_result.stderr[:200]}")
            return False
        else:
            logging.info("✅ Link çalışıyor!")
    except Exception as e:
        logging.error(f"❌ Link test hatası: {e}")
        return False
    
    # FFmpeg komutunu oluştur
    if logo_exists and os.path.exists('logo1.png'):
        command = [
            'ffmpeg',
            '-re',
            '-stream_loop', '-1',
            '-i', VIDEO_URL,
            '-i', 'logo1.png',
            '-filter_complex',
            '[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,fps=25[v0];'
            '[1:v]scale=230:90[logo];'
            '[v0][logo]overlay=W-w-10:10[v1];'
            '[v1]drawtext=text=t.me/digitaltivi:fontcolor=white:fontsize=24:box=1:boxcolor=black@0.6:boxborderw=5:x=(w-text_w)/2:y=h-text_h-20[v]',
            '-map', '[v]',
            '-map', '0:a?',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-pix_fmt', 'yuv420p',
            '-b:v', '4000k',
            '-maxrate', '4000k',
            '-bufsize', '8000k',
            '-g', '50',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar', '44100',
            '-f', 'flv',
            rtmp_server
        ]
    else:
        command = [
            'ffmpeg',
            '-re',
            '-stream_loop', '-1',
            '-i', VIDEO_URL,
            '-filter_complex',
            '[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,fps=25[v0];'
            '[v0]drawtext=text=t.me/digitaltivi:fontcolor=white:fontsize=24:box=1:boxcolor=black@0.6:boxborderw=5:x=(w-text_w)/2:y=h-text_h-20[v]',
            '-map', '[v]',
            '-map', '0:a?',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-pix_fmt', 'yuv420p',
            '-b:v', '4000k',
            '-maxrate', '4000k',
            '-bufsize', '8000k',
            '-g', '50',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar', '44100',
            '-f', 'flv',
            rtmp_server
        ]
    
    logging.info("🎥 Yayın başlatılıyor...")
    logging.info("🖼️ Logo: Sağ üst (230x90)")
    logging.info("📝 Alt yazı: t.me/digitaltivi")
    logging.info("⏸️ Durdurmak için: Ctrl + C\n")
    
    restart_count = 0
    
    while True:
        try:
            # FFmpeg'i başlat
            process = subprocess.Popen(
                command,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                bufsize=1
            )
            
            last_log_time = time.time()
            
            # Yayını izle
            while True:
                # Process durumu kontrol et
                if process.poll() is not None:
                    logging.warning(f"⚠️ Yayın durdu (kod: {process.returncode})")
                    restart_count += 1
                    logging.info(f"🔄 Yeniden başlatılıyor ({restart_count}. kez)...")
                    time.sleep(CONFIG['restart_delay'])
                    break
                
                # FFmpeg çıktısını oku
                if process.stdout:
                    line = process.stdout.readline()
                    if line:
                        line = line.strip()
                        if "error" in line.lower() or "failed" in line.lower():
                            logging.error(f"⚠️ {line}")
                        elif "frame=" in line.lower() and "fps" in line.lower():
                            current_time = time.time()
                            if current_time - last_log_time >= CONFIG['log_interval']:
                                logging.info(f"📊 {line}")
                                last_log_time = current_time
                        elif "speed" in line.lower() and "x" in line:
                            logging.info(f"📊 {line}")
                
                time.sleep(0.5)
                
        except KeyboardInterrupt:
            logging.info("\n\n⛔ Yayın durduruluyor...")
            if 'process' in locals():
                process.terminate()
                process.wait()
            logging.info("✅ Yayın sonlandırıldı.")
            return False
        except Exception as e:
            logging.error(f"❌ Hata: {e}")
            time.sleep(CONFIG['restart_delay'])
            continue

# ===================== ANA PROGRAM =====================
def main():
    """Ana program"""
    print_colored(Colors.BLUE, "=" * 50)
    print_colored(Colors.GREEN, "  Catcast.tv Yayın Sistemi")
    print_colored(Colors.BLUE, "=" * 50)
    
    logging.info("=" * 50)
    logging.info("  Catcast.tv Yayın Sistemi Başlatıldı")
    logging.info("=" * 50)
    
    check_and_install_packages()
    
    # Sonsuz döngü
    while True:
        try:
            success = start_stream()
            if not success:
                logging.error("❌ Yayın başlatılamadı! 5 saniye sonra yeniden deneniyor...")
                time.sleep(5)
        except Exception as e:
            logging.error(f"❌ Kritik hata: {e}")
            time.sleep(5)

if __name__ == "__main__":
    main()
