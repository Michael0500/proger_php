<?php

namespace tests\unit\models;

use app\models\NostroEntry;
use app\models\NostroEntryAudit;

/**
 * Тестовый класс `NostroEntryTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class NostroEntryTest extends \Codeception\Test\Unit
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
     * Проверяет сценарий: money amount validation.
     * @return void
     */
    public function testMoneyAmountValidation(): void
    {
        $entry = new NostroEntry();

        $entry->amount = '123456789012345678.99';
        verify($entry->validate(['amount']))->true();

        $entry->amount = '123.456';
        verify($entry->validate(['amount']))->false();

        $entry->clearErrors();
        $entry->amount = '-1.00';
        verify($entry->validate(['amount']))->false();

        $entry->clearErrors();
        $entry->amount = '1234567890123456789.00';
        verify($entry->validate(['amount']))->false();

        $this->stdout('Денежная валидация суммы записи decimal(20,2): 18+2 знака — ок; 3 знака, отрицательная сумма и 19 цифр до точки — отклоняются.');
    }

    /**
     * Проверяет сценарий: matched status sets and clears matched at.
     * @return void
     */
    public function testMatchedStatusSetsAndClearsMatchedAt(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();

        $entry = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'match_status' => NostroEntry::STATUS_MATCHED,
            'match_id' => 'MTCH00000001',
        ]);

        verify($entry->matched_at)->notEmpty();

        $entry->match_status = NostroEntry::STATUS_UNMATCHED;
        $entry->matched_at = '2026-01-01 10:00:00';
        $entry->save(false);

        verify($entry->matched_at)->null();

        $this->stdout('beforeSave: статус M проставляет matched_at; смена на U очищает matched_at даже при явно заданном значении.');
    }

    /**
     * Проверяет сценарий: audit is written on create update and delete.
     * @return void
     */
    public function testAuditIsWrittenOnCreateUpdateAndDelete(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();

        $entry = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'comment' => 'old',
        ], false);

        verify(NostroEntryAudit::find()->where([
            'entry_id' => $entry->id,
            'action' => NostroEntryAudit::ACTION_CREATE,
        ])->count())->equals(1);

        $entry->comment = 'new';
        $entry->save(false);

        verify(NostroEntryAudit::find()->where([
            'entry_id' => $entry->id,
            'action' => NostroEntryAudit::ACTION_UPDATE,
            'changed_field' => 'comment',
        ])->count())->equals(1);

        $entryId = (int)$entry->id;
        $entry->delete();

        verify(NostroEntryAudit::find()->where([
            'entry_id' => $entryId,
            'action' => NostroEntryAudit::ACTION_DELETE,
        ])->count())->equals(1);

        $this->stdout('Аудит записи: create при вставке, update с changed_field при изменении comment, delete перед удалением — по одной записи аудита на каждое событие.');
    }

    // ── TC-115 ────────────────────────────────────────────────────────────

    /**
     * TC-115. Невалидные значения ls/dc/match_status отклоняются `in range`.
     *
     * @return void
     */
    public function testInvalidEnumValuesAreRejected(): void
    {
        $entry = new NostroEntry();

        $entry->ls = 'X';
        verify($entry->validate(['ls']))->false();
        $entry->clearErrors();

        $entry->dc = 'Hold';
        verify($entry->validate(['dc']))->false();
        $entry->clearErrors();

        $entry->match_status = 'Z';
        verify($entry->validate(['match_status']))->false();

        $this->stdout('TC-115: невалидные значения ls=«X», dc=«Hold», match_status=«Z» отвергаются правилом `in range`.');
    }

    // ── TC-116 ────────────────────────────────────────────────────────────

    /**
     * TC-116. Превышение длин строковых полей отклоняется правилами `string max`.
     *
     * @return void
     */
    public function testStringLengthLimitsAreEnforced(): void
    {
        $entry = new NostroEntry();

        $entry->transaction_id = str_repeat('a', 61); // max 60
        verify($entry->validate(['transaction_id']))->false();
        $entry->clearErrors();

        $entry->instruction_id = str_repeat('b', 41); // max 40
        verify($entry->validate(['instruction_id']))->false();
        $entry->clearErrors();

        $entry->branch_code = '1234'; // max 3
        verify($entry->validate(['branch_code']))->false();
        $entry->clearErrors();

        $entry->comment = str_repeat('c', 41); // max 40
        verify($entry->validate(['comment']))->false();
        $entry->clearErrors();

        $entry->currency = 'EURO'; // max 3
        verify($entry->validate(['currency']))->false();

        $this->stdout('TC-116: превышение длин строковых полей (transaction_id>60, instruction_id>40, branch_code>3, comment>40, currency>3) отвергается.');
    }

    // ── TC-117 ────────────────────────────────────────────────────────────

    /**
     * TC-117. Несуществующие account_id/company_id отвергаются правилом `exist`.
     *
     * @return void
     */
    public function testForeignKeyExistenceIsValidated(): void
    {
        $entry = new NostroEntry();
        $entry->account_id = 9999999;
        $entry->company_id = 9999999;
        $entry->ls = NostroEntry::LS_LEDGER;
        $entry->dc = NostroEntry::DC_DEBIT;
        $entry->amount = '10.00';
        $entry->currency = 'RUB';

        verify($entry->validate())->false();
        verify($entry->errors)->arrayHasKey('account_id');
        verify($entry->errors)->arrayHasKey('company_id');

        $this->stdout('TC-117: account_id и company_id несуществующих ID отвергаются `exist`-валидацией (ошибки на обоих полях).');
    }
}
