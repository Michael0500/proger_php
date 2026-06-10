# Тестирование SmartMatch

Документ описывает, какие тесты есть в проекте, зачем они запускаются и какие проверки выполняют. Тестовый стек: Codeception + PHPUnit + Yii2 module для PHP/backend и Vitest для browser JS-модулей.

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
npm run test:js
```

Запускать перед передачей изменений, если правки затрагивают модели, сервисы, контроллеры, миграции, права доступа, JSON API, бизнес-логику или `web/js/app`.

Unit-тесты:

```bash
vendor/bin/codecept run unit
```

Проверяют доменную логику без HTTP-слоя: модели, сервисы, валидацию, аудит, расчёты, SQL-логику автоквитования.

Быстрый локальный прогон без нагрузочных кейсов (`@group slow` — TC-036, ~14 с):

```bash
vendor/bin/codecept run unit --skip-group slow
```

Functional-тесты:

```bash
vendor/bin/codecept run functional
```

Проверяют web/API-сценарии через Yii request layer: авторизацию, JSON endpoints, `company_id` scoping, request parsing, транзакции контроллеров.

JS-тесты:

```bash
npm install
npm run test:js
```

Проверяют browser scripts из `web/js/app` в sandbox-окружении Vitest без запуска Yii-сервера: общие Vue-хелперы, хранение UI-состояния и thin API wrapper поверх axios.

Один тестовый файл:

```bash
vendor/bin/codecept run unit tests/unit/models/NostroEntryTest.php
vendor/bin/codecept run functional tests/functional/MatchingApiCest.php
npx vitest run --config vitest.config.mjs tests/js/common.test.js
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
| `tests/unit/commands/FccMergeControllerTest.php` | FCC12 merge: перенос строк-балансов и транзакций, трассировка `extract_no/line_no/branch_code`, аудит, удаление источника и partial-режим при ненайденном счёте. |
| `tests/unit/commands/DwhMergeControllerTest.php` | DWH merge: перенос suspend_posting в INV, группировка балансов, маппинг D/C, обрезка денег до 2 знаков, аудит, защита от дублей `posting_id`, partial при ненайденном счёте. |
| `tests/unit/commands/TdsMergeControllerTest.php` | TDS merge (CAMT053/MT950/ED211/ED743): маппинг D/C, `is_merged` только без пропусков, partial при ненайденном/пустом счёте, `--type`/`--delete-source`, `stmt_ref → statement_number`, `msg_key → message_id`, `ph_tds_stmt_dtl.other_id → nostro_entries.other_id`, MT950 fallback `op_type → other_id`, ED `edno/eddate/edauthor`, трассировка `stmt_id/line_no/branch_code`, аудит импорта, chunking. |
| `tests/unit/commands/AutoMatchControllerTest.php` | Консольный wrapper автоквитования: `run` без фильтров обходит все компании; `--company`/`--account` ограничивают область; `status` без побочных эффектов; консольный (guest) запуск пишет `updated_by=NULL` в сквитованные записи. |
| `tests/unit/commands/PcrControllerTest.php` | PCRFIHIST export (`pcr/export`): формат строк 60/61 — фиксированные ширины и паддинг, разделитель `|`, RUB→RUR, дата баланса `dd/MM/YYYY`, суммы с `.`, `operationId` без дефисов, `Debit→D`/`Credit→C`, строка 61 без `settlement_date_time`, фильтры `--correlation-id`/`--date`, пустой случай. |
| `tests/unit/controllers/PcrCallbackControllerTest.php` | Приёмник callback СЦР (`PcrCallbackController`): нормализация FIWalletInfo в `pcr_callback`/`pcr_wallet_info`/`pcr_operation`, идемпотентность по `(operation_id, part_id)`, Basic Auth (401 без/с неверными реквизитами), игнор callback с неизвестным `correlationId` и недельная ротация runtime-лога на два файла. |
| `tests/unit/components/BalanceParsersTest.php` | Парсеры BND/CAMT и ASB: корректный разбор XML с namespace, Windows-1251 ASB, ошибки отсутствующего closing balance и некорректной даты. |
| `tests/unit/controllers/ReconReportExportTest.php` | Экспорт раккорда: createXlsxFile (валидный XLSX), createPdfFile (сигнатура %PDF), createZipFile (несколько файлов), уникальные имена в ZIP, safeFilename/reportFilename. |
| `tests/unit/models/ArchiveSettingsTest.php` | Настройки архива по умолчанию и валидацию границ `archive_after_days` / `retention_years`. |
| `tests/unit/models/CookieAuthTest.php` | Внутреннюю авторизацию пользователя через Yii user component без пользовательского пароля. |
| `tests/unit/models/MatchingRuleTest.php` | Текстовое описание включённых критериев правила квитования. |
| `tests/unit/models/NostroBalanceTest.php` | Денежную точность балансов, continuity statement-балансов, дубли номеров statement, `statement_number` required для `ls_type=S`, enum D/C для `opening_dc`/`closing_dc`. |
| `tests/unit/models/NostroEntryArchiveTest.php` | Перенос сквитованной записи в архив, сохранение `matched_at`, срок retention и пользователя архивирования. |
| `tests/unit/models/NostroEntryTest.php` | Валидацию суммы `decimal(20,2)`, автоматическое выставление/очистку `matched_at`, аудит create/update/delete, enum `ls/dc/match_status`, длины строковых полей, `exist` для `account_id`/`company_id`. |
| `tests/unit/models/UserPreferenceTest.php` | Upsert JSON-настроек UI и чтение старого double-encoded JSON. |
| `tests/unit/models/UserTest.php` | Поиск активных пользователей, исключение удалённых и внутренний cookie/session login найденного пользователя. |
| `tests/unit/services/MatchingServiceTest.php` | Ручное квитование NRE/INV (в т.ч. набор >2, только Ledger, регистр валюты), отказ при дисбалансе NRE/INV, разные валюты/банки, уже сквитованная запись в наборе, одиночная нулевая/ненулевая, откат транзакции при ошибке БД, расквитование группы и scope по компании, summary с tenant-фильтром. |
| `tests/unit/services/AutoMatchingServiceTest.php` | Автоквитование: пары LS/LL/SS, реверс D/C, дедупликация (1 L на 2 S), match по amount+value_date, scope `limitAccountIds` и `accountId`, изоляция по пулам, порядок правил по priority, устойчивость к ошибке правила, отказ без активных правил, `autoMatchStep` неизвестный/завершённый job, `resolveScopeAccounts` и пустой scope, формат/уникальность `match_id`, обработка >5000 пар. |
| `tests/unit/widgets/AlertTest.php` | Рендер системных flash-уведомлений Yii. |

## 5. Functional-тесты

| Файл | Что проверяет |
|---|---|
| `tests/functional/AccountApiCest.php` | `/account/list`, `/account/create`, `/account/update`, `/account/delete`: `company_id` scope, запрет чужого `pool_id`, создание начального баланса при добавлении счёта. |
| `tests/functional/AccountPoolApiCest.php` | `/account-pool/*`: список ностро-банков текущей компании, привязка только доступных счетов, запрет чужих счетов/категорий, отвязка счетов при удалении. |
| `tests/functional/AllNostroApiCest.php` | `/all-nostro/list` и `/all-nostro/search-accounts`: фильтр по выбранным ностро-банкам, игнорирование чужих pool ID, поиск счетов только внутри компании. |
| `tests/functional/ArchiveApiCest.php` | `/archive/run-batch`, `/archive/restore-preview`, `/archive/restore`: batch-архивирование, аудит archive (с `archived_id`), восстановление всей группы по `match_id`, аудит restore; `/archive/count` по `matched_at` (не `updated_at`), `/archive/purge-expired` со scope по компании, `/archive/save-settings` валидация диапазонов, `/archive/list` фильтры (amount/search/scope), clamp `limit∈[10..200]`, `/archive/restore-preview` для чужой записи, `/archive/history` по `original_id`, `/archive/stats` счётчики. |
| `tests/functional/BalanceArchiveApiCest.php` | `/balance-archive/run-batch`, `/balance-archive/restore`: batch-архивирование старых балансов, аудит archive/restore, восстановление активной строки. |
| `tests/functional/CategoryApiCest.php` | `/category/get-categories`, `/category/create`, `/category/update`, `/category/delete`: дерево категорий текущей компании, запрет обновления/удаления чужих категорий. |
| `tests/functional/CookieAuthCest.php` | Страница логина, редирект гостя с защищённой страницы и внутренний cookie/session login helper. |
| `tests/functional/MatchingApiCest.php` | `/matching/match-manual`, `/matching/unmatch`, `/matching/calc-summary`: scoped-квитование текущей компании, отказ при чужих ID, защита от расквитования чужих строк с тем же `match_id`, summary без утечки чужих данных. |
| `tests/functional/MatchingRuleApiCest.php` | `/matching/get-rules`, `/matching/save-rule`, `/matching/delete-rule`: `company_id` scope правил, сортировка по приоритету, корректное чтение boolean-параметров `0/1`. |
| `tests/functional/NostroBalanceApiCest.php` | `/nostro-balance/list`, `/nostro-balance/create`, `/nostro-balance/update`, `/nostro-balance/confirm`, `/nostro-balance/delete`: scope балансов, фильтры по банку/счёту, запрет чужих счетов, аудит ручного ввода и подтверждения. |
| `tests/functional/NostroEntryApiCest.php` | `/nostro-entry/list`, `/nostro-entry/create`, `/nostro-entry/update`: `company_id` scope, нормализация суммы, upper-case валюты, запрет создания/переноса записи на счёт другой компании. |
| `tests/functional/ReferenceApiCest.php` | `/reference/*`: CRUD валют и стран, нормализация ISO-кодов до валидации, сортировка и отказ невалидных кодов. |
| `tests/functional/ReconReportApiCest.php` | `/recon-report/generate`: сбор отчёта по ностро-банку из Ledger/Statement closing balances и outstanding items, исключение уже сквитованных записей; валидация (нет компании, pool+category/ни одного, период и даты), Closing Balance по последнему балансу ≤ даты, MULTI-валюта, окно дат prevDay+recon, изоляция чужого пула. |
| `tests/functional/UserPreferenceCest.php` | `/user-preference/save` и `/user-preference/get`: сохранение разрешённого ключа, отказ неизвестного ключа. |
| `tests/functional/SecurityApiCest.php` | Безопасность JSON API: `filters.search_value` обрабатывается как литерал `ilike` (защита от SQL-инъекции); `pool_id` приводится к `int` (метасимволы безопасны); гость не может POST к защищённому API (редирект 302, БД не меняется). |

## 6. Как добавлять новые тесты

Для модели или сервиса добавляйте unit-тест, если поведение можно проверить без HTTP-запроса. Это быстрее и проще диагностируется.

Для контроллера, access control, JSON API, `company_id` scoping, request parsing и транзакций добавляйте functional-тест.

Для JS-кода в `web/js/app` добавляйте Vitest-тест, если поведение можно проверить без полного браузера: форматтеры, storage helpers, API wrapper, чистые функции и устойчивость к отсутствующим globals. Для сценариев, где важны реальный DOM, Vue lifecycle, datepicker, модальные окна или переходы между страницами, добавляйте браузерный E2E-тест отдельным набором.

Каждый новый тест должен:

- начинать сценарий с `SmartMatchTestHelper::resetDatabase()`;
- создавать собственную компанию и пользователя;
- явно проверять `company_id` scope, если endpoint читает или меняет бизнес-данные;
- не зависеть от реальных сидов, пользовательских данных и текущей даты без необходимости;
- проверять не только `success`, но и состояние БД после операции.

Для JS-тестов каждый новый тест должен:

- загружать browser script через `tests/js/helpers/load-browser-script.js`;
- явно задавать нужные globals (`window`, `Vue`, `axios`, `AppRoutes`, `localStorage`) в sandbox;
- не обращаться к рабочему серверу и реальному `localStorage`;
- проверять payload/side effects, а не только факт вызова функции.

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

Если функциональный Cest начал падать с `Session cookie parameters cannot be changed after headers have already been sent`, см. раздел 8 — это типично от `use Yii;` в файле без namespace.

## 8. Конвенции и подводные камни

**`use Yii;` в Cest без namespace ломает сессию.** Файлы `tests/functional/*Cest.php` не имеют namespace, и `Yii` уже доступен глобально. PHP при загрузке файла выводит предупреждение «use statement with non-compound name 'Yii' has no effect» в STDOUT, и при первом обращении к сессии (`$I->amLoggedInAs(...)`) получаете «headers already sent». В Cest пишите `\Yii::$app->...` напрямую и **не добавляйте `use Yii;`**. Unit-тесты в namespace-классах добавлять `use Yii;` могут.

**Описания тестов на русском.** Для unit-тестов подключайте трейт `\PrintsTestDescription` (из `tests/_support/PrintsTestDescription.php`) и в конце каждого test-метода вызывайте `$this->stdout('<что делает и что проверено>')`. Описание печатается в STDOUT под именем теста и видно в обычном выводе `vendor/bin/codecept run`. Для функциональных Cest используйте родную конвенцию `$I->wantTo('...')` в начале метода.

**Источники импорта очищаются автоматически.** `SmartMatchTestHelper::resetDatabase()` уже truncate-ит `ph_tds_stmt_hdr/dtl`, `gitb_nostro_extract_custom`, `suspend_posting`, `tds_status` — в merge-тестах не нужна ручная очистка перед каждым кейсом.

**Нагрузочные тесты помечайте `@group slow`.** Кейсы со временем выполнения больше нескольких секунд (например, нагрузочный `runRule` на >5000 пар) — `@group slow` в docblock, чтобы локальный TDD-прогон с `--skip-group slow` оставался быстрым. CI-полный прогон без флага охватит и их.

**Acceptance suite (browser e2e) не настроен.** В `tests/acceptance/*.php` лежат стоковые Yii-кейсы (Home/About/Contact/Login), но `tests/acceptance.suite.yml` и WebDriver/ChromeDriver не сконфигурированы — `vendor/bin/codecept run acceptance` не работает. Перед запуском e2e нужно зарегистрировать suite-конфиг с `WebDriver`, поднять `chromedriver`/Chrome и настроить тестовый vhost на `smartmatch_test`.
