# MYCOURSESDAT

Курсовые проекты.

## СМП «Фаворит Диван» — аналитика эффективности предприятия

Папка [`semiglazov/`](semiglazov/) — веб-система на PHP 8 + MySQL/SQLite
(OpenServer Panel 6, PHP 8.4, MySQL 8.4).

Быстрый старт: положить папку в `OSPanel\home\semiglazov` → включить модули
PHP-8.4 и MySQL-8.4 → перезапустить панель → открыть
`http://semiglazov.local/install.php`.

* [`semiglazov/КАК-ЗАПУСТИТЬ.md`](semiglazov/КАК-ЗАПУСТИТЬ.md) — короткая памятка;
* [`semiglazov/README-OPENSERVER.md`](semiglazov/README-OPENSERVER.md) — подробная инструкция,
  включая разбор «проект не виден в OpenServer» и ошибку `could not find driver`;
* [`semiglazov/check.php`](semiglazov/check.php) — проверка окружения (работает без базы данных).

Сайт запускается и без MySQL: при недоступном сервере автоматически включается
резервная база SQLite (`semiglazov/data/favorit.sqlite`) с теми же таблицами и
демонстрационными данными.
