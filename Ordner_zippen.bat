@echo off
setlocal

rem Ordner ermitteln, in dem diese BAT-Datei liegt
for %%I in ("%~dp0.") do set "SRC=%%~fI"

rem Ordnername und Elternordner ermitteln
for %%I in ("%SRC%") do (
    set "PARENT=%%~dpI"
    set "NAME=%%~nxI"
)

rem ZIP-Datei eine Ebene hoeher speichern
set "ZIP=%PARENT%%NAME%.zip"

echo.
echo Quelle: "%SRC%"
echo Ziel:   "%ZIP%"
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$src=$env:SRC; $zip=$env:ZIP; " ^
  "if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }; " ^
  "Compress-Archive -LiteralPath $src -DestinationPath $zip -Force"

if errorlevel 1 (
    echo.
    echo FEHLER: ZIP konnte nicht erstellt werden.
    pause
    exit /b 1
)

echo.
echo Fertig: "%ZIP%"
pause
