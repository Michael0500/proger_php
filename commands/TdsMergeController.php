<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Перенос выписок TDS (CAMT053 / MT950 / ED211 / ED743) из таблиц
 * ph_tds_stmt_hdr + ph_tds_stmt_dtl в nostro_balance / nostro_entries.
 *
 * Алгоритм:
 *   1. В tds_status ищем is_merged = false и type ∈ {CAMT053, MT950, ED211, ED743}.
 *   2. Для каждой такой записи открываем транзакцию.
 *   3. По type находим в ph_tds_stmt_hdr все заголовки с format_type = type
 *      (связь с tds_status только по типу — stmt_id из tds_status не используется).
 *   4. Для каждого hdr:
 *      - находим счёт по accounts.name = hdr.account_no (company_id = 1);
 *        если не нашли — пропускаем этот hdr (пакет помечается частично-обработанным
 *        и tds_status НЕ помечается merged, чтобы можно было повторить);
 *      - вставляем одну строку nostro_balance;
 *      - вставляем все строки nostro_entries из ph_tds_stmt_dtl (stmt_id = hdr.stmt_id).
 *   5. Пишем batch-аудит (nostro_entry_audit / nostro_balance_audit).
 *   6. Если пропущенных hdr нет — tds_status.is_merged := true и, при --delete-source,
 *      удаляем обработанные строки из ph_tds_stmt_hdr/dtl.
 *   7. Commit.
 *
 * Использование:
 *   php yii tds-merge/run
 *   php yii tds-merge/run --type=CAMT053
 *   php yii tds-merge/run --delete-source
 */
class TdsMergeController extends Controller
{
    const COMPANY_ID    = 1;
    const SECTION       = 'NRE';
    const LS_STATEMENT  = 'S';

    const SUPPORTED_TYPES = ['CAMT053', 'MT950', 'ED211', 'ED743'];

    /** Сколько строк-источников тянуть за один SELECT. */
    const FETCH_CHUNK  = 5000;
    /** Сколько строк копить перед batchInsert. */
    const INSERT_CHUNK = 1000;

    /** --type=CAMT053|MT950|ED211|ED743: обработать только один тип */
    public ?string $type = null;

    /** --delete-source: удалять обработанные строки ph_tds_stmt_hdr/dtl после успешного merge */
    public bool $deleteSource = false;

    /**
     * Описание опций командной строки.
     *
     * @param string $actionID Идентификатор action.
     * @return array
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['type', 'deleteSource']);
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
     * Запускает перенос всех необработанных TDS-пакетов.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionRun(): int
    {
        $modeFlags = [];
        if ($this->deleteSource) {
            $modeFlags[] = 'delete-source';
        }
        if ($this->type !== null) {
            $modeFlags[] = 'type=' . $this->type;
        }
        $modeStr = $modeFlags ? ' [' . implode(', ', $modeFlags) . ']' : '';

        $this->stdout("=== TDS merge: " . date('Y-m-d H:i:s') . $modeStr . " ===\n", Console::BOLD);

        $types = self::SUPPORTED_TYPES;
        if ($this->type !== null) {
            $t = strtoupper($this->type);
            if (!in_array($t, self::SUPPORTED_TYPES, true)) {
                $this->stderr("Неизвестный тип: '{$this->type}'. Допустимы: " . implode(', ', self::SUPPORTED_TYPES) . "\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $types = [$t];
        }

        $db = Yii::$app->db;

        $placeholders = [];
        $params = [];
        foreach ($types as $i => $t) {
            $key = ':t' . $i;
            $placeholders[] = $key;
            $params[$key] = $t;
        }

        $pending = $db->createCommand(
            "SELECT id, type
               FROM {{%tds_status}}
              WHERE is_merged = FALSE
                AND type IN (" . implode(',', $placeholders) . ")
              ORDER BY id",
            $params
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
            $statusId = (int)$row['id'];
            $type     = $row['type'];

            $this->stdout("\n┌─ tds_status.id={$statusId}, type={$type}\n", Console::FG_CYAN);

            $tx = $db->beginTransaction();
            try {
                [$balances, $entries, $processedStmtIds, $skippedHdrs] = $this->mergeType($type);

                if ($balances === 0 && $entries === 0 && empty($skippedHdrs)) {
                    $this->stdout("│  Нет заголовков типа {$type} в ph_tds_stmt_hdr — нечего переносить.\n", Console::FG_GREY);
                }

                $allOk = empty($skippedHdrs);

                if ($allOk) {
                    if ($this->deleteSource && !empty($processedStmtIds)) {
                        $idsList = implode(',', array_map('strval', $processedStmtIds));
                        $db->createCommand(
                            "DELETE FROM {{%ph_tds_stmt_dtl}} WHERE stmt_id IN ({$idsList})"
                        )->execute();
                        $db->createCommand(
                            "DELETE FROM {{%ph_tds_stmt_hdr}} WHERE stmt_id IN ({$idsList})"
                        )->execute();
                    }

                    $db->createCommand()
                        ->update('{{%tds_status}}', ['is_merged' => true], ['id' => $statusId])
                        ->execute();
                }

                $tx->commit();

                $totalBalances += $balances;
                $totalEntries  += $entries;

                if ($allOk) {
                    $this->stdout("│  Балансов: {$balances}, записей: {$entries}\n", Console::FG_GREEN);
                    $this->stdout("└─ OK\n");
                } else {
                    $this->stdout("│  Балансов: {$balances}, записей: {$entries}, пропущено hdr: " . count($skippedHdrs) . " (счёт не найден)\n", Console::FG_YELLOW);
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
     * Переносит все заголовки и детали одного типа в nostro_balance/nostro_entries.
     *
     * Читает ph_tds_stmt_hdr потоково по stmt_id, по каждому hdr подтягивает
     * соответствующие ph_tds_stmt_dtl и формирует batch для вставки.
     *
     * @param string $type Один из {CAMT053, MT950, ED211, ED743}.
     * @return array `[balancesInserted, entriesInserted, processedStmtIds, skippedStmtIds]`.
     */
    private function mergeType(string $type): array
    {
        $db = Yii::$app->db;

        $entryColumns = [
            'account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'other_id', 'source',
            'match_status', 'line_no', 'branch_code',
            'stmt_id', 'edno', 'eddate', 'edauthor',
            'created_at', 'updated_at',
        ];
        $balanceColumns = [
            'company_id', 'account_id', 'ls_type', 'statement_number', 'currency',
            'value_date', 'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
            'section', 'source', 'status', 'branch_code',
            'stmt_id', 'edno', 'eddate', 'edauthor',
            'created_at', 'updated_at',
        ];

        $accountCache = [];
        $now = date('Y-m-d H:i:s');

        $entryBuf    = [];
        $balanceBuf  = [];

        $totalEntries  = 0;
        $totalBalances = 0;

        $processedStmtIds = [];
        $skippedStmtIds   = [];

        $flushEntries = function () use ($db, $entryColumns, &$entryBuf, &$totalEntries, $type) {
            if (empty($entryBuf)) return;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_entries}}")->queryScalar();

            $db->createCommand()
                ->batchInsert('{{%nostro_entries}}', $entryColumns, $entryBuf)
                ->execute();

            $this->writeEntryAuditAfterFlush($lastId, $entryBuf, $type);
            $totalEntries += count($entryBuf);
            $entryBuf = [];
        };

        $flushBalances = function () use ($db, $balanceColumns, &$balanceBuf, &$totalBalances, $type) {
            if (empty($balanceBuf)) return;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_balance}}")->queryScalar();

            $db->createCommand()
                ->batchInsert('{{%nostro_balance}}', $balanceColumns, $balanceBuf)
                ->execute();

            $this->writeBalanceAuditAfterFlush($lastId, $balanceBuf, $type);
            $totalBalances += count($balanceBuf);
            $balanceBuf = [];
        };

        // Потоковое чтение hdr.
        $lastStmtId = '-1';
        while (true) {
            $hdrRows = $db->createCommand(
                "SELECT * FROM {{%ph_tds_stmt_hdr}}
                  WHERE format_type = :ft
                    AND stmt_id > :sid
                  ORDER BY stmt_id
                  LIMIT :lim",
                [
                    ':ft'  => $type,
                    ':sid' => $lastStmtId,
                    ':lim' => self::FETCH_CHUNK,
                ]
            )->queryAll();

            if (empty($hdrRows)) {
                break;
            }

            foreach ($hdrRows as $hdr) {
                $stmtId = (string)$hdr['stmt_id'];
                $lastStmtId = $stmtId;

                $accountNo = trim((string)($hdr['account_no'] ?? ''));
                if ($accountNo === '') {
                    $this->stdout("│  Пропуск stmt_id={$stmtId}: пустой account_no\n", Console::FG_YELLOW);
                    $skippedStmtIds[] = $stmtId;
                    continue;
                }

                if (!array_key_exists($accountNo, $accountCache)) {
                    $accountCache[$accountNo] = $db->createCommand(
                        "SELECT id FROM {{%accounts}}
                          WHERE company_id = :cid
                            AND name = :name
                          LIMIT 1",
                        [':cid' => self::COMPANY_ID, ':name' => $accountNo]
                    )->queryScalar();
                }

                $accountId = $accountCache[$accountNo];
                if (!$accountId) {
                    $this->stdout("│  Пропуск stmt_id={$stmtId}: счёт не найден для account_no='{$accountNo}'\n", Console::FG_YELLOW);
                    $skippedStmtIds[] = $stmtId;
                    continue;
                }

                $balanceBuf[] = $this->buildBalanceRow($hdr, $type, $accountId, $now);
                if (count($balanceBuf) >= self::INSERT_CHUNK) {
                    $flushBalances();
                }

                // Тянем детали по stmt_id потоково.
                $lastLineNo = -1;
                while (true) {
                    $dtlRows = $db->createCommand(
                        "SELECT * FROM {{%ph_tds_stmt_dtl}}
                          WHERE stmt_id = :sid
                            AND line_no > :ln
                          ORDER BY line_no
                          LIMIT :lim",
                        [
                            ':sid' => $stmtId,
                            ':ln'  => $lastLineNo,
                            ':lim' => self::FETCH_CHUNK,
                        ]
                    )->queryAll();

                    if (empty($dtlRows)) {
                        break;
                    }

                    foreach ($dtlRows as $dtl) {
                        $entryBuf[] = $this->buildEntryRow($hdr, $dtl, $type, $accountId, $now);
                        $lastLineNo = (int)$dtl['line_no'];

                        if (count($entryBuf) >= self::INSERT_CHUNK) {
                            $flushEntries();
                        }
                    }

                    if (count($dtlRows) < self::FETCH_CHUNK) {
                        break;
                    }
                }

                $processedStmtIds[] = $stmtId;
            }

            if (count($hdrRows) < self::FETCH_CHUNK) {
                break;
            }
        }

        $flushEntries();
        $flushBalances();

        return [$totalBalances, $totalEntries, $processedStmtIds, $skippedStmtIds];
    }

    /**
     * Строит строку для batchInsert в nostro_balance по hdr и типу.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @param string $type Тип источника.
     * @param int $accountId Найденный account_id.
     * @param string $now Метка времени created_at/updated_at.
     * @return array Строка для batchInsert в порядке `$balanceColumns`.
     */
    private function buildBalanceRow(array $hdr, string $type, int $accountId, string $now): array
    {
        $statementNumber = ($type === 'MT950') ? ($hdr['stmt_ref'] ?? null) : null;

        [$edno, $eddate, $edauthor] = $this->edFieldsForBalance($hdr, $type);

        return [
            self::COMPANY_ID,
            $accountId,
            self::LS_STATEMENT,
            $statementNumber,
            $hdr['opening_currency'] ?? null,
            $hdr['opening_value_dt'] ?? null,
            $hdr['opening_amount'] ?? 0,
            $this->mapDcShort($hdr['opening_dc'] ?? null, $type),
            $hdr['closing_amount'] ?? 0,
            $this->mapDcShort($hdr['closing_dc'] ?? null, $type),
            self::SECTION,
            $hdr['format_type'],
            'normal',
            $hdr['edbranch'] ?? null,
            $hdr['stmt_id'],
            $edno,
            $eddate,
            $edauthor,
            $now,
            $now,
        ];
    }

    /**
     * Строит строку для batchInsert в nostro_entries по hdr+dtl и типу.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @param array $dtl Строка ph_tds_stmt_dtl.
     * @param string $type Тип источника.
     * @param int $accountId Найденный account_id.
     * @param string $now Метка времени created_at/updated_at.
     * @return array Строка для batchInsert в порядке `$entryColumns`.
     */
    private function buildEntryRow(array $hdr, array $dtl, string $type, int $accountId, string $now): array
    {
        // Маппинг общих полей.
        $amount      = $dtl['amount'] ?? null;
        $currency    = $dtl['currency'] ?? null;
        $endToEndId  = $dtl['end_to_end_id'] ?? null;
        $branchCode  = $hdr['edbranch'] ?? null;
        $lineNo      = $dtl['line_no'] ?? null;
        $stmtId      = $dtl['stmt_id'];

        // Поля, специфичные для типа.
        $valueDate     = null;
        $postDate      = null;
        $instructionId = null;
        $transactionId = null;
        $messageId     = null;
        $otherId       = null;
        $edno          = null;
        $eddate        = null;
        $edauthor      = null;

        switch ($type) {
            case 'CAMT053':
                $valueDate     = $hdr['opening_value_dt'] ?? null;
                $postDate      = $hdr['opening_value_dt'] ?? null;
                $instructionId = $dtl['instr_id'] ?? null;
                $transactionId = $dtl['tx_id'] ?? null;
                $messageId     = $dtl['msg_id'] ?? null;
                break;

            case 'MT950':
                $valueDate     = $dtl['value_dt'] ?? null;
                $postDate      = $dtl['entry_dt'] ?? null;
                $instructionId = $dtl['instr_id'] ?? null;
                $transactionId = $dtl['tx_id'] ?? null;
                $otherId       = $dtl['op_type'] ?? null;
                break;

            case 'ED211':
            case 'ED743':
                $valueDate     = $hdr['opening_value_dt'] ?? null;
                $postDate      = $hdr['opening_value_dt'] ?? null;
                $instructionId = $dtl['entry_ref'] ?? null;
                $transactionId = $dtl['ed_bank_name'] ?? null;
                $edno          = $dtl['ref_edno'] ?? null;
                $eddate        = $dtl['ref_eddate'] ?? null;
                $edauthor      = $dtl['ref_edauthor'] ?? null;
                break;
        }

        return [
            $accountId,
            self::COMPANY_ID,
            self::LS_STATEMENT,
            $this->mapDc($dtl['dc_mark'] ?? null, $type),
            $amount,
            $currency,
            $valueDate,
            $postDate,
            $instructionId,
            $endToEndId,
            $transactionId,
            $messageId,
            $otherId,
            $hdr['format_type'],
            'U',
            $lineNo,
            $branchCode,
            $stmtId,
            $edno,
            $eddate,
            $edauthor,
            $now,
            $now,
        ];
    }

    /**
     * Возвращает [edno, eddate, edauthor] для строки баланса.
     *
     * Для ED211/ED743 берём edno/eddate из hdr, а edauthor — из первой строки
     * ph_tds_stmt_dtl (ref_edauthor), т.к. в ph_tds_stmt_hdr такого поля нет.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @param string $type Тип источника.
     * @return array [edno, eddate, edauthor]
     */
    private function edFieldsForBalance(array $hdr, string $type): array
    {
        if ($type !== 'ED211' && $type !== 'ED743') {
            return [null, null, null];
        }

        $edauthor = Yii::$app->db->createCommand(
            "SELECT ref_edauthor FROM {{%ph_tds_stmt_dtl}}
              WHERE stmt_id = :sid
                AND ref_edauthor IS NOT NULL
              ORDER BY line_no
              LIMIT 1",
            [':sid' => $hdr['stmt_id']]
        )->queryScalar();

        return [
            $hdr['edno'] ?? null,
            $hdr['eddate'] ?? null,
            $edauthor ?: null,
        ];
    }

    /**
     * Преобразует D/C-признак в значение `Debit` или `Credit`.
     *
     * CAMT053: DBIT/CRDT. MT950, ED211, ED743: D/C.
     * Пустое значение пропускается как null.
     *
     * @param string|null $raw Сырое значение из источника.
     * @param string $type Тип источника.
     * @return string|null Нормализованное значение.
     * @throws \RuntimeException Если признак не распознан.
     */
    private function mapDc(?string $raw, string $type): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $v = strtoupper(trim((string)$raw));

        if ($type === 'CAMT053') {
            if ($v === 'DBIT' || $v === 'D') return 'Debit';
            if ($v === 'CRDT' || $v === 'C') return 'Credit';
        } else {
            if ($v === 'D' || $v === 'DBIT') return 'Debit';
            if ($v === 'C' || $v === 'CRDT') return 'Credit';
        }

        throw new \RuntimeException("Некорректный D/C для {$type}: '{$raw}'");
    }

    /**
     * Преобразует D/C-признак в односимвольный код `D`/`C` для nostro_balance.
     *
     * Колонки nostro_balance.opening_dc/closing_dc — char(1), поэтому полная
     * форма `Debit`/`Credit` из mapDc() в них не помещается. Метод нормализует
     * результат к односимвольному виду, сохраняя строгую проверку источника.
     *
     * @param string|null $raw Сырое значение из источника.
     * @param string $type Тип источника.
     * @return string|null `D`, `C` или `null` для пустого значения.
     * @throws \RuntimeException Если признак не распознан.
     */
    private function mapDcShort(?string $raw, string $type): ?string
    {
        $full = $this->mapDc($raw, $type);
        if ($full === null) {
            return null;
        }
        return $full === 'Debit' ? 'D' : 'C';
    }

    /**
     * Пишет аудит создания TDS-записей после batchInsert.
     *
     * @param int $lastId Максимальный ID до вставки batch.
     * @param array $insertedRows Буфер строк, переданный в batchInsert.
     * @param string $type Тип источника (для reason).
     * @return void
     */
    private function writeEntryAuditAfterFlush(int $lastId, array $insertedRows, string $type): void
    {
        // stmt_id и line_no в порядке колонок entryColumns:
        // 0:account_id ... 15:line_no, 16:branch_code, 17:stmt_id
        $stmtIds = [];
        foreach ($insertedRows as $row) {
            if (isset($row[17])) {
                $stmtIds[(string)$row[17]] = true;
            }
        }
        if (empty($stmtIds)) {
            return;
        }
        $stmtIdsList = implode(',', array_map('strval', array_keys($stmtIds)));

        $rows = Yii::$app->db->createCommand(
            "SELECT id, account_id, company_id, ls, dc, amount, currency,
                    value_date, post_date, instruction_id, end_to_end_id,
                    transaction_id, message_id, other_id, comment, source,
                    match_status, match_id, extract_no, line_no, branch_code,
                    stmt_id, edno, eddate, edauthor, created_at, updated_at
               FROM {{%nostro_entries}}
              WHERE id > :last_id
                AND source = :source
                AND stmt_id IN ({$stmtIdsList})
              ORDER BY id",
            [
                ':last_id' => $lastId,
                ':source'  => $type,
            ]
        )->queryAll();

        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        $now = date('Y-m-d H:i:s');
        $reason = "Импорт {$type}";
        foreach ($rows as $row) {
            $auditRows[] = [
                (int)$row['id'],
                0,
                'create',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                null,
                null,
                $reason,
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
     * Пишет аудит импорта TDS-балансов после batchInsert.
     *
     * @param int $lastId Максимальный ID до вставки batch.
     * @param array $insertedRows Буфер строк, переданный в batchInsert.
     * @param string $type Тип источника (для reason).
     * @return void
     */
    private function writeBalanceAuditAfterFlush(int $lastId, array $insertedRows, string $type): void
    {
        // stmt_id в порядке колонок balanceColumns:
        // 0:company_id ... 13:branch_code, 14:stmt_id
        $stmtIds = [];
        foreach ($insertedRows as $row) {
            if (isset($row[14])) {
                $stmtIds[(string)$row[14]] = true;
            }
        }
        if (empty($stmtIds)) {
            return;
        }
        $stmtIdsList = implode(',', array_map('strval', array_keys($stmtIds)));

        $rows = Yii::$app->db->createCommand(
            "SELECT id, company_id, account_id, ls_type, statement_number, currency,
                    value_date, opening_balance, opening_dc, closing_balance, closing_dc,
                    section, source, status, comment, extract_no, line_no, branch_code,
                    stmt_id, edno, eddate, edauthor, created_at, updated_at
               FROM {{%nostro_balance}}
              WHERE id > :last_id
                AND source = :source
                AND stmt_id IN ({$stmtIdsList})
              ORDER BY id",
            [
                ':last_id' => $lastId,
                ':source'  => $type,
            ]
        )->queryAll();

        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        $now = date('Y-m-d H:i:s');
        $reason = "Импорт {$type}";
        foreach ($rows as $row) {
            $auditRows[] = [
                (int)$row['id'],
                0,
                'import',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                $reason,
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
}
