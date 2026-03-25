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
 *    php yii migrate-data/run              — мигрировать account_pools и accounts
 *    php yii migrate-data/pools            — только account_pools
 *    php yii migrate-data/accounts         — только accounts
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

    /**
     * Мигрировать всё: сначала пулы, потом счета
     */
    public function actionRun()
    {
        $this->actionPools();
        $this->stdout("\n");
        $this->actionAccounts();

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
