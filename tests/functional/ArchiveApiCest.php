<?php

use app\models\NostroEntry;
use app\models\NostroEntryArchive;
use app\models\NostroEntryAudit;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `ArchiveApiCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class ArchiveApiCest
{
    private User $user;
    private $company;
    private $account;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        [$this->company, , $this->account] = SmartMatchTestHelper::createCompanyPoolAccount();
        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: run batch moves matched entries to archive and writes audit.
     *
     * @return void
     */
    public function runBatchMovesMatchedEntriesToArchiveAndWritesAudit(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::createArchiveSettings((int)$this->company->id, ['archive_after_days' => 1]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'match_id' => 'MTCH00000010',
            'match_status' => NostroEntry::STATUS_MATCHED,
            'matched_at' => '2026-01-01 10:00:00',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'match_id' => 'MTCH00000010',
            'match_status' => NostroEntry::STATUS_MATCHED,
            'matched_at' => '2026-01-01 10:00:00',
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/archive/run-batch']), [
            'total_done' => 0,
            'total_all' => 2,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(2, $response['archived']);
        Assert::assertSame(0, (int)NostroEntry::find()->count());
        Assert::assertSame(2, (int)NostroEntryArchive::find()->count());
        Assert::assertSame(2, (int)NostroEntryAudit::find()->where([
            'action' => NostroEntryAudit::ACTION_ARCHIVE,
        ])->count());
    }

    /**
     * Выполняет тестовый сценарий: restore preview and restore work for whole match group.
     *
     * @return void
     */
    public function restorePreviewAndRestoreWorkForWholeMatchGroup(\FunctionalTester $I): void
    {
        $first = SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'original_id' => 11,
            'match_id' => 'MTCH00000011',
        ]);
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'original_id' => 12,
            'match_id' => 'MTCH00000011',
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
        ]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/restore-preview']), ['id' => $first->id]);
        $preview = $this->grabJson($I);
        Assert::assertTrue($preview['success']);
        Assert::assertSame(2, $preview['count']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/archive/restore']), ['id' => $first->id]);
        $restore = $this->grabJson($I);

        Assert::assertTrue($restore['success']);
        Assert::assertSame(2, $restore['count']);
        Assert::assertSame(2, (int)NostroEntry::find()->where(['match_id' => 'MTCH00000011'])->count());
        Assert::assertSame(0, (int)NostroEntryArchive::find()->where(['match_id' => 'MTCH00000011'])->count());
        Assert::assertSame(2, (int)NostroEntryAudit::find()->where([
            'action' => NostroEntryAudit::ACTION_RESTORE,
        ])->count());
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
