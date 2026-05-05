# CLAUDE.md
# Языковая инструкция
Всегда отвечай на русском языке. Используй русский для всех ответов, объяснений и комментариев в коде, если явно не попросят иначе.

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SmartMatch is a **Nostro account reconciliation** (квитование) system built on **Yii 2 Basic** (PHP 7.4+) with **PostgreSQL 17** and **Vue.js** frontend embedded in PHP views.

## Обязательное правило: обновление CLAUDE.md
При любых значимых изменениях в проекте (новый модуль, новый контроллер, новая подсистема, изменение архитектуры, новые команды, изменение стека) — **всегда обновляй этот файл**, чтобы он отражал актуальное состояние проекта.

## Обязательное правило: обновление README.md
При любых значимых изменениях в функционале, возможностях, структуре проекта или стеке технологий — **всегда обновляй README.md**, чтобы он отражал актуальное описание проекта. Это включает: новые разделы/страницы, изменение ключевых возможностей, добавление/удаление технологий, изменение структуры каталогов, новые команды для установки/запуска.


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
vendor/bin/codecept run                        # unit + functional
vendor/bin/codecept run unit                   # unit only
vendor/bin/codecept run functional             # functional only
vendor/bin/codecept run unit tests/unit/SomeTest.php  # single test file
vendor/bin/codecept run --coverage --coverage-html    # with coverage
```

Test config: `codeception.yml` (uses `config/test.php` and `config/test_db.php`).

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
| `NostroEntry` | `nostro_entries` | Core transaction records. Key fields: `ls` (L=Ledger/S=Statement), `dc` (Debit/Credit), `match_status` (U/M/I), `match_id` |
| `MatchingRule` | `matching_rules` | Rules for auto-matching: `pair_type` (LS/LL/SS), match criteria flags, `priority` |
| `Account` | `accounts` | Nostro bank accounts. `is_suspense` distinguishes suspense accounts (used in INV section). FK `pool_id` → `account_pools` |
| `Category` | `categories` | Категории — верхний уровень группировки в сайдбаре выверки |
| `AccountPool` | `account_pools` | Ностро-банки. Привязываются напрямую к категории через `category_id` (nullable). `accounts.pool_id` → `account_pools` |
| `NostroBalance` | `nostro_balance` | Closing balances (L/S) by account and date — used in recon report |
| `NostroEntryAudit` | `nostro_entry_audit` | Full audit log of all NostroEntry changes (create/update/delete/archive) |
| `NostroEntryArchive` | `nostro_entries_archive` | Matched entries moved to archive; has `original_id`, `archived_at`, `expires_at` |
| `ArchiveSettings` | `archive_settings` | Per-company archive settings (`archive_after_days`, `retention_years`) |
| `UserPreference` | `user_preferences` | Персональные настройки UI (JSONB). Ключи whitelist'ятся в `UserPreferenceController`. Текущий ключ: `entries_table_columns` — видимость и ширина колонок таблицы выверки |
| `Currency` | `currencies` | Справочник валют (общесистемный, не привязан к компании). ISO 4217. Поля: `code`, `name`, `symbol`, `is_active`, `sort_order`. Управление — на странице `/references` |
| `Country` | `countries` | Справочник стран (общесистемный). ISO 3166-1 alpha-2 (+ опц. alpha-3). Поля: `code`, `code3`, `name`, `is_active`, `sort_order`. Управление — на странице `/references` |
| — | `git_no_stro_extract_custom` | Сырой приёмник выписок FCC12 (построчный разбор: баланс или транзакция). После merge очищается |
| — | `tds_status` | Статус пакетов выписок. `type='FCC12' + is_merged=false` → забирает `fcc-merge/run` |

### FCC12 ingestion (`commands/FccMergeController`)
`php yii fcc-merge/run` — для каждой `tds_status` с `type='FCC12'` и `is_merged=false` в одной транзакции:
1. Читает строки `git_no_stro_extract_custom` c тем же `extract_no`.
2. Строки-транзакции (`amount IS NOT NULL`) → `nostro_entries` (ls=L, section=NRE, source=FCC12, company_id=1).
3. Строки-балансы (`opening_bal`/`closing_bal`) → `nostro_balance` (ls_type=L, section=NRE, source=FCC12).
4. Счёт находится через `accounts.name = git_no_stro_extract_custom.cbr_cc_no` (company_id=1).
5. В `nostro_balance`/`nostro_entries` проставляются `extract_no` и `line_no` — трассировка до исходной строки.
6. `tds_status.is_merged := true`, исходные строки `git_no_stro_extract_custom` удаляются. Commit.

### Matching logic (`services/MatchingService.php`)
- **Manual matching** (`matchManual`): takes array of entry IDs, validates balance (Ledger sum = Statement sum for mixed L+S sets), assigns a `MTCH` + 8-char hex `match_id`.
- **Auto-matching** (`autoMatch`): runs all active `MatchingRule`s in priority order. Each rule uses a CTE with `ROW_NUMBER() OVER (PARTITION BY ...)` to find unique one-to-one pairs in a single SQL query. Supports `cross_id_search` (any ID field on one side matches any ID field on the other). Accepts optional `$onProgress` callback for console output.
- **Step-by-step auto-matching** (UI): `autoMatchStart` → returns `job_id` + rules list, then client calls `autoMatchStep` per rule. State stored in FileCache. Provides real-time progress in UI.
- **Unmatch** (`unmatch`): clears `match_id` and sets `match_status = U` for all entries sharing a `match_id`.
- **Match ID uniqueness**: `generateMatchId()` uses `random_bytes(4)` + DB check loop (nostro_entries + archive) to guarantee no collisions.
- **Console command** (`commands/AutoMatchController`): `php yii auto-match/run` — runs auto-matching for all or specific company/account with progress output.

### Pool filtering (`NostroEntryController::actionList`)
Параметр `pool_id` в `GET /nostro-entry/list` — это `AccountPool.id`. Фильтрация: `accounts.pool_id = pool_id`. Дополнительные фильтры строки — через query-параметр `filters` (JSON).

### Cross-bank reconciliation page (`AllNostroController`)
Standalone страница `/all-nostro` — "Выверка по всем ностро-банкам". Показывает записи `NostroEntry` со всех ностро-банков компании с полным набором фильтров (как на главной выверке) + **мультивыбор ностро-банков** (`filters.pool_ids[]`) и выбор счёта (`filters.account_id`). Основная страница выверки (`site/index`) больше не содержит фильтров по ностро-банку/счёту — они перенесены сюда.

### Reconciliation Report / Раккорд (`ReconReportController`)
Страница `/recon-report` формирует отчет **Reconciliation Report** для раздела NRE вручную по выбранной категории или ностро-банку. Интерфейс не использует выбор счета: категория разворачивается в ностро-банки через `account_pools.category_id`, ностро-банк — во все счета `accounts.pool_id`. Одна карточка отчета соответствует одному ностро-банку и агрегирует все его Ledger/Statement-счета.

Правила отчета:
- учитываются только несквитованные `nostro_entries` с пустым `match_id` и `match_status = U`;
- режим даты включает предыдущий день и записи на `Date Reconciliation` на момент формирования;
- режим периода включает записи за `date_from`…`date_to`, а `Date Reconciliation` берется как `date_to`;
- `Closing Balance` суммируется строго из `nostro_balance` по всем счетам ностро-банка, `section=NRE`, `ls_type=L/S`, `value_date=Date Reconciliation`;
- `Trial Balance = Closing Balance - Outstanding Items`;
- серверный экспорт: `/recon-report/export?format=xlsx|pdf...`; для нескольких отчетов возвращается ZIP, для одного отчета — файл `ReconReport_<Nostro Bank>_<Date>.xlsx|pdf`;
- XLSX строится через `phpoffice/phpspreadsheet` на основе `web/reconciliation_report_template.xlsx`, PDF — через `mpdf/mpdf`.

### Reference dictionaries (`ReferenceController`)
Standalone-страница `/references` — управление общесистемными справочниками валют и стран. Данные **не привязаны к компании** — общие для всей системы.

- Таблицы: `currencies` (ISO 4217 + символ + активность + порядок) и `countries` (ISO 3166-1 alpha-2/alpha-3 + название + активность + порядок).
- Начальное наполнение — миграция `m260506_120000_create_currencies_and_countries` (12 валют и 38 стран).
- API: `/reference/currencies`, `/reference/currency-create|update|delete`, `/reference/countries`, `/reference/country-create|update|delete`.
- Активные значения публикуются во все Vue-инстансы через `window.AppDictionaries.{currencies,countries}` (заполняется в `views/layouts/_vue-scripts.php`).
- Глобальный mixin (`web/js/app/common.js`) отдаёт computed-свойства `dictCurrencies`, `dictCountries`, `dictCurrencyCodes` — они доступны во всех Vue-компонентах без явной привязки.
- Все формы и фильтры (счета, балансы, записи выверки, архив) собирают select-ы валют/стран из этих справочников; захардкоженные списки `['USD','EUR','RUB',...]` удалены.

### Hierarchy: Category → AccountPool
- **Category** (`CategoryController`): верхний уровень навигации в сайдбаре. CRUD на странице выверки.
- **AccountPool** (`AccountPoolController`): ностро-банки. Привязываются напрямую к категории через `account_pools.category_id` (nullable, FK `ON DELETE SET NULL`). Standalone страница `/nostro-banks` — CRUD ностро-банков, привязка к категории, привязка/отвязка счетов (`Account.pool_id`).
- **Сайдбар выверки**: отображает дерево «Категория → Ностро-банки этой категории». Выбор ностро-банка фильтрует записи в `nostro_entries` через `accounts.pool_id`.
- Группы (`Group`) и фильтры группы (`GroupFilter`) удалены в миграции `m260505_120000_link_account_pool_to_category` — связь категория↔ностро-банк теперь прямая.

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
- Vue mixins (`web/js/app/mixins/`): `CategoriesMixin`, `PoolsMixin`, `EntriesMixin`, `MatchingMixin`, `BalanceMixin`, `ArchiveMixin`, `ModalsMixin`, `StatePersistenceMixin`.

### Стартеры Vue (`web/js/app/page-*.js`)
Каждый стартер создаёт свой Vue-инстанс, но только если находит соответствующий корневой `<div id="...">`. Все три файла грузятся через `AppAsset`, но выполняется только тот, чей корень есть в DOM.

| Стартер | Корень | Mixins |
|---|---|---|
| `page-entries.js` | `#entries-app` | Modals, Categories, Pools, Entries, Matching, StatePersistence |
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
Restore is group-based: UI first calls `GET /archive/restore-preview?id=...` and shows all archived rows with the same `match_id`; `POST /archive/restore` restores all those rows in one transaction and removes them from `nostro_entries_archive`.

### Audit trail
`NostroEntry` hooks (`afterSave`, `beforeDelete`) automatically call `NostroEntryAudit::log()`. `NostroEntryController::actionHistory` reconstructs full record snapshots at each point in time by replaying audit events.

## Configuration

- Database: `config/db.php` — PostgreSQL 17, host `PostgreSQL-17`, port `5432`, dbname `smartmatch`
- Test DB: `config/test_db.php`
- App params: `config/params.php`
- RBAC: `yii\rbac\DbManager` with standard auth tables
- Dev tools (Gii, Debug) enabled when `YII_ENV_DEV`
