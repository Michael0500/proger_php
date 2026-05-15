<?php

namespace tests\unit\models;

use app\models\LoginForm;

/**
 * Тестовый класс `LoginFormTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class LoginFormTest extends \Codeception\Test\Unit
{
    private $model;
    private $user;

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
            'password' => 'demo',
        ]);
    }

    /**
     * Очищает окружение после теста.
     * @return void
     */
    protected function _after()
    {
        \Yii::$app->user->logout(false);
    }

    /**
     * Проверяет сценарий: login no user.
     * @return void
     */
    public function testLoginNoUser()
    {
        $this->model = new LoginForm([
            'username' => 'not_existing_username',
            'password' => 'not_existing_password',
        ]);

        verify($this->model->login())->false();
        verify(\Yii::$app->user->isGuest)->true();
    }

    /**
     * Проверяет сценарий: login wrong password.
     * @return void
     */
    public function testLoginWrongPassword()
    {
        $this->model = new LoginForm([
            'username' => 'demo',
            'password' => 'wrong_password',
        ]);

        verify($this->model->login())->false();
        verify(\Yii::$app->user->isGuest)->true();
        verify($this->model->errors)->arrayHasKey('password');
    }

    /**
     * Проверяет сценарий: login correct.
     * @return void
     */
    public function testLoginCorrect()
    {
        $this->model = new LoginForm([
            'username' => 'demo',
            'password' => 'demo',
        ]);

        verify($this->model->login())->true();
        verify(\Yii::$app->user->isGuest)->false();
        verify($this->model->errors)->arrayHasNotKey('password');
    }
}
