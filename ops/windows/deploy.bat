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

rem Queue worker: must come AFTER optimize:clear (which wipes the cache, including the restart signal).
rem The worker finishes its current job, exits, and the DawakQueueWorker supervisor starts it again
rem within ~5 seconds with the new code and config. Nothing is killed.
php artisan queue:restart
if errorlevel 1 echo [deploy] WARNING: queue:restart failed - restart the worker: schtasks /End /TN DawakQueueWorker then schtasks /Run /TN DawakQueueWorker

echo [deploy] OK. Remember: edit .env  ==^>  run deploy.bat again.
echo [deploy] Queue worker restarts by itself (log: C:\dawak-queue\worker.log).
exit /b 0

:fail
echo [deploy] FAILED (exit %errorlevel%). To return to no-cache mode run: php artisan optimize:clear
exit /b 1
