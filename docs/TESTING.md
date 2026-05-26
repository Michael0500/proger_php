# Тестирование SmartMatch

Документ описывает, какие тесты есть в проекте, зачем они запускаются и какие проверки выполняют. Тестовый стек: Codeception + PHPUnit + Yii2 module.

## 1. База для тестов

Тесты должны работать только с отдельной PostgreSQL-базой `smartmatch_test`. Рабочую базу `smartmatch` использовать нельзя, потому что `SmartMatchTestHelper::resetDatabase()` очищает бизнес-таблицы через `TRUNCATE`.

Создать базу один раз:

```sql
CREATE DATABASE smartmatch_test;
```

Применить миграции:

```bash
php tests/bin/yii migrate --interactive=0
```

По умолчанию используется `config/test_db.php`:

```text
pgsql:host=PostgreSQL-17;port=5432;dbname=smartmatch_test
```

Для другого окружения можно задать переменные:

```bash
SMARTMATCH_TEST_DSN="pgsql:host=PostgreSQL-17;port=5432;dbname=smartmatch_test"
SMARTMATCH_TEST_DB_USERNAME="postgres"
SMARTMATCH_TEST_DB_PASSWORD=""
```

## 2. Что запускать

Полный регресс:

```bash
vendor/bin/codecept run
```

Запускать перед передачей изменений, если правки затрагивают модели, сервисы, контроллеры, миграции, права доступа, JSON API или бизнес-логику.

Unit-тесты:

```bash
vendor/bin/codecept run unit
```

Проверяют доменную логику без HTTP-слоя: модели, сервисы, валидацию, аудит, расчёты, SQL-логику автоквитования.

Functional-тесты:

```bash
vendor/bin/codecept run functional
```

Проверяют web/API-сценарии через Yii request layer: авторизацию, JSON endpoints, `company_id` scoping, request parsing, транзакции контроллеров.

Один тестовый файл:

```bash
vendor/bin/codecept run unit tests/unit/models/NostroEntryTest.php
vendor/bin/codecept run functional tests/functional/MatchingApiCest.php
```

Пересборка actor-классов:

```bash
vendor/bin/codecept build
```

Нужна после изменения suite-конфигов, подключённых Codeception-модулей или actor-классов. На PHP 8.2 текущий Codeception 4 может печатать deprecated warnings во время `build`; если команда завершилась с кодом 0, actor-классы пересобраны.

Покрытие:

```bash
vendor/bin/codecept run --coverage --coverage-html
```

Использовать для оценки покрытия перед крупными релизами или после массовых изменений. Для обычной локальной проверки быстрее запускать `unit`, `functional` или полный `run` без coverage.

## 3. Общая тестовая инфраструктура

`tests/_support/SmartMatchTestHelper.php` содержит фабрики и reset-логику:

- `resetDatabase()` очищает тестовые бизнес-таблицы и сбрасывает `match_id_seq`;
- `createCompany()`, `createUser()`, `createPool()`, `createAccount()` собирают минимальный tenant-контекст;
- `createEntry()`, `createRule()`, `createBalance()`, `createArchiveSettings()`, `createArchivedEntry()` создают доменные записи для сценариев;
- helper подключается в `tests/unit/_bootstrap.php` и `tests/functional/_bootstrap.php`.

`createUser()` не создаёт известный пользовательский пароль и не требует наличия legacy-колонок `auth_key` / `password_hash`. Для тестов авторизация выполняется через cookie/session helpers Yii и Codeception: `Yii::$app->user->login($user, 0)` или `$I->amLoggedInAs(...)`. Тесты не должны проверять парольный вход или bearer/API tokens.

Каждый тест сам создаёт нужную компанию, пользователя, ностро-банк, счёт и записи. Это делает проверки изолированными и не зависящими от сидов рабочей базы.

## 4. Unit-тесты

| Файл | Что проверяет |
|---|---|
| `tests/unit/models/ArchiveSettingsTest.php` | Настройки архива по умолчанию и валидацию границ `archive_after_days` / `retention_years`. |
| `tests/unit/models/CookieAuthTest.php` | Внутреннюю авторизацию пользователя через Yii user component без пользовательского пароля. |
| `tests/unit/models/MatchingRuleTest.php` | Текстовое описание включённых критериев правила квитования. |
| `tests/unit/models/NostroBalanceTest.php` | Денежную точность балансов, continuity statement-балансов, дубли номеров statement. |
| `tests/unit/models/NostroEntryArchiveTest.php` | Перенос сквитованной записи в архив, сохранение `matched_at`, срок retention и пользователя архивирования. |
| `tests/unit/models/NostroEntryTest.php` | Валидацию суммы `decimal(20,2)`, автоматическое выставление/очистку `matched_at`, аудит create/update/delete. |
| `tests/unit/models/UserPreferenceTest.php` | Upsert JSON-настроек UI и чтение старого double-encoded JSON. |
| `tests/unit/models/UserTest.php` | Поиск активных пользователей, исключение удалённых и внутренний cookie/session login найденного пользователя. |
| `tests/unit/services/MatchingServiceTest.php` | Ручное квитование NRE и INV, отказ при дисбалансе, одиночную нулевую запись, расквитование группы, summary выбранных строк. |
| `tests/unit/services/AutoMatchingServiceTest.php` | Автоквитование уникальной пары, cross-id search, отказ правила без условий, пошаговый запуск с category scope. |
| `tests/unit/widgets/AlertTest.php` | Рендер системных flash-уведомлений Yii. |

## 5. Functional-тесты

| Файл | Что проверяет |
|---|---|
| `tests/functional/AllNostroApiCest.php` | `/all-nostro/list` и `/all-nostro/search-accounts`: фильтр по выбранным ностро-банкам, игнорирование чужих pool ID, поиск счетов только внутри компании. |
| `tests/functional/ArchiveApiCest.php` | `/archive/run-batch`, `/archive/restore-preview`, `/archive/restore`: batch-архивирование, аудит archive, восстановление всей группы по `match_id`, аудит restore. |
| `tests/functional/BalanceArchiveApiCest.php` | `/balance-archive/run-batch`, `/balance-archive/restore`: batch-архивирование старых балансов, аудит archive/restore, восстановление активной строки. |
| `tests/functional/CookieAuthCest.php` | Страница логина, редирект гостя с защищённой страницы и внутренний cookie/session login helper. |
| `tests/functional/MatchingApiCest.php` | `/matching/match-manual`, `/matching/unmatch`, `/matching/calc-summary`: scoped-квитование текущей компании, отказ при чужих ID, защита от расквитования чужих строк с тем же `match_id`, summary без утечки чужих данных. |
| `tests/functional/NostroEntryApiCest.php` | `/nostro-entry/list`, `/nostro-entry/create`, `/nostro-entry/update`: `company_id` scope, нормализация суммы, upper-case валюты, запрет создания/переноса записи на счёт другой компании. |
| `tests/functional/ReconReportApiCest.php` | `/recon-report/generate`: сбор отчёта по ностро-банку из Ledger/Statement closing balances и outstanding items, исключение уже сквитованных записей. |
| `tests/functional/UserPreferenceCest.php` | `/user-preference/save` и `/user-preference/get`: сохранение разрешённого ключа, отказ неизвестного ключа. |

## 6. Как добавлять новые тесты

Для модели или сервиса добавляйте unit-тест, если поведение можно проверить без HTTP-запроса. Это быстрее и проще диагностируется.

Для контроллера, access control, JSON API, `company_id` scoping, request parsing и транзакций добавляйте functional-тест.

Каждый новый тест должен:

- начинать сценарий с `SmartMatchTestHelper::resetDatabase()`;
- создавать собственную компанию и пользователя;
- явно проверять `company_id` scope, если endpoint читает или меняет бизнес-данные;
- не зависеть от реальных сидов, пользовательских данных и текущей даты без необходимости;
- проверять не только `success`, но и состояние БД после операции.

## 7. Частые проблемы

Если тесты падают с ошибкой подключения к БД, проверьте, что `smartmatch_test` создана и переменные `SMARTMATCH_TEST_*` указывают на тестовый PostgreSQL.

Если таблиц не хватает, выполните:

```bash
php tests/bin/yii migrate --interactive=0
```

Если Codeception не видит новые helper-методы или actor-методы, выполните:

```bash
vendor/bin/codecept build
```
