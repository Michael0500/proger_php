<?php

use app\models\NostroBalance;
use app\models\NostroEntry;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `ReconReportApiCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class ReconReportApiCest
{
    private User $user;
    private $company;
    private $pool;
    private $ledgerAccount;
    private $statementAccount;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        $this->company = SmartMatchTestHelper::createCompany(['name' => 'NRE', 'code' => 'NRE']);
        $this->pool = SmartMatchTestHelper::createPool((int)$this->company->id, ['name' => 'BANK-A']);
        $this->ledgerAccount = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->pool->id, [
            'name' => 'BANK-A-L',
            'account_type' => NostroBalance::LS_LEDGER,
        ]);
        $this->statementAccount = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->pool->id, [
            'name' => 'BANK-A-S',
            'account_type' => NostroBalance::LS_STATEMENT,
        ]);
        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: generate builds pool report from balances and outstanding items.
     *
     * @return void
     */
    public function generateBuildsPoolReportFromBalancesAndOutstandingItems(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: формирует отчёт по ностро-банку из балансов и outstanding items');
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->ledgerAccount->id,
            'ls_type' => NostroBalance::LS_LEDGER,
            'value_date' => '2026-01-10',
            'closing_balance' => '1000.00',
        ]);
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->statementAccount->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => 'ST-001',
            'value_date' => '2026-01-10',
            'closing_balance' => '900.00',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->ledgerAccount->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '30.00',
            'value_date' => '2026-01-09',
            'instruction_id' => 'L-OUT',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->statementAccount->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '20.00',
            'value_date' => '2026-01-10',
            'instruction_id' => 'S-OUT',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->ledgerAccount->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '999.00',
            'value_date' => '2026-01-10',
            'match_id' => 'MTCH00000999',
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id,
            'category_id' => 0,
            'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertCount(1, $response['reports']);
        $report = $response['reports'][0];

        Assert::assertSame('BANK-A', $report['nostro_bank']);
        Assert::assertEquals(1000.0, $report['closing_balance']['ledger']);
        Assert::assertEquals(900.0, $report['closing_balance']['statement']);
        Assert::assertEquals(-30.0, $report['outstanding_items']['ledger']);
        Assert::assertEquals(20.0, $report['outstanding_items']['statement']);
        Assert::assertEquals(970.0, $report['trial_balance']['ledger']);
        Assert::assertEquals(920.0, $report['trial_balance']['statement']);
        Assert::assertSame('L-OUT', $report['outstanding_items']['ledger_debit'][0]['instruction_id']);
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
