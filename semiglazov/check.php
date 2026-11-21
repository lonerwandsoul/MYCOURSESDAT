<?php
/**
 * Проверка окружения — страница диагностики.
 *
 * Работает ВСЕГДА, даже если база данных недоступна и в PHP не подключены
 * драйверы PDO: файл не подключает db.php и ничего не создаёт без спроса.
 *
 * Что показывает:
 *   · версию PHP и включённые расширения (pdo_mysql, pdo_sqlite, mbstring …);
 *   · доступные драйверы PDO;
 *   · есть ли MySQL на стандартных адресах OpenServer (MySQL-8.4, 127.0.0.1 …);
 *   · куда пишется резервная база SQLite и сколько в ней записей;
 *   · найден ли проект в OpenServer Panel 6 (файл .osp/project.ini);
 *   · что именно поправить, если что-то не работает.
 */

require_once __DIR__ . '/db_probe.php';

$config = db_config();
$drivers = db_available_drivers();
$hasMysql = in_array('mysql', $drivers, true);
$hasSqlite = in_array('sqlite', $drivers, true);
$projectDir = __DIR__;
$dataDir = db_writable_dir();

/* ------------------------------------------------------------- диагностика */

$report = [];

// 1. PHP и расширения.
$extensions = ['pdo', 'pdo_mysql', 'pdo_sqlite', 'sqlite3', 'mbstring', 'json', 'session', 'openssl', 'fileinfo'];
$extensionState = [];
foreach ($extensions as $extension) {
    $extensionState[$extension] = extension_loaded($extension);
}

// 2. MySQL: поиск сервера (быстрые проверки портов).
$mysqlProbe = [];
if ($hasMysql && !empty($config['mysql'])) {
    foreach (array_slice(db_mysql_candidates(), 0, 10) as $candidate) {
        $open = db_port_open($candidate['host'], (int) $candidate['port'], 0.25);
        $mysqlProbe[] = ['host' => $candidate['host'], 'port' => $candidate['port'], 'open' => $open];
    }
}
$mysqlOpen = array_values(array_filter($mysqlProbe, static fn ($row) => $row['open']));

// 3. Живое подключение, если порт открыт (создаёт базу, если её ещё нет).
$mysqlConnect = null;
if ($mysqlOpen) {
    try {
        $result = db_mysql_connect($mysqlOpen[0]['host'], (string) $mysqlOpen[0]['port']);
        $mysqlConnect = $result instanceof PDO
            ? ['ok' => true, 'text' => 'Подключение успешно: ' . $mysqlOpen[0]['host'] . ':' . $mysqlOpen[0]['port']]
            : ['ok' => false, 'text' => (string) $result];
    } catch (Throwable $e) {
        $mysqlConnect = ['ok' => false, 'text' => $e->getMessage()];
    }
}

// 4. SQLite: файл резервной базы и количество записей.
$sqliteFile = $dataDir !== null ? $dataDir . '/favorit.sqlite' : null;
$sqliteInfo = null;
if ($sqliteFile !== null && is_file($sqliteFile) && $hasSqlite) {
    try {
        $sqlite = new PDO('sqlite:' . $sqliteFile);
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $sqlite->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        }
        $sqliteInfo = ['tables' => $counts, 'size' => filesize($sqliteFile)];
    } catch (Throwable $e) {
        $sqliteInfo = ['error' => $e->getMessage()];
    }
}

// 5. OpenServer Panel 6: есть ли конфигурация проекта.
$projectIni = $projectDir . '/.osp/project.ini';
$projectIniData = is_file($projectIni) ? (string) file_get_contents($projectIni) : null;
$domains = [];
if ($projectIniData !== null) {
    foreach (preg_split('/\R/', $projectIniData) ?: [] as $line) {
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
            $domains[] = $m[1];
        }
    }
}

// 6. Файлы и права.
$checks = [
    ['config.php', is_file($projectDir . '/config.php'), 'настройки подключения'],
    ['config.local.php', is_file($projectDir . '/config.local.php'), 'локальные перекрытия (необязательно)'],
    ['database/favorit_db.sql', is_file($projectDir . '/database/favorit_db.sql'), 'дамп для phpMyAdmin'],
    ['.osp/project.ini', is_file($projectIni), 'автообнаружение проекта в OSP 6'],
    ['.htaccess', is_file($projectDir . '/.htaccess'), 'защита служебных файлов (Apache)'],
    ['data/', $dataDir !== null, $dataDir !== null ? 'запись разрешена: ' . $dataDir : 'нет доступной для записи папки'],
];

/* ---------------------------------------------------------------- итоговый статус */

if (!$hasMysql && !$hasSqlite) {
    $status = ['class' => 'bad', 'title' => 'База данных недоступна',
        'text' => 'В PHP не включены расширения pdo_mysql и pdo_sqlite. Сайт не сможет сохранять данные — включите расширение (инструкция ниже).'];
} elseif ($mysqlConnect && $mysqlConnect['ok']) {
    $status = ['class' => 'good', 'title' => 'MySQL подключён',
        'text' => 'Сайт работает на MySQL (' . $mysqlOpen[0]['host'] . ':' . $mysqlOpen[0]['port'] . '), база «' . $config['database'] . '». Резервная SQLite не нужна.'];
} elseif ($hasSqlite && empty($config['mysql'])) {
    $status = ['class' => 'warn', 'title' => 'Режим «только SQLite»',
        'text' => 'В config.php стоит mysql = false, поэтому используется резервная база. Сайт полностью работоспособен.'];
} elseif ($hasSqlite) {
    $status = ['class' => 'warn', 'title' => 'Работает резервная база SQLite',
        'text' => 'MySQL не найден или не запущен — сайт автоматически перешёл на SQLite. Данные сохраняются в файл, всё работает. Включите MySQL-8.4 в OpenServer, если нужна база MySQL.'];
} else {
    $status = ['class' => 'bad', 'title' => 'MySQL найден, но подключиться не удалось',
        'text' => 'Включено только расширение pdo_mysql, а сервер не отвечает. Запустите MySQL в OpenServer или включите pdo_sqlite для резервного режима.'];
}

// Текстовый отчёт для пересылки преподавателю/разработчику.
$textReport = [];
$textReport[] = 'Проверка окружения «Фаворит Диван»';
$textReport[] = 'Дата: ' . date('d.m.Y H:i');
$textReport[] = 'PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . '), ' . PHP_OS . ', ' . (PHP_INT_SIZE * 8) . ' бит';
$textReport[] = 'Драйверы PDO: ' . ($drivers ? implode(', ', $drivers) : 'нет');
$textReport[] = 'Расширения: ' . implode(', ', array_map(
    static fn ($name, $on) => $name . ($on ? ' +' : ' -'),
    array_keys($extensionState),
    $extensionState
));
$textReport[] = 'Каталог проекта: ' . $projectDir;
$textReport[] = 'Каталог данных: ' . ($dataDir ?? 'недоступен для записи');
$textReport[] = 'SQLite-файл: ' . ($sqliteFile ?? '—') . ($sqliteInfo && isset($sqliteInfo['size']) ? ' (' . $sqliteInfo['size'] . ' байт)' : '');
$textReport[] = 'MySQL-кандидаты: ' . ($mysqlProbe
    ? implode('; ', array_map(static fn ($row) => $row['host'] . ':' . $row['port'] . ($row['open'] ? ' открыт' : ' закрыт'), $mysqlProbe))
    : 'проверка не выполнялась (нет pdo_mysql или MySQL отключён)');
$textReport[] = 'MySQL-подключение: ' . ($mysqlConnect['text'] ?? 'нет');
$textReport[] = 'Домены в .osp/project.ini: ' . ($domains ? implode(', ', $domains) : 'нет файла');
$textReport[] = 'Итог: ' . $status['title'];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Проверка окружения — Фаворит Диван</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #0f1117; font-family: 'Segoe UI', system-ui, sans-serif; color: #1a1f2e; }
        .wrap { max-width: 980px; margin: 0 auto; padding: 36px 18px 60px; }
        .card { background: #fff; border-radius: 18px; padding: 26px 28px; box-shadow: 0 24px 60px rgba(0,0,0,.35); margin-bottom: 18px; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .brand img { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; }
        .brand b { display: block; color: #1a1f2e; }
        .brand span { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #9aa1b3; }
        h1 { font-size: 23px; margin: 0 0 6px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        p.sub { color: #7b8194; font-size: 14px; margin: 0 0 18px; }
        p, li { font-size: 14px; line-height: 1.6; color: #3c4257; }
        code { background: #eef2ff; color: #2f6fed; padding: 1px 6px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef0f6; vertical-align: top; }
        th { color: #7b8194; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .ok { color: #0b6b3a; font-weight: 600; }
        .no { color: #a61b12; font-weight: 600; }
        .status { border-radius: 14px; padding: 16px 18px; margin-bottom: 18px; font-size: 14px; }
        .status b { display: block; font-size: 16px; margin-bottom: 4px; }
        .status.good { background: #e8f8ef; border: 1px solid #b8e8cd; color: #0b6b3a; }
        .status.warn { background: #fff7e8; border: 1px solid #f7d9a8; color: #8a5a00; }
        .status.bad { background: #fdecea; border: 1px solid #f6c6c2; color: #a61b12; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 6px; }
        .btn { display: inline-block; padding: 11px 18px; border-radius: 10px; background: #2f6fed; color: #fff;
               text-decoration: none; font-size: 14px; font-weight: 600; border: 0; cursor: pointer; }
        .btn.ghost { background: #eef2ff; color: #2f6fed; }
        .btn.dark { background: #1a1f2e; }
        details { margin-top: 10px; }
        summary { cursor: pointer; font-size: 13.5px; color: #2f6fed; }
        pre { background: #0f1117; color: #d7dce8; padding: 14px; border-radius: 10px; overflow-x: auto;
              font-size: 12px; line-height: 1.6; margin: 10px 0 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
        .muted { color: #7b8194; }
        ol { padding-left: 20px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="brand">
            <img src="images/logo.jpg" alt="Фаворит">
            <div><span>СМП</span><b>Фаворит Диван</b></div>
        </div>
        <h1>Проверка окружения</h1>
        <p class="sub">Страница работает без базы данных — она показывает, чего не хватает для запуска</p>

        <div class="status <?= $e($status['class']) ?>">
            <b><?= $e($status['title']) ?></b>
            <?= $e($status['text']) ?>
        </div>

        <div class="actions">
            <a class="btn" href="install.php">Установить / создать базу</a>
            <a class="btn dark" href="analytics.php">Открыть аналитику</a>
            <a class="btn ghost" href="check.php?refresh=1">Проверить снова</a>
        </div>
        <?php if (isset($_GET['refresh'])): ?>
            <p class="muted" style="margin-top:12px;font-size:12.5px">Повторная проверка выполнена: <?= $e(date('H:i:s')) ?>
                <?php db_cache_write([]); ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>PHP и расширения</h2>
        <table>
            <tr><th>Параметр</th><th>Значение</th></tr>
            <tr><td>Версия PHP</td><td><b><?= $e(PHP_VERSION) ?></b> (<?= $e(PHP_SAPI) ?>, <?= $e(PHP_OS) ?>, <?= (int) (PHP_INT_SIZE * 8) ?> бит)</td></tr>
            <tr><td>Драйверы PDO</td><td>
                <?php if ($drivers): ?>
                    <span class="ok"><?= $e(implode(', ', $drivers)) ?></span>
                <?php else: ?>
                    <span class="no">не подключены</span>
                <?php endif; ?>
            </td></tr>
            <?php foreach ($extensionState as $name => $on): ?>
                <tr>
                    <td><code><?= $e($name) ?></code></td>
                    <td class="<?= $on ? 'ok' : 'no' ?>"><?= $on ? 'подключено' : 'нет' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><td>Лимит памяти</td><td><?= $e(ini_get('memory_limit')) ?></td></tr>
            <tr><td>Каталог сессий</td><td><?= $e(ini_get('session.save_path') ?: sys_get_temp_dir()) ?></td></tr>
        </table>
    </div>

    <div class="card">
        <h2>Базы данных</h2>
        <div class="grid">
            <div>
                <h2 style="font-size:14px">MySQL</h2>
                <?php if (empty($config['mysql'])): ?>
                    <p class="muted">Отключён в <code>config.php</code> (<code>mysql = false</code>).</p>
                <?php elseif (!$hasMysql): ?>
                    <p class="no">Расширение pdo_mysql не подключено.</p>
                <?php elseif (!$mysqlProbe): ?>
                    <p class="muted">Проверка не выполнялась.</p>
                <?php else: ?>
                    <table>
                        <tr><th>Адрес</th><th>Порт</th><th>Статус</th></tr>
                        <?php foreach ($mysqlProbe as $row): ?>
                            <tr>
                                <td><?= $e($row['host']) ?></td>
                                <td><?= $e($row['port']) ?></td>
                                <td class="<?= $row['open'] ? 'ok' : 'muted' ?>"><?= $row['open'] ? 'открыт' : 'закрыт' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php if ($mysqlConnect): ?>
                        <p style="margin-top:10px" class="<?= $mysqlConnect['ok'] ? 'ok' : 'no' ?>">
                            <?= $e($mysqlConnect['text']) ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div>
                <h2 style="font-size:14px">SQLite (резерв)</h2>
                <?php if (!$hasSqlite): ?>
                    <p class="no">Расширение pdo_sqlite не подключено.</p>
                <?php else: ?>
                    <p>Каталог: <b><?= $e($dataDir ?? 'нет доступного для записи') ?></b></p>
                    <p>Файл: <code><?= $e($sqliteFile ?? '—') ?></code>
                        <?php if ($sqliteInfo && isset($sqliteInfo['size'])): ?>
                            · <?= number_format($sqliteInfo['size'] / 1024, 1, ',', ' ') ?> КБ
                        <?php endif; ?>
                    </p>
                    <?php if ($sqliteInfo && isset($sqliteInfo['tables'])): ?>
                        <p class="ok">Таблиц: <?= count($sqliteInfo['tables']) ?>,
                            записей: <?= number_format(array_sum($sqliteInfo['tables']), 0, ',', ' ') ?></p>
                    <?php elseif ($sqliteInfo && isset($sqliteInfo['error'])): ?>
                        <p class="no">Файл открыть не удалось: <?= $e($sqliteInfo['error']) ?></p>
                    <?php else: ?>
                        <p class="muted">Файл ещё не создан — он появится при первом открытии сайта.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Файлы проекта</h2>
        <table>
            <tr><th>Файл</th><th>Есть</th><th>Назначение</th></tr>
            <?php foreach ($checks as [$name, $exists, $note]): ?>
                <tr>
                    <td><code><?= $e($name) ?></code></td>
                    <td class="<?= $exists ? 'ok' : 'no' ?>"><?= $exists ? 'да' : 'нет' ?></td>
                    <td class="muted"><?= $e($note) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($domains): ?>
            <p style="margin-top:12px">Домены проекта в <code>.osp/project.ini</code>:
                <?php foreach ($domains as $domain): ?>
                    <b><?= $e($domain) ?></b> — откройте <code>http://<?= $e($domain) ?>/</code>
                <?php endforeach; ?>
            </p>
        <?php elseif ($projectIniData === null): ?>
            <p style="margin-top:12px" class="no">
                Файла <code>.osp/project.ini</code> нет — OpenServer Panel 6 не покажет проект в списке.
                Скопируйте папку проекта целиком (вместе со скрытой папкой <code>.osp</code>) в <code>OSPanel\home</code>.
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Что делать, если что-то не так</h2>
        <ol>
            <li><b>Проект не виден в OpenServer Panel 6.</b> Папка проекта должна лежать в
                <code>OSPanel\home\semiglazov</code> и содержать <code>.osp\project.ini</code>
                (он уже есть в архиве). После копирования перезапустите OSP.</li>
            <li><b>Сайт не открывается по адресу.</b> Проверьте имя домена в
                <code>.osp\project.ini</code> (например, <code>[semiglazov.local]</code>)
                и откройте <code>http://semiglazov.local/</code>. Домены из файла hosts
                OSP прописывает сам.</li>
            <li><b>«Could not find driver».</b> В PHP-модуле не включены расширения:
                меню OSP → «Модули» → PHP-8.4 → настройки расширений → включите
                <code>pdo_mysql</code> и <code>pdo_sqlite</code>, затем «Перезапустить».</li>
            <li><b>MySQL не подключается.</b> Никогда не используйте <code>localhost</code>:
                в OSP 6 имя хоста — это имя модуля, например <code>MySQL-8.4</code>.
                Сайт определяет хост сам, но можно указать вручную в <code>config.local.php</code>.</li>
            <li><b>Не работает запись.</b> Папка <code>data</code> должна быть доступна для записи.
                На этой странице выше видно, какой каталог используется.</li>
        </ol>

        <details>
            <summary>Показать текстовый отчёт (можно скопировать целиком)</summary>
            <pre><?= $e(implode("\n", $textReport)) ?></pre>
        </details>
    </div>
</div>
</body>
</html>
