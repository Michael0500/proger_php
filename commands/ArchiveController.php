<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\models\NostroEntry;
use app\models\NostroEntryArchive;
use app\models\ArchiveSettings;
use app\models\Company;

/**
 * Команды автоматического архивирования.
 *
 * Использование:
 *   php yii archive/run            — архивировать все компании
 *   php yii archive/run --company=1 — архивировать конкретную компанию
 *   php yii archive/purge-expired  — удалить просроченные из всех компаний
 *
 * Cron (каждую ночь в 02:00):
 *   0 2 * * * /path/to/php /path/to/yii archive/run >> /var/log/smartmatch-archive.log 2>&1
 */
class ArchiveController extends Controller
{
    /** @var int|null ID компании (опционально) */
    public $company;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['company']);
    }

    // ─────────────────────────────────────────────────────────────
    // archive/run
    // ─────────────────────────────────────────────────────────────
    public function actionRun(): int
    {
        $this->stdout("=== SmartMatch Archive Run: " . date('Y-m-d H:i:s') . " ===\n");

        $companies = $this->company
            ? [Company::findOne($this->company)]
            : Company::find()->all();

        $companies = array_filter($companies);

        if (empty($companies)) {
            $this->stderr("Компании не найдены.\n");
            return ExitCode::DATAERR;
        }

        $totalArchived = 0;
        $totalErrors   = 0;

        foreach ($companies as $company) {
            $settings = ArchiveSettings::getForCompany($company->id);

            if (!$settings->auto_archive_enabled && !$this->company) {
                $this->stdout("  [{$company->name}] Автоархивирование отключено — пропускаем.\n");
                continue;
            }

            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$settings->archive_after_days} days"));
            $this->stdout("  [{$company->name}] Порог: {$cutoffDate} (>{$settings->archive_after_days} дн.)\n");

            $baseQuery = NostroEntry::find()
                ->where([
                    'company_id'   => $company->id,
                    'match_status' => NostroEntry::STATUS_MATCHED,
                ])
                ->andWhere(['is not', 'match_id', null])
                ->andWhere(['<', 'updated_at', $cutoffDate])
                ->orderBy(['id' => SORT_ASC]);

            $count    = (int)$baseQuery->count();
            $archived = 0;
            $errors   = 0;

            $this->stdout("  [{$company->name}] Записей для архивирования: {$count}\n");
            if ($count === 0) continue;

            $db         = Yii::$app->db;
            $batchSize  = 200;
            $archivedAt = date('Y-m-d H:i:s');
            $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$settings->retention_years} years"));

            $archiveTable = NostroEntryArchive::tableName();
            $archiveCols  = [
                'original_id', 'account_id', 'company_id', 'match_id',
                'ls', 'dc', 'amount', 'currency', 'value_date', 'post_date',
                'instruction_id', 'end_to_end_id', 'transaction_id', 'message_id',
                'comment', 'source', 'match_status',
                'archived_at', 'expires_at', 'archived_by',
                'original_created_at', 'original_updated_at',
            ];

            foreach ($baseQuery->batch($batchSize) as $batch) {
                $rows = [];
                $ids  = [];
                foreach ($batch as $entry) {
                    $rows[] = [
                        $entry->id, $entry->account_id, $entry->company_id,
                        $entry->match_id, $entry->ls, $entry->dc,
                        $entry->amount, $entry->currency,
                        $entry->value_date, $entry->post_date,
                        $entry->instruction_id, $entry->end_to_end_id,
                        $entry->transaction_id, $entry->message_id,
                        $entry->comment, $entry->source,
                        NostroEntryArchive::STATUS_ARCHIVED,
                        $archivedAt, $expiresAt, null,
                        $entry->created_at, $entry->updated_at,
                    ];
                    $ids[] = $entry->id;
                }
                if (empty($ids)) continue;

                $transaction = $db->beginTransaction();
                try {
                    $db->createCommand()->batchInsert($archiveTable, $archiveCols, $rows)->execute();
                    $db->createCommand(
                        'DELETE FROM {{%nostro_entries}} WHERE id = ANY(:ids)',
                        [':ids' => '{' . implode(',', $ids) . '}']
                    )->execute();
                    $transaction->commit();
                    $archived += count($ids);
                } catch (\Exception $e) {
                    $transaction->rollBack();
                    $errors += count($ids);
                    Yii::error("Archive batch error [{$company->name}]: " . $e->getMessage(), 'archive');
                }
                unset($rows, $ids, $batch);
            }

            $this->stdout("  [{$company->name}] Заархивировано: {$archived}, ошибок: {$errors}\n");
            $totalArchived += $archived;
            $totalErrors   += $errors;
        }

        $this->stdout("=== Итого: заархивировано {$totalArchived}, ошибок: {$totalErrors} ===\n");

        return ExitCode::OK;
    }

    // ─────────────────────────────────────────────────────────────
    // archive/purge-expired
    // ─────────────────────────────────────────────────────────────
    public function actionPurgeExpired(): int
    {
        $this->stdout("=== Purge Expired Archives: " . date('Y-m-d H:i:s') . " ===\n");

        $now   = date('Y-m-d H:i:s');
        $query = NostroEntryArchive::find()->andWhere(['<', 'expires_at', $now]);

        if ($this->company) {
            $query->andWhere(['company_id' => (int)$this->company]);
        }

        $deleted = 0;
        $batch = $query->each(100);
        foreach ($batch as $row) {
            $row->delete();
            $deleted++;
        }

        $this->stdout("Удалено просроченных записей: {$deleted}\n");
        return ExitCode::OK;
    }
}