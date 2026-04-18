# Экспорт шаблона CRM-портала Bitrix24

Файл [bitrix24_crm_blueprint_export.php](/Users/yi/bufer/module.move.bx/bitrix24_crm_blueprint_export.php) выгружает в JSON:

- воронки и стадии сделок;
- статусы лидов;
- цвета стадий и статусов;
- шаблоны бизнес-процессов;
- роботов и триггеры CRM;
- пользовательские поля лидов, сделок, контактов и компаний;
- настройки пользовательских полей и значения полей типа `Список`.

## Как запускать

Вариант 1, через браузер под администратором портала:

```text
https://your-portal.local/local/tools/bitrix24_crm_blueprint_export.php
```

Вариант 2, с указанием своего пути для JSON:

```text
https://your-portal.local/local/tools/bitrix24_crm_blueprint_export.php?output=/upload/crm_blueprints/my-export.json
```

Вариант 3, через CLI внутри портала:

```bash
php /home/bitrix/www/local/tools/bitrix24_crm_blueprint_export.php --output=/home/bitrix/www/upload/crm_blueprints/my-export.json
```

Если путь не передан, файл будет создан автоматически в каталоге `/upload/crm_blueprints/`.

## Что важно

- скрипт рассчитан на коробочный Битрикс24;
- запускать лучше под администратором;
- структура JSON сделана так, чтобы потом можно было строить отдельный импортёр под тиражирование порталов.

## Импорт

Файл [bitrix24_crm_blueprint_import.php](/Users/yi/bufer/module.move.bx/bitrix24_crm_blueprint_import.php) разворачивает новый портал из ранее сохраненного JSON.

Что делает импортёр:

- создает или обновляет направления сделок;
- создает или обновляет стадии и статусы;
- создает пользовательские поля;
- при конфликте `FIELD_NAME` создает новое поле с суффиксом вида `_X1`, `_X2` и подменяет ссылки на это поле в шаблонах БП, роботах и триггерах;
- пытается сохранить исходные ID значений списков, чтобы снизить риск поломки БП;
- импортирует шаблоны бизнес-процессов;
- импортирует роботов и триггеры.

### Как запускать импорт

Сначала безопасная проверка без записи в портал:

```bash
php /home/bitrix/www/local/tools/bitrix24_crm_blueprint_import.php --input=/home/bitrix/www/upload/crm_blueprints/my-export.json --mode=dry-run
```

Реальный импорт:

```bash
php /home/bitrix/www/local/tools/bitrix24_crm_blueprint_import.php --input=/home/bitrix/www/upload/crm_blueprints/my-export.json --mode=apply
```

Через браузер:

```text
https://your-portal.local/local/tools/bitrix24_crm_blueprint_import.php?input=/upload/crm_blueprints/my-export.json&mode=dry-run
https://your-portal.local/local/tools/bitrix24_crm_blueprint_import.php?input=/upload/crm_blueprints/my-export.json&mode=apply
```

После запуска создается JSON-отчет в `/upload/crm_blueprints/`:

- созданных и обновленных полей;
- коллизий `FIELD_NAME`;
- подмен имен полей;
- подмен стадий;
- предупреждений по значениям списков;
- результатов импорта БП и автоматизации.

### Практические замечания

- сначала лучше всегда запускать `dry-run`;
- импортёр рассчитан прежде всего на новые или почти пустые порталы;
