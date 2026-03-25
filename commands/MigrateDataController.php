<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Миграция данных из старой БД в новую.
 *
 * 1. Настроить подключение 'oldDb' в config/console.php (или config/web.php):
 *    'components' => [
 *        'oldDb' => [
 *            'class' => 'yii\db\Connection',
 *            'dsn' => 'pgsql:host=...;port=5432;dbname=OLD_DB_NAME',
 *            'username' => 'postgres',
 *            'password' => '',
 *            'charset' => 'utf8',
 *        ],
 *    ]
 *
 * 2. Запуск:
 *    php yii migrate-data/run              — мигрировать всё: пулы, счета, записи
 *    php yii migrate-data/pools            — только account_pools
 *    php yii migrate-data/accounts         — только accounts
 *    php yii migrate-data/entries          — только nostro_entries (из таблицы item)
 */
class MigrateDataController extends Controller
{
    /** Маппинг company_id: старая → новая */
    private $companyMap = [
        5 => 1, // NRE
        8 => 2, // INV
    ];

    /** Маппинг old pool_id → new pool_id (заполняется при миграции пулов) */
    private $poolMap = [];

    /** Маппинг old account_id → new account_id */
    private $accountMap = [];

    /** Маппинг old account_id → new company_id */
    private $accountCompanyMap = [];

    /** Маппинг DCIP числовых кодов → текстовые значения */
    private $dcMap = [
        4352 => 'Debit',
        4608 => 'Credit',
    ];

    /** Размер пакета для batchInsert */
    private $batchSize = 1000;

    /**
     * Мигрировать всё: сначала пулы, потом счета
     */
    public function actionRun()
    {
        $this->actionPools();
        $this->stdout("\n");
        $this->actionAccounts();
        $this->stdout("\n");
        $this->actionEntries();

        return ExitCode::OK;
    }

    /**
     * Мигрировать account_pool → account_pools
     */
    public function actionPools()
    {
        $oldDb = $this->getOldDb();
        $newDb = Yii::$app->db;
        $now = date('Y-m-d H:i:s');

        $companyIds = array_keys($this->companyMap);
        $placeholders = implode(',', $companyIds);

        $oldPools = $oldDb->createCommand(
            "SELECT id, company_id, name, notes FROM account_pool WHERE company_id IN ({$placeholders}) ORDER BY id"
        )->queryAll();

        $this->stdout("=== Миграция account_pools ===\n");
        $this->stdout("Найдено в старой БД: " . count($oldPools) . "\n");

        $inserted = 0;
        $skipped = 0;

        foreach ($oldPools as $old) {
            $newCompanyId = $this->companyMap[$old['company_id']];

            // Проверяем, нет ли уже такого пула по имени
            $exists = $newDb->createCommand(
                "SELECT id FROM account_pools WHERE company_id = :cid AND name = :name",
                [':cid' => $newCompanyId, ':name' => $old['name']]
            )->queryScalar();

            if ($exists) {
                $this->poolMap[$old['id']] = $exists;
                $this->stdout("  [skip] pool #{$old['id']} \"{$old['name']}\" — уже существует (new id={$exists})\n");
                $skipped++;
                continue;
            }

            $newDb->createCommand()->insert('account_pools', [
                'company_id' => $newCompanyId,
                'name' => $old['name'],
                'description' => $old['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            $newId = $newDb->getLastInsertID('account_pools_id_seq');
            $this->poolMap[$old['id']] = $newId;

            $this->stdout("  [ok] pool #{$old['id']} \"{$old['name']}\" → new id={$newId} (company {$old['company_id']}→{$newCompanyId})\n");
            $inserted++;
        }

        $this->stdout("Итого: вставлено={$inserted}, пропущено={$skipped}\n");
    }

    /**
     * Мигрировать account → accounts
     */
    public function actionAccounts()
    {
        $oldDb = $this->getOldDb();
        $newDb = Yii::$app->db;
        $now = date('Y-m-d H:i:s');

        // Если poolMap пустой — подгружаем маппинг из старой и новой БД
        if (empty($this->poolMap)) {
            $this->buildPoolMap($oldDb, $newDb);
        }

        $companyIds = array_keys($this->companyMap);
        $placeholders = implode(',', $companyIds);

        $oldAccounts = $oldDb->createCommand(
            "SELECT id, company_id, pool_id, number_x, account_type, initial_balance_date, flags
             FROM account
             WHERE company_id IN ({$placeholders})
             ORDER BY id"
        )->queryAll();

        $this->stdout("=== Миграция accounts ===\n");
        $this->stdout("Найдено в старой БД: " . count($oldAccounts) . "\n");

        $inserted = 0;
        $skipped = 0;

        foreach ($oldAccounts as $old) {
            $newCompanyId = $this->companyMap[$old['company_id']];
            $name = $old['number_x'];

            if (empty($name)) {
                $this->stdout("  [skip] account #{$old['id']} — пустое имя (number_x)\n");
                $skipped++;
                continue;
            }

            // Проверяем дубликат по имени в той же компании
            $exists = $newDb->createCommand(
                "SELECT id FROM accounts WHERE company_id = :cid AND name = :name",
                [':cid' => $newCompanyId, ':name' => $name]
            )->queryScalar();

            if ($exists) {
                $this->stdout("  [skip] account #{$old['id']} \"{$name}\" — уже существует (new id={$exists})\n");
                $skipped++;
                continue;
            }

            // Маппинг pool_id
            $newPoolId = null;
            if ($old['pool_id']) {
                $newPoolId = $this->poolMap[$old['pool_id']] ?? null;
                if ($newPoolId === null) {
                    $this->stdout("  [warn] account #{$old['id']} \"{$name}\" — pool_id={$old['pool_id']} не найден в маппинге, pool_id будет NULL\n");
                }
            }

            $newDb->createCommand()->insert('accounts', [
                'company_id' => $newCompanyId,
                'pool_id' => $newPoolId,
                'name' => $name,
                'is_suspense' => false,
                'load_status' => 'L',
                'load_barsgl' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            $newId = $newDb->getLastInsertID('accounts_id_seq');
            $this->stdout("  [ok] account #{$old['id']} \"{$name}\" → new id={$newId} (pool: {$old['pool_id']}→" . ($newPoolId ?? 'NULL') . ")\n");
            $inserted++;
        }

        $this->stdout("Итого: вставлено={$inserted}, пропущено={$skipped}\n");
    }

    /**
     * Мигрировать item → nostro_entries
     */
    public function actionEntries()
    {
        $oldDb = $this->getOldDb();
        $newDb = Yii::$app->db;
        $now = date('Y-m-d H:i:s');

        // Построить маппинг account_id (старый → новый)
        $this->buildAccountMap($oldDb, $newDb);

        if (empty($this->accountMap)) {
            $this->stderr("Ошибка: маппинг счетов пуст. Сначала мигрируйте счета: php yii migrate-data/accounts\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $oldAccountIds = array_keys($this->accountMap);
        $placeholders = implode(',', $oldAccountIds);

        // Считаем общее количество
        $totalCount = $oldDb->createCommand(
            "SELECT COUNT(*) FROM item WHERE account_id IN ({$placeholders})"
        )->queryScalar();

        $this->stdout("=== Миграция nostro_entries ===\n");
        $this->stdout("Найдено записей в старой БД: {$totalCount}\n");

        $columns = [
            'account_id', 'company_id', 'match_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'other_id', 'comment', 'match_status',
            'created_at', 'updated_at',
        ];

        $offset = 0;
        $limit = 5000;
        $inserted = 0;
        $skipped = 0;
        $rows = [];

        $flush = function () use ($newDb, $columns, &$rows, &$inserted) {
            if (!empty($rows)) {
                $newDb->createCommand()->batchInsert('nostro_entries', $columns, $rows)->execute();
                $inserted += count($rows);
                $rows = [];
            }
        };

        while ($offset < $totalCount) {
            $items = $oldDb->createCommand(
                "SELECT account_id, \"MatchID\", \"MMO\", \"DCIP\", \"Amount\", \"Currency\",
                        \"AvailOrPaid\", \"PostedORIssue\", \"UserText2\", \"UserText4\",
                        \"UserText3\", \"Addr2\", \"UserText1\", \"Addr1\"
                 FROM item
                 WHERE account_id IN ({$placeholders})
                 ORDER BY id
                 LIMIT :limit OFFSET :offset",
                [':limit' => $limit, ':offset' => $offset]
            )->queryAll();

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $newAccountId = $this->accountMap[$item['account_id']] ?? null;
                if ($newAccountId === null) {
                    $skipped++;
                    continue;
                }

                $newCompanyId = $this->accountCompanyMap[$item['account_id']] ?? null;

                // D/C маппинг
                $dc = $this->dcMap[(int)$item['DCIP']] ?? null;
                if ($dc === null) {
                    $this->stdout("  [warn] Неизвестный DCIP={$item['DCIP']}, пропуск\n");
                    $skipped++;
                    continue;
                }

                // L/S
                $ls = $item['MMO'];
                if (!in_array($ls, ['L', 'S'])) {
                    $this->stdout("  [warn] Неизвестный MMO={$item['MMO']}, пропуск\n");
                    $skipped++;
                    continue;
                }

                // match_status
                $matchId = !empty($item['MatchID']) ? $item['MatchID'] : null;
                $matchStatus = $matchId ? 'M' : 'U';

                // Даты
                $valueDate = $this->parseDate($item['AvailOrPaid']);
                $postDate = $this->parseDate($item['PostedORIssue']);

                $rows[] = [
                    $newAccountId,
                    $newCompanyId,
                    $matchId,
                    $ls,
                    $dc,
                    $item['Amount'],
                    trim($item['Currency']),
                    $valueDate,
                    $postDate,
                    $item['UserText2'] ?: null,  // instruction_id
                    $item['UserText4'] ?: null,  // end_to_end_id
                    $item['UserText3'] ?: null,  // transaction_id
                    $item['Addr2'] ?: null,      // message_id
                    $item['UserText1'] ?: null,  // other_id
                    $item['Addr1'] ?: null,      // comment
                    $matchStatus,
                    $now,
                    $now,
                ];

                if (count($rows) >= $this->batchSize) {
                    $flush();
                }
            }

            $flush();
            $offset += $limit;
            $this->stdout("  ... обработано {$offset}/{$totalCount}, вставлено {$inserted}\n");
        }

        $flush();
        $this->stdout("Итого: вставлено={$inserted}, пропущено={$skipped}\n");

        return ExitCode::OK;
    }

    /**
     * Построить маппинг account_id (старый → новый) по совпадению number_x → name
     */
    private function buildAccountMap($oldDb, $newDb)
    {
        $companyIds = array_keys($this->companyMap);
        $placeholders = implode(',', $companyIds);

        $oldAccounts = $oldDb->createCommand(
            "SELECT id, company_id, number_x FROM account WHERE company_id IN ({$placeholders})"
        )->queryAll();

        $this->accountMap = [];
        $this->accountCompanyMap = [];

        foreach ($oldAccounts as $old) {
            $newCompanyId = $this->companyMap[$old['company_id']];
            $name = $old['number_x'];

            if (empty($name)) {
                continue;
            }

            $newId = $newDb->createCommand(
                "SELECT id FROM accounts WHERE company_id = :cid AND name = :name",
                [':cid' => $newCompanyId, ':name' => $name]
            )->queryScalar();

            if ($newId) {
                $this->accountMap[$old['id']] = $newId;
                $this->accountCompanyMap[$old['id']] = $newCompanyId;
            }
        }

        $this->stdout("Account маппинг загружен: " . count($this->accountMap) . " записей\n");
    }

    /**
     * Парсинг даты из различных форматов
     */
    private function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    /**
     * Построить маппинг pool_id (старый → новый) по совпадению имён
     */
    private function buildPoolMap($oldDb, $newDb)
    {
        $companyIds = array_keys($this->companyMap);
        $placeholders = implode(',', $companyIds);

        $oldPools = $oldDb->createCommand(
            "SELECT id, company_id, name FROM account_pool WHERE company_id IN ({$placeholders})"
        )->queryAll();

        foreach ($oldPools as $old) {
            $newCompanyId = $this->companyMap[$old['company_id']];
            $newId = $newDb->createCommand(
                "SELECT id FROM account_pools WHERE company_id = :cid AND name = :name",
                [':cid' => $newCompanyId, ':name' => $old['name']]
            )->queryScalar();

            if ($newId) {
                $this->poolMap[$old['id']] = $newId;
            }
        }

        $this->stdout("Pool маппинг загружен: " . count($this->poolMap) . " записей\n");
    }

    /**
     * @return \yii\db\Connection
     */
    private function getOldDb()
    {
        $db = Yii::$app->get('oldDb', false);
        if (!$db) {
            $this->stderr("Ошибка: компонент 'oldDb' не настроен.\n");
            $this->stderr("Добавьте в config/console.php или config/web.php:\n");
            $this->stderr("'oldDb' => [\n");
            $this->stderr("    'class' => 'yii\\db\\Connection',\n");
            $this->stderr("    'dsn' => 'pgsql:host=...;port=5432;dbname=OLD_DB_NAME',\n");
            $this->stderr("    'username' => 'postgres',\n");
            $this->stderr("    'password' => '',\n");
            $this->stderr("    'charset' => 'utf8',\n");
            $this->stderr("],\n");
            exit(1);
        }
        return $db;
    }
}
