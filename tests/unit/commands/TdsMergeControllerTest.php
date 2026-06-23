<?php

namespace tests\unit\commands;

use app\commands\TdsMergeController;
use app\models\Account;
use app\models\NostroBalance;
use app\models\NostroBalanceAudit;
use app\models\NostroEntry;
use app\models\NostroEntryAudit;
use Yii;
use yii\console\ExitCode;

/**
 * Проверяет консольный импорт выписок TDS (CAMT053 / MT950 / ED211 / ED743)
 * из ph_tds_stmt_hdr + ph_tds_stmt_dtl в nostro_balance / nostro_entries.
 *
 * Покрывает маппинг D/C, partial-режим при ненайденном счёте, аудит,
 * трассировку stmt_id/line_no/branch_code, удаление источника и опции команды.
 */
class TdsMergeControllerTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /** Кэш пула company_id=1, чтобы все счета теста были одного ностро-банка. */
    private ?int $poolId = null;

    /**
     * Подготавливает окружение перед тестом.
     *
     * Источники ph_tds_stmt_hdr/dtl очищаются в SmartMatchTestHelper::resetDatabase().
     *
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
        $this->poolId = null;
    }

    // ── TC-001 ────────────────────────────────────────────────────────────

    /**
     * TC-001. CAMT053: DBIT→Debit, CRDT→Credit; создаётся строка баланса S.
     *
     * @return void
     */
    public function testCamt053MapsDbitCrdtAndCreatesBalance(): void
    {
        $account = $this->seedAccount('CAMT-ACC-1', 'USD');
        $statusId = $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 100,
            'format_type' => 'CAMT053',
            'account_no' => 'CAMT-ACC-1',
            'stmt_ref' => 'CAMT-STMT-100',
            'opening_currency' => 'USD',
            'opening_value_dt' => '2026-01-10',
            'opening_amount' => '1000.00',
            'opening_dc' => 'C',
            'closing_amount' => '900.00',
            'closing_dc' => 'D',
        ]);
        $this->insertDtl([
            'stmt_id' => 100, 'line_no' => 1, 'dc_mark' => 'DBIT',
            'amount' => '111.11', 'currency' => 'USD', 'msg_id' => 'MSG-CAMT-1',
        ]);
        $this->insertDtl([
            'stmt_id' => 100, 'line_no' => 2, 'dc_mark' => 'CRDT',
            'amount' => '222.22', 'currency' => 'USD',
        ]);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));

        $entries = $this->entriesByStmt(100);
        $this->assertCount(2, $entries);
        $this->assertSame(NostroEntry::DC_DEBIT, $entries[0]['dc']);
        $this->assertSame(NostroEntry::DC_CREDIT, $entries[1]['dc']);
        $this->assertSame(NostroEntry::LS_STATEMENT, $entries[0]['ls']);
        $this->assertSame('CAMT053', $entries[0]['source']);
        $this->assertSame((int)$account->id, (int)$entries[0]['account_id']);
        $this->assertSame('CAMT-STMT-100', $entries[0]['statement_number']);
        // message_id для CAMT053 берётся из ph_tds_stmt_dtl.msg_id.
        $this->assertSame('MSG-CAMT-1', (string)$entries[0]['message_id']);

        $balance = NostroBalance::findOne(['stmt_id' => 100]);
        $this->assertNotNull($balance);
        $this->assertSame('CAMT-STMT-100', $balance->statement_number);
        $this->assertSame((int)$account->id, (int)$balance->account_id);
        $this->assertSame(NostroBalance::LS_STATEMENT, $balance->ls_type);
        $this->assertSame(NostroBalance::SECTION_NRE, $balance->section);
        $this->assertSame('CAMT053', $balance->source);
        $this->assertSame('1000.00', $balance->opening_balance);
        $this->assertSame('900.00', $balance->closing_balance);
        // nostro_balance.opening_dc/closing_dc — char(1), хранят 'C'/'D'.
        $this->assertSame(NostroBalance::DC_CREDIT, $balance->opening_dc);
        $this->assertSame(NostroBalance::DC_DEBIT, $balance->closing_dc);

        $this->assertTrue((bool)$this->statusIsMerged($statusId));

        $this->stdout('TC-001: CAMT053 — DBIT→Debit, CRDT→Credit; message_id из dtl.msg_id; создан баланс S (source=CAMT053), opening/closing_dc хранятся как C/D, статус помечен merged.');
    }

    // ── TC-002 ────────────────────────────────────────────────────────────

    /**
     * TC-002. MT950: D→Debit, C→Credit.
     *
     * Примечание: «пустой dc_mark → NULL» из спецификации проверить нельзя —
     * nostro_entries.dc объявлена NOT NULL, и пустой признак роняет весь пакет
     * (известное расхождение схемы и документации, вынесено в отчёт).
     *
     * @return void
     */
    public function testMt950MapsDc(): void
    {
        $this->seedAccount('MT950-ACC', 'EUR');
        $this->insertStatus('MT950');
        $this->insertHdr([
            'stmt_id' => 200, 'format_type' => 'MT950', 'account_no' => 'MT950-ACC',
            'opening_currency' => 'EUR', 'opening_value_dt' => '2026-02-01',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'C',
        ]);
        $this->insertDtl(['stmt_id' => 200, 'line_no' => 1, 'dc_mark' => 'D', 'amount' => '10.00', 'currency' => 'EUR', 'value_dt' => '2026-02-01', 'entry_dt' => '2026-02-01']);
        $this->insertDtl(['stmt_id' => 200, 'line_no' => 2, 'dc_mark' => 'C', 'amount' => '20.00', 'currency' => 'EUR', 'value_dt' => '2026-02-01', 'entry_dt' => '2026-02-01']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('MT950'));

        $entries = $this->entriesByStmt(200);
        $this->assertCount(2, $entries);
        $this->assertSame(NostroEntry::DC_DEBIT, $entries[0]['dc']);
        $this->assertSame(NostroEntry::DC_CREDIT, $entries[1]['dc']);

        $this->stdout('TC-002: MT950 — D→Debit, C→Credit для строк транзакций.');
    }

    // ── TC-003 ────────────────────────────────────────────────────────────

    /**
     * TC-003. Невалидный D/C → RuntimeException → rollback пакета, exit SOFTWARE,
     * is_merged остаётся false, ничего не перенесено.
     *
     * @return void
     */
    public function testInvalidDcMarkRollsBackPackage(): void
    {
        $this->seedAccount('BAD-DC-ACC', 'USD');
        $statusId = $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 300, 'format_type' => 'CAMT053', 'account_no' => 'BAD-DC-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 300, 'line_no' => 1, 'dc_mark' => 'X', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::SOFTWARE, $this->runTdsMerge('CAMT053'));

        $this->assertSame(0, (int)NostroEntry::find()->count());
        $this->assertSame(0, (int)NostroBalance::find()->count());
        $this->assertFalse((bool)$this->statusIsMerged($statusId));

        $this->stdout('TC-003: невалидный D/C («X») → RuntimeException → откат пакета, exit SOFTWARE, ничего не перенесено, is_merged=false.');
    }

    // ── TC-004 ────────────────────────────────────────────────────────────

    /**
     * TC-004. Счёт не найден → hdr пропущен, is_merged=false (partial);
     * повторный запуск после создания счёта дотягивает данные.
     *
     * @return void
     */
    public function testMissingAccountLeavesPartialAndRepeatable(): void
    {
        // company_id=1 существует, но счёта с таким именем нет.
        $company = \SmartMatchTestHelper::createCompany();
        $this->assertSame(TdsMergeController::COMPANY_ID, (int)$company->id);
        $this->poolId = (int)\SmartMatchTestHelper::createPool((int)$company->id)->id;

        $statusId = $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 400, 'format_type' => 'CAMT053', 'account_no' => 'LATER-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 400, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));
        $this->assertSame(0, (int)NostroEntry::find()->count());
        $this->assertFalse((bool)$this->statusIsMerged($statusId));

        // Создаём пропущенный счёт и повторяем — данные доезжают.
        \SmartMatchTestHelper::createAccount(TdsMergeController::COMPANY_ID, $this->poolId, [
            'name' => 'LATER-ACC', 'currency' => 'USD',
        ]);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));
        $this->assertSame(1, (int)NostroEntry::find()->count());
        $this->assertTrue((bool)$this->statusIsMerged($statusId));

        $this->stdout('TC-004: счёт не найден → hdr пропущен (partial), is_merged=false; после создания счёта повторный запуск перенёс данные и пометил merged.');
    }

    // ── TC-005 ────────────────────────────────────────────────────────────

    /**
     * TC-005. Пустой account_no → hdr пропускается (skipped), не помечается merged.
     *
     * @return void
     */
    public function testEmptyAccountNoSkipsHeader(): void
    {
        $this->seedAccount('SOME-ACC', 'USD');
        $statusId = $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 500, 'format_type' => 'CAMT053', 'account_no' => '',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 500, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));
        $this->assertSame(0, (int)NostroEntry::find()->count());
        $this->assertFalse((bool)$this->statusIsMerged($statusId));

        $this->stdout('TC-005: пустой account_no → hdr пропущен, ничего не перенесено, is_merged=false.');
    }

    // ── TC-006 ────────────────────────────────────────────────────────────

    /**
     * TC-006. --delete-source удаляет обработанные строки ph_tds_stmt_hdr/dtl.
     *
     * @return void
     */
    public function testDeleteSourceRemovesHdrAndDtl(): void
    {
        $this->seedAccount('DEL-ACC', 'USD');
        $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 600, 'format_type' => 'CAMT053', 'account_no' => 'DEL-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 600, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053', true));

        $hdrLeft = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%ph_tds_stmt_hdr}} WHERE stmt_id = 600"
        )->queryScalar();
        $dtlLeft = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%ph_tds_stmt_dtl}} WHERE stmt_id = 600"
        )->queryScalar();

        $this->assertSame(0, $hdrLeft);
        $this->assertSame(0, $dtlLeft);

        $this->stdout('TC-006: --delete-source удалил обработанные строки ph_tds_stmt_hdr и ph_tds_stmt_dtl.');
    }

    /**
     * TC-006b. Без --delete-source строки источника остаются на месте.
     *
     * @return void
     */
    public function testWithoutDeleteSourceKeepsSource(): void
    {
        $this->seedAccount('KEEP-ACC', 'USD');
        $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 650, 'format_type' => 'CAMT053', 'account_no' => 'KEEP-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 650, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));

        $hdrLeft = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%ph_tds_stmt_hdr}} WHERE stmt_id = 650"
        )->queryScalar();
        $this->assertSame(1, $hdrLeft);

        $this->stdout('TC-006b: без --delete-source строки источника остаются на месте.');
    }

    // ── TC-007 ────────────────────────────────────────────────────────────

    /**
     * TC-007. is_merged=true только если нет пропущенных hdr.
     * Один hdr со счётом, второй — без; переносится только валидный,
     * статус остаётся false для повторного запуска.
     *
     * @return void
     */
    public function testIsMergedOnlyWhenNoSkippedHeaders(): void
    {
        $account = $this->seedAccount('OK-ACC', 'USD');
        $statusId = $this->insertStatus('CAMT053');

        $this->insertHdr([
            'stmt_id' => 700, 'format_type' => 'CAMT053', 'account_no' => 'OK-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 700, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->insertHdr([
            'stmt_id' => 701, 'format_type' => 'CAMT053', 'account_no' => 'NO-SUCH-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 701, 'line_no' => 1, 'dc_mark' => 'CRDT', 'amount' => '9.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));

        // Перенесён только валидный hdr.
        $this->assertCount(1, $this->entriesByStmt(700));
        $this->assertCount(0, $this->entriesByStmt(701));
        $this->assertSame((int)$account->id, (int)$this->entriesByStmt(700)[0]['account_id']);
        $this->assertFalse((bool)$this->statusIsMerged($statusId));

        $this->stdout('TC-007: из двух hdr перенесён только валидный (счёт найден); из-за пропущенного hdr is_merged остался false для повторного прогона.');
    }

    // ── TC-008 ────────────────────────────────────────────────────────────

    /**
     * TC-008. MT950: statement_number = hdr.stmt_ref, other_id = dtl.op_type.
     *
     * @return void
     */
    public function testMt950StatementNumberAndOtherId(): void
    {
        $this->seedAccount('MT-REF-ACC', 'USD');
        $this->insertStatus('MT950');
        $this->insertHdr([
            'stmt_id' => 800, 'format_type' => 'MT950', 'account_no' => 'MT-REF-ACC',
            'stmt_ref' => 'STMT-REF-42',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-03-01',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'C',
        ]);
        $this->insertDtl([
            'stmt_id' => 800, 'line_no' => 1, 'dc_mark' => 'D',
            'amount' => '15.00', 'currency' => 'USD', 'op_type' => 'NTRF',
            'value_dt' => '2026-03-02', 'entry_dt' => '2026-03-03',
            'instr_id' => 'OWNER-1', 'tx_id' => 'TX-1',
        ]);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('MT950'));

        $balance = NostroBalance::findOne(['stmt_id' => 800]);
        $this->assertNotNull($balance);
        $this->assertSame('STMT-REF-42', $balance->statement_number);

        $entry = $this->entriesByStmt(800)[0];
        $this->assertSame('STMT-REF-42', $entry['statement_number']);
        // MT950 не заполняет message_id (нет маппинга для этого типа).
        $this->assertNull($entry['message_id']);
        $this->assertSame('NTRF', $entry['other_id']);
        $this->assertSame('2026-03-02', $entry['value_date']);
        $this->assertSame('2026-03-03', $entry['post_date']);
        $this->assertSame('OWNER-1', $entry['instruction_id']);
        $this->assertSame('TX-1', $entry['transaction_id']);

        $this->stdout('TC-008: MT950 — statement_number=hdr.stmt_ref, other_id=dtl.op_type; value_date/post_date/instruction_id/transaction_id заполнены из dtl.');
    }

    // ── TC-009 ────────────────────────────────────────────────────────────

    /**
     * TC-009. ED211: edno/eddate/edauthor пишутся в обе таблицы; edauthor баланса
     * берётся из первой ph_tds_stmt_dtl.ref_edauthor; D/C маппинг D→Debit.
     *
     * @return void
     */
    public function testEd211WritesEdFields(): void
    {
        $this->seedAccount('CB_ED-ACC', 'RUB');
        $this->insertStatus('ED211');
        $this->insertHdr([
            'stmt_id' => 900, 'format_type' => 'ED211', 'account_no' => 'ED-ACC',
            'opening_currency' => 'RUB', 'opening_value_dt' => '2026-04-01',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'C',
            'edno' => '12345', 'eddate' => '2026-04-01', 'edbranch' => '044',
        ]);
        $this->insertDtl([
            'stmt_id' => 900, 'line_no' => 1, 'dc_mark' => 'D', 'amount' => '77.00', 'currency' => 'RUB',
            'entry_ref' => 'ENTRY-REF-1', 'ed_bank_name' => 'BANK NAME',
            'ref_edno' => '555', 'ref_eddate' => '2026-04-02', 'ref_edauthor' => 'AUTH01',
        ]);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('ED211'));

        $entry = $this->entriesByStmt(900)[0];
        $this->assertSame(NostroEntry::DC_DEBIT, $entry['dc']);
        $this->assertSame('ENTRY-REF-1', $entry['instruction_id']);
        $this->assertSame('BANK NAME', $entry['transaction_id']);
        $this->assertSame('555', $entry['edno']);
        $this->assertSame('2026-04-02', $entry['eddate']);
        $this->assertSame('AUTH01', $entry['edauthor']);

        $balance = NostroBalance::findOne(['stmt_id' => 900]);
        $this->assertNotNull($balance);
        $this->assertSame('12345', $balance->edno);
        $this->assertSame('2026-04-01', $balance->eddate);
        // edauthor для баланса берётся из первой строки dtl.
        $this->assertSame('AUTH01', $balance->edauthor);

        $this->stdout('TC-009: ED211 — edno/eddate/edauthor записаны в запись и баланс; edauthor баланса взят из первой dtl.ref_edauthor; D→Debit.');
    }

    // ── TC-010 ────────────────────────────────────────────────────────────

    /**
     * TC-010. Трассировка: stmt_id, branch_code = hdr.edbranch, line_no = dtl.line_no.
     *
     * @return void
     */
    public function testTraceabilityStmtIdLineNoBranchCode(): void
    {
        $this->seedAccount('TRACE-ACC', 'USD');
        $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 1000, 'format_type' => 'CAMT053', 'account_no' => 'TRACE-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D', 'edbranch' => '077',
        ]);
        $this->insertDtl(['stmt_id' => 1000, 'line_no' => 7, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));

        $entry = $this->entriesByStmt(1000)[0];
        $this->assertSame('1000', (string)$entry['stmt_id']);
        $this->assertSame(7, (int)$entry['line_no']);
        $this->assertSame('077', $entry['branch_code']);

        $balance = NostroBalance::findOne(['stmt_id' => 1000]);
        $this->assertSame('077', $balance->branch_code);

        $this->stdout('TC-010: трассировка — stmt_id, branch_code=hdr.edbranch, line_no=dtl.line_no проставлены и в записи, и в балансе.');
    }

    // ── TC-011 ────────────────────────────────────────────────────────────

    /**
     * TC-011. Аудит импорта: nostro_entry_audit(create) и nostro_balance_audit(import),
     * user_id=0, reason='Импорт {type}'.
     *
     * @return void
     */
    public function testImportAuditCreated(): void
    {
        $this->seedAccount('AUDIT-ACC', 'USD');
        $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 1100, 'format_type' => 'CAMT053', 'account_no' => 'AUDIT-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 1100, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));

        $entry = $this->entriesByStmt(1100)[0];
        $entryAudit = (int)NostroEntryAudit::find()->where([
            'entry_id' => (int)$entry['id'],
            'action' => NostroEntryAudit::ACTION_CREATE,
            'user_id' => 0,
            'reason' => 'Импорт CAMT053',
        ])->count();
        $this->assertSame(1, $entryAudit);

        $balance = NostroBalance::findOne(['stmt_id' => 1100]);
        $balanceAudit = (int)NostroBalanceAudit::find()->where([
            'balance_id' => (int)$balance->id,
            'action' => NostroBalanceAudit::ACTION_IMPORT,
            'user_id' => 0,
            'reason' => 'Импорт CAMT053',
        ])->count();
        $this->assertSame(1, $balanceAudit);

        $this->stdout('TC-011: аудит импорта — nostro_entry_audit(create) и nostro_balance_audit(import), user_id=0, reason=«Импорт CAMT053».');
    }

    // ── TC-012 ────────────────────────────────────────────────────────────

    /**
     * TC-012. Неизвестный --type → ExitCode::USAGE, ничего не перенесено.
     *
     * @return void
     */
    public function testUnknownTypeReturnsUsage(): void
    {
        $this->seedAccount('ANY-ACC', 'USD');
        $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 1200, 'format_type' => 'CAMT053', 'account_no' => 'ANY-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 1200, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::USAGE, $this->runTdsMerge('SWIFT999'));
        $this->assertSame(0, (int)NostroEntry::find()->count());

        $this->stdout('TC-012: неизвестный --type=SWIFT999 → ExitCode::USAGE, ничего не перенесено.');
    }

    // ── TC-013 ────────────────────────────────────────────────────────────

    /**
     * TC-013. Нет необработанных пакетов → ExitCode::OK, ничего не делает.
     *
     * @return void
     */
    public function testNoPendingReturnsOk(): void
    {
        $this->assertSame(ExitCode::OK, $this->runTdsMerge());
        $this->assertSame(0, (int)NostroEntry::find()->count());
        $this->assertSame(0, (int)NostroBalance::find()->count());

        $this->stdout('TC-013: нет необработанных пакетов → ExitCode::OK, таблицы пусты.');
    }

    // ── TC-014 ────────────────────────────────────────────────────────────

    /**
     * TC-014. Один пакет PH_TDS содержит несколько format_type — за прогон без
     * --type обрабатываются все.
     *
     * @return void
     */
    public function testMultipleTypesProcessedInOneRun(): void
    {
        $this->seedAccount('MULTI-ACC', 'USD');
        // Оба вызова возвращают один и тот же пакет PH_TDS.
        $camtStatus = $this->insertStatus('CAMT053');
        $mtStatus   = $this->insertStatus('MT950');

        $this->insertHdr([
            'stmt_id' => 1400, 'format_type' => 'CAMT053', 'account_no' => 'MULTI-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 1400, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->insertHdr([
            'stmt_id' => 1401, 'format_type' => 'MT950', 'account_no' => 'MULTI-ACC',
            'stmt_ref' => 'R-1', 'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-11',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'C',
        ]);
        $this->insertDtl(['stmt_id' => 1401, 'line_no' => 1, 'dc_mark' => 'C', 'amount' => '6.00', 'currency' => 'USD', 'value_dt' => '2026-01-11', 'entry_dt' => '2026-01-11']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge());

        $this->assertCount(1, $this->entriesByStmt(1400));
        $this->assertCount(1, $this->entriesByStmt(1401));
        $this->assertTrue((bool)$this->statusIsMerged($camtStatus));
        $this->assertTrue((bool)$this->statusIsMerged($mtStatus));

        $this->stdout('TC-014: один пакет PH_TDS с CAMT053 и MT950 — за прогон без --type обработаны оба format_type, пакет помечен merged.');
    }

    // ── TC-015 ────────────────────────────────────────────────────────────

    /**
     * TC-015. Связь только по типу: hdr подбираются по format_type=type
     * независимо от того, какой stmt_id указан в tds_status.
     *
     * @return void
     */
    public function testLinkByTypeNotStmtId(): void
    {
        $this->seedAccount('TYPE-ACC', 'USD');
        // tds_status без какой-либо связи по stmt_id — только type.
        $statusId = $this->insertStatus('CAMT053');

        $this->insertHdr([
            'stmt_id' => 1500, 'format_type' => 'CAMT053', 'account_no' => 'TYPE-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 1500, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge());
        $this->assertCount(1, $this->entriesByStmt(1500));
        $this->assertTrue((bool)$this->statusIsMerged($statusId));

        $this->stdout('TC-015: hdr подбираются по format_type=type независимо от stmt_id в tds_status (связь только по типу).');
    }

    // ── TC-016 ────────────────────────────────────────────────────────────

    /**
     * TC-016. Chunking: набор деталей больше INSERT_CHUNK (1000) переносится
     * полностью несколькими батчами, аудит создаётся на каждую строку.
     *
     * @return void
     */
    public function testChunkingHandlesLargeDetailSet(): void
    {
        $this->seedAccount('BIG-ACC', 'USD');
        $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 1600, 'format_type' => 'CAMT053', 'account_no' => 'BIG-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);

        $count = 1200; // > INSERT_CHUNK
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [1600, $i, 'DBIT', '1.00', 'USD'];
        }
        Yii::$app->db->createCommand()->batchInsert(
            '{{%ph_tds_stmt_dtl}}',
            ['stmt_id', 'line_no', 'dc_mark', 'amount', 'currency'],
            $rows
        )->execute();

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));

        $this->assertSame($count, (int)NostroEntry::find()->where(['stmt_id' => 1600])->count());
        $auditCount = (int)NostroEntryAudit::find()->where([
            'action' => NostroEntryAudit::ACTION_CREATE,
            'reason' => 'Импорт CAMT053',
        ])->count();
        $this->assertSame($count, $auditCount);

        $this->stdout('TC-016: набор из 1200 деталей (> INSERT_CHUNK 1000) перенесён полностью несколькими батчами, аудит создан на каждую строку.');
    }

    // ── TC-017 ────────────────────────────────────────────────────────────

    /**
     * TC-017. Непустой ph_tds_stmt_dtl.other_id переносится в nostro_entries.other_id.
     *
     * @return void
     */
    public function testDtlOtherIdCopiedToNostroEntry(): void
    {
        $this->seedAccount('OTHER-ID-ACC', 'USD');
        $this->insertStatus('CAMT053');
        $this->insertHdr([
            'stmt_id' => 1700, 'format_type' => 'CAMT053', 'account_no' => 'OTHER-ID-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl([
            'stmt_id' => 1700, 'line_no' => 1, 'dc_mark' => 'DBIT',
            'amount' => '5.00', 'currency' => 'USD', 'other_id' => 'OTHER-1700',
        ]);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge('CAMT053'));

        $entry = $this->entriesByStmt(1700)[0];
        $this->assertSame('OTHER-1700', $entry['other_id']);

        $this->stdout('TC-017: непустой ph_tds_stmt_dtl.other_id перенесён в nostro_entries.other_id.');
    }

    // ── TC-018 ────────────────────────────────────────────────────────────

    /**
     * TC-018. Занятая пачка (is_processing=true) пропускается: данные не
     * переносятся, пакет не помечается merged (взаимоисключение с другим процессом).
     *
     * @return void
     */
    public function testProcessingLockSkipsBusyBatch(): void
    {
        $this->seedAccount('LOCK-ACC', 'USD');
        $statusId = $this->insertStatus();
        Yii::$app->db->createCommand()->update('{{%tds_status}}', [
            'is_processing' => true,
            'processing_owner' => 'background',
        ], ['id' => $statusId])->execute();

        $this->insertHdr([
            'stmt_id' => 1800, 'format_type' => 'CAMT053', 'account_no' => 'LOCK-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 1800, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge());

        $this->assertSame(0, (int)NostroEntry::find()->count());
        $this->assertFalse((bool)$this->statusIsMerged($statusId));

        $this->stdout('TC-018: пачка с is_processing=true пропускается merge-командой (lock держит другой процесс), данные не переносятся.');
    }

    // ── TC-019 ────────────────────────────────────────────────────────────

    /**
     * TC-019. После успешной обработки блокировка снимается (is_processing=false).
     *
     * @return void
     */
    public function testProcessingLockReleasedAfterRun(): void
    {
        $this->seedAccount('REL-ACC', 'USD');
        $statusId = $this->insertStatus();
        $this->insertHdr([
            'stmt_id' => 1900, 'format_type' => 'CAMT053', 'account_no' => 'REL-ACC',
            'opening_currency' => 'USD', 'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00', 'opening_dc' => 'C',
            'closing_amount' => '0.00', 'closing_dc' => 'D',
        ]);
        $this->insertDtl(['stmt_id' => 1900, 'line_no' => 1, 'dc_mark' => 'DBIT', 'amount' => '5.00', 'currency' => 'USD']);

        $this->assertSame(ExitCode::OK, $this->runTdsMerge());

        $this->assertTrue((bool)$this->statusIsMerged($statusId));
        $processing = Yii::$app->db->createCommand(
            "SELECT is_processing FROM {{%tds_status}} WHERE id = :id",
            [':id' => $statusId]
        )->queryScalar();
        $this->assertFalse((bool)$processing);

        $this->stdout('TC-019: после успешного processOne блокировка снимается (is_processing=false).');
    }

    // ── Хелперы ─────────────────────────────────────────────────────────────

    /**
     * Запускает консольную команду tds-merge/run.
     *
     * @param string|null $type Значение опции --type.
     * @param bool $deleteSource Значение опции --delete-source.
     * @return int Код завершения.
     */
    private function runTdsMerge(?string $type = null, bool $deleteSource = false): int
    {
        $controller = new TdsMergeController('tds-merge', Yii::$app);
        $controller->type = $type;
        $controller->deleteSource = $deleteSource;
        return $controller->actionRun();
    }

    /**
     * Лениво создаёт company_id=1 + ностро-банк и добавляет в него счёт.
     *
     * @param string $name Имя счёта (по нему ищет контроллер).
     * @param string $currency Валюта счёта.
     * @return Account Созданный счёт.
     */
    private function seedAccount(string $name, string $currency = 'USD'): Account
    {
        if ($this->poolId === null) {
            $company = \SmartMatchTestHelper::createCompany();
            $this->assertSame(TdsMergeController::COMPANY_ID, (int)$company->id);
            $this->poolId = (int)\SmartMatchTestHelper::createPool((int)$company->id)->id;
        }

        return \SmartMatchTestHelper::createAccount(TdsMergeController::COMPANY_ID, $this->poolId, [
            'name' => $name,
            'currency' => $currency,
        ]);
    }

    /**
     * Добавляет (один раз) pending tds_status типа PH_TDS.
     *
     * format_type больше не хранится в tds_status — один пакет PH_TDS содержит
     * все типы разом, а конкретный тип лежит в ph_tds_stmt_hdr.format_type.
     * Поэтому параметр `$type` игнорируется, а повторные вызовы возвращают тот
     * же пакет (это покрывает сценарий «несколько типов в одном прогоне»).
     *
     * @param string $type Игнорируется (оставлен для совместимости вызовов).
     * @return int ID статуса PH_TDS.
     */
    private function insertStatus(string $type = TdsMergeController::STATUS_TYPE): int
    {
        $existing = Yii::$app->db->createCommand(
            "SELECT id FROM {{%tds_status}} WHERE type = :type ORDER BY id LIMIT 1",
            [':type' => TdsMergeController::STATUS_TYPE]
        )->queryScalar();
        if ($existing) {
            return (int)$existing;
        }

        Yii::$app->db->createCommand()->insert('{{%tds_status}}', [
            'type' => TdsMergeController::STATUS_TYPE,
            'is_merged' => false,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID('tds_status_id_seq');
    }

    /**
     * Добавляет строку-заголовок ph_tds_stmt_hdr.
     *
     * @param array $attributes Переопределения полей.
     * @return void
     */
    private function insertHdr(array $attributes): void
    {
        Yii::$app->db->createCommand()->insert('{{%ph_tds_stmt_hdr}}', array_merge([
            'stmt_id' => 1,
            'msg_key' => null,
            'format_type' => 'CAMT053',
            'stmt_ref' => null,
            'account_no' => null,
            'opening_dc' => null,
            'opening_value_dt' => null,
            'opening_currency' => null,
            'opening_amount' => null,
            'closing_dc' => null,
            'closing_currency' => null,
            'closing_amount' => null,
            'edno' => null,
            'eddate' => null,
            'edbranch' => null,
            'proc_status' => null,
        ], $attributes))->execute();
    }

    /**
     * Добавляет строку-деталь ph_tds_stmt_dtl.
     *
     * @param array $attributes Переопределения полей.
     * @return void
     */
    private function insertDtl(array $attributes): void
    {
        Yii::$app->db->createCommand()->insert('{{%ph_tds_stmt_dtl}}', array_merge([
            'stmt_id' => 1,
            'line_no' => 1,
            'value_dt' => null,
            'entry_dt' => null,
            'op_type' => null,
            'other_id' => null,
            'dc_mark' => null,
            'amount' => null,
            'currency' => null,
            'txn_type' => null,
            'instr_id' => null,
            'tx_id' => null,
            'msg_id' => null,
            'end_to_end_id' => null,
            'ref_edno' => null,
            'ref_eddate' => null,
            'ref_edauthor' => null,
            'entry_ref' => null,
            'ed_account' => null,
            'ed_bank_name' => null,
        ], $attributes))->execute();
    }

    /**
     * Возвращает записи nostro_entries по stmt_id, упорядоченные по line_no.
     *
     * @param int $stmtId Идентификатор выписки.
     * @return array Строки записей выверки.
     */
    private function entriesByStmt(int $stmtId): array
    {
        return Yii::$app->db->createCommand(
            "SELECT * FROM {{%nostro_entries}} WHERE stmt_id = :sid ORDER BY line_no",
            [':sid' => $stmtId]
        )->queryAll();
    }

    /**
     * Возвращает значение is_merged для tds_status.
     *
     * @param int $statusId ID статуса.
     * @return mixed Значение поля is_merged.
     */
    private function statusIsMerged(int $statusId)
    {
        return Yii::$app->db->createCommand(
            "SELECT is_merged FROM {{%tds_status}} WHERE id = :id",
            [':id' => $statusId]
        )->queryScalar();
    }
}
