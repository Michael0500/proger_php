<?php

use app\models\Country;
use app\models\Currency;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет JSON API общесистемных справочников.
 */
class ReferenceApiCest
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
        Currency::deleteAll();
        Country::deleteAll();

        $company = SmartMatchTestHelper::createCompany();
        $this->user = SmartMatchTestHelper::createUser((int)$company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: CRUD валют нормализует код и сортирует список.
     *
     * @return void
     */
    public function currencyCrudNormalizesCodeAndSortsList(\FunctionalTester $I): void
    {
        $I->wantTo('Справочник валют: CRUD нормализует код и сортирует список');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/currency-create']), [
            'code' => 'usd',
            'name' => 'US Dollar',
            'symbol' => '$',
            'is_active' => '1',
            'sort_order' => 20,
        ]);
        $created = $this->grabJson($I);

        Assert::assertTrue($created['success']);
        Assert::assertSame('USD', $created['data']['code']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/currency-create']), [
            'code' => 'eur',
            'name' => 'Euro',
            'sort_order' => 10,
        ]);
        $this->grabJson($I);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/reference/currencies']));
        $list = $this->grabJson($I);
        Assert::assertTrue($list['success']);
        Assert::assertSame(['EUR', 'USD'], array_column($list['data'], 'code'));

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/currency-update']), [
            'id' => $created['data']['id'],
            'code' => 'gbp',
            'name' => 'Pound Sterling',
            'symbol' => '',
            'is_active' => '0',
            'sort_order' => 30,
        ]);
        $updated = $this->grabJson($I);
        Assert::assertTrue($updated['success']);
        Assert::assertSame('GBP', $updated['data']['code']);
        Assert::assertFalse($updated['data']['is_active']);
        Assert::assertNull($updated['data']['symbol']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/currency-delete']), [
            'id' => $created['data']['id'],
        ]);
        $deleted = $this->grabJson($I);
        Assert::assertTrue($deleted['success']);
        Assert::assertSame(0, (int)Currency::find()->where(['code' => 'GBP'])->count());
    }

    /**
     * Выполняет тестовый сценарий: CRUD стран нормализует коды.
     *
     * @return void
     */
    public function countryCrudNormalizesCodes(\FunctionalTester $I): void
    {
        $I->wantTo('Справочник стран: CRUD нормализует alpha-2 и alpha-3 коды');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/country-create']), [
            'code' => 'us',
            'code3' => 'usa',
            'name' => 'United States',
            'is_active' => '1',
            'sort_order' => 5,
        ]);
        $created = $this->grabJson($I);

        Assert::assertTrue($created['success']);
        Assert::assertSame('US', $created['data']['code']);
        Assert::assertSame('USA', $created['data']['code3']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/country-update']), [
            'id' => $created['data']['id'],
            'code' => 'de',
            'code3' => '',
            'name' => 'Germany',
            'is_active' => '0',
            'sort_order' => 7,
        ]);
        $updated = $this->grabJson($I);

        Assert::assertTrue($updated['success']);
        Assert::assertSame('DE', $updated['data']['code']);
        Assert::assertNull($updated['data']['code3']);
        Assert::assertFalse($updated['data']['is_active']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/country-delete']), [
            'id' => $created['data']['id'],
        ]);
        $deleted = $this->grabJson($I);
        Assert::assertTrue($deleted['success']);
        Assert::assertSame(0, (int)Country::find()->where(['code' => 'DE'])->count());
    }

    /**
     * Выполняет тестовый сценарий: невалидные ISO-коды отклоняются.
     *
     * @return void
     */
    public function invalidIsoCodesAreRejected(\FunctionalTester $I): void
    {
        $I->wantTo('Справочники: невалидные ISO-коды отклоняются');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/currency-create']), [
            'code' => 'US1',
            'name' => 'Broken',
        ]);
        $currency = $this->grabJson($I);
        Assert::assertFalse($currency['success']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/reference/country-create']), [
            'code' => 'USA',
            'name' => 'Broken',
        ]);
        $country = $this->grabJson($I);
        Assert::assertFalse($country['success']);
    }

    /**
     * Декодирует JSON-ответ текущей страницы.
     *
     * @return array
     */
    private function grabJson(\FunctionalTester $I): array
    {
        $decoded = json_decode($I->grabPageSource(), true);
        Assert::assertIsArray($decoded);
        return $decoded;
    }
}
