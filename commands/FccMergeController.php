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

        $rows = $db->createCommand(
            "SELECT * FROM {{%git_no_stro_extract_custom}}
              WHERE extract_no = :ext
              ORDER BY line_no",
            [':ext' => $extractNo]
        )->queryAll();

        if (empty($rows)) {
            return [0, 0];
        }

        // Кэш account_id по cbr_cc_no (accounts.name) — чтобы не искать в цикле.
        $accountCache = [];
        $now = date('Y-m-d H:i:s');

        $balances = 0;
        $entries  = 0;

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
                $db->createCommand()->insert('{{%nostro_entries}}', [
                    'account_id'     => $accountId,
                    'company_id'     => self::COMPANY_ID,
                    'ls'             => self::LS_LEDGER,
                    'dc'             => $this->mapDc($r['drcr_ind']),
                    'amount'         => $r['amount'],
                    'currency'       => $r['ccv'],
                    'value_date'     => $r['trn_dt'],
                    'post_date'      => $r['value_dt'],
                    'instruction_id' => $r['ed_no'],
                    'end_to_end_id'  => $r['trn_ref_sr_no'],
                    'transaction_id' => $r['obj_ref'],
                    'source'         => self::SOURCE,
                    'match_status'   => 'U',
                    'extract_no'     => $r['extract_no'],
                    'line_no'        => $r['line_no'],
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ])->execute();

                $entries++;
                continue;
            }

            // Иначе считаем строкой баланса
            $hasBalance = $r['opening_bal'] !== null || $r['closing_bal'] !== null;
            if (!$hasBalance) {
                // Строка без полезной нагрузки — пропускаем.
                continue;
            }

            $db->createCommand()->insert('{{%nostro_balance}}', [
                'company_id'      => self::COMPANY_ID,
                'account_id'      => $accountId,
                'ls_type'         => self::LS_LEDGER,
                'currency'        => $r['ccv'],
                'value_date'      => $r['dt'],
                'opening_balance' => $r['opening_bal'] ?? 0,
                'opening_dc'      => $r['opening_bal_dc'] ?: 'C',
                'closing_balance' => $r['closing_bal'] ?? 0,
                'closing_dc'      => $r['closing_bal_dc'] ?: 'C',
                'section'         => self::SECTION,
                'source'          => self::SOURCE,
                'status'          => 'normal',
                'extract_no'      => $r['extract_no'],
                'line_no'         => $r['line_no'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->execute();

            $balances++;
        }

        return [$balances, $entries];
    }

    private function mapDc(?string $drcrInd): string
    {
        $v = strtoupper((string)$drcrInd);
        if ($v === 'D') return 'Debit';
        if ($v === 'C') return 'Credit';
        throw new \RuntimeException("Некорректный drcr_ind: '{$drcrInd}'");
    }
}
