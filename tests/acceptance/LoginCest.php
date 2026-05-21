<?php

use yii\helpers\Url;

/**
 * Тестовый класс `LoginCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class LoginCest
{
    /**
     * Выполняет тестовый сценарий: ensure that login works.
     *
     * @return void
     */
    public function ensureThatLoginWorks(AcceptanceTester $I)
    {
        $I->amOnPage(Url::toRoute('/site/login'));
        $I->see('Login', 'h1');
    }
}
