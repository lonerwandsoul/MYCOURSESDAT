<?php
/**
 * Установка базы данных в один клик.
 *
 * Открывается один раз после распаковки проекта:
 *     http://semiglazov.local/install.php
 *
 * Что делает:
 *   · ищет работающий MySQL (OpenServer) и создаёт базу favorit_db;
 *   · если MySQL не найден — создаёт резервную базу SQLite (data/favorit.sqlite);
 *   · создаёт таблицы и демонстрационные данные (май–октябрь 2025);
 *   · показывает отчёт: какие таблицы есть и сколько в них записей.
 *
 * Кнопка «Перепроверить MySQL» сбрасывает кэш поиска сервера — удобно после
 * того, как в OpenServer включили модуль MySQL-8.4.
 */

require_once __DIR__ . '/db_probe.php';

// --- Кнопки: сброс кэша поиска MySQL и повторная установка ------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    db_cache_write([]);
    header('Location: install.php?done=1');
    exit;
}

// Дальше db.php сам подключается, создаёт базу, таблицы и демо-данные.
require_once __DIR__ . '/db.php';

$config = db_config();
$tables = [
    'users' => 'Пользователи и роли (администратор, сотрудник)',
    'an_categories' => 'Категории продукции',
    'an_products' => 'Справочник продукции и цены',
    'an_channels' => 'Каналы продаж, план и затраты',
    'an_production' => 'Выпуск, брак, часы, себестоимость по цехам',
    'an_sales' => 'Продажи по каналам за месяц',
    'an_orders' => 'Портфель заказов и статусы',
    'an_defects' => 'Брак по причинам и потери',
    'an_staff' => 'Штат сотрудников и оклады',
    'an_targets' => 'Плановые и допустимые показатели',
    'an_audit' => 'Журнал действий пользователей',
];

$counts = [];
$total = 0;
foreach (array_keys($tables) as $table) {
    try {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        $total += max(0, $counts[$table]);
    } catch (Throwable $e) {
        $counts[$table] = -1;
    }
}

$isMysql = $dbInfo['engine'] === 'mysql';
$drivers = $dbInfo['drivers'];
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка базы данных — Фаворит</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #0f1117; font-family: 'Segoe UI', system-ui, sans-serif; color: #1a1f2e; }
        .install { max-width: 940px; margin: 0 auto; padding: 40px 18px 60px; }
        .card { background: #fff; border-radius: 18px; padding: 28px 30px; box-shadow: 0 24px 60px rgba(0,0,0,.35); }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .brand img { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; }
        .brand b { display: block; color: #1a1f2e; }
        .brand span { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #9aa1b3; }
        h1 { font-size: 24px; margin: 0 0 6px; }
        h2 { font-size: 16px; margin: 22px 0 10px; }
        p.sub { color: #7b8194; margin: 0 0 20px; font-size: 14px; }
        p, li { color: #3c4257; font-size: 14px; line-height: 1.65; }
        code { background: #eef2ff; color: #2f6fed; padding: 1px 6px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; margin-top: 8px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef0f6; vertical-align: top; }
        th { color: #7b8194; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .ok { background: #e8f8ef; border: 1px solid #b8e8cd; color: #0b6b3a; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .warn { background: #fff7e8; border: 1px solid #f7d9a8; color: #8a5a00; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .err { background: #fdecea; border: 1px solid #f6c6c2; color: #a61b12; padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
        .links { margin-top: 22px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .links a, .links button { display: inline-block; padding: 11px 18px; border-radius: 10px; background: #1a1f2e; color: #fff;
                   text-decoration: none; font-size: 14px; border: 0; cursor: pointer; font-weight: 600; }
        .links a.ghost, .links button.ghost { background: #eef2ff; color: #2f6fed; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; margin-top: 12px; }
        .kpi { background: #f7f9ff; border-radius: 12px; padding: 14px 16px; }
        .kpi span { display: block; font-size: 12px; color: #7b8194; text-transform: uppercase; letter-spacing: .04em; }
        .kpi b { font-size: 17px; }
        .muted { color: #7b8194; }
    </style>
</head>
<body>
<div class="install">
    <div class="card">
        <div class="brand">
            <img src="images/logo.jpg" alt="Фаворит">
            <div><span>СМП</span><b>Фаворит Диван</b></div>
        </div>
        <h1>Установка базы данных</h1>
        <p class="sub">База, таблицы и демонстрационные данные готовы — отчёт ниже</p>

        <?php if ($isMysql): ?>
            <div class="ok">
                <b>База данных готова. Движок: MySQL.</b><br>
                База <code><?= $e($config['database']) ?></code> на сервере
                <code><?= $e($dbInfo['host']) ?></code> — используется основная база OpenServer.
            </div>
        <?php else: ?>
            <div class="warn">
                <b>База данных готова. Движок: SQLite (резервная).</b><br>
                MySQL не найден<?= $dbError !== '' ? ' (' . $e($dbError) . ')' : '' ?>,
                поэтому сайт работает на файле <code><?= $e((string) ($dbInfo['file'] ?? 'data/favorit.sqlite')) ?></code>.
                Функциональность полная: все разделы, формы и отчёты работают.
                Чтобы перейти на MySQL — включите модуль <b>MySQL-8.4</b> в OpenServer
                и нажмите «Перепроверить MySQL».
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['done'])): ?>
            <div class="ok">Повторная установка выполнена: <?= $e(date('d.m.Y H:i:s')) ?>.</div>
        <?php endif; ?>

        <div class="grid">
            <div class="kpi"><span>Движок</span><b><?= $e($isMysql ? 'MySQL' : 'SQLite (резерв)') ?></b></div>
            <div class="kpi"><span>База / файл</span><b style="font-size:14px"><?= $e($isMysql ? $config['database'] : (string) ($dbInfo['file'] ?? 'data/favorit.sqlite')) ?></b></div>
            <div class="kpi"><span>Адрес</span><b style="font-size:14px"><?= $e($isMysql ? $dbInfo['host'] : 'файл на диске') ?></b></div>
            <div class="kpi"><span>Записей всего</span><b><?= number_format($total, 0, ',', ' ') ?></b></div>
        </div>

        <h2>Таблицы</h2>
        <table>
            <thead><tr><th>Таблица</th><th>Записей</th><th>Назначение</th></tr></thead>
            <tbody>
            <?php foreach ($tables as $table => $purpose): ?>
                <tr>
                    <td><code><?= $e($table) ?></code></td>
                    <td><?= $counts[$table] >= 0 ? number_format($counts[$table], 0, ',', ' ') : '—' ?></td>
                    <td class="muted"><?= $e($purpose) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Окружение</h2>
        <p class="muted" style="font-size:13px">
            PHP <b><?= $e(PHP_VERSION) ?></b> ·
            драйверы PDO: <b><?= $e($drivers ? implode(', ', $drivers) : 'нет') ?></b> ·
            каталог данных: <b><?= $e((string) (db_writable_dir() ?? 'недоступен для записи')) ?></b>
        </p>

        <div class="links">
            <a href="analytics.php">Открыть аналитику</a>
            <a class="ghost" href="login.php">Войти (manager / manager123)</a>
            <a class="ghost" href="check.php">Проверка окружения</a>
            <form method="post" style="margin:0">
                <button class="ghost" type="submit">Перепроверить MySQL</button>
            </form>
        </div>

        <p class="muted" style="margin-top:18px;font-size:12.5px">
            Полный SQL-дамп <code>database/favorit_db.sql</code> можно импортировать в phpMyAdmin вручную —
            данные в нём совпадают с теми, что создаются автоматически.
            Параметры подключения — <code>config.php</code> и <code>config.local.php</code>.
        </p>
    </div>
</div>
</body>
</html>
