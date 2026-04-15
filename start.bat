@echo off
set PROJECT=d:\02-Coding\SEO Validation App\seo-workbook-verifier

echo Starting Queue Worker...
start "Queue Worker" /MIN cmd /k "cd /d "%PROJECT%" && php artisan queue:work --timeout=2700 --tries=1 --sleep=2"

timeout /t 2 /nobreak >nul

echo Starting Web Server...
cd /d "%PROJECT%"
php artisan serve
