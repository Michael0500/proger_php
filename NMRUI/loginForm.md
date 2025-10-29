Вот полная реализация **LoginForm**, **контроллера авторизации** и **view-формы**, а также механизм автоматического перенаправления на страницу логина при устаревшем или отсутствующем токене.

---

## 1. **Модель LoginForm**

**Файл:** `models/LoginForm.php`

```php
<?php

namespace app\models;

use Yii;
use yii\base\Model;

class LoginForm extends Model
{
    public $username;
    public $password;
    public $rememberMe = true;

    private $_user = false;

    public function rules()
    {
        return [
            [['username', 'password'], 'required'],
            ['rememberMe', 'boolean'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'username' => 'Логин или email',
            'password' => 'Пароль',
            'rememberMe' => 'Запомнить меня',
        ];
    }

    /**
     * Авторизует пользователя через API
     * @return bool
     */
    public function login()
    {
        if ($this->validate()) {
            return Yii::$app->api->login($this->username, $this->password);
        }
        return false;
    }
}
```

---

## 2. **Контроллер авторизации**

**Файл:** `controllers/AuthController.php`

```php
<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\models\LoginForm;

class AuthController extends Controller
{
    public function actionLogin()
    {
        // Если уже авторизован — редирект на главную
        if (Yii::$app->api->getToken()) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            $returnUrl = Yii::$app->session->get('returnUrl') ?: Yii::$app->homeUrl;
            Yii::$app->session->remove('returnUrl');
            return $this->redirect($returnUrl);
        }

        return $this->render('login', [
            'model' => $model,
        ]);
    }

    public function actionLogout()
    {
        Yii::$app->api->logout();
        Yii::$app->session->setFlash('success', 'Вы вышли из системы.');
        return $this->goHome();
    }
}
```

---

## 3. **View-форма входа**

**Файл:** `views/auth/login.php`

```php
<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Вход';
?>

<h1><?= Html::encode($this->title) ?></h1>

<?php $form = ActiveForm::begin([
    'id' => 'login-form',
    'options' => ['class' => 'form-horizontal'],
]); ?>

<?= $form->field($model, 'username')->textInput(['autofocus' => true]) ?>
<?= $form->field($model, 'password')->passwordInput() ?>
<?= $form->field($model, 'rememberMe')->checkbox() ?>

<div class="form-group">
    <?= Html::submitButton('Войти', ['class' => 'btn btn-primary']) ?>
</div>

<?php ActiveForm::end(); ?>
```

---

## 4. **Автоматическое перенаправление при устаревшем токене**

Для этого добавим **поведение** или **мидлварь** через **beforeAction** в базовый контроллер (например, `AppController`), от которого будут наследоваться все контроллеры, требующие авторизации.

### Создайте базовый контроллер:

**Файл:** `controllers/AppController.php`

```php
<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;

class AppController extends Controller
{
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Проверяем, нужна ли авторизация для этого действия
        if ($this->requireAuth($action)) {
            $token = Yii::$app->api->getToken();
            if (!$token) {
                // Сохраняем URL, на который пользователь пытался зайти
                Yii::$app->session->set('returnUrl', Yii::$app->request->getUrl());
                return $this->redirect(['/auth/login']);
            }

            // Опционально: можно сделать "пинг" к API для проверки валидности токена
            // Но обычно API само вернёт 401, и тогда можно обработать это глобально (см. ниже)
        }

        return true;
    }

    /**
     * Определяет, требует ли действие авторизации.
     * Можно переопределить в дочерних контроллерах.
     */
    protected function requireAuth($action)
    {
        // По умолчанию — все действия требуют авторизации,
        // кроме явно разрешённых (например, site/index)
        $publicActions = [
            'site/index',
            'site/error',
            'auth/login',
            'auth/logout',
        ];

        $current = $action->controller->id . '/' . $action->id;
        return !in_array($current, $publicActions);
    }
}
```

Теперь ваши CRUD-контроллеры должны наследоваться от `AppController`:

```php
class ItemController extends AppController
{
    // Все действия автоматически защищены
}
```

---

### Альтернатива: глобальная обработка 401 от API

Если API возвращает **HTTP 401 Unauthorized** при невалидном/просроченном токене, можно перехватывать это в методах контроллера:

```php
$response = Yii::$app->api->get('/items');
if ($response->statusCode == 401) {
    Yii::$app->session->setFlash('error', 'Сессия истекла. Пожалуйста, войдите снова.');
    return $this->redirect(['/auth/login']);
}
```

Но лучше — централизованно через `AppController` + проверку токена перед запросом, как показано выше.

---

## 5. **Настройка маршрутов (опционально)**

Убедитесь, что в `config/web.php` или через правила URL вы можете обращаться к `/auth/login`.

Если используете ЧПУ:

```php
'urlManager' => [
    'enablePrettyUrl' => true,
    'showScriptName' => false,
    'rules' => [
        'login' => 'auth/login',
        'logout' => 'auth/logout',
        // ...
    ],
],
```

---

Теперь:

- При отсутствии токена — редирект на `/auth/login`
- После входа — возврат на запрошенную страницу
- Токен хранится в куках и автоматически подставляется во все API-запросы
- Есть кнопка выхода (`/auth/logout`)

Готово!