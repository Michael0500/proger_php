<?php

namespace tests\unit\models;

use app\models\User;

/**
 * Тестовый класс `CookieAuthTest`.
 *
 * Проверяет внутреннюю авторизацию пользователя без паролей и токенов.
 */
class CookieAuthTest extends \Codeception\Test\Unit
{
    private User $user;

    /**
     * Подготавливает окружение перед тестом.
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
        $company = \SmartMatchTestHelper::createCompany();
        $this->user = \SmartMatchTestHelper::createUser((int)$company->id, [
            'username' => 'demo',
            'email' => 'demo@example.test',
        ]);
    }

    /**
     * Очищает состояние авторизации после теста.
     * @return void
     */
    protected function _after(): void
    {
        \Yii::$app->user->logout(false);
    }

    /**
     * Проверяет, что пользователь по умолчанию не авторизован.
     * @return void
     */
    public function testGuestByDefault(): void
    {
        verify(\Yii::$app->user->isGuest)->true();
    }

    /**
     * Проверяет внутренний вход через Yii user component без проверки пароля.
     * @return void
     */
    public function testCookieSessionLogin(): void
    {
        verify(\Yii::$app->user->login($this->user, 0))->true();
        verify(\Yii::$app->user->isGuest)->false();
        verify((int)\Yii::$app->user->id)->equals((int)$this->user->id);
    }
}
