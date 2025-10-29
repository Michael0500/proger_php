Для реализации авторизации через сторонний API с использованием Bearer-токена в Yii2, а также сохранения токена в куки и его автоматической отправки в последующих запросах к API, можно использовать следующий подход:

---

### 1. **Конфигурация компонента для работы с API**

Создадим компонент `ApiService`, который будет отвечать за:

- Авторизацию (получение токена)
- Сохранение токена в куки
- Выполнение запросов к API с автоматической подстановкой токена

**Файл:** `components/ApiService.php`

```php
<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\httpclient\Client;

class ApiService extends Component
{
    public $apiBaseUrl = 'https://api.example.com';
    public $authEndpoint = '/auth/login';
    public $cookieName = 'api_token';
    public $cookieDuration = 3600; // 1 час

    private $_token = null;

    /**
     * Получает токен из куки или из внутреннего кэша
     */
    public function getToken()
    {
        if ($this->_token === null) {
            $this->_token = Yii::$app->request->cookies->getValue($this->cookieName);
        }
        return $this->_token;
    }

    /**
     * Авторизуется в API и сохраняет токен в куки
     */
    public function login($username, $password)
    {
        $client = new Client(['baseUrl' => $this->apiBaseUrl]);

        $response = $client->post($this->authEndpoint, [
            'username' => $username,
            'password' => $password,
        ])->send();

        if ($response->isOk) {
            $data = $response->data;
            if (isset($data['token'])) {
                $this->setToken($data['token']);
                return true;
            }
        }

        return false;
    }

    /**
     * Сохраняет токен в куки
     */
    public function setToken($token)
    {
        $this->_token = $token;
        $cookie = new \yii\web\Cookie([
            'name' => $this->cookieName,
            'value' => $token,
            'expire' => time() + $this->cookieDuration,
            'httpOnly' => true,
        ]);
        Yii::$app->response->cookies->add($cookie);
    }

    /**
     * Выполняет GET-запрос к API
     */
    public function get($url, $data = [])
    {
        return $this->request('GET', $url, $data);
    }

    /**
     * Выполняет POST-запрос к API
     */
    public function post($url, $data = [])
    {
        return $this->request('POST', $url, $data);
    }

    /**
     * Выполняет PUT-запрос к API
     */
    public function put($url, $data = [])
    {
        return $this->request('PUT', $url, $data);
    }

    /**
     * Выполняет DELETE-запрос к API
     */
    public function delete($url)
    {
        return $this->request('DELETE', $url);
    }

    /**
     * Общий метод для выполнения HTTP-запросов с токеном
     */
    protected function request($method, $url, $data = [])
    {
        $client = new Client(['baseUrl' => $this->apiBaseUrl]);
        $token = $this->getToken();

        $request = $client->createRequest()
            ->setMethod($method)
            ->setUrl($url)
            ->setData($data);

        if ($token) {
            $request->addHeaders(['Authorization' => 'Bearer ' . $token]);
        }

        return $request->send();
    }

    /**
     * Удаляет токен (логаут)
     */
    public function logout()
    {
        $this->_token = null;
        Yii::$app->response->cookies->remove($this->cookieName);
        unset(Yii::$app->request->cookies[$this->cookieName]);
    }
}
```

---

### 2. **Регистрация компонента в конфигурации**

**Файл:** `config/web.php`

```php
'components' => [
    // ...
    'api' => [
        'class' => 'app\components\ApiService',
        'apiBaseUrl' => 'https://your-api-domain.com',
        'authEndpoint' => '/auth/login',
        'cookieName' => 'api_bearer_token',
        'cookieDuration' => 3600,
    ],
],
```

---

### 3. **Пример использования в контроллере**

**Авторизация:**

```php
public function actionLogin()
{
    $model = new LoginForm();
    if ($model->load(Yii::$app->request->post()) && $model->validate()) {
        if (Yii::$app->api->login($model->username, $model->password)) {
            return $this->goBack();
        } else {
            Yii::$app->session->setFlash('error', 'Неверные учетные данные');
        }
    }
    return $this->render('login', ['model' => $model]);
}
```

**Запрос к API (например, получение списка записей):**

```php
public function actionIndex()
{
    $response = Yii::$app->api->get('/items');
    if ($response->isOk) {
        $items = $response->data;
    } else {
        // Обработка ошибки
        $items = [];
    }
    return $this->render('index', ['items' => $items]);
}
```

**Удаление записи:**

```php
public function actionDelete($id)
{
    $response = Yii::$app->api->delete("/items/{$id}");
    if ($response->isOk) {
        Yii::$app->session->setFlash('success', 'Запись удалена');
    } else {
        Yii::$app->session->setFlash('error', 'Ошибка удаления');
    }
    return $this->redirect(['index']);
}
```

---

### 4. **Безопасность**

- Убедитесь, что куки устанавливаются с флагами `HttpOnly` и, при возможности, `Secure` (если сайт работает по HTTPS).
- Рассмотрите использование `SameSite` атрибута для защиты от CSRF.
- Не храните чувствительные данные в куках — только токен.

---

Такой подход обеспечивает централизованную работу с API, автоматическую подстановку токена и безопасное хранение в куках.