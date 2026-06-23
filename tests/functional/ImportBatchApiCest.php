<?php

use app\models\NostroEntry;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет страницу «Откат загруженных данных» (`ImportBatchController`):
 * ручной запуск загрузки (merge) под блокировкой и фильтрацию по секции.
 */
class ImportBatchApiCest
{
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
        // Первой создаётся company_id=1 — это NRE-компания (merge FCC/TDS идут в company 1).
        $this->company = SmartMatchTestHelper::createCompany();
        $pool = SmartMatchTestHelper::createPool((int)$this->company->id);
        $this->account = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$pool->id, [
            'name' => 'CAMT-ACC', 'currency' => 'USD',
        ]);

        $user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($user);
    }

    /**
     * Ручной запуск загрузки PH_TDS переносит данные и снимает блокировку.
     *
     * @return void
     */
    public function manualLoadMergesPendingBatch(\FunctionalTester $I): void
    {
        $I->wantTo('Импорт: ручной запуск загрузки PH_TDS переносит данные');
        $statusId = $this->insertPhTdsBatch();
        $this->seedCamtSource(5000);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/import-batch/load']), ['id' => $statusId]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertTrue($response['ok']);

        $entry = NostroEntry::findOne(['batch_id' => $statusId]);
        Assert::assertNotNull($entry);
        Assert::assertSame((int)$this->account->id, (int)$entry->account_id);

        $row = Yii::$app->db->createCommand(
            "SELECT is_merged, is_processing FROM {{%tds_status}} WHERE id = :id",
            [':id' => $statusId]
        )->queryOne();
        Assert::assertTrue((bool)$row['is_merged']);
        Assert::assertFalse((bool)$row['is_processing']);
    }

    /**
     * Занятая пачка (is_processing=true) не запускается вручную.
     *
     * @return void
     */
    public function manualLoadBlockedWhenProcessing(\FunctionalTester $I): void
    {
        $I->wantTo('Импорт: занятая пачка не запускается вручную (взаимоисключение)');
        $statusId = $this->insertPhTdsBatch();
        $this->seedCamtSource(5100);
        Yii::$app->db->createCommand()->update('{{%tds_status}}', [
            'is_processing' => true,
            'processing_owner' => 'background',
        ], ['id' => $statusId])->execute();

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/import-batch/load']), ['id' => $statusId]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame(0, (int)NostroEntry::find()->count());
        Assert::assertFalse((bool)$this->statusMerged($statusId));
    }

    /**
     * Список фильтруется по секции: NRE-компания не видит SUSPENSE_POSTING,
     * но видит PH_TDS и ASB.
     *
     * @return void
     */
    public function listFiltersBySection(\FunctionalTester $I): void
    {
        $I->wantTo('Импорт: список скрывает SUSPENSE_POSTING в секции NRE');
        // Все с company_id=1, чтобы company-scope пропустил — фильтрует именно секция.
        $this->insertBatch('PH_TDS', false);
        $this->insertBatch('ASB', true);
        $this->insertBatch('SUSPENSE_POSTING', false);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/import-batch/list']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        $types = array_map(static fn($b) => $b['type'], $response['data']);
        Assert::assertContains('PH_TDS', $types);
        Assert::assertContains('ASB', $types);
        Assert::assertNotContains('SUSPENSE_POSTING', $types);
    }

    /**
     * Вставляет пачку tds_status указанного типа в company_id=1.
     *
     * @param string $type Тип пачки.
     * @param bool $merged is_merged.
     * @return int ID пачки.
     */
    private function insertBatch(string $type, bool $merged): int
    {
        Yii::$app->db->createCommand()->insert('{{%tds_status}}', [
            'type' => $type,
            'is_merged' => $merged,
            'company_id' => (int)$this->company->id,
        ])->execute();
        return (int)Yii::$app->db->getLastInsertID('tds_status_id_seq');
    }

    /**
     * Вставляет pending-пачку PH_TDS.
     *
     * @return int ID пачки.
     */
    private function insertPhTdsBatch(): int
    {
        return $this->insertBatch('PH_TDS', false);
    }

    /**
     * Создаёт минимальный источник CAMT053 (hdr + dtl) под счёт CAMT-ACC.
     *
     * @param int $stmtId Идентификатор выписки.
     * @return void
     */
    private function seedCamtSource(int $stmtId): void
    {
        Yii::$app->db->createCommand()->insert('{{%ph_tds_stmt_hdr}}', [
            'stmt_id' => $stmtId,
            'format_type' => 'CAMT053',
            'account_no' => 'CAMT-ACC',
            'opening_currency' => 'USD',
            'opening_value_dt' => '2026-01-10',
            'opening_amount' => '0.00',
            'opening_dc' => 'C',
            'closing_amount' => '0.00',
            'closing_dc' => 'D',
        ])->execute();
        Yii::$app->db->createCommand()->insert('{{%ph_tds_stmt_dtl}}', [
            'stmt_id' => $stmtId,
            'line_no' => 1,
            'dc_mark' => 'DBIT',
            'amount' => '10.00',
            'currency' => 'USD',
        ])->execute();
    }

    /**
     * Возвращает is_merged пачки.
     *
     * @param int $statusId ID пачки.
     * @return mixed
     */
    private function statusMerged(int $statusId)
    {
        return Yii::$app->db->createCommand(
            "SELECT is_merged FROM {{%tds_status}} WHERE id = :id",
            [':id' => $statusId]
        )->queryScalar();
    }

    /**
     * Декодирует JSON-ответ.
     *
     * @return array
     */
    private function grabJson(\FunctionalTester $I): array
    {
        $decoded = json_decode($I->grabPageSource(), true);
        Assert::assertIsArray($decoded);
        return $decoded;
    }
}
