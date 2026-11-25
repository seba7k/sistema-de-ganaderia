@echo off
title Ganaderia - servidor local (no cierres esta ventana)
cd /d "%~dp0"
echo Iniciando Ganaderia en http://localhost:8000 ...
start "" http://localhost:8000/
"%USERPROFILE%\Downloads\php\php.exe" -c "%USERPROFILE%\Downloads\php\php.ini" -S localhost:8000 index.php
echo.
echo El servidor se detuvo. Si ves un error arriba, sacale una foto y enviasela a Claude.
pause
