@echo off
rem Run after every code update (git pull) and after ANY change to .env.
rem It clears Laravel's caches, then rebuilds config/route/event/view caches.
rem IMPORTANT: after config:cache the app no longer reads .env at all, so a change
rem to .env has no effect until you run this file again.
rem Undo (back to no-cache mode): php artisan optimize:clear
rem No need to restart ApacheDawak: OPcache re-checks changed files every 2 seconds.
rem (ASCII only on purpose: cmd.exe misparses UTF-8 Arabic text in .bat files.)
setlocal
cd /d "%~dp0..\.."
if not exist artisan (
    echo [deploy] artisan not found in %CD%
    exit /b 1
)

echo [deploy] %CD%
php artisan optimize:clear || goto :fail
php artisan config:cache || goto :fail
php artisan route:cache
if errorlevel 1 (
    echo [deploy] WARNING: route:cache failed - continuing without route cache.
    php artisan route:clear
)
php artisan event:cache || goto :fail
php artisan view:cache || goto :fail

echo [deploy] OK. Remember: edit .env  ==^>  run deploy.bat again.
echo [deploy] NOTE: the queue worker (php artisan queue:work) keeps running OLD code until restarted.
echo          If jobs/imports code changed: stop the worker, then start it again from this folder:
echo          php artisan queue:work --timeout=600
exit /b 0

:fail
echo [deploy] FAILED (exit %errorlevel%). To return to no-cache mode run: php artisan optimize:clear
exit /b 1
