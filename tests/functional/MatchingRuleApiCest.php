<?php

use app\models\MatchingRule;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет JSON API правил автоквитования.
 */
class MatchingRuleApiCest
{
    private User $user;
    private $company;
    private $foreignCompany;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        $this->company = SmartMatchTestHelper::createCompany(['code' => 'NRE']);
        $this->foreignCompany = SmartMatchTestHelper::createCompany(['code' => 'INV']);
        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: список правил ограничен текущей компанией.
     *
     * @return void
     */
    public function getRulesReturnsOnlyCurrentCompanyRulesOrderedByPriority(\FunctionalTester $I): void
    {
        $I->wantTo('Правила квитования: список ограничен текущей компанией и сортируется по приоритету');
        SmartMatchTestHelper::createRule((int)$this->company->id, ['name' => 'B', 'priority' => 20]);
        SmartMatchTestHelper::createRule((int)$this->company->id, ['name' => 'A', 'priority' => 10]);
        SmartMatchTestHelper::createRule((int)$this->foreignCompany->id, ['name' => 'FOREIGN', 'priority' => 1]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/matching/get-rules']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(['A', 'B'], array_column($response['data'], 'name'));
    }

    /**
     * Выполняет тестовый сценарий: сохранение создает правило в текущей компании.
     *
     * @return void
     */
    public function saveRuleCreatesRuleForCurrentCompanyAndNormalizesBooleans(\FunctionalTester $I): void
    {
        $I->wantTo('Правила квитования: создание использует текущую компанию и корректно читает boolean');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/save-rule']), [
            'name' => 'Amount only',
            'section' => MatchingRule::SECTION_NRE,
            'pair_type' => MatchingRule::PAIR_LS,
            'match_dc' => '0',
            'match_amount' => '1',
            'match_value_date' => '0',
            'match_instruction_id' => '0',
            'match_end_to_end_id' => '0',
            'match_transaction_id' => '0',
            'match_message_id' => '0',
            'cross_id_search' => '0',
            'is_active' => '0',
            'priority' => 30,
            'description' => 'Только сумма',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        $rule = MatchingRule::findOne($response['id']);
        Assert::assertNotNull($rule);
        Assert::assertSame((int)$this->company->id, (int)$rule->company_id);
        Assert::assertFalse((bool)$rule->match_dc);
        Assert::assertTrue((bool)$rule->match_amount);
        Assert::assertFalse((bool)$rule->is_active);
    }

    /**
     * Выполняет тестовый сценарий: нельзя обновить чужое правило.
     *
     * @return void
     */
    public function saveRuleRejectsForeignRuleUpdate(\FunctionalTester $I): void
    {
        $I->wantTo('Правила квитования: обновление чужого правила запрещено');
        $foreignRule = SmartMatchTestHelper::createRule((int)$this->foreignCompany->id, ['name' => 'FOREIGN']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/save-rule']), [
            'id' => $foreignRule->id,
            'name' => 'HACKED',
            'section' => MatchingRule::SECTION_NRE,
            'pair_type' => MatchingRule::PAIR_LS,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Правило не найдено', $response['message']);

        $foreignRule->refresh();
        Assert::assertSame('FOREIGN', $foreignRule->name);
    }

    /**
     * Выполняет тестовый сценарий: нельзя удалить чужое правило.
     *
     * @return void
     */
    public function deleteRuleRejectsForeignRule(\FunctionalTester $I): void
    {
        $I->wantTo('Правила квитования: удаление чужого правила запрещено');
        $foreignRule = SmartMatchTestHelper::createRule((int)$this->foreignCompany->id);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/delete-rule']), [
            'id' => $foreignRule->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Правило не найдено', $response['message']);
        Assert::assertSame(1, (int)MatchingRule::find()->where(['id' => $foreignRule->id])->count());
    }

    /**
     * Выполняет тестовый сценарий: свое правило удаляется.
     *
     * @return void
     */
    public function deleteRuleRemovesCurrentCompanyRule(\FunctionalTester $I): void
    {
        $I->wantTo('Правила квитования: свое правило удаляется');
        $rule = SmartMatchTestHelper::createRule((int)$this->company->id);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/delete-rule']), [
            'id' => $rule->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(0, (int)MatchingRule::find()->where(['id' => $rule->id])->count());
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
