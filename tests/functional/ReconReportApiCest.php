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
     * TC-065. Пользователь без компании получает отказ.
     *
     * @return void
     */
    public function generateRejectsUserWithoutCompany(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: пользователь без компании получает отказ «Компания не выбрана»');
        $noCompanyUser = SmartMatchTestHelper::createUser(null);
        $I->amLoggedInAs($noCompanyUser);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0, 'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('Компания не выбрана', $response['message']);
    }

    /**
     * TC-068. Одновременный выбор и категории, и ностро-банка отклоняется.
     *
     * @return void
     */
    public function generateRejectsBothPoolAndCategory(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: одновременный выбор pool и category отклоняется');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 999, 'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('Выберите категорию или ностро-банк', $response['message']);
    }

    /**
     * TC-069. Отсутствие и категории, и ностро-банка отклоняется.
     *
     * @return void
     */
    public function generateRejectsNeitherPoolNorCategory(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: отсутствие и pool, и category отклоняется');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => 0, 'category_id' => 0, 'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('Выберите категорию или ностро-банк', $response['message']);
    }

    /**
     * TC-070. Период требует обе даты.
     *
     * @return void
     */
    public function generateRejectsPeriodWithOneDate(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: период с одной датой отклоняется');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0,
            'date_from' => '2026-01-01', 'date_to' => '',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('Для периода укажите обе даты', $response['message']);
    }

    /**
     * TC-071. Дата начала периода не может быть позже даты конца.
     *
     * @return void
     */
    public function generateRejectsPeriodFromAfterTo(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: дата начала периода позже даты конца отклоняется');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0,
            'date_from' => '2026-01-20', 'date_to' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('не может быть позже', $response['message']);
    }

    /**
     * TC-072. Невалидный формат даты раккорда отклоняется.
     *
     * @return void
     */
    public function generateRejectsInvalidDate(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: невалидная дата раккорда отклоняется');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0, 'date_recon' => '2026-13-99',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('Неверный формат даты', $response['message']);
    }

    /**
     * TC-073. В режиме периода Date Reconciliation берётся как date_to.
     *
     * @return void
     */
    public function generatePeriodSetsReconDateToEnd(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: в режиме периода date_recon берётся как date_to');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0,
            'date_from' => '2026-01-09', 'date_to' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        $report = $response['reports'][0];
        Assert::assertSame('2026-01-10', $report['date_recon']);
        Assert::assertSame('2026-01-10', $report['date_to']);
    }

    /**
     * TC-074. Closing Balance берёт последний баланс не позже даты раккорда.
     *
     * @return void
     */
    public function generateClosingBalanceUsesLatestBeforeDate(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: Closing Balance — последний баланс не позже даты раккорда');
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->ledgerAccount->id,
            'ls_type' => NostroBalance::LS_LEDGER,
            'value_date' => '2026-01-05',
            'closing_balance' => '500.00',
        ]);
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->ledgerAccount->id,
            'ls_type' => NostroBalance::LS_LEDGER,
            'value_date' => '2026-01-10',
            'closing_balance' => '1000.00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0, 'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertEquals(1000.0, $response['reports'][0]['closing_balance']['ledger']);
    }

    /**
     * TC-076. Пул с разными валютами счетов отображается как MULTI.
     *
     * @return void
     */
    public function generateMultiCurrencyPoolReturnsMulti(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: пул со счетами в разных валютах даёт currency=MULTI');
        SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->pool->id, [
            'name' => 'BANK-A-E', 'currency' => 'EUR',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0, 'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame('MULTI', $response['reports'][0]['currency']);
    }

    /**
     * TC-077. Режим даты включает предыдущий день и дату раккорда, исключая
     * записи вне окна.
     *
     * @return void
     */
    public function generateDateModeIncludesPrevDayAndReconDateOnly(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: режим даты включает prevDay и date_recon, исключая записи вне окна');
        foreach ([['2026-01-09', 'IN-PREV'], ['2026-01-10', 'IN-RECON'], ['2026-01-05', 'OUT-OLD']] as [$date, $instr]) {
            SmartMatchTestHelper::createEntry([
                'company_id' => $this->company->id,
                'account_id' => $this->ledgerAccount->id,
                'ls' => NostroEntry::LS_LEDGER,
                'dc' => NostroEntry::DC_DEBIT,
                'amount' => '10.00',
                'value_date' => $date,
                'instruction_id' => $instr,
            ]);
        }

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $this->pool->id, 'category_id' => 0, 'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        $instructions = array_column($response['reports'][0]['outstanding_items']['ledger_debit'], 'instruction_id');
        Assert::assertContains('IN-PREV', $instructions);
        Assert::assertContains('IN-RECON', $instructions);
        Assert::assertNotContains('OUT-OLD', $instructions);
    }

    /**
     * TC-078. Ностро-банк чужой компании не попадает в отчёт.
     *
     * @return void
     */
    public function generateExcludesForeignCompanyPool(\FunctionalTester $I): void
    {
        $I->wantTo('Раккорд: ностро-банк чужой компании не формирует отчёт');
        $otherCompany = SmartMatchTestHelper::createCompany(['name' => 'OTHER', 'code' => 'OTH']);
        $foreignPool = SmartMatchTestHelper::createPool((int)$otherCompany->id, ['name' => 'FOREIGN']);
        SmartMatchTestHelper::createAccount((int)$otherCompany->id, (int)$foreignPool->id, ['name' => 'FOREIGN-L']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/recon-report/generate']), [
            'pool_id' => $foreignPool->id, 'category_id' => 0, 'date_recon' => '2026-01-10',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('Не найдено ни одного ностро-банка', $response['message']);
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
