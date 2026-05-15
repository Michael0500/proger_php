<?php

use yii\helpers\Url;

/**
 * Тестовый класс `AboutCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class AboutCest
{
    /**
     * Выполняет тестовый сценарий: ensure that about works.
     *
     * @return void
     */
    public function ensureThatAboutWorks(AcceptanceTester $I)
    {
        $I->amOnPage(Url::toRoute('/site/about'));
        $I->see('About', 'h1');
    }
}
