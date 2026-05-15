<?php

use yii\helpers\Url;

/**
 * Тестовый класс `HomeCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class HomeCest
{
    /**
     * Выполняет тестовый сценарий: ensure that home page works.
     *
     * @return void
     */
    public function ensureThatHomePageWorks(AcceptanceTester $I)
    {
        $I->amOnPage(Url::toRoute('/site/index'));
        $I->see('My Company');

        $I->seeLink('About');
        $I->click('About');
        $I->wait(2); // wait for page to be opened

        $I->see('This is the About page.');
    }
}
