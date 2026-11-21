<?php
/**
 * Вспомогательный слой подключения к базе: настройки, поиск сервера MySQL,
 * подключение к MySQL и SQLite, каталог для данных.
 *
 * Файл ничего не выполняет при подключении (нет побочных эффектов), поэтому его
 * можно безопасно подключать на странице диагностики check.php, даже когда база
 * данных недоступна.
 *
 * Основная логика подключения — в db.php.
 */

/* ------------------------------------------------------------------ настройки */

if (!function_exists('db_config')) {
    /** Настройки подключения из config.php + config.local.php. */
    function db_config(): array
    {
        static $config = null;
        if ($config === null) {
            $file = __DIR__ . '/config.php';
            $config = is_file($file) ? (array) require $file : [];
            $config += [
                'mysql' => true,
                'host' => '',
                'port' => '',
                'database' => 'favorit_db',
                'user' => 'root',
                'pass' => '',
                'charset' => 'utf8mb4',
                'sqlite_fallback' => true,
                'sqlite_dir' => __DIR__ . '/data',
                'recheck_seconds' => 20,
                'debug' => false,
            ];
        }
        return $config;
    }
}

if (!function_exists('db_available_drivers')) {
    /** Список доступных драйверов PDO: ['mysql', 'sqlite', …]. */
    function db_available_drivers(): array
    {
        if (!class_exists('PDO')) {
            return [];
        }
        try {
            return array_values(array_map('strval', PDO::getAvailableDrivers()));
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('db_has_driver')) {
    /** Есть ли нужный драйвер PDO. */
    function db_has_driver(string $driver): bool
    {
        return in_array($driver, db_available_drivers(), true);
    }
}

/* --------------------------------------------------------------- каталог данных */

if (!function_exists('db_data_dirs')) {
    /** Каталоги-кандидаты для файла SQLite и кэша (первый доступный на запись). */
    function db_data_dirs(): array
    {
        $config = db_config();
        $dirs = [
            (string) $config['sqlite_dir'],
            __DIR__ . '/data',
            __DIR__ . '/../favorit-data',
            sys_get_temp_dir() . '/favorit',
        ];
        $windows = (string) getenv('LOCALAPPDATA');
        if ($windows !== '') {
            $dirs[] = $windows . '/favorit';
        }
        $result = [];
        foreach ($dirs as $dir) {
            $dir = rtrim(str_replace('\\', '/', $dir), '/');
            if ($dir === '' || in_array($dir, $result, true)) {
                continue;
            }
            $result[] = $dir;
        }
        return $result;
    }
}

if (!function_exists('db_writable_dir')) {
    /** Первый каталог, куда PHP реально может писать (создаётся при необходимости). */
    function db_writable_dir(): ?string
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        foreach (db_data_dirs() as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_dir($dir)) {
                continue;
            }
            $probe = $dir . '/.write-test';
            if (@file_put_contents($probe, 'ok') !== false) {
                @unlink($probe);
                $cached = $dir;
                return $cached;
            }
        }
        $cached = null;
        return null;
    }
}

if (!function_exists('db_cache_read')) {
    /** Кэш «где находится MySQL» — чтобы не тормозить страницы при выключенной БД. */
    function db_cache_read(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $dir = db_writable_dir();
        if ($dir !== null) {
            $file = $dir . '/db-cache.json';
            if (is_file($file)) {
                $raw = @file_get_contents($file);
                $data = $raw === false ? null : json_decode($raw, true);
                if (is_array($data)) {
                    $cache = $data;
                }
            }
        }
        return $cache;
    }
}

if (!function_exists('db_cache_write')) {
    /** Сохраняет кэш подключения (или очищает его при пустом массиве). */
    function db_cache_write(array $data): void
    {
        $dir = db_writable_dir();
        if ($dir === null) {
            return;
        }
        $file = $dir . '/db-cache.json';
        if ($data === []) {
            @unlink($file);
            return;
        }
        $data['saved_at'] = time();
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

/* ------------------------------------------------------- поиск сервера MySQL */

if (!function_exists('db_hosts_file_names')) {
    /** Имена хостов из системного файла hosts, похожие на MySQL/MariaDB. */
    function db_hosts_file_names(): array
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }
        $names = [];
        $candidates = [];
        $systemRoot = (string) getenv('SystemRoot');
        if ($systemRoot !== '') {
            $candidates[] = $systemRoot . '/System32/drivers/etc/hosts';
        }
        $candidates[] = '/etc/hosts';
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim(preg_replace('/#.*/', '', $line) ?? '');
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $line) ?: [];
                array_shift($parts); // IP-адрес
                foreach ($parts as $name) {
                    $lower = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
                    if (strpos($lower, 'mysql') !== false || strpos($lower, 'mariadb') !== false) {
                        $names[] = $name;
                    }
                }
            }
        }
        $names = array_values(array_unique($names));
        return $names;
    }
}

if (!function_exists('db_mysql_candidates')) {
    /**
     * Список пар «хост:порт» для проверки — в порядке приоритета.
     *
     * Сначала дешёвые проверки без DNS (127.0.0.1, имена из файла hosts),
     * потом имена стандартных модулей OpenServer. Так первый запуск без MySQL
     * не «зависает» на поиске несуществующих имён.
     *
     * @return array<int, array{host:string, port:string}>
     */
    function db_mysql_candidates(): array
    {
        $config = db_config();
        $cache = db_cache_read();
        $candidates = [];
        $ports = ['3306', '3307', '3308'];

        $configPort = trim((string) $config['port']);
        if ($configPort !== '') {
            array_unshift($ports, $configPort);
            $ports = array_values(array_unique($ports));
        }

        $add = static function (string $host, array $usePorts) use (&$candidates): void {
            $host = trim($host);
            if ($host === '') {
                return;
            }
            foreach ($usePorts as $port) {
                $candidates[] = ['host' => $host, 'port' => (string) $port];
            }
        };

        // 1. Явно заданный хост (config.php, config.local.php, переменные окружения).
        $configHost = trim((string) $config['host']);
        if ($configHost !== '') {
            $add($configHost, [$ports[0]]);
        }

        // 2. То, что работало в прошлый раз.
        if (!empty($cache['mysql_host'])) {
            $add((string) $cache['mysql_host'], [(string) ($cache['mysql_port'] ?? $ports[0])]);
        }

        // 3. Адрес по умолчанию (OpenServer слушает 127.0.0.1:3306 без DNS).
        $add('127.0.0.1', $ports);

        // 4. Имена модулей БД из системного файла hosts (OpenServer Panel 6
        //    прописывает их сам при включении модуля).
        foreach (db_hosts_file_names() as $name) {
            $add($name, [$ports[0]]);
        }

        // 5. Стандартные имена модулей OpenServer Panel 6. Если полный поиск
        //    уже не находил сервер, эти имена пропускаем (не тратим время на DNS):
        //    при включении модуля OSP сам добавляет имя в hosts — пункт 4.
        if (empty($cache['mysql_skip_standard'])) {
            foreach (['MySQL-8.4', 'MySQL-8.0', 'MySQL-5.7', 'MariaDB-11.4', 'MariaDB-10.11', 'MariaDB-10.5'] as $name) {
                $add($name, [$ports[0]]);
            }
        }

        // 6. localhost — самый последний вариант (в OSP 6 может вести на IPv6).
        $add('localhost', [$ports[0]]);

        $unique = [];
        foreach ($candidates as $item) {
            $unique[$item['host'] . ':' . $item['port']] = $item;
        }
        return array_values($unique);
    }
}

if (!function_exists('db_port_open')) {
    /** Быстрая проверка «порт открыт» — дешевле, чем попытка подключения PDO. */
    function db_port_open(string $host, int $port, float $timeout = 0.3): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }
}

if (!function_exists('db_mysql_connect')) {
    /**
     * Подключение к MySQL на конкретном хосте и порту.
     * База favorit_db создаётся автоматически, если её ещё нет.
     *
     * @return PDO|string PDO при успехе, текст ошибки при неудаче.
     */
    function db_mysql_connect(string $host, string $port)
    {
        $config = db_config();
        // ERRORMODE обязателен: иначе ошибка подключения вернёт false
        // вместо исключения и код упадёт на вызове метода у false.
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 4,
        ];
        $charset = (string) ($config['charset'] ?: 'utf8mb4');
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $charset;
        }
        $database = (string) $config['database'];
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=' . $charset;

        try {
            $pdo = new PDO($dsn, (string) $config['user'], (string) $config['pass'], $options);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        if (!$pdo instanceof PDO) {
            return 'драйвер MySQL вернул некорректный ответ';
        }

        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $database)
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            // Нет прав на создание — значит база должна существовать. Проверим ниже.
        }

        try {
            $pdo->exec('USE `' . str_replace('`', '', $database) . '`');
            $pdo->exec('SET NAMES ' . $charset);
        } catch (Throwable $e) {
            return 'база «' . $database . '» недоступна: ' . $e->getMessage();
        }

        return $pdo;
    }
}

/* -------------------------------------------------------------------- SQLite */

if (!function_exists('db_sqlite_connect')) {
    /**
     * Подключение к резервной базе SQLite.
     * @return PDO|string PDO при успехе, текст ошибки при неудаче.
     */
    function db_sqlite_connect()
    {
        $dir = db_writable_dir();
        if ($dir === null) {
            return 'нет каталога, доступного для записи (проверьте права на папку data)';
        }
        $file = $dir . '/favorit.sqlite';
        try {
            $pdo = new PDO('sqlite:' . $file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return $pdo;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
