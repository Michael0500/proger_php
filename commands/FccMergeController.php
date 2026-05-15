<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Перенос выписок FCC12 из gitb_nostro_extract_custom
 * в nostro_balance и nostro_entries.
 *
 * Алгоритм:
 *   1. В tds_status ищем type = 'FCC12' И is_merged = false.
 *   2. Для каждой такой записи открываем транзакцию.
 *   3. Из gitb_nostro_extract_custom выбираем строки с extract_no = tds_status.fcc_extract_no.
 *      - строка-баланс (opening_bal/closing_bal заданы, amount пустая) → nostro_balance
 *      - строка-транзакция (amount задана) → nostro_entries
 *      При этом в обе таблицы пишем extract_no, line_no и branch_code.
 *   4. tds_status.is_merged := true.
 *   5. Удаляем строки из gitb_nostro_extract_custom с этим extract_no.
 *   6. Commit.
 *
 * Использование:
 *   php yii fcc-merge/run
 */
class FccMergeController extends Controller
{
    const COMPANY_ID = 1;
    const SOURCE     = 'FCC12';
    const SECTION    = 'NRE';
    const LS_LEDGER  = 'L';

    /** Сколько строк-источников тянуть за один SELECT. */
    const FETCH_CHUNK  = 5000;
    /** Сколько строк копить перед batchInsert. 18 колонок × 1000 = 18000 параметров (< 65535). */
    const INSERT_CHUNK = 1000;

    /** --keep-source: не удалять строки из gitb_nostro_extract_custom после обработки */
    public bool $keepSource = false;

    /**
     * Запускает перенос всех необработанных пакетов FCC12.
     *
     * Для каждой строки `tds_status` с `type='FCC12'` и `is_merged=false`
     * открывает транзакцию, переносит строки источника, пишет аудит, удаляет
     * успешно обработанный источник и помечает пакет как merged.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionRun(): int
    {
        $this->stdout("=== FCC12 merge: " . date('Y-m-d H:i:s') . ($this->keepSource ? " [keep-source]" : "") . " ===\n", Console::BOLD);

        $db = Yii::$app->db;

        $pending = $db->createCommand(
            "SELECT id, fcc_extract_no
               FROM {{%tds_status}}
              WHERE type = :type
                AND is_merged = FALSE
                AND fcc_extract_no IS NOT NULL
              ORDER BY id",
            [':type' => self::SOURCE]
        )->queryAll();

        if (empty($pending)) {
            $this->stdout("Нет записей для обработки.\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("К обработке: " . count($pending) . "\n");

        $totalBalances = 0;
        $totalEntries  = 0;
        $errors        = 0;

        foreach ($pending as $row) {
            $statusId  = (int)$row['id'];
            $extractNo = (int)$row['fcc_extract_no'];

            $this->stdout("\n┌─ tds_status.id={$statusId}, extract_no={$extractNo}\n", Console::FG_CYAN);

            $tx = $db->beginTransaction();
            try {
                [$balances, $entries, $skipped] = $this->mergeExtract($extractNo);

                if (!$this->keepSource) {
                    if (empty($skipped)) {
                        $db->createCommand()
                            ->delete('{{%gitb_nostro_extract_custom}}', ['extract_no' => $extractNo])
                            ->execute();
                    } else {
                        // Удаляем только успешно обработанные строки; пропущенные остаются для повторной попытки
                        $skippedStr = implode(',', array_map('intval', $skipped));
                        $db->createCommand(
                            "DELETE FROM {{%gitb_nostro_extract_custom}}
                              WHERE extract_no = :ext AND line_no NOT IN ({$skippedStr})",
                            [':ext' => $extractNo]
                        )->execute();
                    }
                }

                if (empty($skipped) && !$this->keepSource) {
                    $db->createCommand()
                        ->update('{{%tds_status}}', ['is_merged' => true], ['id' => $statusId])
                        ->execute();
                }

                $tx->commit();

                $totalBalances += $balances;
                $totalEntries  += $entries;

                if (empty($skipped)) {
                    $this->stdout("│  Балансов: {$balances}, записей: {$entries}\n", Console::FG_GREEN);
                    $this->stdout("└─ OK\n");
                } else {
                    $this->stdout("│  Балансов: {$balances}, записей: {$entries}, пропущено строк: " . count($skipped) . " (счёт не найден)\n", Console::FG_YELLOW);
                    $this->stdout("└─ PARTIAL (не помечен merged, будет повторён)\n", Console::FG_YELLOW);
                }
            } catch (\Throwable $e) {
                $tx->rollBack();
                $errors++;
                $this->stderr("│  Ошибка: " . $e->getMessage() . "\n", Console::FG_RED);
                $this->stdout("└─ ROLLBACK\n", Console::FG_RED);
            }
        }

        $this->stdout("\n=== Итого: балансов {$totalBalances}, записей {$totalEntries}", Console::BOLD);
        if ($errors > 0) {
            $this->stdout(", ошибок {$errors}", Console::FG_RED);
        }
        $this->stdout(" ===\n");

        return $errors > 0 ? ExitCode::SOFTWARE : ExitCode::OK;
    }

    /**
     * Переносит строки одного `extract_no` в балансы и операции.
     *
     * Читает источник потоково по `line_no`, использует batchInsert для
     * `nostro_entries` и `nostro_balance`, а строки с ненайденным счётом
     * возвращает как skipped, чтобы пакет можно было повторить.
     *
     * @param int $extractNo Номер FCC12-выгрузки.
     * @return array `[balancesInserted, entriesInserted, skippedLineNos]`.
     */
    private function mergeExtract(int $extractNo): array
    {
        $db = Yii::$app->db;

        $entryColumns = [
            'account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'source', 'match_status', 'extract_no', 'line_no',
            'branch_code', 'created_at', 'updated_at',
        ];
        $balanceColumns = [
            'company_id', 'account_id', 'ls_type', 'currency', 'value_date',
            'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
            'section', 'source', 'status', 'extract_no', 'line_no',
            'branch_code', 'created_at', 'updated_at',
        ];

        // Кэш account_id по cbr_cc_no (accounts.name) — чтобы не искать в цикле.
        $accountCache = [];
        $now = date('Y-m-d H:i:s');

        $entryBuf    = [];
        $balanceBuf  = [];
        $skippedLineNos = [];
        $totalEntries  = 0;
        $totalBalances = 0;

        $flushEntries = function () use ($db, $entryColumns, &$entryBuf, &$totalEntries) {
            if (empty($entryBuf)) return;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_entries}}")->queryScalar();

            $db->createCommand()
                ->batchInsert('{{%nostro_entries}}', $entryColumns, $entryBuf)
                ->execute();

            $this->writeEntryAuditAfterFlush($lastId, $entryBuf);
            $totalEntries += count($entryBuf);
            $entryBuf = [];
        };

        $flushBalances = function () use ($db, $balanceColumns, &$balanceBuf, &$totalBalances) {
            if (empty($balanceBuf)) return;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_balance}}")->queryScalar();

            $db->createCommand()
                ->batchInsert('{{%nostro_balance}}', $balanceColumns, $balanceBuf)
                ->execute();

            $this->writeBalanceAuditAfterFlush($lastId, $balanceBuf);
            $totalBalances += count($balanceBuf);
            $balanceBuf = [];
        };

        // Потоковое чтение источника по (extract_no, line_no) — без OFFSET и без полного queryAll.
        $lastLineNo = -1;
        while (true) {
            $rows = $db->createCommand(
                "SELECT * FROM {{%gitb_nostro_extract_custom}}
                  WHERE extract_no = :ext
                    AND line_no > :ln
                  ORDER BY line_no
                  LIMIT :lim",
                [
                    ':ext' => $extractNo,
                    ':ln'  => $lastLineNo,
                    ':lim' => self::FETCH_CHUNK,
                ]
            )->queryAll();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $r) {
                $isEntry   = $r['amount'] !== null && $r['amount'] !== '';
                $isBalance = $r['opening_bal'] !== null || $r['closing_bal'] !== null;

                // строки-заголовки / хвостовики — без финансовых данных, пропускаем
                if (!$isEntry && !$isBalance) {
                    $lastLineNo = (int)$r['line_no'];
                    continue;
                }

                $cbrCcNo = trim((string)$r['cbr_cc_no']);
                if ($cbrCcNo === '') {
                    throw new \RuntimeException("Строка line_no={$r['line_no']}: пустой cbr_cc_no");
                }

                if (!array_key_exists($cbrCcNo, $accountCache)) {
                    $accountCache[$cbrCcNo] = $db->createCommand(
                        "SELECT id FROM {{%accounts}}
                          WHERE company_id = :cid
                            AND name = :name
                          LIMIT 1",
                        [':cid' => self::COMPANY_ID, ':name' => $cbrCcNo]
                    )->queryScalar();
                }

                $accountId = $accountCache[$cbrCcNo];
                if (!$accountId) {
                    $this->stdout("│  Пропуск line_no={$r['line_no']}: счёт не найден для cbr_cc_no='{$cbrCcNo}'\n", Console::FG_YELLOW);
                    $skippedLineNos[] = (int)$r['line_no'];
                    $lastLineNo = (int)$r['line_no'];
                    continue;
                }

                if ($isEntry) {
                    $entryBuf[] = [
                        $accountId,
                        self::COMPANY_ID,
                        self::LS_LEDGER,
                        $this->mapDc($r['drcr_ind']),
                        $r['amount'],
                        $r['ccy'],
                        $r['value_dt'],
                        $r['trn_dt'],
                        $r['ed_no'],
                        $r['trn_ref_sr_no'],
                        $r['obj_ref'],
                        self::SOURCE,
                        'U',
                        $r['extract_no'],
                        $r['line_no'],
                        $r['branch_code'] ?? null,
                        $now,
                        $now,
                    ];
                    if (count($entryBuf) >= self::INSERT_CHUNK) {
                        $flushEntries();
                    }
                } elseif ($isBalance) {
                    $balanceBuf[] = [
                        self::COMPANY_ID,
                        $accountId,
                        self::LS_LEDGER,
                        $r['ccy'],
                        $r['dt'],
                        $r['opening_bal'] ?? 0,
                        $r['opening_bal_dc'] ?: 'C',
                        $r['closing_bal'] ?? 0,
                        $r['closing_bal_dc'] ?: 'C',
                        self::SECTION,
                        self::SOURCE,
                        'normal',
                        $r['extract_no'],
                        $r['line_no'],
                        $r['branch_code'] ?? null,
                        $now,
                        $now,
                    ];
                    if (count($balanceBuf) >= self::INSERT_CHUNK) {
                        $flushBalances();
                    }
                }

                $lastLineNo = (int)$r['line_no'];
            }

            if (count($rows) < self::FETCH_CHUNK) {
                break;
            }
        }

        $flushEntries();
        $flushBalances();

        return [$totalBalances, $totalEntries, $skippedLineNos];
    }

    /**
     * Пишет аудит создания FCC12-записей после batchInsert.
     *
     * @param int $lastId Максимальный ID до вставки batch.
     * @param array $insertedRows Буфер строк, переданный в batchInsert.
     * @return void
     */
    private function writeEntryAuditAfterFlush(int $lastId, array $insertedRows): void
    {
        $lineNos = $this->extractLineNos($insertedRows, 14);
        if (empty($lineNos)) {
            return;
        }

        $rows = Yii::$app->db->createCommand(
            "SELECT id, account_id, company_id, ls, dc, amount, currency,
                    value_date, post_date, instruction_id, end_to_end_id,
                    transaction_id, message_id, other_id, comment, source,
                    match_status, match_id, extract_no, line_no, branch_code, created_at, updated_at
               FROM {{%nostro_entries}}
              WHERE id > :last_id
                AND source = :source
                AND extract_no = :extract_no
                AND line_no = ANY(:line_nos)
              ORDER BY id",
            [
                ':last_id'    => $lastId,
                ':source'     => self::SOURCE,
                ':extract_no' => (int)$insertedRows[0][13],
                ':line_nos'   => '{' . implode(',', $lineNos) . '}',
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
                'create',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                null,
                null,
                'Импорт FCC12',
                $now,
            ];
        }

        Yii::$app->db->createCommand()
            ->batchInsert('{{%nostro_entry_audit}}', [
                'entry_id', 'user_id', 'action', 'old_values', 'new_values',
                'changed_field', 'archived_id', 'reason', 'created_at',
            ], $auditRows)
            ->execute();
    }

    /**
     * Пишет аудит импорта FCC12-балансов после batchInsert.
     *
     * @param int $lastId Максимальный ID до вставки batch.
     * @param array $insertedRows Буфер строк, переданный в batchInsert.
     * @return void
     */
    private function writeBalanceAuditAfterFlush(int $lastId, array $insertedRows): void
    {
        $lineNos = $this->extractLineNos($insertedRows, 14);
        if (empty($lineNos)) {
            return;
        }

        $rows = Yii::$app->db->createCommand(
            "SELECT id, company_id, account_id, ls_type, statement_number, currency,
                    value_date, opening_balance, opening_dc, closing_balance, closing_dc,
                    section, source, status, comment, extract_no, line_no, branch_code, created_at, updated_at
               FROM {{%nostro_balance}}
              WHERE id > :last_id
                AND source = :source
                AND extract_no = :extract_no
                AND line_no = ANY(:line_nos)
              ORDER BY id",
            [
                ':last_id'    => $lastId,
                ':source'     => self::SOURCE,
                ':extract_no' => (int)$insertedRows[0][13],
                ':line_nos'   => '{' . implode(',', $lineNos) . '}',
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
                'Импорт FCC12',
                $now,
            ];
        }

        Yii::$app->db->createCommand()
            ->batchInsert('{{%nostro_balance_audit}}', [
                'balance_id', 'user_id', 'action', 'old_values',
                'new_values', 'reason', 'created_at',
            ], $auditRows)
            ->execute();
    }

    /**
     * Извлекает уникальные `line_no` из буфера batchInsert.
     *
     * @param array $rows Строки batchInsert.
     * @param int $lineNoIndex Индекс колонки `line_no` в строке batch.
     * @return int[] Уникальные номера строк источника.
     */
    private function extractLineNos(array $rows, int $lineNoIndex): array
    {
        $lineNos = [];
        foreach ($rows as $row) {
            if (isset($row[$lineNoIndex])) {
                $lineNos[] = (int)$row[$lineNoIndex];
            }
        }

        return array_values(array_unique($lineNos));
    }

    /**
     * Преобразует FCC12 D/C-признак в значение `NostroEntry::dc`.
     *
     * @param string|null $drcrInd Значение `D` или `C` из источника.
     * @return string `Debit` или `Credit`.
     * @throws \RuntimeException Если признак не распознан.
     */
    private function mapDc(?string $drcrInd): string
    {
        $v = strtoupper((string)$drcrInd);
        if ($v === 'D') return 'Debit';
        if ($v === 'C') return 'Credit';
        throw new \RuntimeException("Некорректный drcr_ind: '{$drcrInd}'");
    }
}
