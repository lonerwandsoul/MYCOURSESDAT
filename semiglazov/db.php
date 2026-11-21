<?php
/**
 * Подключение к базе данных. Работает в двух режимах:
 *
 *   1. MySQL / MariaDB (OpenServer Panel 5 и 6). Сервер и порт определяются
 *      автоматически: config.local.php → переменные окружения → имена модулей
 *      из файла hosts (MySQL-8.4, MySQL-8.0, MariaDB-…) → 127.0.0.1:3306–3308.
 *      База favorit_db создаётся сама, таблицы и демо-данные тоже.
 *
 *   2. SQLite (файл data/favorit.sqlite). Включается автоматически, если MySQL
 *      не запущен или расширение pdo_mysql не подключено. Сайт полностью
 *      работоспособен: те же таблицы, те же демонстрационные цифры.
 *
 * Если не подключено НИ ОДНО расширение баз данных (нет ни pdo_mysql, ни
 * pdo_sqlite), вместо «белого экрана» открывается понятная страница
 * с инструкцией — см. check.php.
 *
 * После подключения в переменных доступны:
 *   $pdo      — объект PDO (все страницы работают через него);
 *   $dbInfo   — движок, база, хост, файл, ошибки (показывается в admin.php);
 *   $dbError  — текст последней ошибки подключения (пустая строка, если всё хорошо).
 */

require_once __DIR__ . '/analytics_schema.php';

require_once __DIR__ . '/db_probe.php';

/* --------------------------------------------------------- страница с ошибкой */

if (!function_exists('db_fatal_page')) {
    /** Понятная страница вместо «белого экрана», когда БД недоступна совсем. */
    function db_fatal_page(string $details): void
    {
        $php = PHP_VERSION;
        $drivers = db_available_drivers();
        $driverText = $drivers ? implode(', ', $drivers) : 'нет ни одного';
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        if ($drivers) {
            $headline = 'База данных не подключена';
            $lead = 'Расширения PDO в PHP есть (' . $e($driverText) . '), но ни MySQL, ни резервная база '
                . 'SQLite не открылись. Обычно помогает включить модуль <b>MySQL-8.4</b> в OpenServer '
                . 'Panel и перезапустить панель — либо разрешить резервный режим SQLite '
                . '(<code>sqlite_fallback = true</code> в <code>config.php</code>).';
        } else {
            $headline = 'Не подключено ни одно расширение баз данных';
            $lead = 'Сайт умеет работать без MySQL — на резервной базе SQLite. Но в текущей сборке PHP '
                . 'не включены расширения <code>pdo_sqlite</code> и <code>pdo_mysql</code>, поэтому '
                . 'сохранять данные некуда.';
        }

        http_response_code(503);
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>База данных недоступна — Фаворит</title>'
            . '<style>body{margin:0;background:#0f1117;font-family:"Segoe UI",system-ui,sans-serif;color:#1a1f2e}'
            . '.wrap{max-width:820px;margin:0 auto;padding:48px 20px}'
            . '.card{background:#fff;border-radius:18px;padding:30px 32px;box-shadow:0 24px 60px rgba(0,0,0,.35)}'
            . 'h1{font-size:22px;margin:0 0 6px}p{line-height:1.65;font-size:14px;color:#3c4257}'
            . 'code{background:#eef2ff;color:#2f6fed;padding:1px 6px;border-radius:4px}'
            . 'ol{padding-left:20px;line-height:1.8;font-size:14px;color:#3c4257}'
            . '.note{background:#fff7e8;border:1px solid #f7d9a8;border-radius:10px;padding:12px 14px;font-size:13px;color:#8a5a00}'
            . '.tech{margin-top:18px;font-size:12px;color:#a61b12;word-break:break-word}'
            . 'a.btn{display:inline-block;margin-top:18px;background:#2f6fed;color:#fff;text-decoration:none;'
            . 'padding:11px 18px;border-radius:10px;font-size:14px;font-weight:600}</style></head><body>'
            . '<div class="wrap"><div class="card">'
            . '<h1>' . $headline . '</h1>'
            . '<p>' . $lead . '</p>'
            . '<div class="note">PHP ' . $e($php) . ' · доступные драйверы PDO: ' . $e($driverText) . '</div>'
            . '<p style="margin-top:18px"><b>Как включить (OpenServer Panel 6):</b></p>'
            . '<ol>'
            . '<li>Меню OSP в трее → <b>Модули</b> → включите <b>PHP-8.4</b> (или <b>PHP-8.4-FCGI</b>) и <b>MySQL-8.4</b>.</li>'
            . '<li>Меню → <b>Настройки</b> → <b>PHP</b> → выберите профиль нужной версии → '
            . 'в списке расширений отметьте <code>pdo_mysql</code>, <code>pdo_sqlite</code>, <code>sqlite3</code> и сохраните.</li>'
            . '<li>Или откройте вручную файл <code>modules/php/PHP-8.4/ini/php.ini</code> (путь внутри папки OSP) '
            . 'и убедитесь, что строки <code>extension=pdo_mysql</code> и <code>extension=pdo_sqlite</code> не закомментированы.</li>'
            . '<li>Перезапустите сервер: меню OSP → <b>Перезапустить</b>.</li>'
            . '</ol>'
            . '<p><b>Как быстро проверить, что всё включилось:</b> откройте <code>check.php</code> — '
            . 'страница «Проверка окружения» работает вообще без базы данных.</p>'
            . '<a class="btn" href="check.php">Открыть проверку окружения</a>'
            . '<div class="tech">Техническая информация: ' . $e($details) . '</div>'
            . '</div></div></body></html>';
        exit;
    }
}

/* ------------------------------------------------------------- установка БД */

if (!function_exists('db_is_installed')) {
    /** Есть ли в базе рабочие таблицы (быстрая проверка без DDL). */
    function db_is_installed(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM an_production LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* --------------------------------------------------------------- подключение */

$pdo = null;
$dbError = '';
$dbEngine = '';
$dbFile = null;
$dbHost = '';
$dbPort = '';
$dbTried = [];
$dbDumpImported = 0;

$drivers = db_available_drivers();
$config = db_config();
$cache = db_cache_read();

// --- 1. MySQL ---------------------------------------------------------------
if (empty($config['mysql'])) {
    $dbError = 'MySQL отключён в config.php (mysql = false) — используется резервная база';
} elseif (in_array('mysql', $drivers, true)) {
    $skipUntil = (int) ($cache['mysql_skip_until'] ?? 0);
    if ($skipUntil > time()) {
        $dbError = (string) ($cache['mysql_error'] ?? 'MySQL не запущен');
        $dbTried = (array) ($cache['mysql_tried'] ?? []);
    } else {
        foreach (db_mysql_candidates() as $candidate) {
            $host = (string) $candidate['host'];
            $port = (string) $candidate['port'];
            $dbTried[] = $host . ':' . $port;

            if (!db_port_open($host, (int) $port)) {
                continue;
            }

            $result = db_mysql_connect($host, $port);
            if ($result instanceof PDO) {
                $pdo = $result;
                $dbEngine = 'mysql';
                $dbHost = $host;
                $dbPort = $port;
                $dbError = '';
                // Кэш перезаписываем только если сервер нашли в новом месте.
                if (($cache['mysql_host'] ?? '') !== $host || (string) ($cache['mysql_port'] ?? '') !== $port) {
                    db_cache_write(['mysql_host' => $host, 'mysql_port' => $port]);
                }
                break;
            }
            $dbError = 'MySQL ' . $host . ':' . $port . ' — ' . $result;
        }

        if ($pdo === null) {
            if ($dbError === '') {
                $dbError = 'MySQL не найден (проверены: '
                    . implode(', ', array_slice($dbTried, 0, 6)) . ')';
            }
            db_cache_write([
                'mysql_skip_until' => time() + max(5, (int) $config['recheck_seconds']),
                'mysql_skip_standard' => true,
                'mysql_error' => $dbError,
                'mysql_tried' => $dbTried,
            ]);
        }
    }
} else {
    $dbError = 'расширение pdo_mysql не подключено в PHP';
}

// --- 2. SQLite (резерв) -----------------------------------------------------
if ($pdo === null && in_array('sqlite', $drivers, true) && !empty($config['sqlite_fallback'])) {
    $result = db_sqlite_connect();
    if ($result instanceof PDO) {
        $pdo = $result;
        $dbEngine = 'sqlite';
        $dbFile = db_writable_dir() . '/favorit.sqlite';
    } else {
        $dbError .= ($dbError === '' ? '' : ' | ') . 'SQLite: ' . $result;
    }
}

// --- 3. Совсем нет БД -------------------------------------------------------
if ($pdo === null) {
    if (!$drivers) {
        $dbError .= ($dbError === '' ? '' : ' | ') . 'PDO-драйверы не подключены';
    }
    db_fatal_page($dbError);
}

// --- 4. Схема и демо-данные -------------------------------------------------
an_ensure_schema($pdo);

// --- 5. Сведения о подключении ----------------------------------------------
$dbFilePublic = $dbFile;
if ($dbFilePublic !== null && strpos(str_replace('\\', '/', $dbFilePublic), str_replace('\\', '/', __DIR__) . '/') === 0) {
    $dbFilePublic = substr(str_replace('\\', '/', $dbFilePublic), strlen(str_replace('\\', '/', __DIR__)) + 1);
}

$dbInfo = [
    'engine' => $dbEngine,                                   // 'mysql' | 'sqlite'
    'engine_label' => $dbEngine === 'mysql' ? 'MySQL' : 'SQLite (резерв)',
    'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'database' => (string) $config['database'],
    'host' => $dbEngine === 'mysql' ? $dbHost . ':' . $dbPort : 'файл',
    'file' => $dbFilePublic,
    'imported' => $dbDumpImported,
    'error' => $dbError,
    'tried' => $dbTried,
    'drivers' => $drivers,
    'php' => PHP_VERSION,
    'debug' => (bool) $config['debug'],
];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
