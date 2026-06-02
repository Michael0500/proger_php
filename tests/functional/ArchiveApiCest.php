<?php

use app\models\ArchiveSettings;
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
        $I->wantTo('Архивация: batch переносит сквитованные записи в архив и пишет аудит');
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
        $I->wantTo('Восстановление из архива: preview и restore работают для всей match-группы');
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
     * TC-090. count считает кандидатов по matched_at, а не updated_at.
     *
     * @return void
     */
    public function countUsesMatchedAtForAging(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: count агрегирует кандидатов по matched_at (не updated_at)');
        SmartMatchTestHelper::createArchiveSettings((int)$this->company->id, ['archive_after_days' => 30]);

        // Старая matched_at, но updated_at у записи будет «сейчас» (через beforeSave).
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'match_id' => 'MTCH00000090', 'match_status' => NostroEntry::STATUS_MATCHED,
            'matched_at' => '2026-01-01 10:00:00',
        ]);
        // Свежая matched_at — кандидатом быть не должна.
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'match_id' => 'MTCH00000091', 'match_status' => NostroEntry::STATUS_MATCHED,
            'matched_at' => date('Y-m-d H:i:s'),
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/archive/count']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, (int)$response['total']);
    }

    /**
     * TC-091. purge-expired удаляет только истёкшие архивы текущей компании.
     *
     * @return void
     */
    public function purgeExpiredRemovesOnlyExpiredOfCurrentCompany(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: purge-expired удаляет только истёкшие записи своей компании');
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 91, 'match_id' => 'MTCH00000091',
            'expires_at' => '2025-01-01 00:00:00',
        ]);
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 92, 'match_id' => 'MTCH00000092',
            'expires_at' => '2030-01-01 00:00:00',
        ]);
        [$otherCompany, , $otherAccount] = SmartMatchTestHelper::createCompanyPoolAccount();
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $otherCompany->id, 'account_id' => $otherAccount->id,
            'original_id' => 93, 'match_id' => 'MTCH00000093',
            'expires_at' => '2025-01-01 00:00:00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/archive/purge-expired']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, (int)$response['deleted']);
        Assert::assertSame(1, (int)NostroEntryArchive::find()->where(['company_id' => $this->company->id])->count());
        Assert::assertSame(1, (int)NostroEntryArchive::find()->where(['company_id' => $otherCompany->id])->count());
    }

    /**
     * TC-092. save-settings отвергает значения вне диапазона.
     *
     * @return void
     */
    public function saveSettingsRejectsOutOfRangeValues(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: save-settings отвергает archive_after_days=-1, retention_years=21');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/archive/save-settings']), [
            'archive_after_days' => -1,
            'retention_years' => 21,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertArrayHasKey('errors', $response);
        Assert::assertArrayHasKey('archive_after_days', $response['errors']);
        Assert::assertArrayHasKey('retention_years', $response['errors']);
    }

    /**
     * TC-093. list фильтрует архив по диапазону суммы.
     *
     * @return void
     */
    public function listFiltersByAmountRange(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: list фильтрует по диапазону суммы (amount_min/amount_max)');
        foreach (['50.00', '100.00', '150.00'] as $i => $amt) {
            SmartMatchTestHelper::createArchivedEntry([
                'company_id' => $this->company->id, 'account_id' => $this->account->id,
                'original_id' => 200 + $i, 'match_id' => 'MTCH-AMT-' . $i,
                'amount' => $amt,
            ]);
        }

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/list']), [
            'filters' => json_encode(['amount_min' => 80, 'amount_max' => 120]),
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, (int)$response['total']);
        Assert::assertEquals(100.0, (float)$response['data'][0]['amount']);
    }

    /**
     * TC-094. list поиск по конкретному полю — регистронезависимый (ilike).
     *
     * @return void
     */
    public function listSearchFieldIsCaseInsensitive(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: list поиск по instruction_id регистронезависимый');
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 301, 'match_id' => 'MTCH-SRCH', 'instruction_id' => 'INSTR-ABC',
        ]);
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 302, 'match_id' => 'MTCH-OTHER', 'instruction_id' => 'OTHER',
        ]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/list']), [
            'filters' => json_encode(['search_field' => 'instruction_id', 'search_value' => 'abc']),
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, (int)$response['total']);
        Assert::assertSame('INSTR-ABC', $response['data'][0]['instruction_id']);
    }

    /**
     * TC-095. list ограничивает значение limit диапазоном [10..200].
     *
     * @return void
     */
    public function listClampsLimitToBounds(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: list ограничивает limit в диапазоне [10..200]');
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/list']), ['limit' => 9999]);
        $response = $this->grabJson($I);
        Assert::assertSame(200, (int)$response['limit']);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/list']), ['limit' => 1]);
        $response = $this->grabJson($I);
        Assert::assertSame(10, (int)$response['limit']);
    }

    /**
     * TC-096. list возвращает только записи текущей компании.
     *
     * @return void
     */
    public function listIsScopedByCompany(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: list возвращает только записи текущей компании');
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 401, 'match_id' => 'MTCH-OWN',
        ]);
        [$otherCompany, , $otherAccount] = SmartMatchTestHelper::createCompanyPoolAccount();
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $otherCompany->id, 'account_id' => $otherAccount->id,
            'original_id' => 402, 'match_id' => 'MTCH-FOREIGN',
        ]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/list']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, (int)$response['total']);
        Assert::assertSame('MTCH-OWN', $response['data'][0]['match_id']);
    }

    /**
     * TC-097. restore-preview по чужой архивной записи возвращает «не найдена».
     *
     * @return void
     */
    public function restorePreviewRejectsForeignArchive(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: restore-preview по чужой архивной записи возвращает «не найдена»');
        [$otherCompany, , $otherAccount] = SmartMatchTestHelper::createCompanyPoolAccount();
        $foreign = SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $otherCompany->id, 'account_id' => $otherAccount->id,
            'original_id' => 501, 'match_id' => 'MTCH-FORGN',
        ]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/restore-preview']), ['id' => $foreign->id]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertStringContainsString('не найдена', $response['message']);
    }

    /**
     * TC-099. После run-batch аудит ACTION_ARCHIVE содержит archived_id,
     * указывающий на созданную архивную запись.
     *
     * @return void
     */
    public function archiveAuditLinksArchivedId(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: archive-аудит связан с архивной записью через archived_id');
        SmartMatchTestHelper::createArchiveSettings((int)$this->company->id, ['archive_after_days' => 1]);
        $entry = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'match_id' => 'MTCH00000099', 'match_status' => NostroEntry::STATUS_MATCHED,
            'matched_at' => '2026-01-01 10:00:00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/archive/run-batch']), [
            'total_done' => 0, 'total_all' => 1,
        ]);

        $archive = NostroEntryArchive::findOne(['original_id' => $entry->id]);
        Assert::assertNotNull($archive);
        $audit = NostroEntryAudit::findOne([
            'entry_id' => $entry->id,
            'action' => NostroEntryAudit::ACTION_ARCHIVE,
        ]);
        Assert::assertNotNull($audit);
        Assert::assertSame((int)$archive->id, (int)$audit->archived_id);
    }

    /**
     * TC-100. history архивной записи возвращает события аудита из исходной
     * записи (по original_id).
     *
     * @return void
     */
    public function historyExposesAuditEvents(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: history возвращает события аудита исходной записи (по original_id)');
        $originalId = 4242;
        $archive = SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => $originalId, 'match_id' => 'MTCH-HIST', 'comment' => 'new',
        ]);

        \Yii::$app->db->createCommand()->insert('{{%nostro_entry_audit}}', [
            'entry_id' => $originalId,
            'user_id' => $this->user->id,
            'action' => NostroEntryAudit::ACTION_UPDATE,
            'old_values' => json_encode(['comment' => 'old']),
            'new_values' => json_encode(['comment' => 'new']),
            'changed_field' => 'comment',
            'archived_id' => null,
            'reason' => null,
            'created_at' => '2026-01-05 10:00:00',
        ])->execute();

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/history']), ['id' => $archive->id]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertNotEmpty($response['data']);
        $actions = array_column($response['data'], 'action');
        Assert::assertContains(NostroEntryAudit::ACTION_UPDATE, $actions);
    }

    /**
     * TC-101. stats возвращает счётчики архива и текущие настройки.
     *
     * @return void
     */
    public function statsReturnsCounters(\FunctionalTester $I): void
    {
        $I->wantTo('Архив: stats возвращает total_archived/pending_archive/expired_records и settings');
        SmartMatchTestHelper::createArchiveSettings((int)$this->company->id, ['archive_after_days' => 30]);
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 601, 'match_id' => 'MTCH-ST-1',
            'expires_at' => '2025-01-01 00:00:00',
        ]);
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 602, 'match_id' => 'MTCH-ST-2',
            'expires_at' => '2030-01-01 00:00:00',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'match_id' => 'MTCH-ST-PEND', 'match_status' => NostroEntry::STATUS_MATCHED,
            'matched_at' => '2026-01-01 10:00:00',
        ]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/stats']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(2, (int)$response['data']['total_archived']);
        Assert::assertSame(1, (int)$response['data']['expired_records']);
        Assert::assertSame(1, (int)$response['data']['pending_archive']);
        Assert::assertArrayHasKey('archive_after_days', $response['data']['settings']);
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
