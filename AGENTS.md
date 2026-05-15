# AGENTS.md
# Языковая инструкция
Всегда отвечай на русском языке. Используй русский для всех ответов, объяснений и комментариев в коде, если явно не попросят иначе.

This file provides guidance to coding agents when working with code in this repository.

## Project Overview

SmartMatch is a **Nostro account reconciliation** (квитование) system built on **Yii 2 Basic** (PHP 7.4+) with **PostgreSQL 17** and **Vue.js** frontend embedded in PHP views.

## Project documentation

- `docs/USER_GUIDE.md` — подробное пользовательское руководство по рабочему процессу в системе.
- `docs/FAQ.md` — пользовательский FAQ по выверке, автоквитованию, балансам, раккорду и архиву.
- `docs/DEVELOPER_GUIDE.md` — руководство разработчика: архитектура, соглашения, команды, тесты и сопровождение.
- `docs/TESTING.md` — документация по тестам: тестовая БД, команды запуска, назначение suites и карта проверок.

## Обязательное правило: обновление AGENTS.md
При любых значимых изменениях в проекте (новый модуль, новый контроллер, новая подсистема, изменение архитектуры, новые команды, изменение стека) — **всегда обновляй этот файл**, чтобы он отражал актуальное состояние проекта.

## Обязательное правило: обновление CLAUDE.md
Если в проекте сохраняется и поддерживается `CLAUDE.md`, то при тех же значимых изменениях **обновляй и его тоже**, чтобы инструкции для разных агентных инструментов не расходились.

## Commands

### Database migrations
```bash
php yii migrate              # apply all pending migrations
php yii migrate/down         # roll back last migration
php yii migrate/create name  # create new migration
```

### Auto-matching (console)
```bash
php yii auto-match/run                           # all companies
php yii auto-match/run --company=1               # specific company
php yii auto-match/run --company=1 --account=5   # specific account
php yii auto-match/status                        # show stats without running
php yii auto-match/status --company=1            # stats for one company
```

### FCC12 merge (console)
```bash
php yii fcc-merge/run   # перенос выписок FCC12 из git_no_stro_extract_custom
                        # в nostro_balance / nostro_entries (см. FccMergeController)
```

### Console entry point
```bash
php yii <command>/<action>
```

### Tests (Codeception)
```bash
php tests/bin/yii migrate --interactive=0      # prepare/update smartmatch_test schema
vendor/bin/codecept build                      # rebuild actors after suite/module changes
vendor/bin/codecept run                        # unit + functional
vendor/bin/codecept run unit                   # unit only
vendor/bin/codecept run functional             # functional only
vendor/bin/codecept run unit tests/unit/models/NostroEntryTest.php  # single test file
vendor/bin/codecept run functional tests/functional/MatchingApiCest.php  # single API test file
vendor/bin/codecept run --coverage --coverage-html    # with coverage
```

Test config: `codeception.yml` (uses `config/test.php` and `config/test_db.php`). Default test DB is PostgreSQL `smartmatch_test`; override with `SMARTMATCH_TEST_DSN`, `SMARTMATCH_TEST_DB_USERNAME`, `SMARTMATCH_TEST_DB_PASSWORD`. See `docs/TESTING.md` for what each test file checks.

### Install dependencies
```bash
composer install
```

## Architecture

### Multi-tenancy
Every user belongs to a `Company`. All data is strictly scoped by `company_id`. Controllers retrieve the current company via a `cid()` helper:
```php
$u = Yii::$app->user->identity;
return ($u && $u->company_id) ? (int)$u->company_id : null;
```
All DB queries must include `company_id` scoping.

### Controller conventions
- All feature controllers extend `BaseController` (`controllers/BaseController.php`), which enforces authentication via `AccessControl`.
- All API actions set `Yii::$app->response->format = Response::FORMAT_JSON` and return arrays directly.
- CSRF validation is disabled in all API controllers: `$this->enableCsrfValidation = false` in `beforeAction`.
- Global access control: `app\components\AccessControl` filter registered as `'as access'` in `config/web.php` — redirects guests everywhere except `site/login`, `site/signup`, `site/error`, `site/index`.

### Domain model

| Model | Table | Purpose |
|---|---|---|
| `NostroEntry` | `nostro_entries` | Core transaction records. Key fields: `ls` (L=Ledger/S=Statement), `dc` (Debit/Credit), `match_status` (U/M/I), `match_id`, `matched_at` |
| `MatchingRule` | `matching_rules` | Rules for auto-matching: `pair_type` (LS/LL/SS), match criteria flags, `priority` |
| `Account` | `accounts` | Nostro bank accounts. `is_suspense` distinguishes suspense accounts (used in INV section) |
| `Category` | `categories` | Категории — верхний уровень группировки (ранее `account_groups`) |
| `Group` | `groups` | Группы счетов с динамическими фильтрами (ранее `account_pools`). FK `category_id` → `categories` |
| `GroupFilter` | `group_filters` | Фильтры группы (ранее `account_pool_filters`). Поддерживает фильтрацию по `account_pool_id`. FK `group_id` → `groups` |
| `AccountPool` | `account_pools` | Ностро-банки (технические пулы счетов). `accounts.pool_id` → `account_pools` |
| `NostroBalance` | `nostro_balance` | Closing balances (L/S) by account and date — used in recon report |
| `NostroEntryAudit` | `nostro_entry_audit` | Full audit log of all NostroEntry changes (create/update/delete/archive) |
| `NostroEntryArchive` | `nostro_entries_archive` | Matched entries moved to archive; has `original_id`, `matched_at`, `archived_at`, `expires_at` |
| `ArchiveSettings` | `archive_settings` | Per-company archive settings (`archive_after_days`, `retention_years`) |
| `UserPreference` | `user_preferences` | Персональные настройки UI (JSONB). Ключи whitelist'ятся в `UserPreferenceController`. Текущий ключ: `entries_table_columns` — видимость и ширина колонок таблицы выверки |
| — | `git_no_stro_extract_custom` | Сырой приёмник выписок FCC12 (построчный разбор: баланс или транзакция). После merge очищается |
| — | `tds_status` | Статус пакетов выписок. `type='FCC12' + is_merged=false` → забирает `fcc-merge/run` |

### Money precision
Денежные поля используют `decimal(20,2)`: `nostro_entries.amount`, `nostro_entries_archive.amount`, `nostro_balance.opening_balance`, `nostro_balance.closing_balance`. UI и модели должны валидировать максимум 18 цифр до десятичного разделителя и 2 после; не приводить пользовательский ввод к `float` до сохранения, чтобы не терять точность.

### FCC12 ingestion (`commands/FccMergeController`)
`php yii fcc-merge/run` — для каждой `tds_status` с `type='FCC12'` и `is_merged=false` в одной транзакции:
1. Читает строки `git_no_stro_extract_custom` c тем же `extract_no`.
2. Строки-транзакции (`amount IS NOT NULL`) → `nostro_entries` (ls=L, section=NRE, source=FCC12, company_id=1).
3. Строки-балансы (`opening_bal`/`closing_bal`) → `nostro_balance` (ls_type=L, section=NRE, source=FCC12).
4. Счёт находится через `accounts.name = git_no_stro_extract_custom.cbr_cc_no` (company_id=1).
5. В `nostro_balance`/`nostro_entries` проставляются `extract_no`, `line_no` и `branch_code` — трассировка до исходной строки и филиала.
6. После каждого `batchInsert` создаётся batch-аудит: `nostro_entry_audit` (`action=create`) для транзакций и `nostro_balance_audit` (`action=import`) для балансов, `user_id=0`, `reason='Импорт FCC12'`.
7. `tds_status.is_merged := true`, исходные строки `git_no_stro_extract_custom` удаляются. Commit.

### Matching logic (`services/MatchingService.php`)
- **Manual matching** (`matchManual`): takes array of entry IDs, validates balance (Ledger sum = Statement sum for mixed L+S sets), assigns a `MTCH` + 8-char hex `match_id` and sets `matched_at`.
- **Auto-matching** (`autoMatch`): runs all active `MatchingRule`s in priority order. Each rule uses a CTE with `ROW_NUMBER() OVER (PARTITION BY ...)` to find unique one-to-one pairs in a single SQL query. Supports `cross_id_search` (any ID field on one side matches any ID field on the other). Accepts optional `$onProgress` callback for console output.
- **Step-by-step auto-matching** (UI): `autoMatchStart` → returns `job_id` + rules list, then client calls `autoMatchStep` per rule. State stored in FileCache. Provides real-time progress in UI.
- **Unmatch** (`unmatch`): clears `match_id`, `matched_at` and sets `match_status = U` for all entries sharing a `match_id`.
- **Match ID uniqueness**: `generateMatchId()` uses `random_bytes(4)` + DB check loop (nostro_entries + archive) to guarantee no collisions.
- **Console command** (`commands/AutoMatchController`): `php yii auto-match/run` — runs auto-matching for all or specific company/account with progress output.

### Group filtering (two-step, `NostroEntryController::actionList`)
When filtering entries by `group_id` (formerly `pool_id`):
1. Apply `GroupFilter` conditions that target `accounts` table (account-level fields, including `account_pool_id` → `accounts.pool_id`) → get matching `account_id` list.
2. Apply `GroupFilter` conditions that target `nostro_entries` table (entry-level fields) → filter the main query.

### Cross-bank reconciliation page (`AllNostroController`)
Standalone страница `/all-nostro` — "Выверка по всем ностро-банкам". Показывает записи `NostroEntry` со всех ностро-банков компании с полным набором фильтров (как на главной выверке) + **мультивыбор ностро-банков** (`filters.pool_ids[]`) и выбор счёта (`filters.account_id`). Основная страница выверки (`site/index`) больше не содержит фильтров по ностро-банку/счёту — они перенесены сюда.

### Reconciliation Report / Раккорд (`ReconReportController`)
Страница `/recon-report` формирует отчет **Reconciliation Report** для раздела NRE вручную по выбранной категории или ностро-банку. Интерфейс не использует выбор группы и счета: категория разворачивается в ностро-банки через активные `Group`/`GroupFilter`, ностро-банк — во все счета `accounts.pool_id`. Одна карточка отчета соответствует одному ностро-банку и агрегирует все его Ledger/Statement-счета.

Правила отчета:
- учитываются только несквитованные `nostro_entries` с пустым `match_id` и `match_status = U`;
- режим даты включает предыдущий день и записи на `Date Reconciliation` на момент формирования;
- режим периода включает записи за `date_from`…`date_to`, а `Date Reconciliation` берется как `date_to`;
- `Closing Balance` суммируется строго из `nostro_balance` по всем счетам ностро-банка, `section=NRE`, `ls_type=L/S`, `value_date=Date Reconciliation`;
- `Trial Balance = Closing Balance - Outstanding Items`;
- серверный экспорт: `/recon-report/export?format=xlsx|pdf...`; для нескольких отчетов возвращается ZIP, для одного отчета — файл `ReconReport_<Nostro Bank>_<Date>.xlsx|pdf`;
- XLSX строится через `phpoffice/phpspreadsheet` на основе `web/reconciliation_report_template.xlsx`, PDF — через `mpdf/mpdf`.

### Hierarchy: Category → Group → GroupFilter
- **Category** (`CategoryController`): верхний уровень навигации в сайдбаре
- **Group** (`GroupController`): набор фильтров для выборки записей. Принадлежит категории через `category_id`
- **GroupFilter**: условие фильтрации (поле, оператор, значение). Поддерживает `account_pool_id` для фильтрации по ностро-банку
- **AccountPool**: отдельная сущность — ностро-банки. Standalone страница `/nostro-banks` (`AccountPoolController`). CRUD + привязка/отвязка счетов (`Account.pool_id` nullable). Не связан с категориями/группами напрямую

### Frontend
- Main layout: `views/layouts/main.php` — Bootstrap 5 navbar + тонкий диспетчер по типу страницы (guest / no-company / entries-page / other app / standalone).
- Каждая страница с бизнес-логикой поднимает **свой изолированный Vue-инстанс** по id корневого элемента:
  - `/site/index` → `#entries-app` (выверка, с sidebar)
  - `/balance` → `#balance-app`
  - `/archive` → `#archive-app`
  - `/all-nostro` → `#all-nostro-app`
  - `/recon-report`, `/nostro-banks`, `/accounts` — каждая со своим инстансом внутри view
- Общая инфраструктура: `web/js/app/common.js` (глобальные методы `recordText`, `formatAmount` через `Vue.mixin`), `datepicker.js` (глобальная директива `v-datepicker` и метод `fmtDate`), `api.js` (`SmartMatchApi` поверх axios), `state-storage.js`.
- `views/layouts/_vue-scripts.php` заполняет `window.AppRoutes` и `window.AppConfig` — рендерится во всех залогиненных страницах.
- Vue mixins (`web/js/app/mixins/`): `CategoriesMixin`, `GroupsMixin`, `EntriesMixin`, `MatchingMixin`, `BalanceMixin`, `ArchiveMixin`, `ModalsMixin`, `StatePersistenceMixin`.

### Стартеры Vue (`web/js/app/page-*.js`)
Каждый стартер создаёт свой Vue-инстанс, но только если находит соответствующий корневой `<div id="...">`. Все три файла грузятся через `AppAsset`, но выполняется только тот, чей корень есть в DOM.

| Стартер | Корень | Mixins |
|---|---|---|
| `page-entries.js` | `#entries-app` | Modals, Categories, Groups, Entries, Matching, StatePersistence |
| `page-balance.js` | `#balance-app` | Modals, Balance |
| `page-archive.js` | `#archive-app` | Modals, Archive |

Страница `/all-nostro` (`views/all-nostro/index.php`) использует свой собственный инстанс с inline-скриптом и не полагается на эти стартеры.

### Views: декомпозиция секций
Старый монолитный `_content.php` удалён. Вместо него:
- `views/site/entries.php` — сайдбар + `_section-entries.php` + `_modals.php` (выверка)
- `views/nostro-balance/page.php` — `_section-balance.php` (баланс)
- `views/archive/page.php` — `_section-archive.php` (архив)

Секции `views/layouts/_section-*.php` — только разметка без `v-show`, они включаются каждая на своей странице.

Общие partial'ы в `views/partials/` переиспользуются между страницей выверки и `all-nostro`:
- `_entries-filters.php` — панель фильтров. Параметры: `showMultiPoolFilter`, `showAccountFilter`, `poolSelectId`, `accountSelectId`. По умолчанию пул/счёт скрыты (как на главной выверке); `all-nostro` передаёт `true`.
- `_entries-detail-modal.php` — модалка деталей записи. Требует во Vue-инстансе: `data.detailEntry`, методы `closeEntryDetail`, `formatAmount`, `fmtDate`.

### Archive process
Archiving is batch-processed: client calls `POST /archive/run-batch` repeatedly (300 records per call) until `is_finished = true`. Uses raw SQL `batchInsert` + `DELETE WHERE id = ANY(ARRAY[...])` for performance.
Archive candidates are matched rows with `match_status = M`, non-null `match_id`, non-null `matched_at`, and `matched_at` older than `archive_settings.archive_after_days`; `updated_at` is not used for archive aging because restore/comments update it.
Restore is group-based: UI first calls `GET /archive/restore-preview?id=...` and shows all archived rows with the same `match_id`; `POST /archive/restore` restores all those rows in one transaction and removes them from `nostro_entries_archive`.
Restore preserves `matched_at` from `nostro_entries_archive`, so the original matching date survives archive round-trips.

### Audit trail
`NostroEntry` hooks (`afterSave`, `beforeDelete`) automatically call `NostroEntryAudit::log()`. `NostroEntryController::actionHistory` reconstructs full record snapshots at each point in time by replaying audit events. For restored entries it also follows the `restore.old_values.original_id` link back to the original archived entry history, so the active restored row shows its pre-archive audit chain. Archive history is read from `nostro_entry_audit` by `nostro_entries_archive.original_id` and also follows `restore.old_values.original_id` across restore/archive cycles; `nostro_entry_audit.entry_id` intentionally has no FK to `nostro_entries`, otherwise archive/delete would null the audit link. `ArchiveController::actionRunBatch` writes explicit `archive` audit rows because archiving uses raw `batchInsert` + `DELETE`; `ArchiveController::actionRestore` writes explicit `restore` audit rows for the newly restored active entry and suppresses the technical create audit for the new physical row. FCC12 merge uses raw `batchInsert` for performance, so `commands/FccMergeController` writes audit rows explicitly after each flush.

## Configuration

- Database: `config/db.php` — PostgreSQL 17, host `PostgreSQL-17`, port `5432`, dbname `smartmatch`
- Test DB: `config/test_db.php` — PostgreSQL `smartmatch_test` by default; never run tests against `smartmatch`
- App params: `config/params.php`
- RBAC: `yii\rbac\DbManager` with standard auth tables
- Dev tools (Gii, Debug) enabled when `YII_ENV_DEV`
