<?php
/**
 * Схема базы и демонстрационные данные системы «Фаворит».
 *
 * Один и тот же код создаёт структуру и наполняет её данными и в MySQL
 * (OpenServer), и в резервной базе SQLite. Поэтому сайт одинаково работает
 * с запущенным MySQL и вообще без него.
 *
 * Все функции идемпотентны: их можно вызывать на каждом запросе — существующие
 * таблицы и записи не пересоздаются.
 */

if (!defined('AN_SCHEMA_VERSION')) {
    define('AN_SCHEMA_VERSION', '4');
}

if (!function_exists('an_driver')) {
    /** Имя драйвера PDO текущего подключения: mysql / sqlite. */
    function an_driver(?PDO $pdo = null): string
    {
        if (!$pdo instanceof PDO) {
            $pdo = $GLOBALS['pdo'] ?? null;
        }
        if (!$pdo instanceof PDO) {
            return '';
        }
        try {
            return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('an_is_sqlite')) {
    /** Работаем ли на резервной базе SQLite. */
    function an_is_sqlite(?PDO $pdo = null): bool
    {
        return an_driver($pdo) === 'sqlite';
    }
}

/* ------------------------------------------------------------- структура БД */

if (!function_exists('an_schema_statements')) {
    /**
     * DDL-запросы для текущего движка.
     * @return array<int, string>
     */
    function an_schema_statements(PDO $pdo): array
    {
        $sqlite = an_is_sqlite($pdo);

        if ($sqlite) {
            return [
                "CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    login TEXT NOT NULL UNIQUE,
                    password TEXT NOT NULL,
                    full_name TEXT NOT NULL DEFAULT '',
                    phone TEXT NOT NULL DEFAULT '',
                    email TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT '',
                    role TEXT NOT NULL DEFAULT 'manager'
                )",
                "CREATE TABLE IF NOT EXISTS an_categories (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS an_products (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    category_id INTEGER NOT NULL DEFAULT 0,
                    title TEXT NOT NULL,
                    unit TEXT NOT NULL DEFAULT 'шт.',
                    price REAL NOT NULL DEFAULT 0,
                    active INTEGER NOT NULL DEFAULT 1
                )",
                "CREATE TABLE IF NOT EXISTS an_channels (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    type TEXT NOT NULL DEFAULT 'прямые',
                    plan_revenue REAL NOT NULL DEFAULT 0,
                    cost REAL NOT NULL DEFAULT 0,
                    leads INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS an_production (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    period TEXT NOT NULL,
                    workshop TEXT NOT NULL,
                    category TEXT NOT NULL,
                    qty INTEGER NOT NULL DEFAULT 0,
                    defects INTEGER NOT NULL DEFAULT 0,
                    hours REAL NOT NULL DEFAULT 0,
                    cost REAL NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS an_sales (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    period TEXT NOT NULL,
                    channel TEXT NOT NULL,
                    orders INTEGER NOT NULL DEFAULT 0,
                    revenue REAL NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS an_orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    period TEXT NOT NULL,
                    client TEXT NOT NULL,
                    product TEXT NOT NULL,
                    channel TEXT NOT NULL,
                    amount REAL NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'new',
                    due_date TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS an_defects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    period TEXT NOT NULL,
                    workshop TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    qty INTEGER NOT NULL DEFAULT 0,
                    cost REAL NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'open'
                )",
                "CREATE TABLE IF NOT EXISTS an_staff (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    full_name TEXT NOT NULL,
                    department TEXT NOT NULL,
                    position TEXT NOT NULL,
                    salary REAL NOT NULL DEFAULT 0,
                    hired_at TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'active'
                )",
                "CREATE TABLE IF NOT EXISTS an_targets (
                    metric TEXT NOT NULL PRIMARY KEY,
                    target_value REAL NOT NULL DEFAULT 0,
                    title TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS an_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    actor TEXT NOT NULL,
                    action TEXT NOT NULL,
                    details TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL
                )",
                'CREATE INDEX IF NOT EXISTS idx_production_period ON an_production (period)',
                'CREATE INDEX IF NOT EXISTS idx_sales_period ON an_sales (period)',
                'CREATE INDEX IF NOT EXISTS idx_orders_period ON an_orders (period)',
                'CREATE INDEX IF NOT EXISTS idx_orders_status ON an_orders (status)',
                'CREATE INDEX IF NOT EXISTS idx_defects_period ON an_defects (period)',
                'CREATE INDEX IF NOT EXISTS idx_staff_department ON an_staff (department)',
                'CREATE INDEX IF NOT EXISTS idx_audit_created ON an_audit (created_at)',
            ];
        }

        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                login VARCHAR(50) NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL DEFAULT '',
                phone VARCHAR(30) NOT NULL DEFAULT '',
                email VARCHAR(100) NOT NULL DEFAULT '',
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                role VARCHAR(20) NOT NULL DEFAULT 'manager',
                PRIMARY KEY (id),
                UNIQUE KEY uq_users_login (login)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_categories (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(80) NOT NULL,
                PRIMARY KEY (id)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_products (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                category_id INT NOT NULL DEFAULT 0,
                title VARCHAR(120) NOT NULL,
                unit VARCHAR(20) NOT NULL DEFAULT 'шт.',
                price DECIMAL(12,2) NOT NULL DEFAULT 0,
                active INT NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                KEY idx_products_category (category_id)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_channels (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(80) NOT NULL,
                type VARCHAR(40) NOT NULL DEFAULT 'прямые',
                plan_revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
                cost DECIMAL(14,2) NOT NULL DEFAULT 0,
                leads INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_production (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                period VARCHAR(7) NOT NULL,
                workshop VARCHAR(80) NOT NULL,
                category VARCHAR(60) NOT NULL,
                qty INT NOT NULL DEFAULT 0,
                defects INT NOT NULL DEFAULT 0,
                hours DECIMAL(10,1) NOT NULL DEFAULT 0,
                cost DECIMAL(14,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_production_period (period)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_sales (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                period VARCHAR(7) NOT NULL,
                channel VARCHAR(60) NOT NULL,
                orders INT NOT NULL DEFAULT 0,
                revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_sales_period (period)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_orders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                period VARCHAR(7) NOT NULL,
                client VARCHAR(120) NOT NULL,
                product VARCHAR(120) NOT NULL,
                channel VARCHAR(80) NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                due_date VARCHAR(10) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_orders_period (period),
                KEY idx_orders_status (status)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_defects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                period VARCHAR(7) NOT NULL,
                workshop VARCHAR(80) NOT NULL,
                reason VARCHAR(80) NOT NULL,
                qty INT NOT NULL DEFAULT 0,
                cost DECIMAL(14,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                PRIMARY KEY (id),
                KEY idx_defects_period (period)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_staff (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                full_name VARCHAR(120) NOT NULL,
                department VARCHAR(80) NOT NULL,
                position VARCHAR(80) NOT NULL,
                salary DECIMAL(12,2) NOT NULL DEFAULT 0,
                hired_at VARCHAR(10) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                PRIMARY KEY (id),
                KEY idx_staff_department (department)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_targets (
                metric VARCHAR(40) NOT NULL,
                target_value DECIMAL(14,2) NOT NULL DEFAULT 0,
                title VARCHAR(120) NOT NULL,
                PRIMARY KEY (metric)
            )$suffix",
            "CREATE TABLE IF NOT EXISTS an_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor VARCHAR(120) NOT NULL,
                action VARCHAR(120) NOT NULL,
                details VARCHAR(255) NOT NULL DEFAULT '',
                created_at VARCHAR(19) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_audit_created (created_at)
            )$suffix",
        ];
    }
}

/* ------------------------------------------------------------ данные демо */

if (!function_exists('an_seed_data')) {
    /** Массив демонстрационных данных (один для MySQL и SQLite). */
    function an_seed_data(): array
    {
        static $data = null;
        if ($data === null) {
            $file = __DIR__ . '/seed_data.php';
            $data = is_file($file) ? (array) require $file : [];
        }
        return $data;
    }
}

if (!function_exists('an_table_count')) {
    /** Количество записей в таблице (0, если таблицы нет). */
    function an_table_count(PDO $pdo, string $table): int
    {
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('an_seed')) {
    /**
     * Наполнение базы демонстрационными данными.
     * По умолчанию заполняются только пустые таблицы.
     */
    function an_seed(PDO $pdo, bool $all = false, bool $withUsers = true): void
    {
        $data = an_seed_data();
        $inTransaction = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $inTransaction = true;
            }

            // Пользователи (пароль хешируется, поэтому не в SQL-дампе).
            // При «Пересоздать демо-данные» учётные записи не дублируются.
            if ($withUsers && ($all || an_table_count($pdo, 'users') === 0)) {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE login = ?');
                $insert = $pdo->prepare('INSERT INTO users (login, password, full_name, phone, email, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($data['users'] ?? [] as $row) {
                    $exists->execute([$row[0]]);
                    if ((int) $exists->fetchColumn() > 0) {
                        continue;
                    }
                    $insert->execute([
                        $row[0],
                        password_hash((string) $row[1], PASSWORD_DEFAULT),
                        $row[2] ?? '',
                        $row[3] ?? '',
                        $row[4] ?? '',
                        $row[5] ?? 'manager',
                        date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // Справочники. Категории вставляем отдельно, чтобы продукция
            // ссылалась на реально созданные идентификаторы (после «Пересоздать
            // демо-данные» автоинкремент продолжается с новых чисел).
            $categoryMap = [];
            if ($all || an_table_count($pdo, 'an_categories') === 0) {
                $insert = $pdo->prepare('INSERT INTO an_categories (name) VALUES (?)');
                foreach ($data['an_categories'] ?? [] as $index => $row) {
                    $insert->execute([$row[0]]);
                    $categoryMap[$index + 1] = (int) $pdo->lastInsertId();
                }
            }

            foreach (['an_products', 'an_channels'] as $table) {
                if (!$all && an_table_count($pdo, $table) > 0) {
                    continue;
                }
                $columns = $table === 'an_products'
                    ? ['category_id', 'title', 'unit', 'price']
                    : ['name', 'type', 'plan_revenue', 'cost', 'leads'];
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $insert = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
                foreach ($data[$table] ?? [] as $row) {
                    $row = array_values($row);
                    if ($table === 'an_products') {
                        $old = (int) $row[0];
                        $row[0] = $categoryMap[$old] ?? $old;
                    }
                    $insert->execute($row);
                }
            }

            $tables = [
                'an_production' => ['period', 'workshop', 'category', 'qty', 'defects', 'hours', 'cost'],
                'an_sales' => ['period', 'channel', 'orders', 'revenue'],
                'an_orders' => ['period', 'client', 'product', 'channel', 'amount', 'status', 'due_date'],
                'an_defects' => ['period', 'workshop', 'reason', 'qty', 'cost', 'status'],
                'an_staff' => ['full_name', 'department', 'position', 'salary', 'hired_at', 'status'],
            ];
            foreach ($tables as $table => $columns) {
                if (!$all && an_table_count($pdo, $table) > 0) {
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $insert = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
                foreach ($data[$table] ?? [] as $row) {
                    $insert->execute(array_values($row));
                }
            }

            if ($all || an_table_count($pdo, 'an_targets') === 0) {
                $insert = $pdo->prepare('INSERT INTO an_targets (metric, target_value, title) VALUES (?, ?, ?)');
                foreach ($data['an_targets'] ?? [] as $row) {
                    $insert->execute(array_values($row));
                }
            }

            if ($inTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($inTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/* ------------------------------------------------------------- установка */

if (!function_exists('an_ensure_role_column')) {
    /** В старых базах в таблице users нет колонки role — добавляем. */
    function an_ensure_role_column(PDO $pdo): void
    {
        try {
            if (an_is_sqlite($pdo)) {
                $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
                $hasRole = false;
                foreach ($columns as $column) {
                    if (($column['name'] ?? '') === 'role') {
                        $hasRole = true;
                        break;
                    }
                }
            } else {
                $hasRole = false;
                foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $column) {
                    if (($column['Field'] ?? '') === 'role') {
                        $hasRole = true;
                        break;
                    }
                }
            }
            if (!$hasRole) {
                $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'manager'");
            }
        } catch (Throwable $ignored) {
            // Колонка уже есть или таблица недоступна — работаем как есть.
        }
    }
}

if (!function_exists('an_init_schema')) {
    /** Полная установка: таблицы + демо-данные. Идемпотентно. */
    function an_init_schema(PDO $pdo): void
    {
        foreach (an_schema_statements($pdo) as $statement) {
            $pdo->exec($statement);
        }
        an_ensure_role_column($pdo);

        // Демо-данные только в пустую базу: существующие записи не трогаем.
        an_seed($pdo, false);
    }
}

if (!function_exists('an_schema_ready')) {
    /** Быстрая проверка, что структура уже создана и доступна. */
    function an_schema_ready(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM an_production LIMIT 1');
            $pdo->query('SELECT 1 FROM an_orders LIMIT 1');
            $pdo->query('SELECT 1 FROM users LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('an_schema_marker')) {
    /** Файл-маркер «структура создана» — чтобы не выполнять DDL на каждом запросе. */
    function an_schema_marker(PDO $pdo): ?string
    {
        if (!function_exists('db_writable_dir')) {
            return null;
        }
        $dir = db_writable_dir();
        if ($dir === null) {
            return null;
        }
        return $dir . '/schema-' . an_driver($pdo) . '-' . AN_SCHEMA_VERSION . '.ok';
    }
}

if (!function_exists('an_ensure_schema')) {
    /** Вызывается на каждом запросе: создаёт таблицы только при необходимости. */
    function an_ensure_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $marker = an_schema_marker($pdo);
        if ($marker !== null && is_file($marker) && an_schema_ready($pdo)) {
            return;
        }

        an_init_schema($pdo);

        if ($marker !== null && an_schema_ready($pdo)) {
            @file_put_contents($marker, date('c') . ' schema ' . AN_SCHEMA_VERSION);
        }
    }
}

/* ------------------------------------------------------------- операции */

if (!function_exists('an_audit')) {
    /** Запись в журнал действий. */
    function an_audit(PDO $pdo, string $actor, string $action, string $details = ''): void
    {
        try {
            $statement = $pdo->prepare('INSERT INTO an_audit (actor, action, details, created_at) VALUES (?, ?, ?, ?)');
            $statement->execute([$actor, $action, mb_substr($details, 0, 250), date('Y-m-d H:i:s')]);
        } catch (Throwable $ignored) {
            // Журнал не должен ломать работу сайта.
        }
    }
}

if (!function_exists('an_reset_demo')) {
    /** Полностью пересоздаёт демонстрационные данные (кнопка на странице «Данные»). */
    function an_reset_demo(PDO $pdo): void
    {
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            foreach ([
            'an_production', 'an_sales', 'an_staff', 'an_orders', 'an_defects',
            'an_channels', 'an_products', 'an_categories', 'an_targets', 'an_audit',
            ] as $table) {
                try {
                    $pdo->exec('DELETE FROM ' . $table);
                    // В SQLite сбрасываем счётчик, чтобы идентификаторы начинались с 1.
                    // В MySQL ALTER TABLE нельзя выполнять внутри транзакции — пропускаем.
                    if (an_is_sqlite($pdo)) {
                        $pdo->exec("DELETE FROM sqlite_sequence WHERE name = '" . $table . "'");
                    }
                } catch (Throwable $ignored) {
                }
            }

            an_seed($pdo, true, false);
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($ownTransaction && $pdo->inTransaction()) {
            try {
                $pdo->commit();
            } catch (Throwable $ignored) {
                // MySQL мог сам закрыть транзакцию — данные уже записаны.
            }
        }
    }
}
