@echo off
title SSH101 Yayin
cd /d "%~dp0"

echo ========================================
echo  SSH101 Yayin Baslatiliyor
echo ========================================
echo.

REM FFmpeg kontrolu
where ffmpeg >nul 2>&1
if errorlevel 1 (
    echo HATA: FFmpeg kurulu degil veya PATH'te yok!
    echo.
    echo Cozum:
    echo   1^) winget install Gyan.FFmpeg
    echo   2^) veya https://www.gyan.dev/ffmpeg/builds/ indir
    echo   3^) C:\ffmpeg\bin klasorunu PATH'e ekle
    echo.
    pause
    exit /b 1
)

echo FFmpeg bulundu.
echo.

REM Python kontrolu
where python >nul 2>&1
if errorlevel 1 (
    echo HATA: Python kurulu degil!
    pause
    exit /b 1
)

echo Python bulundu.
echo.
echo Yayin baslatiliyor...
echo.

python yayin.py

echo.
echo Yayin kapatildi.
pause
