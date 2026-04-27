<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Перенос выписок FCC12 из git_no_stro_extract_custom
 * в nostro_balance и nostro_entries.
 *
 * Алгоритм:
 *   1. В tds_status ищем type = 'FCC12' И is_merged = false.
 *   2. Для каждой такой записи открываем транзакцию.
 *   3. Из git_no_stro_extract_custom выбираем строки с extract_no = tds_status.fcc_extract_no.
 *      - строка-баланс (opening_bal/closing_bal заданы, amount пустая) → nostro_balance
 *      - строка-транзакция (amount задана) → nostro_entries
 *      При этом в обе таблицы пишем extract_no и line_no.
 *   4. tds_status.is_merged := true.
 *   5. Удаляем строки из git_no_stro_extract_custom с этим extract_no.
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
    /** Сколько строк копить перед batchInsert. 17 колонок × 1000 = 17000 параметров (< 65535). */
    const INSERT_CHUNK = 1000;

    public function actionRun(): int
    {
        $this->stdout("=== FCC12 merge: " . date('Y-m-d H:i:s') . " ===\n", Console::BOLD);

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
                [$balances, $entries] = $this->mergeExtract($extractNo);

                $db->createCommand()
                    ->update('{{%tds_status}}', ['is_merged' => true], ['id' => $statusId])
                    ->execute();

                $db->createCommand()
                    ->delete('{{%git_no_stro_extract_custom}}', ['extract_no' => $extractNo])
                    ->execute();

                $tx->commit();

                $totalBalances += $balances;
                $totalEntries  += $entries;

                $this->stdout("│  Балансов: {$balances}, записей: {$entries}\n", Console::FG_GREEN);
                $this->stdout("└─ OK\n");
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
     * Перенести строки одного extract_no в nostro_balance/nostro_entries.
     *
     * @return array [$balancesInserted, $entriesInserted]
     */
    private function mergeExtract(int $extractNo): array
    {
        $db = Yii::$app->db;

        $entryColumns = [
            'account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'source', 'match_status', 'extract_no', 'line_no',
            'created_at', 'updated_at',
        ];
        $balanceColumns = [
            'company_id', 'account_id', 'ls_type', 'currency', 'value_date',
            'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
            'section', 'source', 'status', 'extract_no', 'line_no',
            'created_at', 'updated_at',
        ];

        // Кэш account_id по cbr_cc_no (accounts.name) — чтобы не искать в цикле.
        $accountCache = [];
        $now = date('Y-m-d H:i:s');

        $entryBuf   = [];
        $balanceBuf = [];
        $totalEntries  = 0;
        $totalBalances = 0;

        $flushEntries = function () use ($db, $entryColumns, &$entryBuf, &$totalEntries) {
            if (empty($entryBuf)) return;
            $db->createCommand()
                ->batchInsert('{{%nostro_entries}}', $entryColumns, $entryBuf)
                ->execute();
            $totalEntries += count($entryBuf);
            $entryBuf = [];
        };

        $flushBalances = function () use ($db, $balanceColumns, &$balanceBuf, &$totalBalances) {
            if (empty($balanceBuf)) return;
            $db->createCommand()
                ->batchInsert('{{%nostro_balance}}', $balanceColumns, $balanceBuf)
                ->execute();
            $totalBalances += count($balanceBuf);
            $balanceBuf = [];
        };

        // Потоковое чтение источника по (extract_no, line_no) — без OFFSET и без полного queryAll.
        $lastLineNo = -1;
        while (true) {
            $rows = $db->createCommand(
                "SELECT * FROM {{%git_no_stro_extract_custom}}
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
                    throw new \RuntimeException(
                        "Строка line_no={$r['line_no']}: не найден счёт в accounts по cbr_cc_no='{$cbrCcNo}'"
                    );
                }

                $isEntry = $r['amount'] !== null && $r['amount'] !== '';

                if ($isEntry) {
                    $entryBuf[] = [
                        $accountId,
                        self::COMPANY_ID,
                        self::LS_LEDGER,
                        $this->mapDc($r['drcr_ind']),
                        $r['amount'],
                        $r['ccv'],
                        $r['trn_dt'],
                        $r['value_dt'],
                        $r['ed_no'],
                        $r['trn_ref_sr_no'],
                        $r['obj_ref'],
                        self::SOURCE,
                        'U',
                        $r['extract_no'],
                        $r['line_no'],
                        $now,
                        $now,
                    ];
                    if (count($entryBuf) >= self::INSERT_CHUNK) {
                        $flushEntries();
                    }
                } elseif ($r['opening_bal'] !== null || $r['closing_bal'] !== null) {
                    $balanceBuf[] = [
                        self::COMPANY_ID,
                        $accountId,
                        self::LS_LEDGER,
                        $r['ccv'],
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
                        $now,
                        $now,
                    ];
                    if (count($balanceBuf) >= self::INSERT_CHUNK) {
                        $flushBalances();
                    }
                }
                // строка без полезной нагрузки — пропускаем

                $lastLineNo = (int)$r['line_no'];
            }

            if (count($rows) < self::FETCH_CHUNK) {
                break;
            }
        }

        $flushEntries();
        $flushBalances();

        return [$totalBalances, $totalEntries];
    }

    private function mapDc(?string $drcrInd): string
    {
        $v = strtoupper((string)$drcrInd);
        if ($v === 'D') return 'Debit';
        if ($v === 'C') return 'Credit';
        throw new \RuntimeException("Некорректный drcr_ind: '{$drcrInd}'");
    }
}
