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
| `Account` | `accounts` | Nostro bank accounts. `is_suspense` distinguishes suspense accounts (used in INV section) |
| `Category` | `categories` | Категории — верхний уровень группировки (ранее `account_groups`) |
| `Group` | `groups` | Группы счетов с динамическими фильтрами (ранее `account_pools`). FK `category_id` → `categories` |
| `GroupFilter` | `group_filters` | Фильтры группы (ранее `account_pool_filters`). Поддерживает фильтрацию по `account_pool_id`. FK `group_id` → `groups` |
| `AccountPool` | `account_pools` | Ностро-банки (технические пулы счетов). `accounts.pool_id` → `account_pools` |
| `NostroBalance` | `nostro_balance` | Closing balances (L/S) by account and date — used in recon report |
| `NostroEntryAudit` | `nostro_entry_audit` | Full audit log of all NostroEntry changes (create/update/delete/archive) |
| `NostroEntryArchive` | `nostro_entries_archive` | Matched entries moved to archive; has `original_id`, `archived_at`, `expires_at` |
| `ArchiveSettings` | `archive_settings` | Per-company archive settings (`archive_after_days`, `retention_years`) |
| `UserPreference` | `user_preferences` | Персональные настройки UI (JSONB). Ключи whitelist'ятся в `UserPreferenceController`. Текущий ключ: `entries_table_columns` — видимость и ширина колонок таблицы выверки |

### Matching logic (`services/MatchingService.php`)
- **Manual matching** (`matchManual`): takes array of entry IDs, validates balance (Ledger sum = Statement sum for mixed L+S sets), assigns a `MTCH` + 8-char hex `match_id`.
- **Auto-matching** (`autoMatch`): runs all active `MatchingRule`s in priority order. Each rule uses a CTE with `ROW_NUMBER() OVER (PARTITION BY ...)` to find unique one-to-one pairs in a single SQL query. Supports `cross_id_search` (any ID field on one side matches any ID field on the other). Accepts optional `$onProgress` callback for console output.
- **Step-by-step auto-matching** (UI): `autoMatchStart` → returns `job_id` + rules list, then client calls `autoMatchStep` per rule. State stored in FileCache. Provides real-time progress in UI.
- **Unmatch** (`unmatch`): clears `match_id` and sets `match_status = U` for all entries sharing a `match_id`.
- **Match ID uniqueness**: `generateMatchId()` uses `random_bytes(4)` + DB check loop (nostro_entries + archive) to guarantee no collisions.
- **Console command** (`commands/AutoMatchController`): `php yii auto-match/run` — runs auto-matching for all or specific company/account with progress output.

### Group filtering (two-step, `NostroEntryController::actionList`)
When filtering entries by `group_id` (formerly `pool_id`):
1. Apply `GroupFilter` conditions that target `accounts` table (account-level fields, including `account_pool_id` → `accounts.pool_id`) → get matching `account_id` list.
2. Apply `GroupFilter` conditions that target `nostro_entries` table (entry-level fields) → filter the main query.

### Hierarchy: Category → Group → GroupFilter
- **Category** (`CategoryController`): верхний уровень навигации в сайдбаре
- **Group** (`GroupController`): набор фильтров для выборки записей. Принадлежит категории через `category_id`
- **GroupFilter**: условие фильтрации (поле, оператор, значение). Поддерживает `account_pool_id` для фильтрации по ностро-банку
- **AccountPool**: отдельная сущность — ностро-банки. Standalone страница `/nostro-banks` (`AccountPoolController`). CRUD + привязка/отвязка счетов (`Account.pool_id` nullable). Не связан с категориями/группами напрямую

### Frontend
- Main layout: `views/layouts/main.php` — Bootstrap 5 navbar + conditional rendering.
- Two rendering modes controlled by `$isStandalonePage`:
  - **Main Vue app**: sidebar (`_sidebar.php`) + content area with Vue.js components loaded via `_vue-scripts.php`.
  - **Standalone pages**: user profile (`user/view`), recon report (`recon-report/`), and nostro banks management (`nostro-banks`) render their own full-page content.
- Vue scripts are inline/embedded in PHP view partials — not a separate build process.
- Vue mixins (`web/js/app/mixins/`): `CategoriesMixin`, `GroupsMixin`, `EntriesMixin`, `MatchingMixin`, `BalanceMixin`, `ArchiveMixin`, `ModalsMixin`, `StatePersistenceMixin`.

### Archive process
Archiving is batch-processed: client calls `POST /archive/run-batch` repeatedly (300 records per call) until `is_finished = true`. Uses raw SQL `batchInsert` + `DELETE WHERE id = ANY(ARRAY[...])` for performance.

### Audit trail
`NostroEntry` hooks (`afterSave`, `beforeDelete`) automatically call `NostroEntryAudit::log()`. `NostroEntryController::actionHistory` reconstructs full record snapshots at each point in time by replaying audit events.

## Configuration

- Database: `config/db.php` — PostgreSQL 17, host `PostgreSQL-17`, port `5432`, dbname `smartmatch`
- Test DB: `config/test_db.php`
- App params: `config/params.php`
- RBAC: `yii\rbac\DbManager` with standard auth tables
- Dev tools (Gii, Debug) enabled when `YII_ENV_DEV`
