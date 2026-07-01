<?php

namespace tests\unit\commands;

use app\commands\FccMergeController;
use app\models\NostroBalance;
use app\models\NostroBalanceAudit;
use app\models\NostroEntry;
use app\models\NostroEntryAudit;
use Yii;
use yii\console\ExitCode;

/**
 * Проверяет консольный импорт FCC12 из gitb_nostro_extract_custom.
 */
class FccMergeControllerTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

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
     * Проверяет успешный перенос FCC12, аудит и очистку источника.
     *
     * @return void
     */
    public function testRunCreatesEntriesBalancesAuditAndDeletesSource(): void
    {
        [, $account] = $this->createFccAccount('FCC-ACC-001');
        $statusId = $this->insertStatus(9001);
        $this->insertSourceRow([
            'extract_no' => 9001,
            'line_no' => 20,
            'data_section' => 60,
            'cbr_cc_no' => 'FCC-ACC-001',
            'ccy' => 'USD',
            'dt' => '2026-01-10',
            'opening_bal' => '1000.00',
            'opening_bal_dc' => 'C',
            'closing_bal' => '900.00',
            'closing_bal_dc' => 'D',
            'branch_code' => '001',
        ]);
        $this->insertSourceRow([
            'extract_no' => 9001,
            'line_no' => 30,
            'data_section' => 61,
            'cbr_cc_no' => 'FCC-ACC-001',
            'ccy' => 'USD',
            'amount' => '123.45',
            'drcr_ind' => 'D',
            'value_dt' => '2026-01-11',
            'trn_dt' => '2026-01-12',
            'ed_no' => 'ED-001',
            'trn_ref_sr_no' => 'E2E-001',
            'obj_ref' => 'OBJ-001',
            'branch_code' => '002',
        ]);

        $this->assertSame(ExitCode::OK, $this->runFccMerge());

        $entry = NostroEntry::findOne(['extract_no' => 9001, 'line_no' => 30]);
        $this->assertNotNull($entry);
        $this->assertSame((int)$account->id, (int)$entry->account_id);
        $this->assertSame(NostroEntry::LS_LEDGER, $entry->ls);
        $this->assertSame(NostroEntry::DC_DEBIT, $entry->dc);
        $this->assertSame('FCC12', $entry->source);
        $this->assertSame('002', $entry->branch_code);

        $balance = NostroBalance::findOne(['extract_no' => 9001, 'line_no' => 20]);
        $this->assertNotNull($balance);
        $this->assertSame((int)$account->id, (int)$balance->account_id);
        $this->assertSame(NostroBalance::LS_LEDGER, $balance->ls_type);
        $this->assertSame(NostroBalance::SECTION_NRE, $balance->section);
        $this->assertSame('001', $balance->branch_code);

        $this->assertSame(1, (int)NostroEntryAudit::find()->where([
            'entry_id' => $entry->id,
            'action' => NostroEntryAudit::ACTION_CREATE,
            'reason' => 'Импорт FCC12',
        ])->count());
        $this->assertSame(1, (int)NostroBalanceAudit::find()->where([
            'balance_id' => $balance->id,
            'action' => NostroBalanceAudit::ACTION_IMPORT,
            'reason' => 'Импорт FCC12',
        ])->count());

        $sourceCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%gitb_nostro_extract_custom}} WHERE extract_no = 9001"
        )->queryScalar();
        $statusMerged = Yii::$app->db->createCommand(
            "SELECT is_merged FROM {{%tds_status}} WHERE id = :id",
            [':id' => $statusId]
        )->queryScalar();

        $this->assertSame(0, $sourceCount);
        $this->assertTrue((bool)$statusMerged);

        $this->stdout('FCC12 merge: строки-балансы → nostro_balance, транзакции → nostro_entries (ls=L, source=FCC12), трассировка extract_no/line_no/branch_code, аудит create/import, источник очищен, статус merged.');
    }

    /**
     * Проверяет partial-режим: строка с ненайденным счетом остается в источнике.
     *
     * @return void
     */
    public function testMissingAccountLeavesSourceAndStatusUnmerged(): void
    {
        \SmartMatchTestHelper::createCompany();
        $statusId = $this->insertStatus(9002);
        $this->insertSourceRow([
            'extract_no' => 9002,
            'line_no' => 5,
            'data_section' => 61,
            'cbr_cc_no' => 'UNKNOWN-FCC',
            'ccy' => 'EUR',
            'amount' => '10.00',
            'drcr_ind' => 'C',
            'value_dt' => '2026-01-10',
            'trn_dt' => '2026-01-10',
        ]);

        $this->assertSame(ExitCode::OK, $this->runFccMerge());

        $this->assertSame(0, (int)NostroEntry::find()->count());
        $sourceCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%gitb_nostro_extract_custom}} WHERE extract_no = 9002"
        )->queryScalar();
        $statusMerged = Yii::$app->db->createCommand(
            "SELECT is_merged FROM {{%tds_status}} WHERE id = :id",
            [':id' => $statusId]
        )->queryScalar();

        $this->assertSame(1, $sourceCount);
        $this->assertFalse((bool)$statusMerged);

        $this->stdout('FCC12 merge (partial): счёт не найден → строка источника остаётся, записи не создаются, tds_status.is_merged=false для повторного прогона.');
    }

    /**
     * Запускает FCC12 merge.
     *
     * @return int Код завершения.
     */
    private function runFccMerge(): int
    {
        $controller = new FccMergeController('fcc-merge', Yii::$app);
        return $controller->actionRun();
    }

    /**
     * Создаёт компанию company_id=1 и FCC-счёт.
     *
     * @param string $accountName Имя счёта.
     * @return array `[company, account]`.
     */
    private function createFccAccount(string $accountName): array
    {
        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $account = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id, [
            'name' => $accountName,
            'currency' => 'USD',
        ]);

        $this->assertSame(FccMergeController::COMPANY_ID, (int)$company->id);

        return [$company, $account];
    }

    /**
     * Добавляет pending tds_status для FCC12.
     *
     * @param int $extractNo Номер выгрузки.
     * @return int ID статуса.
     */
    private function insertStatus(int $extractNo): int
    {
        Yii::$app->db->createCommand()->insert('{{%tds_status}}', [
            'type' => FccMergeController::SOURCE,
            'is_merged' => false,
            'fcc_extract_no' => $extractNo,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID('tds_status_id_seq');
    }

    /**
     * Добавляет строку сырого FCC12-источника.
     *
     * @param array $attributes Переопределения полей.
     * @return void
     */
    private function insertSourceRow(array $attributes): void
    {
        Yii::$app->db->createCommand()->insert('{{%gitb_nostro_extract_custom}}', array_merge([
            'extract_no' => 1,
            'line_no' => 1,
            'line_content' => null,
            'data_section' => null,
            'branch_code' => null,
            'cbr_cc_no' => null,
            'ccy' => null,
            'dt' => null,
            'opening_bal' => null,
            'opening_bal_dc' => null,
            'closing_bal' => null,
            'closing_bal_dc' => null,
            'obj_ref' => null,
            'trn_ref_sr_no' => null,
            'amount' => null,
            'drcr_ind' => null,
            'trn_dt' => null,
            'value_dt' => null,
            'ed_no' => null,
            'err_msg' => null,
        ], $attributes))->execute();
    }
}
