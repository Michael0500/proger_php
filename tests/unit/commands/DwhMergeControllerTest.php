<?php

namespace tests\unit\commands;

use app\commands\DwhMergeController;
use app\models\NostroBalance;
use app\models\NostroEntry;
use Yii;
use yii\console\ExitCode;

/**
 * Проверяет консольный импорт DWH из suspend_posting.
 */
class DwhMergeControllerTest extends \Codeception\Test\Unit
{
    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
    }

    /**
     * Проверяет создание записей, группировку балансов, маппинг D/C,
     * обрезание денег до двух знаков и аудит.
     *
     * @return void
     */
    public function testRunCreatesEntriesGroupedBalancesAndAudit(): void
    {
        [, $account, $wrongCompanyAccount] = $this->createDwhAccount('SUSP-001');
        $longNarrative = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-extra-tail';

        $this->insertSuspendPosting([
            'posting_id' => 1001,
            'cbaccount' => 'SUSP-001',
            'amount' => '123.456789',
            'dc_indicator' => 'D',
            'saldo_in_amt' => '1000.129999',
            'saldo_out_amt' => '1200.456789',
            'originaltran_ref' => 'ORIG-1001',
            'narrative' => $longNarrative,
        ]);
        $this->insertSuspendPosting([
            'posting_id' => 1002,
            'cbaccount' => 'SUSP-001',
            'amount' => '1.999999',
            'dc_indicator' => 'D',
            'saldo_in_amt' => '1000.129999',
            'saldo_out_amt' => '1200.456789',
            'originaltran_ref' => 'ORIG-1002',
            'narrative' => 'second',
        ]);
        $this->insertSuspendPosting([
            'posting_id' => 1003,
            'cbaccount' => 'SUSP-001',
            'valuedate' => '2026-01-11',
            'amount' => '50.555555',
            'dc_indicator' => 'C',
            'saldo_in_amt' => '1200.450000',
            'saldo_out_amt' => '1250.999999',
            'originaltran_ref' => 'ORIG-1003',
            'narrative' => 'credit',
        ]);

        $this->assertSame(ExitCode::OK, $this->runDwhMerge());

        $entries = Yii::$app->db->createCommand(
            "SELECT account_id, company_id, posting_id, ls, dc, amount, currency,
                    value_date, post_date, instruction_id, end_to_end_id, source, branch_code
               FROM {{%nostro_entries}}
              ORDER BY posting_id"
        )->queryAll();

        $this->assertCount(3, $entries);
        $this->assertSame((int)$account->id, (int)$entries[0]['account_id']);
        $this->assertNotSame((int)$wrongCompanyAccount->id, (int)$entries[0]['account_id']);
        $this->assertSame(2, (int)$entries[0]['company_id']);
        $this->assertSame(NostroEntry::LS_LEDGER, $entries[0]['ls']);
        $this->assertSame(NostroEntry::DC_DEBIT, $entries[0]['dc']);
        $this->assertSame('123.45', $entries[0]['amount']);
        $this->assertSame('ORIG-1001', $entries[0]['instruction_id']);
        $this->assertSame(mb_substr($longNarrative, 0, 40), $entries[0]['end_to_end_id']);
        $this->assertSame(NostroEntry::DC_CREDIT, $entries[2]['dc']);
        $this->assertSame('50.55', $entries[2]['amount']);

        $balances = Yii::$app->db->createCommand(
            "SELECT account_id, company_id, ls_type, currency, value_date,
                    opening_balance, opening_dc, closing_balance, closing_dc,
                    section, source, status, branch_code
               FROM {{%nostro_balance}}
              ORDER BY value_date"
        )->queryAll();

        $this->assertCount(2, $balances);
        $this->assertSame((int)$account->id, (int)$balances[0]['account_id']);
        $this->assertSame(2, (int)$balances[0]['company_id']);
        $this->assertSame(NostroBalance::LS_LEDGER, $balances[0]['ls_type']);
        $this->assertSame(NostroBalance::SECTION_INV, $balances[0]['section']);
        $this->assertSame(DwhMergeController::SOURCE, $balances[0]['source']);
        $this->assertSame(NostroBalance::STATUS_NORMAL, $balances[0]['status']);
        $this->assertSame('1000.12', $balances[0]['opening_balance']);
        $this->assertSame('1200.45', $balances[0]['closing_balance']);
        $this->assertSame('D', $balances[0]['opening_dc']);
        $this->assertSame('D', $balances[0]['closing_dc']);

        $notMerged = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%suspend_posting}} WHERE is_merged = FALSE"
        )->queryScalar();
        $this->assertSame(0, $notMerged);

        $entryAuditCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*)
               FROM {{%nostro_entry_audit}}
              WHERE action = 'create'
                AND reason = :reason",
            [':reason' => DwhMergeController::AUDIT_REASON]
        )->queryScalar();
        $balanceAuditCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*)
               FROM {{%nostro_balance_audit}}
              WHERE action = 'import'
                AND reason = :reason",
            [':reason' => DwhMergeController::AUDIT_REASON]
        )->queryScalar();

        $this->assertSame(3, $entryAuditCount);
        $this->assertSame(2, $balanceAuditCount);
    }

    /**
     * Проверяет, что повторный запуск и новый источник с тем же posting_id
     * не создают дублей в nostro_entries.
     *
     * @return void
     */
    public function testRepeatRunAndDuplicatePostingIdDoNotDuplicateEntries(): void
    {
        $this->createDwhAccount('SUSP-002');
        $this->insertSuspendPosting([
            'posting_id' => 2001,
            'cbaccount' => 'SUSP-002',
            'amount' => '10.999999',
        ]);

        $this->assertSame(ExitCode::OK, $this->runDwhMerge());
        $this->assertSame(ExitCode::OK, $this->runDwhMerge());

        $this->insertSuspendPosting([
            'posting_id' => 2001,
            'cbaccount' => 'SUSP-002',
            'amount' => '99.999999',
        ]);

        $this->assertSame(ExitCode::OK, $this->runDwhMerge());

        $entryCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%nostro_entries}} WHERE posting_id = 2001"
        )->queryScalar();
        $notMerged = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%suspend_posting}} WHERE is_merged = FALSE"
        )->queryScalar();
        $entryAuditCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%nostro_entry_audit}} WHERE reason = :reason",
            [':reason' => DwhMergeController::AUDIT_REASON]
        )->queryScalar();

        $this->assertSame(1, $entryCount);
        $this->assertSame(0, $notMerged);
        $this->assertSame(1, $entryAuditCount);
    }

    /**
     * Проверяет, что строка с ненайденным cbaccount не помечается обработанной.
     *
     * @return void
     */
    public function testMissingAccountIsNotMerged(): void
    {
        \SmartMatchTestHelper::createCompany();
        \SmartMatchTestHelper::createCompany();
        $sourceId = $this->insertSuspendPosting([
            'posting_id' => 3001,
            'cbaccount' => 'MISSING-ACCOUNT',
            'amount' => '10.00',
        ]);

        $this->assertSame(ExitCode::OK, $this->runDwhMerge());

        $isMerged = Yii::$app->db->createCommand(
            "SELECT is_merged FROM {{%suspend_posting}} WHERE id = :id",
            [':id' => $sourceId]
        )->queryScalar();
        $entryCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%nostro_entries}}"
        )->queryScalar();

        $this->assertFalse((bool)$isMerged);
        $this->assertSame(0, $entryCount);
    }

    /**
     * Запускает консольную команду DWH merge.
     *
     * @return int Код завершения.
     */
    private function runDwhMerge(): int
    {
        $controller = new DwhMergeController('dwh-merge', Yii::$app);
        return $controller->actionRun();
    }

    /**
     * Создаёт company_id=2 и счёт DWH; в company_id=1 создаёт одноимённый счёт,
     * чтобы проверить scoping по компании.
     *
     * @param string $accountName Имя счёта.
     * @return array `[company, account, wrongCompanyAccount]`.
     */
    private function createDwhAccount(string $accountName): array
    {
        $wrongCompany = \SmartMatchTestHelper::createCompany();
        $wrongPool = \SmartMatchTestHelper::createPool((int)$wrongCompany->id);
        $wrongAccount = \SmartMatchTestHelper::createAccount((int)$wrongCompany->id, (int)$wrongPool->id, [
            'name' => $accountName,
            'currency' => 'USD',
        ]);

        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $account = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id, [
            'name' => $accountName,
            'currency' => 'USD',
            'is_suspense' => true,
        ]);

        $this->assertSame(2, (int)$company->id);

        return [$company, $account, $wrongAccount];
    }

    /**
     * Добавляет строку suspend_posting.
     *
     * @param array $attributes Переопределения полей.
     * @return int ID созданной строки.
     */
    private function insertSuspendPosting(array $attributes = []): int
    {
        $now = '2026-01-15 12:00:00';
        Yii::$app->db->createCommand()->insert('{{%suspend_posting}}', array_merge([
            'posting_id' => random_int(100000, 999999),
            'abs_branch_code' => '001',
            'cbaccount' => 'SUSP-DEFAULT',
            'ccy' => 'USD',
            'start_date' => '2026-01-10',
            'end_date' => '2026-01-10',
            'saldo_in_amt' => '0.000000',
            'saldo_out_amt' => '0.000000',
            'dc_indicator_saldo' => 'D',
            'valuedate' => '2026-01-10',
            'amount' => '1.000000',
            'dc_indicator' => 'D',
            'originaltran_ref' => 'ORIG',
            'narrative' => 'narrative',
            'valid_from_dttm' => $now,
            'valid_to_dttm' => '2999-12-31 00:00:00',
            'processed_dttm' => $now,
        ], $attributes))->execute();

        return (int)Yii::$app->db->getLastInsertID('suspend_posting_id_seq');
    }
}
