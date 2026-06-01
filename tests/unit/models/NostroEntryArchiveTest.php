<?php

namespace tests\unit\models;

use app\models\NostroEntry;
use app\models\NostroEntryArchive;

/**
 * Тестовый класс `NostroEntryArchiveTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class NostroEntryArchiveTest extends \Codeception\Test\Unit
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
     * Проверяет сценарий: archive entry copies matched entry and retention dates.
     * @return void
     */
    public function testArchiveEntryCopiesMatchedEntryAndRetentionDates(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $entry = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'match_id' => 'MTCH00000077',
            'match_status' => NostroEntry::STATUS_MATCHED,
            'matched_at' => '2026-01-11 10:00:00',
            'instruction_id' => 'INS-1',
        ]);

        $archive = NostroEntryArchive::archiveEntry($entry, 3, 42);

        verify($archive)->notEmpty();
        verify($archive->original_id)->equals($entry->id);
        verify($archive->match_id)->equals('MTCH00000077');
        verify($archive->match_status)->equals(NostroEntryArchive::STATUS_ARCHIVED);
        verify($archive->matched_at)->equals($entry->matched_at);
        verify($archive->archived_by)->equals(42);
        verify(strtotime($archive->expires_at))->greaterThan(strtotime($archive->archived_at));

        $this->stdout('archiveEntry: сквитованная запись копируется в архив со статусом A, сохраняется match_id/matched_at, проставляется archived_by и expires_at > archived_at.');
    }
}
