Ниже — пример того, как объединить описанные ранее идеи (валидацию телефонов, выбор канала, фейковые данные и т. п.) в **единый модуль Yii2**. Показана приблизительная структура проекта, использование сервисов (DI), консольных контроллеров, миграций и т. д. Этот пример не претендует на «идеальный продакшен-код», но демонстрирует подход к организации кода «все в одном месте».

---

## 1. Общая структура модуля

Допустим, создадим Yii2-модуль под названием **`notifications`** в папке `modules/notifications`. Структура может выглядеть так:

```
modules/
 └─ notifications/
     ├─ Module.php                       # Класс модуля
     ├─ config.php                       # (опционально) настройки модуля
     ├─ migrations/
     │   ├─ m230101_000001_create_client_table.php
     │   ├─ ... (другие миграции)
     ├─ models/
     │   ├─ Client.php                   # ActiveRecord (или просто модель)
     │   └─ ...
     ├─ services/
     │   ├─ ChannelChecker.php           # Сервис определения каналов
     │   ├─ NotificationService.php      # Сервис отправки уведомлений
     │   └─ ...
     ├─ controllers/
     │   ├─ NotifyController.php         # Web-контроллер (если нужно)
     │   └─ ConsoleController.php        # Консольный контроллер
     ├─ tests/
     │   └─ TestChannelCheckerController.php # (Опционально) Тестовый контроллер
     └─ ...
```

### 1.1. Файл `Module.php`

Это стандартный класс модуля Yii2. В нем можно подключить свой контейнер, сконфигурировать сервисы и т. п.

```php
<?php

namespace app\modules\notifications;

use Yii;
use yii\base\Module as BaseModule;

class Module extends BaseModule
{
    public $controllerNamespace = 'app\modules\notifications\controllers';

    public function init()
    {
        parent::init();
        
        // Если нужно — подключаем конфигурацию, настраиваем DI контейнер
        $this->registerServices();
    }

    protected function registerServices()
    {
        // Пример: регистрируем ChannelChecker в DI-контейнере
        Yii::$container->set('channelChecker', [
            'class' => 'app\modules\notifications\services\ChannelChecker',
        ]);

        // Или, если нужно, NotificationService
        Yii::$container->set('notificationService', [
            'class' => 'app\modules\notifications\services\NotificationService',
        ]);
    }
}
```

После этого нужно **подключить модуль** в конфиге приложения (например, `config/web.php` и `config/console.php`), чтобы он был виден:

```php
'modules' => [
    'notifications' => [
        'class' => 'app\modules\notifications\Module',
    ],
    // ...
],
```

Тогда вы сможете обращаться к этому модулю как к `Yii::$app->getModule('notifications')`, а также запускать его контроллеры.

---

## 2. Миграции (пример)

В папку `modules/notifications/migrations` можно положить файлы миграций. Например, миграцию для таблицы `client`:

```php
<?php

namespace app\modules\notifications\migrations;

use yii\db\Migration;

class m230101_000001_create_client_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('client', [
            'id' => $this->primaryKey(),
            'phone_for_sms_password' => $this->string()->null(),
            'mobile_phone' => $this->string()->null(),
            'phone_number' => $this->string()->null(),
            'phone_number_unicredit' => $this->text()->null(), // JSON? или просто text
            'email' => $this->string()->null(),
            'enterimb_status' => $this->string()->defaultValue('Не подключён'),
            'enterimb_last_login' => $this->dateTime()->null(),
            // ... при необходимости, флаги, статусы
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('client');
    }
}
```

Чтобы применить миграцию, запустите в консоли:
```bash
php yii migrate --migrationPath=@app/modules/notifications/migrations
```
(если ваш `basePath` настроен на `@app`).

---

## 3. Модель `Client.php`

Допустим, мы хотим использовать ActiveRecord (чтобы данные реально хранились в БД):

```php
<?php

namespace app\modules\notifications\models;

use yii\db\ActiveRecord;

class Client extends ActiveRecord
{
    public static function tableName()
    {
        return 'client';
    }

    // Если нужно, добавьте rules(), relations() и т.п.
}
```

---

## 4. Сервис `ChannelChecker.php`

Все правила по приоритету телефонов, нормализации и т. д. из прошлых ответов — теперь внутри модуля.

```php
<?php

namespace app\modules\notifications\services;

use yii\helpers\ArrayHelper;
use DateTime;
use DateTimeZone;
use app\modules\notifications\models\Client;

/**
 * Сервис определения, какие каналы доступны для конкретного клиента.
 */
class ChannelChecker
{
    /**
     * Возвращает список каналов: ['sms', 'email', 'enterimb'] (или пустой, если ничто не доступно)
     */
    public function getAvailableChannels(Client $client)
    {
        $availableChannels = [];

        // 1. Проверка SMS (ищем телефон по приоритетам)
        $smsPhone = $this->findValidSmsPhone($client);
        if ($smsPhone !== null) {
            $availableChannels[] = 'sms';
        }

        // 2. Проверка E-mail
        if ($this->checkEmailIsValid($client->email)) {
            $availableChannels[] = 'email';
        }

        // 3. Проверка EnterIMB/MCSP
        if ($this->checkEnterIMB($client)) {
            $availableChannels[] = 'enterimb';
        }

        return $availableChannels;
    }

    protected function findValidSmsPhone(Client $client)
    {
        // Приоритет 1
        if (!empty($client->phone_for_sms_password)) {
            $phone = $this->normalizePhone($client->phone_for_sms_password);
            if ($this->isPhoneValid($phone)) {
                return $phone;
            }
        }

        // Приоритет 2
        if (!empty($client->mobile_phone)) {
            $phone = $this->normalizePhone($client->mobile_phone);
            if ($this->isPhoneValid($phone)) {
                return $phone;
            }
        }

        // Приоритет 3
        if (!empty($client->phone_number)) {
            $phone = $this->normalizePhone($client->phone_number);
            if ($this->isPhoneValid($phone)) {
                return $phone;
            }
        }

        // Приоритет 4 (может быть массив номеров или один)
        if (!empty($client->phone_number_unicredit)) {
            // Предположим, что в БД это поле хранится как JSON,
            // и в геттере мы можем преобразовывать к массиву...
            // Если нет — можно строку парсить вручную.
            $candidates = $this->parsePhoneNumberUnicredit($client->phone_number_unicredit);
            foreach ($candidates as $raw) {
                $phone = $this->normalizePhone($raw);
                if ($this->isPhoneValid($phone)) {
                    return $phone;
                }
            }
        }

        return null;
    }

    /**
     * Распарсим phone_number_unicredit (если хранится JSON или через разделители).
     */
    protected function parsePhoneNumberUnicredit($value)
    {
        // Допустим, пытаемся decode как JSON
        $arr = @json_decode($value, true);
        if (is_array($arr)) {
            return $arr;
        }

        // Если не JSON, вернём как массив из одной строки
        return [$value];
    }

    /**
     * Нормализация телефона: убираем скобки, пробелы, дефисы, etc.
     */
    protected function normalizePhone($rawPhone)
    {
        $phone = trim($rawPhone);
        return preg_replace('/[^+0-9]/', '', $phone);
    }

    /**
     * Проверка, что телефон соответствует условным форматам (см. предыдущие примеры).
     */
    protected function isPhoneValid($phone)
    {
        if (preg_match('/^\+79\d{9}$/', $phone)) {
            return true;
        }
        if (preg_match('/^79\d{9}$/', $phone)) {
            return true;
        }
        if (preg_match('/^89\d{9}$/', $phone)) {
            return true;
        }
        if (preg_match('/^9\d{9}$/', $phone)) {
            return true;
        }
        return false;
    }

    protected function checkEmailIsValid($email)
    {
        if (empty($email)) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function checkEnterIMB(Client $client)
    {
        if ($client->enterimb_status !== 'Подключен') {
            return false;
        }
        if (empty($client->enterimb_last_login)) {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $client->enterimb_last_login, new DateTimeZone('UTC'));
        if (!$dt) {
            return false;
        }
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $diffSec = $now->getTimestamp() - $dt->getTimestamp();
        // 3 дня
        if ($diffSec <= 3 * 24 * 3600) {
            return true;
        }
        return false;
    }
}
```

---

## 5. Сервис `NotificationService.php`

Это может быть отдельный сервис (который вызывает `ChannelChecker`, формирует само уведомление и отправляет). Пример:

```php
<?php

namespace app\modules\notifications\services;

use Yii;
use app\modules\notifications\models\Client;

class NotificationService
{
    /**
     * Отправляет уведомление для клиента: определяем доступные каналы — и отправляем в нужные.
     */
    public function sendNotification(Client $client, $message)
    {
        // Получим через DI наш ChannelChecker
        /** @var ChannelChecker $channelChecker */
        $channelChecker = Yii::$container->get('channelChecker');

        $channels = $channelChecker->getAvailableChannels($client);
        if (empty($channels)) {
            // Ни одного канала нет — логируем, возвращаем false
            Yii::warning("No channels available for client #{$client->id}");
            return false;
        }

        // Допустим, отправим во все доступные каналы — либо только в 1-й.  
        // Для примера — во все.
        foreach ($channels as $ch) {
            switch ($ch) {
                case 'sms':
                    $this->sendSms($client, $message);
                    break;
                case 'email':
                    $this->sendEmail($client, $message);
                    break;
                case 'enterimb':
                    $this->sendEnterIMB($client, $message);
                    break;
            }
        }

        return true;
    }

    protected function sendSms(Client $client, $message)
    {
        // Для отправки SMS понадобится внешний сервис (HTTP API, через Yii::$app->smsSender, и т.п.)
        // Для примера - просто лог.
        Yii::info("Send SMS to client #{$client->id}: {$message}", __METHOD__);
    }

    protected function sendEmail(Client $client, $message)
    {
        // Отправка письма (упрощённо через Yii2 mailer):
        if (empty($client->email)) {
            return;
        }
        Yii::$app->mailer->compose()
            ->setTo($client->email)
            ->setSubject('Notification')
            ->setTextBody($message)
            ->send();
    }

    protected function sendEnterIMB(Client $client, $message)
    {
        // Допустим, это push в мобильное приложение или сообщение в онлайн-банк:
        // Опять же, зависит от реализации.
        Yii::info("Send EnterIMB message to client #{$client->id}: {$message}", __METHOD__);
    }
}
```

---

## 6. Консольный контроллер для теста (или для реальной рассылки)

Создадим `ConsoleController.php` внутри `modules/notifications/controllers`:

```php
<?php

namespace app\modules\notifications\controllers;

use Yii;
use yii\console\Controller;
use app\modules\notifications\models\Client;
use app\modules\notifications\services\NotificationService;
use Faker\Factory as FakerFactory;

class ConsoleController extends Controller
{
    /**
     * Пример: сгенерируем нескольких клиентов (fake), проверим доступные каналы и отправим сообщение.
     */
    public function actionTest()
    {
        // Получаем наш сервис из DI
        /** @var NotificationService $notificationService */
        $notificationService = Yii::$container->get('notificationService');

        $faker = FakerFactory::create('ru_RU');

        for ($i = 1; $i <= 5; $i++) {
            // Создадим новую запись в БД (или просто объект)
            $client = new Client();
            $client->id = $i;
            $client->phone_for_sms_password = $faker->boolean(70) ? $faker->phoneNumber : null;
            $client->mobile_phone = $faker->boolean(50) ? $faker->phoneNumber : null;
            $client->phone_number = $faker->boolean(30) ? $faker->phoneNumber : null;
            
            // Иногда пусть будет массив JSON
            if ($faker->boolean(30)) {
                $client->phone_number_unicredit = json_encode([
                    $faker->phoneNumber,
                    $faker->phoneNumber,
                ]);
            } else {
                $client->phone_number_unicredit = $faker->boolean(30) ? $faker->phoneNumber : null;
            }

            $client->email = $faker->boolean(80) ? $faker->email : 'invalid-email';
            
            // Примем логику: 60% Подключен, 20% Заблокирован, 20% Не подключён
            $rnd = mt_rand(1,10);
            if ($rnd <= 6) {
                $client->enterimb_status = 'Подключен';
                // дата последнего входа от 0 до 5 дней назад
                $client->enterimb_last_login = date('Y-m-d H:i:s', strtotime("-".mt_rand(0,5)." days"));
            } elseif ($rnd <= 8) {
                $client->enterimb_status = 'Заблокирован';
                $client->enterimb_last_login = null;
            } else {
                $client->enterimb_status = 'Не подключён';
                $client->enterimb_last_login = null;
            }

            // Если используем ActiveRecord, можем сделать $client->save() (но тогда нужно валидировать)
            // Для упрощения: просто оставим объект в памяти.

            // Отправим "уведомление"
            $ok = $notificationService->sendNotification($client, "Test message #{$i}");

            echo "Client #{$i} => " . ($ok ? "SENT" : "NO CHANNELS") . "\n";
        }

        return Controller::EXIT_CODE_NORMAL;
    }
}
```

> Теперь в консоли можно вызвать:
> ```bash
> php yii notifications/console/test
> ```
> И увидеть, какие клиенты создались, куда отправилось уведомление и т. д.

---

## 7. Подключение и запуск

1. **Добавьте модуль** в `config/console.php` и (опционально) в `config/web.php`:
   ```php
   'modules' => [
       'notifications' => [
           'class' => 'app\modules\notifications\Module',
       ],
   ],
   ```
2. Убедитесь, что у вас установлен **Faker** (если вы используете его в продакшене, обычно он нужен только как dev-зависимость).
3. Запустите миграции:
   ```bash
   php yii migrate --migrationPath=@app/modules/notifications/migrations
   ```
4. Наберите:
   ```bash
   php yii notifications/console/test
   ```
   Получите вывод, демонстрирующий работу `ChannelChecker` и `NotificationService`.

---

## Итого

- Мы **создали Yii2-модуль** `notifications`, в котором лежит вся логика (сервисы, модели, контроллеры, миграции).
- В **`ChannelChecker`** инкапсулирована логика определения доступных каналов (SMS, E-mail, EnterIMB) с учетом приоритета телефонов, нормализации, проверки формата и проч.
- В **`NotificationService`** логика отправки уведомлений, где вызывается `ChannelChecker` и дальше идёт реальное (или тестовое) использование SMS/E-mail/EnterIMB.
- В консольном **`ConsoleController`** мы делаем пример с помощью **Faker** — генерируем фейковых клиентов, проверяем каналы и показываем результат.
- **DI-контейнер** (через `Yii::$container->set()`) в `Module.php` позволяет в любом месте проекта получить сервисы по имени (например, `Yii::$container->get('channelChecker')`).

Таким образом, это **завершённый пример** «полу-боевого» кода, который можно дорабатывать и адаптировать под реальные требования.