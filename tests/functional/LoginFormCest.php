<?php

use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `LoginFormCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class LoginFormCest
{
    private User $user;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I)
    {
        SmartMatchTestHelper::resetDatabase();
        $company = SmartMatchTestHelper::createCompany();
        $this->user = SmartMatchTestHelper::createUser((int)$company->id, [
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => 'admin',
        ]);
        $I->amOnRoute('site/login');
    }

    /**
     * Выполняет тестовый сценарий: open login page.
     *
     * @return void
     */
    public function openLoginPage(\FunctionalTester $I)
    {
        $I->see('Login', 'h1');
    }

    // demonstrates `amLoggedInAs` method
    /**
     * Выполняет тестовый сценарий: internal login by id.
     *
     * @return void
     */
    public function internalLoginById(\FunctionalTester $I)
    {
        $I->amLoggedInAs((int)$this->user->id);
        $I->amOnRoute('site/index');
        $I->see('admin');
    }

    // demonstrates `amLoggedInAs` method
    /**
     * Выполняет тестовый сценарий: internal login by instance.
     *
     * @return void
     */
    public function internalLoginByInstance(\FunctionalTester $I)
    {
        $I->amLoggedInAs($this->user);
        $I->amOnRoute('site/index');
        $I->see('Выверка');
    }

    /**
     * Выполняет тестовый сценарий: login with empty credentials.
     *
     * @return void
     */
    public function loginWithEmptyCredentials(\FunctionalTester $I)
    {
        $I->submitForm('#login-form', []);
        $I->expectTo('see validations errors');
        $I->see('Username cannot be blank.');
        $I->see('Password cannot be blank.');
    }

    /**
     * Выполняет тестовый сценарий: login with wrong credentials.
     *
     * @return void
     */
    public function loginWithWrongCredentials(\FunctionalTester $I)
    {
        $I->submitForm('#login-form', [
            'LoginForm[username]' => 'admin',
            'LoginForm[password]' => 'wrong',
        ]);
        $I->expectTo('see validations errors');
        $I->see('Неверное имя пользователя или пароль.');
    }

    /**
     * Выполняет тестовый сценарий: login successfully.
     *
     * @return void
     */
    public function loginSuccessfully(\FunctionalTester $I)
    {
        $I->submitForm('#login-form', [
            'LoginForm[username]' => 'admin',
            'LoginForm[password]' => 'admin',
        ]);
        $I->see('admin');
        $I->dontSeeElement('form#login-form');
        Assert::assertFalse(Yii::$app->user->isGuest);
    }
}
