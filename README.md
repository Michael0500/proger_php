# SmartMatch

SmartMatch — система выверки Nostro-счетов на Yii 2 Basic, PostgreSQL и Vue.js во views.

## Основные разделы

- Выверка записей Ledger/Statement с ручным и автоматическим квитованием.
- Балансы Nostro-счетов (`nostro_balance`) для Ledger и Statement.
- Архив сквитованных записей с восстановлением всей группы строк по `match_id`.
- Выверка по всем ностро-банкам (`/all-nostro`).
- Раккорд / Reconciliation Report (`/recon-report`) для NRE.

## Сайдбар выверки

В сайдбаре главной выверки (`/site/index`) отображается дерево «Категория → Ностро-банки». Каждый ностро-банк (`account_pools`) привязывается напрямую к категории (`account_pools.category_id`). Промежуточная сущность «Группа» с фильтрами больше не используется — выбор ностро-банка в сайдбаре фильтрует записи через `accounts.pool_id`.

Управление ностро-банками и привязкой к категории — на странице `/nostro-banks`.

## Раккорд

Страница `/recon-report` формирует отчет по выбранной категории или ностро-банку. Категория разворачивается в ностро-банки через `account_pools.category_id`. Одна карточка отчета соответствует одному ностро-банку и агрегирует все его Ledger/Statement-счета.

Отчет включает:

- несквитованные записи `nostro_entries` без `Match_ID`;
- данные за предыдущий день и за Date Reconciliation на момент формирования либо произвольный период;
- Closing Balance из `nostro_balance` строго на Date Reconciliation, суммарно по счетам ностро-банка;
- Outstanding Items, Trial Balance и детализацию Ledger/Statement Debit/Credit;
- экспорт каждого отчета в PDF и XLSX;
- ZIP-архив при выгрузке набора отчетов.

XLSX строится на основе `web/reconciliation_report_template.xlsx`. PDF формируется серверно через mPDF.

## Установка

```bash
composer install
```

Для окружений PHP 8.2 с текущим lock-файлом может потребоваться установка с игнорированием PHP platform requirement из-за dev-зависимости Codeception:

```bash
composer install --ignore-platform-req=php
```

## Миграции

```bash
php yii migrate
php yii migrate/down
php yii migrate/create name
```

## Консольные команды

```bash
php yii auto-match/run
php yii auto-match/status
php yii fcc-merge/run
```
