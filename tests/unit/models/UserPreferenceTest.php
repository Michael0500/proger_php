<?php

namespace tests\unit\models;

use app\models\UserPreference;

/**
 * Тестовый класс `UserPreferenceTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class UserPreferenceTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /**
     * Подготавливает окружение перед тестом.
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
    }

    /**
     * Проверяет сценарий: set value upserts json preference.
     * @return void
     */
    public function testSetValueUpsertsJsonPreference(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $user = \SmartMatchTestHelper::createUser((int)$company->id);
        $value = [
            ['key' => 'amount', 'visible' => true, 'width' => 120],
            ['key' => 'comment', 'visible' => false, 'width' => 180],
        ];

        verify(UserPreference::setValue((int)$user->id, UserPreference::KEY_ENTRIES_TABLE_COLUMNS, $value))->true();
        verify(UserPreference::getValue((int)$user->id, UserPreference::KEY_ENTRIES_TABLE_COLUMNS))->equals($value);

        $updated = [['key' => 'amount', 'visible' => false, 'width' => 140]];
        UserPreference::setValue((int)$user->id, UserPreference::KEY_ENTRIES_TABLE_COLUMNS, $updated);

        verify(UserPreference::find()->count())->equals(1);
        verify(UserPreference::getValue((int)$user->id, UserPreference::KEY_ENTRIES_TABLE_COLUMNS))->equals($updated);

        $this->stdout('setValue делает upsert JSON-настройки: повторное сохранение того же ключа обновляет одну строку (count=1), getValue возвращает новое значение.');
    }

    /**
     * Проверяет сценарий: get value decodes old double encoded json.
     * @return void
     */
    public function testGetValueDecodesOldDoubleEncodedJson(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $user = \SmartMatchTestHelper::createUser((int)$company->id);
        $value = [['key' => 'amount', 'visible' => true]];

        \Yii::$app->db->createCommand()->insert(UserPreference::tableName(), [
            'user_id' => $user->id,
            'pref_key' => UserPreference::KEY_ENTRIES_TABLE_COLUMNS,
            'pref_value' => json_encode(json_encode($value)),
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();

        verify(UserPreference::getValue((int)$user->id, UserPreference::KEY_ENTRIES_TABLE_COLUMNS))->equals($value);

        $this->stdout('getValue корректно читает старый дважды-закодированный JSON (legacy double-encoded), возвращая исходный массив.');
    }
}
