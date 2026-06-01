<?php

namespace tests\unit\models;

use app\models\MatchingRule;

/**
 * Тестовый класс `MatchingRuleTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class MatchingRuleTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /**
     * Проверяет сценарий: conditions summary lists enabled criteria.
     * @return void
     */
    public function testConditionsSummaryListsEnabledCriteria(): void
    {
        $rule = new MatchingRule([
            'match_dc' => true,
            'match_amount' => true,
            'match_value_date' => false,
            'match_instruction_id' => true,
            'match_end_to_end_id' => false,
            'match_transaction_id' => false,
            'match_message_id' => true,
            'cross_id_search' => true,
        ]);

        verify($rule->conditionsSummary)->equals('D/C, Сумма, Instruction, Message, ⟷ Перекрёстный');

        $this->stdout('conditionsSummary правила: перечисляет включённые критерии (D/C, Сумма, Instruction, Message) и пометку перекрёстного поиска.');
    }

    /**
     * Проверяет сценарий: empty conditions summary uses dash.
     * @testdox Проверяет сценарий: empty conditions summary uses dash
     * @return void
     */
    public function testEmptyConditionsSummaryUsesDash(): void
    {
        $rule = new MatchingRule();

        verify($rule->conditionsSummary)->equals('—');

        $this->stdout('conditionsSummary правила без критериев: возвращает тире «—».');
    }
}
