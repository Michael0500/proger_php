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
    }
}
