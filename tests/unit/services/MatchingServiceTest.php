<?php

namespace tests\unit\services;

use app\models\MatchingRule;
use app\models\NostroEntry;
use app\services\MatchingService;

/**
 * Тестовый класс `MatchingServiceTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class MatchingServiceTest extends \Codeception\Test\Unit
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
     * Проверяет сценарий: manual match balances ledger and statement.
     * @return void
     */
    public function testManualMatchBalancesLedgerAndStatement(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '100.00',
        ]);

        $result = (new MatchingService())->matchManual([$ledger->id, $statement->id]);

        verify($result['success'])->true();
        verify($result['match_id'])->equals('MTCH00000001');

        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($statement->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($ledger->match_id)->equals($statement->match_id);
        verify($ledger->matched_at)->notEmpty();
    }

    /**
     * Проверяет сценарий: manual match rejects imbalanced nre set.
     * @return void
     */
    public function testManualMatchRejectsImbalancedNreSet(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '90.00',
        ]);

        $result = (new MatchingService())->matchManual([$ledger->id, $statement->id]);

        verify($result['success'])->false();
        verify($result['warning'])->true();
        verify($result['diff'])->equals(10.0);
    }

    /**
     * Проверяет сценарий: manual match allows single zero amount entry.
     * @return void
     */
    public function testManualMatchAllowsSingleZeroAmountEntry(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $entry = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'amount' => '0.00',
        ]);

        $result = (new MatchingService())->matchManual([$entry->id]);

        verify($result['success'])->true();
        verify($result['count'])->equals(1);
    }

    /**
     * Проверяет сценарий: manual match balances inv by debit and credit.
     * @return void
     */
    public function testManualMatchBalancesInvByDebitAndCredit(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $debit = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '50.00',
        ]);
        $credit = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '50.00',
        ]);

        $result = (new MatchingService())->matchManual([$debit->id, $credit->id], MatchingRule::SECTION_INV);

        verify($result['success'])->true();
        verify($result['count'])->equals(2);
    }

    /**
     * Проверяет сценарий: unmatch clears whole match group.
     * @return void
     */
    public function testUnmatchClearsWholeMatchGroup(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $matchId = 'MTCHTEST' . strtoupper(bin2hex(random_bytes(4)));
        $first = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'match_id' => $matchId,
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);
        $second = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'match_id' => $matchId,
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);

        verify(NostroEntry::find()->where(['match_id' => $matchId, 'company_id' => $company->id])->count())->equals(2);

        $result = (new MatchingService())->unmatch($matchId, (int)$company->id);

        verify($result['success'])->true();
        verify($result['count'])->equals(2);

        $first->refresh();
        $second->refresh();
        verify($first->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($second->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($first->match_id)->null();
        verify($second->matched_at)->null();
    }

    /**
     * Проверяет сценарий: calc summary returns ledger statement counters and diff.
     * @return void
     */
    public function testCalcSummaryReturnsLedgerStatementCountersAndDiff(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'amount' => '100.00',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'amount' => '70.00',
        ]);

        verify(NostroEntry::find()->where(['id' => [$ledger->id, $statement->id], 'company_id' => $company->id])->count())->equals(2);

        $summary = (new MatchingService())->calcSummary([$ledger->id, $statement->id], (int)$company->id);

        verify($summary['sum_ledger'])->equals(100.0);
        verify($summary['sum_statement'])->equals(70.0);
        verify($summary['diff'])->equals(30.0);
        verify($summary['cnt_ledger'])->equals(1);
        verify($summary['cnt_statement'])->equals(1);
    }
}
