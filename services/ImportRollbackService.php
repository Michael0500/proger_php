<?php

namespace app\services;

use Yii;

/**
 * Сервис отката пачек импорта.
 *
 * Каждая загрузка (FCC12 / TDS / DWH / ручные ASB / БНД) представлена строкой
 * в `tds_status`, а все вставленные ею строки `nostro_entries` / `nostro_balance`
 * несут `batch_id = tds_status.id`. Откат удаляет эти строки вместе с их аудитом.
 *
 * Правила:
 *   - откатить можно только пачку, у которой ещё нет ни одной сквитованной записи
 *     (`match_id` задан или `match_status ∈ {M, I}`) И ни одна запись пачки не ушла
 *     в архив (`nostro_entries_archive.batch_id`);
 *   - `is_merged` при откате НЕ снимается; выставляется отдельный флаг
 *     `is_rolled_back` + `rolled_back_at` / `rolled_back_by`;
 *   - исходные таблицы (ph_tds_stmt_*, suspend_posting, gitb_nostro_extract_custom)
 *     не восстанавливаются — откат удаляет только целевые данные.
 */
class ImportRollbackService
{
    /**
     * Проверяет, можно ли откатить пачку.
     *
     * @param array $batch Строка `tds_status` (минимум: id, is_merged, is_rolled_back).
     * @return array `['ok' => bool, 'reason' => string]`.
     */
    public function canRollback(array $batch): array
    {
        if (empty($batch['is_merged'])) {
            return ['ok' => false, 'reason' => 'Пачка ещё не обработана'];
        }
        if (!empty($batch['is_rolled_back'])) {
            return ['ok' => false, 'reason' => 'Пачка уже откатывалась'];
        }

        $batchId = (int)$batch['id'];
        $db = Yii::$app->db;

        $matched = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%nostro_entries}}
              WHERE batch_id = :id
                AND (match_id IS NOT NULL OR match_status IN ('M', 'I'))",
            [':id' => $batchId]
        )->queryScalar();

        if ($matched > 0) {
            return ['ok' => false, 'reason' => 'В пачке есть сквитованные записи'];
        }

        $archived = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%nostro_entries_archive}} WHERE batch_id = :id",
            [':id' => $batchId]
        )->queryScalar();

        if ($archived > 0) {
            return ['ok' => false, 'reason' => 'Часть записей пачки уже в архиве'];
        }

        $liveEntries  = (int)$db->createCommand("SELECT COUNT(*) FROM {{%nostro_entries}} WHERE batch_id = :id", [':id' => $batchId])->queryScalar();
        $liveBalances = (int)$db->createCommand("SELECT COUNT(*) FROM {{%nostro_balance}} WHERE batch_id = :id", [':id' => $batchId])->queryScalar();

        if ($liveEntries === 0 && $liveBalances === 0) {
            return ['ok' => false, 'reason' => 'Нет данных для отката (импорт до внедрения отката)'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Откатывает пачку: удаляет её данные и помечает `tds_status` откатанной.
     *
     * @param int $batchId ID пачки `tds_status`.
     * @param int $userId ID пользователя, выполнившего откат.
     * @return array `['success' => bool, 'message' => string, ...]`.
     */
    public function rollback(int $batchId, int $userId): array
    {
        $db = Yii::$app->db;

        $batch = $db->createCommand(
            "SELECT * FROM {{%tds_status}} WHERE id = :id",
            [':id' => $batchId]
        )->queryOne();

        if (!$batch) {
            return ['success' => false, 'message' => 'Пачка не найдена'];
        }

        $check = $this->canRollback($batch);
        if (!$check['ok']) {
            return ['success' => false, 'message' => 'Откат недоступен: ' . $check['reason']];
        }

        $tx = $db->beginTransaction();
        try {
            // Аудит записей пачки (entry_id ссылается на удаляемые nostro_entries).
            $db->createCommand(
                "DELETE FROM {{%nostro_entry_audit}}
                  WHERE entry_id IN (SELECT id FROM {{%nostro_entries}} WHERE batch_id = :id)",
                [':id' => $batchId]
            )->execute();

            $deletedEntries = $db->createCommand(
                "DELETE FROM {{%nostro_entries}} WHERE batch_id = :id",
                [':id' => $batchId]
            )->execute();

            // Аудит балансов пачки.
            $db->createCommand(
                "DELETE FROM {{%nostro_balance_audit}}
                  WHERE balance_id IN (SELECT id FROM {{%nostro_balance}} WHERE batch_id = :id)",
                [':id' => $batchId]
            )->execute();

            $deletedBalances = $db->createCommand(
                "DELETE FROM {{%nostro_balance}} WHERE batch_id = :id",
                [':id' => $batchId]
            )->execute();

            $db->createCommand()->update('{{%tds_status}}', [
                'is_rolled_back' => true,
                'rolled_back_at' => date('Y-m-d H:i:s'),
                'rolled_back_by' => $userId,
            ], ['id' => $batchId])->execute();

            $tx->commit();

            return [
                'success'          => true,
                'message'          => "Пачка откатана: удалено записей {$deletedEntries}, балансов {$deletedBalances}",
                'deleted_entries'  => $deletedEntries,
                'deleted_balances' => $deletedBalances,
            ];
        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error('Import rollback failed: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'message' => 'Ошибка отката: ' . $e->getMessage()];
        }
    }
}
