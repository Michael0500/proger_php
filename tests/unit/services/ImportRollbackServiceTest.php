<?php

namespace tests\unit\services;

use app\models\NostroBalance;
use app\models\NostroEntry;
use app\services\ImportRollbackService;
use Yii;

/**
 * Проверяет откат пачек импорта: удаление данных пачки и блокировки.
 */
class ImportRollbackServiceTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /** @var \app\models\Company */
    private $company;
    /** @var \app\models\Account */
    private $account;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
        $this->company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$this->company->id);
        $this->account = \SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$pool->id);
    }

    /**
     * Откат удаляет записи, балансы и их аудит и помечает пачку откатанной.
     *
     * @return void
     */
    public function testRollbackDeletesBatchDataAndAudit(): void
    {
        $batchId = $this->insertBatch();

        $entry = $this->makeEntry($batchId);
        $balance = $this->makeBalance($batchId);
        $this->insertEntryAudit((int)$entry->id);
        $this->insertBalanceAudit((int)$balance->id);

        $result = (new ImportRollbackService())->rollback($batchId, 0);

        $this->assertTrue($result['success']);
        $this->assertSame(0, (int)NostroEntry::find()->where(['batch_id' => $batchId])->count());
        $this->assertSame(0, (int)NostroBalance::find()->where(['batch_id' => $batchId])->count());
        $this->assertSame(0, (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%nostro_entry_audit}} WHERE entry_id = :id",
            [':id' => $entry->id]
        )->queryScalar());
        $this->assertSame(0, (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%nostro_balance_audit}} WHERE balance_id = :id",
            [':id' => $balance->id]
        )->queryScalar());

        $row = Yii::$app->db->createCommand(
            "SELECT is_merged, is_rolled_back, rolled_back_by FROM {{%tds_status}} WHERE id = :id",
            [':id' => $batchId]
        )->queryOne();
        $this->assertTrue((bool)$row['is_merged']);          // is_merged не снимается
        $this->assertTrue((bool)$row['is_rolled_back']);
        $this->assertSame(0, (int)$row['rolled_back_by']);

        $this->stdout('Откат пачки: удалены nostro_entries/nostro_balance + их аудит, is_merged сохранён, выставлен is_rolled_back.');
    }

    /**
     * Сквитованная запись в пачке блокирует откат.
     *
     * @return void
     */
    public function testMatchedEntryBlocksRollback(): void
    {
        $batchId = $this->insertBatch();
        $this->makeEntry($batchId, [
            'match_status' => NostroEntry::STATUS_MATCHED,
            'match_id' => 'MTCH00000001',
        ]);

        $result = (new ImportRollbackService())->rollback($batchId, 0);

        $this->assertFalse($result['success']);
        $this->assertSame(1, (int)NostroEntry::find()->where(['batch_id' => $batchId])->count());
        $this->assertFalse((bool)Yii::$app->db->createCommand(
            "SELECT is_rolled_back FROM {{%tds_status}} WHERE id = :id",
            [':id' => $batchId]
        )->queryScalar());

        $this->stdout('Откат заблокирован: в пачке есть сквитованная запись (match_id/match_status=M).');
    }

    /**
     * Заархивированная запись пачки блокирует откат.
     *
     * @return void
     */
    public function testArchivedEntryBlocksRollback(): void
    {
        $batchId = $this->insertBatch();
        $this->makeEntry($batchId);
        \SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'batch_id' => $batchId,
        ]);

        $result = (new ImportRollbackService())->rollback($batchId, 0);

        $this->assertFalse($result['success']);
        $this->assertSame(1, (int)NostroEntry::find()->where(['batch_id' => $batchId])->count());

        $this->stdout('Откат заблокирован: часть записей пачки уже в архиве (nostro_entries_archive.batch_id).');
    }

    /**
     * Повторный откат уже откатанной пачки запрещён.
     *
     * @return void
     */
    public function testAlreadyRolledBackBlocksRollback(): void
    {
        $batchId = $this->insertBatch(['is_rolled_back' => true]);
        $this->makeEntry($batchId);

        $result = (new ImportRollbackService())->rollback($batchId, 0);

        $this->assertFalse($result['success']);
        $this->assertSame(1, (int)NostroEntry::find()->where(['batch_id' => $batchId])->count());

        $this->stdout('Повторный откат запрещён: пачка уже помечена is_rolled_back.');
    }

    /**
     * Создаёт строку-пачку tds_status.
     *
     * @param array $attributes Переопределения полей.
     * @return int ID пачки.
     */
    private function insertBatch(array $attributes = []): int
    {
        Yii::$app->db->createCommand()->insert('{{%tds_status}}', array_merge([
            'type' => NostroBalance::SOURCE_ASB,
            'is_merged' => true,
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
        ], $attributes))->execute();

        return (int)Yii::$app->db->getLastInsertID('tds_status_id_seq');
    }

    /**
     * Создаёт запись nostro_entries в пачке.
     *
     * @param int $batchId ID пачки.
     * @param array $attributes Переопределения.
     * @return NostroEntry
     */
    private function makeEntry(int $batchId, array $attributes = []): NostroEntry
    {
        return \SmartMatchTestHelper::createEntry(array_merge([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'source' => NostroBalance::SOURCE_ASB,
            'batch_id' => $batchId,
        ], $attributes));
    }

    /**
     * Создаёт баланс nostro_balance в пачке.
     *
     * @param int $batchId ID пачки.
     * @return NostroBalance
     */
    private function makeBalance(int $batchId): NostroBalance
    {
        return \SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'source' => NostroBalance::SOURCE_ASB,
            'batch_id' => $batchId,
        ]);
    }

    /**
     * Вставляет строку аудита записи.
     *
     * @param int $entryId ID записи.
     * @return void
     */
    private function insertEntryAudit(int $entryId): void
    {
        Yii::$app->db->createCommand()->insert('{{%nostro_entry_audit}}', [
            'entry_id' => $entryId,
            'user_id' => 0,
            'action' => 'create',
            'reason' => 'Импорт из файла',
            'created_at' => date('Y-m-d H:i:s'),
        ])->execute();
    }

    /**
     * Вставляет строку аудита баланса.
     *
     * @param int $balanceId ID баланса.
     * @return void
     */
    private function insertBalanceAudit(int $balanceId): void
    {
        Yii::$app->db->createCommand()->insert('{{%nostro_balance_audit}}', [
            'balance_id' => $balanceId,
            'user_id' => 0,
            'action' => 'import',
            'reason' => 'Импорт из файла',
            'created_at' => date('Y-m-d H:i:s'),
        ])->execute();
    }
}
