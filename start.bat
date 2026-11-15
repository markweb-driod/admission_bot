@echo off
cd /d "%~dp0"
echo Admission chatbot at http://127.0.0.1:8400/
start "" http://127.0.0.1:8400/
php -S 127.0.0.1:8400 -t .
