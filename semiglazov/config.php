<?php
/**
 * Настройки подключения к базе данных.
 *
 * Главное правило OpenServer Panel 6: НЕ используйте localhost как хост MySQL.
 * Правильные варианты: имя модуля БД (MySQL-8.4, MySQL-8.0) или 127.0.0.1.
 *
 * Поэтому host и port по умолчанию ПУСТЫЕ — db.php сам найдёт работающий
 * сервер MySQL: сначала проверит config.local.php, переменные окружения,
 * затем имена модулей из hosts (MySQL-8.4, MySQL-8.0, MariaDB-...),
 * затем 127.0.0.1 и локальные порты 3306–3308.
 *
 * Если MySQL не найден — база автоматически создаётся в SQLite (файл data/favorit.sqlite),
 * и сайт работает без MySQL вообще.
 *
 * Свои значения (пароль root и т. п.) можно положить в config.local.php — он
 * не попадёт в архив и перекроет настройки ниже, например:
 *
 *     <?php return ['host' => 'MySQL-8.4', 'port' => '3306', 'pass' => 'мой_пароль'];
 */

$config = [
    // --- MySQL / MariaDB (OpenServer) -----------------------------------
    // true  — искать MySQL и работать на нём (обычный режим);
    // false — всегда использовать резервную базу SQLite (демонстрация без MySQL).
    'mysql'    => (getenv('FAVORIT_MYSQL') ?: 'on') !== 'off',

    // Пустая строка = автоопределение (рекомендуется).
    'host'     => getenv('DB_HOST') ?: '',
    'port'     => getenv('DB_PORT') ?: '',
    'database' => getenv('DB_NAME') ?: 'favorit_db',
    'user'     => getenv('DB_USER') ?: 'root',
    'pass'     => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
    'charset'  => 'utf8mb4',

    // --- Резервная база (работа сайта без MySQL) ------------------------
    'sqlite_fallback' => true,
    'sqlite_dir'      => getenv('FAVORIT_DATA_DIR') ?: __DIR__ . '/data',

    // Пауза (секунды) между проверками «а не включили ли MySQL», когда он был
    // выключен. Небольшое значение — сайт быстро подхватывает запущенный MySQL.
    'recheck_seconds' => 20,

    // 1 = внизу страниц выводить техническую строку о подключении к БД.
    'debug' => (bool) (getenv('FAVORIT_DEBUG') ?: false),
];

// Локальные перекрытия (пароли, другой порт) — файл не обязателен.
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = (array) require $localFile;
    $config = array_merge($config, $local);
}

return $config;
