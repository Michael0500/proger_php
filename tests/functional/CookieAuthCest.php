<?php

use app\models\User;

/**
 * Тестовый класс `CookieAuthCest`.
 *
 * Проверяет функциональную авторизацию через cookie/session helper.
 */
class CookieAuthCest
{
    private User $user;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        $company = SmartMatchTestHelper::createCompany();
        $this->user = SmartMatchTestHelper::createUser((int)$company->id, [
            'username' => 'admin',
            'email' => 'admin@example.test',
        ]);
    }

    /**
     * Выполняет тестовый сценарий: guest sees login page.
     *
     * @return void
     */
    public function guestSeesLoginPage(\FunctionalTester $I): void
    {
        $I->amOnRoute('site/login');
        $I->see('Login', 'h1');
    }

    /**
     * Выполняет тестовый сценарий: protected page redirects guest.
     *
     * @return void
     */
    public function protectedPageRedirectsGuest(\FunctionalTester $I): void
    {
        $I->amOnRoute('nostro-balance/page');
        $I->see('Login', 'h1');
    }

    /**
     * Выполняет тестовый сценарий: internal login by id.
     *
     * @return void
     */
    public function internalLoginById(\FunctionalTester $I): void
    {
        $I->amLoggedInAs((int)$this->user->id);
        $I->amOnRoute('site/index');
        $I->see('admin');
    }

    /**
     * Выполняет тестовый сценарий: internal login by instance.
     *
     * @return void
     */
    public function internalLoginByInstance(\FunctionalTester $I): void
    {
        $I->amLoggedInAs($this->user);
        $I->amOnRoute('site/index');
        $I->see('Выверка');
    }
}
