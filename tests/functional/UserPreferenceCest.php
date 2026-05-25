<?php

use app\models\User;
use app\models\UserPreference;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `UserPreferenceCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class UserPreferenceCest
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
        $this->user = SmartMatchTestHelper::createUser((int)$company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: save and load allowed preference.
     *
     * @return void
     */
    public function saveAndLoadAllowedPreference(\FunctionalTester $I): void
    {
        $I->wantTo('UserPreference: сохраняет и читает значение по разрешённому ключу');
        $value = [
            ['key' => 'amount', 'visible' => true, 'width' => 120],
        ];

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/user-preference/save']), [
            'key' => UserPreference::KEY_ENTRIES_TABLE_COLUMNS,
            'value' => $value,
        ]);
        Assert::assertTrue($this->grabJson($I)['success']);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/user-preference/get']), [
            'key' => UserPreference::KEY_ENTRIES_TABLE_COLUMNS,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertEquals($value, $response['value']);
    }

    /**
     * Выполняет тестовый сценарий: rejects unknown preference key.
     *
     * @return void
     */
    public function rejectsUnknownPreferenceKey(\FunctionalTester $I): void
    {
        $I->wantTo('UserPreference: отклоняет сохранение по неизвестному ключу');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/user-preference/save']), [
            'key' => 'unknown_key',
            'value' => ['x' => true],
        ]);
        $response = $this->grabJson($I);
        Assert::assertFalse($response['success']);
        Assert::assertSame('Неизвестный ключ', $response['message']);
    }

    /**
     * Выполняет тестовый сценарий: grab json.
     *
     * @return void
     */
    private function grabJson(\FunctionalTester $I): array
    {
        $decoded = json_decode($I->grabPageSource(), true);
        Assert::assertIsArray($decoded);
        return $decoded;
    }
}
