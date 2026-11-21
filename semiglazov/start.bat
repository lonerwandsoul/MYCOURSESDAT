@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion
title Favorit Divan - lokalnyy server

rem ===========================================================================
rem  Запуск сайта «Фаворит Диван» без OpenServer.
rem  Скрипт сам находит PHP (сначала в папке проекта, затем в модулях
rem  OpenServer Panel 6/5, затем в PATH) и поднимает встроенный сервер.
rem
rem  База данных не нужна: при первом открытии создаётся резервная SQLite
rem  в папке data. Если MySQL/OpenServer запущен — сайт подключится к MySQL.
rem ===========================================================================

set "ROOT=%~dp0"
set "PORT=8082"

rem --- 1. Поиск PHP -----------------------------------------------------------
set "PHP="
if exist "%ROOT%php\php.exe" set "PHP=%ROOT%php\php.exe"

if not defined PHP (
    for %%R in (
        "%ProgramFiles%\OSPanel"
        "%ProgramFiles(x86)%\OSPanel"
        "C:\OSPanel"
        "D:\OSPanel"
        "E:\OSPanel"
        "%LOCALAPPDATA%\OSPanel"
    ) do (
        if not defined PHP (
            if exist "%%~R\modules\php" (
                if exist "%%~R\modules\php\PHP-8.4\php.exe" set "PHP=%%~R\modules\php\PHP-8.4\php.exe"
            )
        )
        if not defined PHP (
            if exist "%%~R\modules\php" (
                for /d %%D in ("%%~R\modules\php\PHP-*") do (
                    if exist "%%D\php.exe" if not exist "%%D\php-cgi.exe" set "PHP=%%D\php.exe"
                )
            )
        )
    )
)

if not defined PHP (
    where php >nul 2>&1
    if not errorlevel 1 set "PHP=php"
)

if not defined PHP (
    echo.
    echo   PHP не найден.
    echo   Варианты:
    echo     1^) Запустить сайт через OpenServer Panel 6 - см. README-OPENSERVER.md
    echo     2^) Установить PHP 8.2+ и добавить его в PATH
    echo     3^) Положить portable-PHP в папку php рядом с сайтом
    echo.
    pause
    exit /b 1
)

echo   PHP: %PHP%

rem --- 2. Каталог данных и сессий --------------------------------------------
if not exist "%ROOT%data" mkdir "%ROOT%data" >nul 2>&1
if not exist "%ROOT%data\sessions" mkdir "%ROOT%data\sessions" >nul 2>&1
set "FAVORIT_DATA_DIR=%ROOT%data"

rem --- 3. Проверка драйверов баз данных --------------------------------------
set "PHP_ARGS="
for /f "delims=" %%V in ('""%PHP%" -r "echo implode(',', PDO::getAvailableDrivers());" 2^>nul"') do set "DRIVERS=%%V"
echo   Драйверы PDO: !DRIVERS!

echo !DRIVERS! | findstr /i "sqlite" >nul
if errorlevel 1 (
    echo !DRIVERS! | findstr /i "mysql" >nul
    if errorlevel 1 (
        rem Пробуем подключить расширения вручную (частая причина «could not find driver»)
        for %%P in ("%PHP%") do set "PHPDIR=%%~dpP"
        if exist "!PHPDIR!ext\php_pdo_sqlite.dll" (
            set "PHP_ARGS=-d extension_dir=!PHPDIR!ext -d extension=pdo_sqlite -d extension=sqlite3"
            echo   В php.ini не включены расширения - подключаю pdo_sqlite вручную.
        ) else (
            echo.
            echo   ВНИМАНИЕ: в PHP не включены pdo_sqlite и pdo_mysql.
            echo   Сайт откроется, но данные сохранять не сможет.
            echo   Включите расширения в OpenServer: Модули -^> PHP-8.4 -^> pdo_sqlite, pdo_mysql.
            echo   Подробности: http://127.0.0.1:%PORT%/check.php
            echo.
        )
    )
)

rem --- 4. Запуск --------------------------------------------------------------
echo ============================================================
echo   Фаворит Диван - аналитика эффективности
echo   Сайт:        http://127.0.0.1:%PORT%/analytics.php
echo   Проверка:    http://127.0.0.1:%PORT%/check.php
echo   Установка БД http://127.0.0.1:%PORT%/install.php
echo   Вход:        manager / manager123
echo   Данные:      %ROOT%data
echo   Закрыть это окно = остановить сайт.
echo ============================================================

start "" cmd /c "timeout /t 2 >nul & start http://127.0.0.1:%PORT%/analytics.php"

"%PHP%" %PHP_ARGS% -d session.save_path="%ROOT%data\sessions" -S 127.0.0.1:%PORT% -t "%ROOT%"

echo.
echo Сервер остановлен.
pause
