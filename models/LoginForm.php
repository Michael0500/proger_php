<?php

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * Форма входа пользователя.
 *
 * Модель валидирует логин и пароль, кэширует найденного пользователя и
 * выполняет авторизацию через компонент `Yii::$app->user`.
 */
class LoginForm extends Model
{
    public $username;
    public $password;
    public $rememberMe = true;

    private $_user = false;

    /**
     * Возвращает правила валидации формы входа.
     *
     * @return array Правила Yii Validator.
     */
    public function rules()
    {
        return [
            [['username', 'password'], 'required'],
            ['rememberMe', 'boolean'],
            ['password', 'validatePassword'],
        ];
    }

    /**
     * Проверяет пароль, введённый в форму.
     *
     * При неверном логине или пароле добавляет одинаковую ошибку, чтобы не
     * раскрывать, какая часть учётных данных неверна.
     *
     * @param string $attribute Имя атрибута пароля.
     * @param array $params Параметры валидатора Yii.
     * @return void
     */
    public function validatePassword($attribute, $params)
    {
        if (!$this->hasErrors()) {
            $user = $this->getUser();
            if (!$user || !$user->validatePassword($this->password)) {
                $this->addError($attribute, 'Неверное имя пользователя или пароль.');
            }
        }
    }

    /**
     * Авторизует пользователя при успешной валидации формы.
     *
     * @return bool Успешность входа в систему.
     */
    public function login()
    {
        if ($this->validate()) {
            return Yii::$app->user->login($this->getUser(), $this->rememberMe ? 3600 * 24 * 30 : 0);
        }
        return false;
    }

    /**
     * Возвращает пользователя по логину формы.
     *
     * Результат кэшируется в модели, чтобы повторные проверки пароля и login
     * не выполняли одинаковый запрос.
     *
     * @return User|null Найденный активный пользователь.
     */
    public function getUser()
    {
        if ($this->_user === false) {
            $this->_user = User::findByUsername($this->username);
        }
        return $this->_user;
    }
}
