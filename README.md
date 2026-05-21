# SmartMatch

SmartMatch — система выверки Nostro-счетов на Yii 2 Basic, PostgreSQL и Vue.js во views.

## Основные разделы

- Выверка записей Ledger/Statement с ручным и автоматическим квитованием.
- Балансы Nostro-счетов (`nostro_balance`) для Ledger и Statement.
- Архив сквитованных записей с восстановлением всей группы строк по `match_id`.
- Выверка по всем ностро-банкам (`/all-nostro`).
- Раккорд / Reconciliation Report (`/recon-report`) для NRE.
- Справочники валют и стран (`/references`) — общесистемные, используются во всех формах.

## Документация

- [Руководство пользователя](docs/USER_GUIDE.md) — подробный рабочий процесс в системе.
- [FAQ](docs/FAQ.md) — ответы на частые вопросы по выверке, автоквитованию, балансу, раккорду и архиву.
- [Руководство разработчика](docs/DEVELOPER_GUIDE.md) — архитектура, соглашения разработки, тесты и типовые сценарии поддержки.
- [Тестирование](docs/TESTING.md) — команды запуска, тестовая БД и карта проверок по unit/functional suites.

Денежные поля записей и балансов хранятся как `decimal(20,2)`: до 18 цифр до десятичного разделителя и 2 после. Формы добавления/редактирования проверяют этот предел до сохранения.

При квитовании записи получают отдельную дату `matched_at`. Архивация считает срок по этой дате, а не по `updated_at`, поэтому комментарии, правки и восстановление из архива не сбрасывают возраст сквитованной записи.

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

## Справочники

Страница `/references` управляет общесистемными справочниками:

- **Валюты** (`currencies`): код ISO 4217, название, символ, флаг активности, порядок.
- **Страны** (`countries`): код ISO 3166-1 alpha-2 и alpha-3, название, флаг активности, порядок.

Начальное наполнение (валюты и страны, встречающиеся в системе) добавляется миграцией `m260506_120000_create_currencies_and_countries`.

Все формы и фильтры (счета, балансы, записи выверки, архив) подтягивают значения из этих справочников через `window.AppDictionaries`. Глобальный Vue-mixin (`web/js/app/common.js`) отдаёт `dictCurrencies` / `dictCountries` всем компонентам без явной привязки.

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

`fcc-merge/run` переносит строки FCC12 в `nostro_entries` и `nostro_balance` пакетами, сохраняет трассировку `extract_no`, `line_no`, `branch_code` и после каждой пакетной вставки пишет аудит: `nostro_entry_audit` (`create`) и `nostro_balance_audit` (`import`).

История архивных записей подтягивается из `nostro_entry_audit` по `nostro_entries_archive.original_id` и проходит назад по `restore.old_values.original_id` после повторных восстановлений/архиваций. При архивировании дополнительно пишется событие `archive`, при восстановлении в активные записи — событие `restore`. История восстановленной активной строки подтягивает старую цепочку аудита через `restore.old_values.original_id`. `nostro_entry_audit.entry_id` не связан FK с живой таблицей `nostro_entries`, чтобы история не теряла связь после удаления активной строки.

`archive/run` и `/archive/run-batch` архивируют только строки с `match_status = M`, непустым `match_id` и `matched_at` старше настройки `archive_after_days`. При восстановлении `matched_at` переносится обратно из `nostro_entries_archive`.
