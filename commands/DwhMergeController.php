<?php

namespace app\commands;

use app\models\NostroBalance;
use app\models\NostroEntry;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Переносит проводки DWH из suspend_posting в INV-балансы и записи выверки.
 *
 * Алгоритм:
 *   1. В tds_status ищем type = 'DWH' и is_merged = false.
 *   2. Для каждой такой записи открываем транзакцию.
 *   3. Из suspend_posting выбираем строки с is_merged = false.
 *      - сгруппированные остатки → nostro_balance (section=INV, ls_type=L);
 *      - строки с amount → nostro_entries (ls=L).
 *   4. Успешно обработанные строки suspend_posting помечаются is_merged=true
 *      или удаляются при --delete-source.
 *   5. Если пропусков нет — tds_status.is_merged := true.
 *   6. Commit.
 *
 * Использование:
 *   php yii dwh-merge/run
 *   php yii dwh-merge/run --delete-source
 */
class DwhMergeController extends Controller
{
    const COMPANY_ID = 2;
    const SOURCE = 'DWH';
    const SECTION = 'INV';
    const LS_LEDGER = 'L';
    const FETCH_CHUNK = 5000;
    const INSERT_CHUNK = 1000;
    const AUDIT_REASON = 'Импорт DWH';

    /** --delete-source: удалять успешно обработанные строки suspend_posting */
    public bool $deleteSource = false;

    /**
     * Описание опций командной строки.
     *
     * @param string $actionID Идентификатор action.
     * @return array
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['deleteSource']);
    }

    /**
     * Алиасы опций (--delete-source → --deleteSource).
     *
     * @return array
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'delete-source' => 'deleteSource',
        ]);
    }

    /**
     * Запускает перенос необработанных DWH-строк.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionRun(): int
    {
        $mode = $this->deleteSource ? ' [delete-source]' : '';
        $this->stdout("=== DWH merge: " . date('Y-m-d H:i:s') . $mode . " ===\n", Console::BOLD);

        $db = Yii::$app->db;
        $pending = $db->createCommand(
            "SELECT id, type
               FROM {{%tds_status}}
              WHERE type = :type
                AND is_merged = FALSE
              ORDER BY id",
            [':type' => self::SOURCE]
        )->queryAll();

        if (empty($pending)) {
            $this->stdout("Нет записей для обработки.\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("К обработке: " . count($pending) . "\n");

        $total = $this->emptySummary();
        $errors = 0;

        foreach ($pending as $row) {
            $statusId = (int)$row['id'];
            $this->stdout("\n┌─ tds_status.id={$statusId}, type={$row['type']}\n", Console::FG_CYAN);

            $tx = $db->beginTransaction();
            try {
                $summary = $this->mergePending($this->deleteSource);

                if ($summary['source_rows'] === 0) {
                    $this->stdout("│  Нет строк suspend_posting для обработки.\n", Console::FG_GREY);
                }

                $allOk = $summary['skipped_rows'] === 0 && $summary['skipped_balances'] === 0;
                if ($allOk) {
                    $db->createCommand()
                        ->update('{{%tds_status}}', ['is_merged' => true], ['id' => $statusId])
                        ->execute();
                }

                $tx->commit();
                $this->addSummary($total, $summary);

                $this->stdout("│  Балансов: {$summary['balances']}, записей: {$summary['entries']}");
                if ($summary['skipped_rows'] > 0) {
                    $this->stdout(", пропущено строк: {$summary['skipped_rows']}", Console::FG_YELLOW);
                }
                if ($summary['duplicate_posting_ids'] > 0) {
                    $this->stdout(", дублей posting_id: {$summary['duplicate_posting_ids']}", Console::FG_YELLOW);
                }
                if ($summary['skipped_balances'] > 0) {
                    $this->stdout(", конфликтов баланса: {$summary['skipped_balances']}", Console::FG_YELLOW);
                }
                $this->stdout("\n");

                if ($allOk) {
                    $this->stdout("└─ OK\n");
                } else {
                    $this->stdout("└─ PARTIAL (tds_status не помечен merged, будет повторён)\n", Console::FG_YELLOW);
                }
            } catch (\Throwable $e) {
                $tx->rollBack();
                $errors++;
                $this->stderr("│  Ошибка: " . $e->getMessage() . "\n", Console::FG_RED);
                $this->stdout("└─ ROLLBACK\n", Console::FG_RED);
            }
        }

        $this->stdout(
            "\n=== Итого: обработано строк {$total['processed_rows']}, "
            . "балансов {$total['balances']}, записей {$total['entries']}",
            Console::BOLD
        );

        if ($total['skipped_rows'] > 0) {
            $this->stdout(", пропущено строк {$total['skipped_rows']}", Console::FG_YELLOW);
        }
        if ($total['duplicate_posting_ids'] > 0) {
            $this->stdout(", дублей posting_id {$total['duplicate_posting_ids']}", Console::FG_YELLOW);
        }
        if ($total['skipped_balances'] > 0) {
            $this->stdout(", конфликтов баланса {$total['skipped_balances']}", Console::FG_YELLOW);
        }
        if ($errors > 0) {
            $this->stdout(", ошибок {$errors}", Console::FG_RED);
        }
        $this->stdout(" ===\n");

        return $errors > 0 ? ExitCode::SOFTWARE : ExitCode::OK;
    }

    /**
     * Обрабатывает все строки suspend_posting, у которых is_merged=false.
     *
     * @param bool $deleteSource Удалять успешно обработанные строки источника.
     * @return array Статистика обработки.
     */
    private function mergePending(bool $deleteSource = false): array
    {
        $summary = $this->emptySummary();

        $lastId = 0;
        $accountCache = [];

        while (true) {
            $rows = Yii::$app->db->createCommand(
                "SELECT *
                   FROM {{%suspend_posting}}
                  WHERE is_merged = FALSE
                    AND id > :last_id
                  ORDER BY id
                  LIMIT :limit",
                [':last_id' => $lastId, ':limit' => self::FETCH_CHUNK]
            )->queryAll();

            if (empty($rows)) {
                break;
            }

            $summary['source_rows'] += count($rows);
            $lastId = (int)$rows[count($rows) - 1]['id'];

            $this->processRows($rows, $deleteSource, $accountCache, $summary);

            if (count($rows) < self::FETCH_CHUNK) {
                break;
            }
        }

        return $summary;
    }

    /**
     * Возвращает пустую структуру статистики обработки.
     *
     * @return array
     */
    private function emptySummary(): array
    {
        return [
            'source_rows' => 0,
            'processed_rows' => 0,
            'skipped_rows' => 0,
            'entries' => 0,
            'balances' => 0,
            'duplicate_posting_ids' => 0,
            'skipped_balances' => 0,
        ];
    }

    /**
     * Добавляет статистику одного пакета к общей статистике.
     *
     * @param array $total Итоговая статистика.
     * @param array $summary Статистика пакета.
     * @return void
     */
    private function addSummary(array &$total, array $summary): void
    {
        foreach ($total as $key => $value) {
            $total[$key] += $summary[$key] ?? 0;
        }
    }

    /**
     * Обрабатывает один chunk строк источника.
     *
     * @param array $rows Строки suspend_posting.
     * @param bool $deleteSource Удалять успешно обработанные строки.
     * @param array $accountCache Кэш `cbaccount => account_id|null`.
     * @param array $summary Статистика обработки.
     * @return void
     */
    private function processRows(array $rows, bool $deleteSource, array &$accountCache, array &$summary): void
    {
        $validRows = [];

        foreach ($rows as $row) {
            $sourceId = (int)$row['id'];
            $cbaccount = trim((string)$row['cbaccount']);

            if ($cbaccount === '') {
                $this->progress("Пропуск suspend_posting.id={$sourceId}: пустой cbaccount");
                $summary['skipped_rows']++;
                continue;
            }

            if (!array_key_exists($cbaccount, $accountCache)) {
                $accountCache[$cbaccount] = Yii::$app->db->createCommand(
                    "SELECT id
                       FROM {{%accounts}}
                      WHERE company_id = :company_id
                        AND name = :name
                      LIMIT 1",
                    [':company_id' => self::COMPANY_ID, ':name' => $cbaccount]
                )->queryScalar() ?: null;
            }

            if ($accountCache[$cbaccount] === null) {
                $this->progress("Пропуск suspend_posting.id={$sourceId}: счёт не найден для cbaccount='{$cbaccount}'");
                $summary['skipped_rows']++;
                continue;
            }

            if (!$this->hasRequiredBalanceFields($row)) {
                $this->progress("Пропуск suspend_posting.id={$sourceId}: не хватает обязательных полей баланса");
                $summary['skipped_rows']++;
                continue;
            }

            if ($this->hasAmount($row) && !$this->isValidEntryDc($row['dc_indicator'] ?? null)) {
                $this->progress("Пропуск suspend_posting.id={$sourceId}: некорректный dc_indicator");
                $summary['skipped_rows']++;
                continue;
            }

            $row['_account_id'] = (int)$accountCache[$cbaccount];
            $validRows[] = $row;
        }

        if (empty($validRows)) {
            return;
        }

        $balanceHandledIds = $this->insertBalances($validRows, $summary);
        $entryHandledIds = $this->insertEntries($validRows, $summary);

        $processedIds = [];
        foreach ($validRows as $row) {
            $sourceId = (int)$row['id'];
            if (isset($balanceHandledIds[$sourceId]) && isset($entryHandledIds[$sourceId])) {
                $processedIds[] = $sourceId;
            }
        }

        if (!empty($processedIds)) {
            $this->finishSourceRows($processedIds, $deleteSource);
            $summary['processed_rows'] += count($processedIds);
        }
    }

    /**
     * Вставляет сгруппированные DWH-балансы.
     *
     * @param array $rows Валидные строки источника.
     * @param array $summary Статистика.
     * @return array Карта `source_id => true` для строк, чей баланс обработан.
     */
    private function insertBalances(array $rows, array &$summary): array
    {
        $now = date('Y-m-d H:i:s');
        $handledIds = [];
        $groups = [];

        foreach ($rows as $row) {
            $targetKey = $this->balanceTargetKey($row);
            $groupKey = $targetKey . '|' . implode('|', [
                $this->truncateMoney($row['saldo_in_amt'] ?? 0),
                $this->truncateMoney($row['saldo_out_amt'] ?? 0),
                $this->normalizeBalanceDc($row['dc_indicator_saldo'] ?? null),
                $this->normalizeBalanceDc($row['dc_indicator'] ?? null),
                (string)($row['abs_branch_code'] ?? ''),
            ]);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'target_key' => $targetKey,
                    'row' => $row,
                    'source_ids' => [],
                ];
            }
            $groups[$groupKey]['source_ids'][] = (int)$row['id'];
        }

        $balanceRows = [];
        $plannedTargetKeys = [];
        $flush = function () use (&$balanceRows, &$summary) {
            if (empty($balanceRows)) {
                return;
            }

            $db = Yii::$app->db;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_balance}}")->queryScalar();
            $db->createCommand()->batchInsert('{{%nostro_balance}}', [
                'company_id', 'account_id', 'ls_type', 'currency', 'value_date',
                'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
                'section', 'source', 'status', 'branch_code', 'created_at', 'updated_at',
            ], $balanceRows)->execute();

            $this->writeBalanceAuditAfterFlush($lastId);
            $summary['balances'] += count($balanceRows);
            $balanceRows = [];
        };

        foreach ($groups as $group) {
            $row = $group['row'];
            $targetKey = $group['target_key'];

            if ($this->balanceExists($row)) {
                foreach ($group['source_ids'] as $sourceId) {
                    $handledIds[$sourceId] = true;
                }
                continue;
            }

            if (isset($plannedTargetKeys[$targetKey])) {
                $this->progress(
                    "Пропуск группы баланса cbaccount='{$row['cbaccount']}', valuedate='{$row['valuedate']}': конфликт уникального ключа"
                );
                $summary['skipped_balances']++;
                continue;
            }
            $plannedTargetKeys[$targetKey] = true;

            $balanceRows[] = [
                self::COMPANY_ID,
                (int)$row['_account_id'],
                self::LS_LEDGER,
                $row['ccy'],
                $row['valuedate'],
                $this->truncateMoney($row['saldo_in_amt'] ?? 0),
                $this->normalizeBalanceDc($row['dc_indicator_saldo'] ?? null),
                $this->truncateMoney($row['saldo_out_amt'] ?? 0),
                $this->normalizeBalanceDc($row['dc_indicator'] ?? null),
                self::SECTION,
                self::SOURCE,
                NostroBalance::STATUS_NORMAL,
                $row['abs_branch_code'] ?? null,
                $now,
                $now,
            ];

            foreach ($group['source_ids'] as $sourceId) {
                $handledIds[$sourceId] = true;
            }

            if (count($balanceRows) >= self::INSERT_CHUNK) {
                $flush();
            }
        }

        $flush();

        return $handledIds;
    }

    /**
     * Вставляет DWH-записи выверки.
     *
     * @param array $rows Валидные строки источника.
     * @param array $summary Статистика.
     * @return array Карта `source_id => true` для строк, чья запись обработана.
     */
    private function insertEntries(array $rows, array &$summary): array
    {
        $now = date('Y-m-d H:i:s');
        $handledIds = [];
        $entryRows = [];
        $postingIdsInBatch = [];
        $pendingPostingIds = [];

        foreach ($rows as $row) {
            if ($this->hasAmount($row)) {
                $pendingPostingIds[] = (string)$row['posting_id'];
            }
        }
        $existingPostingIds = $this->existingPostingIds($pendingPostingIds);

        $flush = function () use (&$entryRows, &$summary) {
            if (empty($entryRows)) {
                return;
            }

            $postingIds = [];
            foreach ($entryRows as $entryRow) {
                $postingIds[] = (string)$entryRow[19];
            }

            Yii::$app->db->createCommand()->batchInsert('{{%nostro_entries}}', [
                'account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
                'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
                'transaction_id', 'message_id', 'other_id', 'source',
                'match_status', 'branch_code', 'created_at', 'updated_at',
                'updated_by', 'posting_id',
            ], $entryRows)->execute();

            $this->writeEntryAuditByPostingIds($postingIds);
            $summary['entries'] += count($entryRows);
            $entryRows = [];
        };

        foreach ($rows as $row) {
            $sourceId = (int)$row['id'];
            if (!$this->hasAmount($row)) {
                $handledIds[$sourceId] = true;
                continue;
            }

            $postingId = (string)$row['posting_id'];
            if (isset($existingPostingIds[$postingId]) || isset($postingIdsInBatch[$postingId])) {
                $summary['duplicate_posting_ids']++;
                $this->progress("Пропуск записи suspend_posting.id={$sourceId}: posting_id={$postingId} уже импортирован");
                $handledIds[$sourceId] = true;
                continue;
            }

            $postingIdsInBatch[$postingId] = true;
            $entryRows[] = [
                (int)$row['_account_id'],
                self::COMPANY_ID,
                self::LS_LEDGER,
                $this->mapEntryDc($row['dc_indicator'] ?? null),
                $this->truncateMoney($row['amount']),
                $row['ccy'],
                $row['valuedate'],
                $row['start_date'],
                $this->truncateString($row['originaltran_ref'] ?? null, 40),
                $this->truncateString($row['narrative'] ?? null, 40),
                null,
                null,
                null,
                self::SOURCE,
                NostroEntry::STATUS_UNMATCHED,
                $row['abs_branch_code'] ?? null,
                $now,
                $now,
                null,
                $row['posting_id'],
            ];
            $handledIds[$sourceId] = true;

            if (count($entryRows) >= self::INSERT_CHUNK) {
                $flush();
            }
        }

        $flush();

        return $handledIds;
    }

    /**
     * Пишет аудит по импортированным DWH-записям.
     *
     * @param array $postingIds Список posting_id, вставленных в текущем batch.
     * @return void
     */
    private function writeEntryAuditByPostingIds(array $postingIds): void
    {
        $postingIds = array_values(array_unique(array_filter($postingIds, static fn($v) => $v !== null && $v !== '')));
        if (empty($postingIds)) {
            return;
        }

        [$inSql, $params] = $this->buildInCondition($postingIds, 'posting_id');
        $rows = Yii::$app->db->createCommand(
            "SELECT id, account_id, company_id, posting_id, ls, dc, amount, currency,
                    value_date, post_date, instruction_id, end_to_end_id,
                    transaction_id, message_id, other_id, comment, source,
                    match_status, match_id, extract_no, line_no, branch_code, created_at, updated_at
               FROM {{%nostro_entries}}
              WHERE source = :source
                AND posting_id IN ({$inSql})
              ORDER BY id",
            array_merge([':source' => self::SOURCE], $params)
        )->queryAll();

        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $auditRows[] = [
                (int)$row['id'],
                0,
                'create',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                null,
                null,
                self::AUDIT_REASON,
                $now,
            ];
        }

        Yii::$app->db->createCommand()->batchInsert('{{%nostro_entry_audit}}', [
            'entry_id', 'user_id', 'action', 'old_values', 'new_values',
            'changed_field', 'archived_id', 'reason', 'created_at',
        ], $auditRows)->execute();
    }

    /**
     * Пишет аудит по DWH-балансам, вставленным после указанного ID.
     *
     * @param int $lastId Максимальный id до batchInsert.
     * @return void
     */
    private function writeBalanceAuditAfterFlush(int $lastId): void
    {
        $rows = Yii::$app->db->createCommand(
            "SELECT id, company_id, account_id, ls_type, statement_number, currency,
                    value_date, opening_balance, opening_dc, closing_balance, closing_dc,
                    section, source, status, comment, extract_no, line_no, branch_code, created_at, updated_at
               FROM {{%nostro_balance}}
              WHERE id > :last_id
                AND company_id = :company_id
                AND source = :source
                AND section = :section
              ORDER BY id",
            [
                ':last_id' => $lastId,
                ':company_id' => self::COMPANY_ID,
                ':source' => self::SOURCE,
                ':section' => self::SECTION,
            ]
        )->queryAll();

        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $auditRows[] = [
                (int)$row['id'],
                0,
                'import',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                self::AUDIT_REASON,
                $now,
            ];
        }

        Yii::$app->db->createCommand()->batchInsert('{{%nostro_balance_audit}}', [
            'balance_id', 'user_id', 'action', 'old_values',
            'new_values', 'reason', 'created_at',
        ], $auditRows)->execute();
    }

    /**
     * Возвращает уже импортированные posting_id.
     *
     * @param array $postingIds Список ID из источника.
     * @return array Карта `posting_id => true`.
     */
    private function existingPostingIds(array $postingIds): array
    {
        $postingIds = array_values(array_unique(array_filter($postingIds, static fn($v) => $v !== null && $v !== '')));
        if (empty($postingIds)) {
            return [];
        }

        [$inSql, $params] = $this->buildInCondition($postingIds, 'posting_id');
        $rows = Yii::$app->db->createCommand(
            "SELECT posting_id
               FROM {{%nostro_entries}}
              WHERE posting_id IN ({$inSql})",
            $params
        )->queryColumn();

        $map = [];
        foreach ($rows as $postingId) {
            $map[(string)$postingId] = true;
        }

        return $map;
    }

    /**
     * Помечает или удаляет успешно обработанные строки источника.
     *
     * @param array $sourceIds ID строк suspend_posting.
     * @param bool $deleteSource Удалять строки вместо пометки.
     * @return void
     */
    private function finishSourceRows(array $sourceIds, bool $deleteSource): void
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        foreach (array_chunk($sourceIds, self::INSERT_CHUNK) as $chunk) {
            [$inSql, $params] = $this->buildInCondition($chunk, 'id');
            if ($deleteSource) {
                Yii::$app->db->createCommand(
                    "DELETE FROM {{%suspend_posting}} WHERE id IN ({$inSql})",
                    $params
                )->execute();
            } else {
                Yii::$app->db->createCommand(
                    "UPDATE {{%suspend_posting}}
                        SET is_merged = TRUE
                      WHERE id IN ({$inSql})",
                    $params
                )->execute();
            }
        }
    }

    /**
     * Проверяет, существует ли целевой баланс.
     *
     * @param array $row Строка источника.
     * @return bool
     */
    private function balanceExists(array $row): bool
    {
        return (bool)Yii::$app->db->createCommand(
            "SELECT 1
               FROM {{%nostro_balance}}
              WHERE company_id = :company_id
                AND account_id = :account_id
                AND ls_type = :ls_type
                AND currency = :currency
                AND value_date = :value_date
                AND section = :section
                AND source = :source
              LIMIT 1",
            [
                ':company_id' => self::COMPANY_ID,
                ':account_id' => (int)$row['_account_id'],
                ':ls_type' => self::LS_LEDGER,
                ':currency' => $row['ccy'],
                ':value_date' => $row['valuedate'],
                ':section' => self::SECTION,
                ':source' => self::SOURCE,
            ]
        )->queryScalar();
    }

    /**
     * Возвращает ключ уникального целевого баланса.
     *
     * @param array $row Строка источника.
     * @return string
     */
    private function balanceTargetKey(array $row): string
    {
        return implode('|', [
            (int)$row['_account_id'],
            self::LS_LEDGER,
            (string)$row['ccy'],
            (string)$row['valuedate'],
            self::SECTION,
            self::SOURCE,
        ]);
    }

    /**
     * Проверяет обязательные поля для формирования баланса.
     *
     * @param array $row Строка источника.
     * @return bool
     */
    private function hasRequiredBalanceFields(array $row): bool
    {
        return trim((string)($row['ccy'] ?? '')) !== ''
            && trim((string)($row['valuedate'] ?? '')) !== ''
            && $this->isValidBalanceDc($row['dc_indicator_saldo'] ?? null)
            && $this->isValidBalanceDc($row['dc_indicator'] ?? null);
    }

    /**
     * Есть ли в строке сумма операции для nostro_entries.
     *
     * @param array $row Строка источника.
     * @return bool
     */
    private function hasAmount(array $row): bool
    {
        return $row['amount'] !== null && $row['amount'] !== '';
    }

    /**
     * D/C для балансов должен остаться в формате D/C.
     *
     * @param string|null $raw Сырое значение.
     * @return string
     */
    private function normalizeBalanceDc(?string $raw): string
    {
        $value = strtoupper(trim((string)$raw));
        if ($value !== 'D' && $value !== 'C') {
            throw new \RuntimeException("Некорректный D/C баланса: '{$raw}'");
        }

        return $value;
    }

    /**
     * Проверяет D/C для баланса.
     *
     * @param string|null $raw Сырое значение.
     * @return bool
     */
    private function isValidBalanceDc(?string $raw): bool
    {
        $value = strtoupper(trim((string)$raw));
        return $value === 'D' || $value === 'C';
    }

    /**
     * Проверяет D/C для записи выверки.
     *
     * @param string|null $raw Сырое значение.
     * @return bool
     */
    private function isValidEntryDc(?string $raw): bool
    {
        $value = strtoupper(trim((string)$raw));
        return $value === 'D' || $value === 'C';
    }

    /**
     * Преобразует DWH D/C в значение nostro_entries.dc.
     *
     * @param string|null $raw Сырое значение.
     * @return string
     */
    private function mapEntryDc(?string $raw): string
    {
        $value = strtoupper(trim((string)$raw));
        if ($value === 'D') {
            return NostroEntry::DC_DEBIT;
        }
        if ($value === 'C') {
            return NostroEntry::DC_CREDIT;
        }

        throw new \RuntimeException("Некорректный D/C операции: '{$raw}'");
    }

    /**
     * Обрезает decimal до двух знаков без округления.
     *
     * @param mixed $value Исходное numeric-значение.
     * @return string Значение с двумя знаками после точки.
     */
    private function truncateMoney($value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        $raw = trim((string)$value);
        $negative = false;
        if (strpos($raw, '-') === 0) {
            $negative = true;
            $raw = substr($raw, 1);
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ($negative ? '-' : '') . $integer . '.' . $fraction;
    }

    /**
     * Обрезает строку до нужной длины.
     *
     * @param string|null $value Значение.
     * @param int $limit Максимальная длина.
     * @return string|null Обрезанная строка или null.
     */
    private function truncateString(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr((string)$value, 0, $limit);
    }

    /**
     * Формирует IN-условие с безопасными плейсхолдерами.
     *
     * @param array $values Значения.
     * @param string $prefix Префикс плейсхолдера.
     * @return array `[sql, params]`.
     */
    private function buildInCondition(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $key = ':' . $prefix . $i;
            $placeholders[] = $key;
            $params[$key] = $value;
        }

        return [implode(',', $placeholders), $params];
    }

    /**
     * Печатает предупреждение о пропущенной строке.
     *
     * @param string $message Сообщение.
     * @return void
     */
    private function progress(string $message): void
    {
        $this->stdout("│  {$message}\n", Console::FG_YELLOW);
    }
}
