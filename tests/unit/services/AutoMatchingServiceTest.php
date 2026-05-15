<?php

namespace tests\unit\services;

use app\models\MatchingRule;
use app\models\NostroEntry;
use app\services\MatchingService;

/**
 * Тестовый класс `AutoMatchingServiceTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class AutoMatchingServiceTest extends \Codeception\Test\Unit
{
    /**
     * Подготавливает окружение перед тестом.
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
    }

    /**
     * Проверяет сценарий: run rule matches unique ledger statement pair.
     * @return void
     */
    public function testRunRuleMatchesUniqueLedgerStatementPair(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_instruction_id' => true,
        ]);
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '250.00',
            'value_date' => '2026-01-15',
            'instruction_id' => 'AUTO-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '250.00',
            'value_date' => '2026-01-15',
            'instruction_id' => 'AUTO-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($statement->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($ledger->match_id)->equals($statement->match_id);
        verify($ledger->match_id)->equals('MTCH00000001');
    }

    /**
     * Проверяет сценарий: run rule supports cross id search.
     * @return void
     */
    public function testRunRuleSupportsCrossIdSearch(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_instruction_id' => true,
            'match_end_to_end_id' => true,
            'cross_id_search' => true,
        ]);
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '10.00',
            'value_date' => '2026-01-15',
            'instruction_id' => 'CROSS-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '10.00',
            'value_date' => '2026-01-15',
            'end_to_end_id' => 'CROSS-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_id)->equals($statement->match_id);
    }

    /**
     * Проверяет сценарий: run rule returns zero when rule has no join conditions.
     * @return void
     */
    public function testRunRuleReturnsZeroWhenRuleHasNoJoinConditions(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_dc' => false,
            'match_amount' => false,
            'match_value_date' => false,
            'match_instruction_id' => false,
            'match_end_to_end_id' => false,
            'match_transaction_id' => false,
            'match_message_id' => false,
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
        ]);

        verify((new MatchingService())->runRule($rule, (int)$company->id, null))->equals(0);
    }

    /**
     * Проверяет сценарий: auto match start and step process category scope.
     * @return void
     */
    public function testAutoMatchStartAndStepProcessCategoryScope(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $category = \SmartMatchTestHelper::createCategory((int)$company->id);
        $pool = \SmartMatchTestHelper::createPool((int)$company->id, ['category_id' => $category->id]);
        $account = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        \SmartMatchTestHelper::createRule((int)$company->id, ['match_instruction_id' => true]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'STEP-1',
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'STEP-1',
        ]);

        $service = new MatchingService();
        $start = $service->autoMatchStart((int)$company->id, null, MatchingRule::SECTION_NRE, 'category', (int)$category->id);

        verify($start['success'])->true();
        verify($start['total_steps'])->equals(1);
        verify($start['unmatched_count'])->equals(2);

        $step = $service->autoMatchStep($start['job_id']);

        verify($step['success'])->true();
        verify($step['is_finished'])->true();
        verify($step['total_matched'])->equals(1);
    }
}
