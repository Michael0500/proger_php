# SmartMatch: руководство разработчика

Документ описывает техническое устройство SmartMatch и практический порядок внесения изменений. Он предназначен для разработчиков, которые поддерживают backend на Yii 2, PostgreSQL и встроенный Vue.js frontend.

## 1. Быстрый старт

### Требования

- PHP 7.4+.
- Composer.
- PostgreSQL 17.
- Расширения PHP, необходимые Yii 2 и используемым пакетам.
- Доступ к базе `smartmatch`.

### Установка зависимостей

```bash
composer install
```

Если окружение использует PHP 8.2, а lock-файл содержит dev-зависимости с более старым ограничением PHP, может потребоваться:

```bash
composer install --ignore-platform-req=php
```

### Миграции

```bash
php yii migrate
```

Откат последней миграции:

```bash
php yii migrate/down
```

Создание новой миграции:

```bash
php yii migrate/create descriptive_name
```

### Запуск тестов

Тесты запускаются через Codeception и используют отдельную PostgreSQL-базу `smartmatch_test`.

Создать тестовую базу один раз:

```sql
CREATE DATABASE smartmatch_test;
```

Применить миграции в тестовую базу:

```bash
php tests/bin/yii migrate --interactive=0
```

Если в окружении другое имя БД или другой доступ к PostgreSQL, задайте переменные:

```bash
SMARTMATCH_TEST_DSN="pgsql:host=PostgreSQL-17;port=5432;dbname=smartmatch_test"
SMARTMATCH_TEST_DB_USERNAME="postgres"
SMARTMATCH_TEST_DB_PASSWORD=""
```

После изменения состава модулей Codeception или actor-классов:

```bash
vendor/bin/codecept build
```

Основные команды:

```bash
vendor/bin/codecept run                                      # все активные suites
vendor/bin/codecept run unit                                 # модели и сервисы
vendor/bin/codecept run functional                           # web/API сценарии через Yii request layer
vendor/bin/codecept run unit tests/unit/models/NostroEntryTest.php
vendor/bin/codecept run functional tests/functional/ArchiveApiCest.php
vendor/bin/codecept run --coverage --coverage-html
```

Тестовая конфигурация находится в `codeception.yml`, `config/test.php` и `config/test_db.php`. Подробная карта тестов, назначение каждого suite и список проверок описаны в [TESTING.md](TESTING.md).

Назначение текущих тестов:

- `unit` — быстрая проверка доменной логики без HTTP: валидация денежных значений, `NostroEntry` audit hooks, `NostroBalance` continuity/sequence, `UserPreference`, `ArchiveSettings`, ручное и автоматическое квитование в `MatchingService`.
- `functional` — проверка пользовательских и API-сценариев через Yii: login flow, настройки UI, список/создание/обновление записей с `company_id` scope, ручное квитование, расквитование, `/all-nostro`, архивирование/восстановление группы, генерация раккорда.

Все тесты очищают тестовые бизнес-таблицы через `SmartMatchTestHelper`; рабочую базу `smartmatch` использовать для тестов нельзя.

## 2. Стек и структура проекта

SmartMatch построен на Yii 2 Basic. Backend отвечает за маршруты, контроллеры, модели, сервисы, миграции и серверный экспорт. Frontend реализован как Vue-инстансы внутри PHP views.

Основные каталоги:

| Путь | Назначение |
|---|---|
| `commands/` | Консольные команды Yii. |
| `components/` | Общие компоненты приложения. |
| `config/` | Конфигурация web/console/test/db/params. |
| `controllers/` | Web-контроллеры. |
| `migrations/` | Миграции БД. |
| `models/` | ActiveRecord и модели форм. |
| `services/` | Доменная логика вне контроллеров. |
| `views/` | PHP views и Vue-разметка. |
| `web/js/app/` | Общий frontend-код, page starters и Vue mixins. |
| `web/css/` | CSS приложения, если используется. |
| `tests/` | Codeception-тесты. |
| `docs/` | Пользовательская и разработческая документация. |

## 3. Конфигурация

Основная web-конфигурация: `config/web.php`.

Ключевые настройки:

- `request.parsers['application/json']` включает JSON parser.
- `cache` использует `yii\caching\FileCache`.
- `user.identityClass` указывает на `app\models\User`.
- `urlManager` включает pretty URL без `index.php`.
- `authManager` использует `yii\rbac\DbManager`.
- Глобальный access filter подключен как `as access`.

Конфигурация БД: `config/db.php`.

По умолчанию используется PostgreSQL:

- host: `PostgreSQL-17`;
- port: `5432`;
- dbname: `smartmatch`.

## 4. Мультитенантность

Каждый пользователь работает в рамках выбранной компании. Почти все бизнес-данные должны быть ограничены `company_id`.

Типовой helper в контроллерах:

```php
private function cid(): ?int
{
    $u = Yii::$app->user->identity;
    return ($u && $u->company_id) ? (int)$u->company_id : null;
}
```

Правила разработки:

- Любой запрос к счетам, записям, балансам, архиву, ностро-банкам и настройкам должен фильтроваться по `company_id`.
- Нельзя доверять `company_id` из запроса клиента.
- При создании записи `company_id` берется только из текущего пользователя.
- При обновлении и удалении сначала ищите запись с учетом `company_id`.
- Если компания не выбрана, API должно возвращать понятную ошибку, а UI должен направлять пользователя в профиль.

## 5. Контроллеры

Функциональные контроллеры должны наследоваться от `controllers/BaseController.php`. Базовый контроллер включает проверку авторизации.

Общие соглашения:

- API actions возвращают массивы.
- Для API actions устанавливается `Yii::$app->response->format = Response::FORMAT_JSON`.
- CSRF для API-контроллеров отключается в `beforeAction`, если action вызывается из текущего Vue UI через AJAX.
- Валидация входных данных выполняется на сервере, даже если форма уже валидируется на клиенте.
- Ошибки возвращаются в едином стиле: `success => false`, `message => ...`, при необходимости `errors => ...`.

Пример структуры ответа:

```php
return [
    'success' => true,
    'data' => $rows,
    'total' => $total,
];
```

Пример ошибки:

```php
return [
    'success' => false,
    'message' => 'Запись не найдена',
];
```

## 6. Маршруты

Pretty routes описаны в `config/web.php`.

Основные пользовательские маршруты:

| URL | Контроллер |
|---|---|
| `/site/index` | Основная выверка. |
| `/all-nostro` | Выверка по всем ностро-банкам. |
| `/accounts` | Счета. |
| `/nostro-banks` | Ностро-банки. |
| `/balance` | Балансы. |
| `/archive` | Архив. |
| `/balance-archive` | Архив балансов. |
| `/references` | Валюты и страны. |
| `/recon-report` | Раккорд. |

API-группы:

- `/nostro-entry/<action>`;
- `/matching/<action>`;
- `/nostro-balance/<action>`;
- `/archive/<action>`;
- `/balance-archive/<action>`;
- `/account-pool/<action>`;
- `/reference/<action>`;
- `/user-preference/<action>`.

При добавлении нового контроллера обновляйте `urlManager`, если нужен человекочитаемый URL.

## 7. Доменная модель

Ключевые модели:

| Модель | Таблица | Роль |
|---|---|---|
| `NostroEntry` | `nostro_entries` | Основные записи выверки. |
| `MatchingRule` | `matching_rules` | Правила автоквитования. |
| `Account` | `accounts` | Nostro-счета. |
| `AccountPool` | `account_pools` | Ностро-банки. |
| `Category` | `categories` | Категории сайдбара. |
| `NostroBalance` | `nostro_balance` | Балансы по счетам и датам. |
| `NostroBalanceArchive` | `nostro_balance_archive` | Архив балансов. |
| `NostroEntryAudit` | `nostro_entry_audit` | Аудит записей выверки. |
| `NostroEntryArchive` | `nostro_entries_archive` | Архив сквитованных записей. |
| `ArchiveSettings` | `archive_settings` | Настройки архивации. |
| `UserPreference` | `user_preferences` | Персональные настройки UI. |
| `Currency` | `currencies` | Справочник валют. |
| `Country` | `countries` | Справочник стран. |

## 8. Денежные значения

Денежные поля хранятся как `decimal(20,2)`.

Правила:

- максимум 18 цифр до разделителя;
- максимум 2 знака после разделителя;
- не приводить пользовательский ввод к `float` до сохранения;
- нормализовать строковый ввод перед валидацией;
- форматирование для отображения делать отдельно от значения для БД.

Поля:

- `nostro_entries.amount`;
- `nostro_entries_archive.amount`;
- `nostro_balance.opening_balance`;
- `nostro_balance.closing_balance`.

При добавлении новых денежных полей придерживайтесь тех же ограничений.

## 9. Выверка и записи

Основной контроллер записей: `controllers/NostroEntryController.php`.

Важные actions:

- `actionList` - список записей с фильтрами, сортировкой и пагинацией.
- `actionSearchAccounts` - поиск счетов для форм.
- `actionCreate` - создание записи.
- `actionUpdate` - изменение записи.
- `actionDelete` - удаление записи.
- `actionUpdateComment` - быстрый комментарий.
- `actionHistory` - история изменений.

Основная страница: `views/site/entries.php`.

Разметка таблицы: `views/layouts/_section-entries.php`.

Frontend-логика:

- `web/js/app/page-entries.js`;
- `web/js/app/mixins/entries.js`;
- `web/js/app/mixins/matching.js`;
- `web/js/app/mixins/categories.js`;
- `web/js/app/mixins/pools.js`;
- `web/js/app/mixins/modals.js`;
- `web/js/app/mixins/state-persistence.js`.

Фильтры записей вынесены в `views/partials/_entries-filters.php` и переиспользуются основной выверкой и страницей `/all-nostro`.

## 10. Квитование

Доменная логика квитования находится в `services/MatchingService.php`.

Основные операции:

- `matchManual` - ручное квитование выбранных записей.
- `autoMatch` - пакетное автоквитование по правилам.
- `unmatch` - расквитование всей группы по `match_id`.
- `generateMatchId` - генерация уникального `Match ID`.

Контроллер: `controllers/MatchingController.php`.

UI:

- выбор записей и расчет итогов в `MatchingMixin`;
- модалки правил и автоквитования в `views/layouts/_modals.php`;
- список правил загружается через `/matching/get-rules`.

Важные правила:

- Сквитованные записи получают `match_status = M`, `match_id` и `matched_at`.
- Расквитование очищает `match_id`, `matched_at` и возвращает `match_status = U`.
- `match_id` должен быть уникален с учетом активной таблицы и архива.
- Автоквитование должно работать только по активным правилам в порядке приоритета.

## 11. Балансы

Контроллер: `controllers/NostroBalanceController.php`.

Страница: `views/nostro-balance/page.php`.

Разметка: `views/layouts/_section-balance.php`.

Frontend: `web/js/app/page-balance.js` и `web/js/app/mixins/balance.js`.

Основные операции:

- список балансов;
- создание и редактирование;
- удаление;
- подтверждение ошибочной строки с причиной;
- история;
- импорт БНД;
- импорт АСБ;
- поиск счетов для формы.

Балансы используются в раккорде, поэтому изменение логики балансов нужно проверять вместе с `/recon-report`.

## 12. Раккорд

Контроллер: `controllers/ReconReportController.php`.

Страница: `views/recon-report/index.php`.

PDF partial: `views/recon-report/_pdf.php`.

Экспорт:

- XLSX через `phpoffice/phpspreadsheet`;
- PDF через `mpdf/mpdf`;
- ZIP при экспорте нескольких отчетов.

Основные правила отчета:

- учитываются только несквитованные записи без `Match ID` и со статусом `U`;
- категория разворачивается в ностро-банки через `account_pools.category_id`;
- ностро-банк разворачивается во все счета `accounts.pool_id`;
- Closing Balance берется из `nostro_balance` строго на Date Reconciliation;
- Trial Balance рассчитывается как Closing Balance минус Outstanding Items.

При изменении отчета проверяйте:

- одиночный экспорт XLSX;
- одиночный экспорт PDF;
- экспорт набора отчетов в ZIP;
- режим даты;
- режим периода;
- отсутствие балансов на дату отчета.

## 13. Архив

Контроллер: `controllers/ArchiveController.php`.

Страница: `views/archive/page.php`.

Frontend: `web/js/app/page-archive.js` и `web/js/app/mixins/archive.js`.

Архивация выполняется порциями через `/archive/run-batch`. Это важно для больших объемов данных.

Кандидаты на архивирование:

- `match_status = M`;
- `match_id IS NOT NULL`;
- `matched_at IS NOT NULL`;
- `matched_at` старше `archive_settings.archive_after_days`.

Восстановление выполняется группой по `match_id`. При восстановлении сохраняется исходный `matched_at`.

### 10.1. Архив балансов

Страница: `views/balance-archive/page.php`.

Frontend: `web/js/app/page-balance-archive.js` и `web/js/app/mixins/balance-archive.js`.

Архивация выполняется порциями через `/balance-archive/run-batch`. Кандидаты: строки `nostro_balance` текущей компании со статусом `normal` или `confirmed`, у которых `value_date` старше `archive_settings.archive_after_days`. Строки со статусом `error` автоматически не архивируются.

Восстановление выполняется по одной строке через `/balance-archive/restore`. Аудит балансов не имеет FK на активную таблицу, чтобы история сохранялась после переноса в архив; события архивации и восстановления пишутся как `archive` и `restore` с `archived_id`.

При изменении архива проверяйте:

- перенос активных строк в архив;
- запись audit-событий `archive`;
- восстановление группы;
- запись audit-событий `restore`;
- историю активной восстановленной строки;
- историю архивной строки;
- удаление просроченных архивных записей.

## 14. Аудит

Аудит записей выверки хранится в `nostro_entry_audit`.

`NostroEntry` пишет аудит через hooks:

- `afterSave`;
- `beforeDelete`.

Исключения:

- архивирование использует raw SQL и пишет audit rows явно;
- восстановление из архива создает новую физическую запись и пишет audit rows явно;
- FCC12 merge использует batch insert и пишет аудит явно.

При добавлении операций, которые обходят ActiveRecord, обязательно добавляйте явную запись аудита.

История должна оставаться восстановимой после архивирования, восстановления и повторных циклов архивирования.

## 15. FCC12 ingestion

Консольная команда:

```bash
php yii fcc-merge/run
```

Команда переносит данные из сырой таблицы `git_no_stro_extract_custom` в:

- `nostro_entries`;
- `nostro_balance`.

Пакеты выбираются из `tds_status`, где `type = 'FCC12'` и `is_merged = false`.

В рамках транзакции команда:

1. Читает строки по `extract_no`.
2. Переносит транзакции в `nostro_entries`.
3. Переносит балансы в `nostro_balance`.
4. Ищет счет по имени.
5. Проставляет `extract_no`, `line_no`, `branch_code`.
6. Пишет аудит для записей и балансов.
7. Помечает пакет как merged.
8. Удаляет исходные строки.

При изменении команды проверяйте идемпотентность, транзакционность и аудит.

## 16. Frontend-архитектура

Frontend использует несколько изолированных Vue-инстансов. Каждый стартер проверяет наличие своего root node и запускается только на нужной странице.

| Стартер | Root | Назначение |
|---|---|---|
| `page-entries.js` | `#entries-app` | Основная выверка. |
| `page-balance.js` | `#balance-app` | Балансы. |
| `page-archive.js` | `#archive-app` | Архив. |

Страницы `/all-nostro`, `/recon-report`, `/nostro-banks`, `/accounts`, `/references` используют собственные inline Vue-инстансы внутри view.

Общая инфраструктура:

- `web/js/app/common.js` - глобальные методы и computed для справочников.
- `web/js/app/api.js` - `SmartMatchApi` поверх axios.
- `web/js/app/datepicker.js` - директива `v-datepicker`.
- `web/js/app/state-storage.js` - хранение UI-состояния.
- `views/layouts/_vue-scripts.php` - публикация `window.AppRoutes`, `window.AppConfig`, `window.AppDictionaries`.

Правила frontend-разработки:

- Не создавайте глобальный Vue-инстанс для всех страниц.
- Новая бизнес-страница должна иметь свой root element.
- Общую логику выносите в mixin только если она реально переиспользуется.
- Не смешивайте состояние разных страниц.
- Все URL берите из `window.AppRoutes`, а не хардкодьте в JS-файлах.
- Для справочников используйте `dictCurrencies`, `dictCountries`, `dictCurrencyCodes`.

## 17. Персональные настройки UI

Настройки пользователя хранятся в `user_preferences`.

Контроллер: `controllers/UserPreferenceController.php`.

Сейчас используются настройки колонок таблиц:

- `entries_table_columns`;
- `balance_table_columns`;
- `archive_table_columns`;
- `balance_archive_table_columns`.

При добавлении нового ключа:

1. Добавьте ключ в whitelist контроллера.
2. Сохраняйте структурированный JSON.
3. Учитывайте совместимость со старыми значениями.
4. При ошибке загрузки настроек UI должен работать с дефолтами.

## 18. Миграции

Правила миграций:

- Название должно описывать изменение.
- Изменения схемы и данных должны быть обратимыми, если это возможно.
- Для больших таблиц избегайте долгих блокировок.
- Новые FK должны учитывать мультитенантность и сценарии удаления.
- Для денежных полей используйте `decimal(20,2)`.
- Для JSON-настроек используйте `jsonb`, если данные должны храниться в PostgreSQL как JSON.

После миграции обновите:

- модели и rules;
- контроллеры;
- формы и фильтры;
- тесты;
- документацию в `docs/`, если изменение влияет на пользователей или разработчиков.

## 19. Добавление новой страницы

Типовой порядок:

1. Создайте контроллер или action.
2. Добавьте route в `config/web.php`, если нужен красивый URL.
3. Создайте view в `views/<feature>/`.
4. Добавьте root element для Vue, если страница интерактивная.
5. Добавьте JS starter или inline Vue-инстанс по существующему паттерну.
6. Добавьте маршруты в `views/layouts/_vue-scripts.php`, если они нужны JS-коду.
7. Добавьте пункт меню в `views/layouts/main.php`, если страница должна быть доступна из навигации.
8. Проверьте доступ без компании и с компанией.
9. Добавьте тесты или ручной чек-лист.
10. Обновите документацию.

## 20. Добавление нового API action

Чек-лист:

- action доступен только авторизованному пользователю;
- response format JSON;
- входные параметры валидируются;
- выборка ограничена `company_id`;
- ошибки возвращаются структурированно;
- запись аудита добавлена, если action меняет важные данные;
- frontend использует route из `AppRoutes`;
- покрыты успешный сценарий и основные ошибки.

## 21. Тестирование изменений

Минимальный набор проверок зависит от изменения.

Для backend-логики:

```bash
vendor/bin/codecept run unit
```

Unit-тесты должны покрывать чистую доменную логику: модели, сервисы, валидацию, расчетные методы, audit hooks и SQL-логику, которую можно проверить без полноценного web-запроса.

Для контроллеров и пользовательских сценариев:

```bash
vendor/bin/codecept run functional
```

Functional-тесты нужны для маршрутов, access control, JSON API, `company_id` scoping и сценариев, где важно поведение приложения целиком: авторизация, request parsing, response format, транзакции контроллеров.

Для полного регресса:

```bash
vendor/bin/codecept run
```

Для миграций:

```bash
php yii migrate
php yii migrate/down
php yii migrate
```

Для frontend-изменений:

- открыть страницу вручную;
- проверить консоль браузера;
- проверить основной сценарий;
- проверить пустые состояния;
- проверить ошибку API;
- проверить сохранение настроек, если менялись таблицы или фильтры.

## 22. Консольные команды

Автоквитование:

```bash
php yii auto-match/run
php yii auto-match/run --company=1
php yii auto-match/run --company=1 --account=5
php yii auto-match/status
php yii auto-match/status --company=1
```

FCC12 merge:

```bash
php yii fcc-merge/run
```

Общий формат:

```bash
php yii <command>/<action>
```

## 23. Типовые проблемы

### API возвращает пустой список

Проверьте:

- выбран ли `company_id` у пользователя;
- есть ли фильтр по текущей компании;
- не передается ли лишний фильтр из UI;
- совпадает ли route в `AppRoutes`;
- не ожидает ли frontend `r.data`, когда helper уже вернул распакованный объект.

### Запись не попадает в раккорд

Проверьте:

- `match_status = U`;
- `match_id` пустой;
- счет входит в выбранный ностро-банк;
- дата попадает в выбранный режим;
- section соответствует компании или фильтру.

### Closing Balance в отчете пустой

Проверьте `nostro_balance`:

- `value_date` равна Date Reconciliation;
- `ls_type` верный;
- `section = NRE`;
- счет входит в нужный `account_pool`;
- валюта и компания корректны.

### Не сохраняются колонки таблицы

Проверьте:

- ключ добавлен в whitelist `UserPreferenceController`;
- frontend вызывает `userPreferenceSave`;
- данные являются валидным JSON;
- загрузка настроек не падает до установки флага `*_TableColumnsLoaded`.

### История неполная после архивации

Проверьте:

- пишется ли событие `archive`;
- пишется ли событие `restore`;
- сохраняется ли `original_id`;
- не появился ли FK, который обнуляет `nostro_entry_audit.entry_id`.

## 24. Правила сопровождения документации

При значимых изменениях обновляйте:

- `docs/USER_GUIDE.md` - если изменился пользовательский процесс.
- `docs/FAQ.md` - если появился типовой вопрос или изменилось поведение.
- `docs/DEVELOPER_GUIDE.md` - если изменилась архитектура, стек, команды, маршруты или соглашения разработки.
