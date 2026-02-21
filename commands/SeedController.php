<?php
namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\models\Account;
use app\models\AccountGroup;
use app\models\AccountPool;
use app\models\Company;

/**
 * Генерация тестовых данных для Nostro-выверки.
 *
 * php yii seed/nostro                          — 1000 записей, первая компания
 * php yii seed/nostro --company=2              — указать ID компании
 * php yii seed/nostro --count=2000             — количество несквитованных
 * php yii seed/nostro --matched=300            — количество сквитованных пар
 * php yii seed/nostro --clear                  — сначала очистить таблицу
 * php yii seed/nostro --with-setup             — создать группу/пул/счета если их нет
 */
class SeedController extends Controller
{
    /** @var int ID компании (0 = первая найденная) */
    public $company = 0;
    /** @var int Количество несквитованных записей */
    public $count = 1000;
    /** @var int Количество сквитованных пар */
    public $matched = 200;
    /** @var bool Очистить таблицу перед генерацией */
    public $clear = false;
    /** @var bool Создать тестовую структуру (группа/пул/счета) если нет */
    public $withSetup = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'company', 'count', 'matched', 'clear', 'withSetup'
        ]);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'with-setup' => 'withSetup',
        ]);
    }

    public function actionNostro()
    {
        // 1. Определяем компанию
        $company = $this->company > 0
            ? Company::findOne($this->company)
            : Company::find()->orderBy('id')->one();

        if (!$company) {
            $this->stderr("❌ Компания не найдена. Создайте компанию через UI.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("🏢 Компания: [{$company->id}] {$company->name}\n");

        // 2. Создать тестовую структуру если нужно
        if ($this->withSetup) {
            $this->stdout("🔧 Создаём тестовую структуру для компании...\n");
            $this->ensureTestStructure($company->id);
        }

        // 3. Получаем счета компании
        $accounts = Account::find()
            ->where(['company_id' => $company->id])
            ->all();

        if (empty($accounts)) {
            $this->stderr("❌ Нет счетов для компании {$company->id}.\n");
            $this->stderr("   Запустите с флагом --with-setup или создайте счета через UI.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("📂 Счетов: " . count($accounts) . "\n");

        // 4. Очистка если нужно
        $db    = Yii::$app->db;
        $table = '{{%nostro_entries}}';
        $now   = date('Y-m-d H:i:s');

        if ($this->clear) {
            $deleted = $db->createCommand(
                "DELETE FROM {{%nostro_entries}} WHERE company_id = :cid",
                [':cid' => $company->id]
            )->execute();
            $this->stdout("🗑  Удалено старых записей: {$deleted}\n");
        }

        $currencies  = ['USD', 'EUR', 'RUB', 'GBP', 'CHF', 'CNY', 'JPY'];
        $accountIds  = array_map(function ($a) { return $a->id; }, $accounts);
        $total       = 0;

        // ── 1. Сквитованные пары ──────────────────────────────────────
        $this->stdout("⚡ Генерирую {$this->matched} сквитованных пар...\n");
        for ($i = 0; $i < $this->matched; $i++) {
            $accId   = $accountIds[array_rand($accountIds)];
            $cur     = $currencies[array_rand($currencies)];
            $amt     = round(mt_rand(10000, 99999999) / 100, 2);
            $vDate   = $this->rDate('2024-01-01', '2026-01-31');
            $pDate   = $this->rDate($vDate, '2026-02-15');
            $instr   = 'INSTR' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $e2e     = 'E2E'   . str_pad($i, 7, '0', STR_PAD_LEFT);
            $txn     = 'TXN'   . str_pad($i, 7, '0', STR_PAD_LEFT);
            $matchId = 'M' . strtoupper(substr(md5($i . 'matched' . $now), 0, 10));

            foreach (['L' => 'Debit', 'S' => 'Credit'] as $ls => $dc) {
                $db->createCommand()->insert($table, [
                    'account_id'     => $accId,
                    'company_id'     => $company->id,
                    'match_id'       => $matchId,
                    'ls'             => $ls,
                    'dc'             => $dc,
                    'amount'         => $amt,
                    'currency'       => $cur,
                    'value_date'     => $vDate,
                    'post_date'      => $pDate,
                    'instruction_id' => $instr,
                    'end_to_end_id'  => $e2e,
                    'transaction_id' => $txn,
                    'message_id'     => 'MSG' . mt_rand(1000000, 9999999),
                    'comment'        => 'Matched pair #' . $i,
                    'match_status'   => 'M',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ])->execute();
                $total++;
            }
        }
        $this->stdout("   ✓ " . ($this->matched * 2) . " сквитованных записей\n");

        // ── 2. Пары для автоквитования (одинаковые instr/e2e) ─────────
        $pairsReady = (int)($this->count * 0.35);
        $this->stdout("🔗 Генерирую {$pairsReady} пар для автоквитования...\n");
        for ($i = 0; $i < $pairsReady; $i++) {
            $accId = $accountIds[array_rand($accountIds)];
            $cur   = $currencies[array_rand($currencies)];
            $amt   = round(mt_rand(10000, 99999999) / 100, 2);
            $vDate = $this->rDate('2025-01-01', '2026-02-10');
            $instr = 'AUTO' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $e2e   = 'AE'   . str_pad($i, 7, '0', STR_PAD_LEFT);

            foreach (['L' => 'Debit', 'S' => 'Credit'] as $ls => $dc) {
                $db->createCommand()->insert($table, [
                    'account_id'     => $accId,
                    'company_id'     => $company->id,
                    'match_id'       => null,
                    'ls'             => $ls,
                    'dc'             => $dc,
                    'amount'         => $amt,
                    'currency'       => $cur,
                    'value_date'     => $vDate,
                    'post_date'      => $this->rDate($vDate, '2026-02-20'),
                    'instruction_id' => $instr,
                    'end_to_end_id'  => $e2e,
                    'transaction_id' => 'AT' . mt_rand(1000000, 9999999),
                    'message_id'     => 'AM' . mt_rand(1000000, 9999999),
                    'comment'        => null,
                    'match_status'   => 'U',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ])->execute();
                $total++;
            }
        }
        $this->stdout("   ✓ " . ($pairsReady * 2) . " записей для автоквитования\n");

        // ── 3. Одиночные несквитованные ──────────────────────────────
        $singles = max(0, $this->count - $pairsReady * 2);
        if ($singles > 0) {
            $this->stdout("📝 Генерирую {$singles} одиночных записей...\n");
            $lsList = ['L', 'S'];
            $dcList = ['Debit', 'Credit'];
            for ($i = 0; $i < $singles; $i++) {
                $accId = $accountIds[array_rand($accountIds)];
                $ls    = $lsList[array_rand($lsList)];
                $dc    = $dcList[array_rand($dcList)];
                $vDate = $this->rDate('2023-01-01', '2026-02-20');

                $db->createCommand()->insert($table, [
                    'account_id'     => $accId,
                    'company_id'     => $company->id,
                    'match_id'       => null,
                    'ls'             => $ls,
                    'dc'             => $dc,
                    'amount'         => round(mt_rand(500, 50000000) / 100, 2),
                    'currency'       => $currencies[array_rand($currencies)],
                    'value_date'     => $vDate,
                    'post_date'      => $this->rDate($vDate, '2026-02-20'),
                    'instruction_id' => (mt_rand(0, 1) ? 'S' . mt_rand(100000, 9999999) : null),
                    'end_to_end_id'  => (mt_rand(0, 1) ? 'E' . mt_rand(100000, 9999999) : null),
                    'transaction_id' => (mt_rand(0, 1) ? 'T' . mt_rand(100000, 9999999) : null),
                    'message_id'     => (mt_rand(0, 1) ? 'G' . mt_rand(100000, 9999999) : null),
                    'comment'        => (mt_rand(0, 4) === 0 ? 'Запись #' . ($i + 1) : null),
                    'match_status'   => 'U',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ])->execute();
                $total++;
            }
            $this->stdout("   ✓ {$singles} одиночных записей\n");
        }

        $this->stdout("\n✅ Готово! Создано записей: {$total}\n");
        $this->stdout("   Сквитованных пар:         {$this->matched} × 2 = " . ($this->matched * 2) . "\n");
        $this->stdout("   Для автоквитования (пары): {$pairsReady} × 2 = " . ($pairsReady * 2) . "\n");
        $this->stdout("   Одиночных:                 {$singles}\n\n");
        $this->stdout("💡 Запустите автоквитование через UI кнопкой «Автоквитование»\n");

        return ExitCode::OK;
    }

    /**
     * Создаёт группу, пул и тестовые счета если их нет
     */
    private function ensureTestStructure(int $companyId): void
    {
        // Группа
        $group = AccountGroup::find()
            ->where(['company_id' => $companyId, 'name' => 'Тестовая группа'])
            ->one();
        if (!$group) {
            $group             = new AccountGroup();
            $group->company_id = $companyId;
            $group->name       = 'Тестовая группа';
            $group->description = 'Создана автоматически для тестирования';
            $group->save(false);
            $this->stdout("   ✓ Группа: {$group->name}\n");
        }

        // Пул
        $pool = AccountPool::find()
            ->where(['group_id' => $group->id, 'name' => 'Основной пул'])
            ->one();
        if (!$pool) {
            $pool             = new AccountPool();
            $pool->company_id = $companyId;
            $pool->group_id   = $group->id;
            $pool->name       = 'Основной пул';
            $pool->is_active  = true;
            $pool->save(false);
            $this->stdout("   ✓ Пул: {$pool->name}\n");
        }

        // Тестовые счета
        $testAccounts = [
            ['name' => 'NOSTRO_USD_01', 'currency' => 'USD', 'is_suspense' => false],
            ['name' => 'NOSTRO_EUR_01', 'currency' => 'EUR', 'is_suspense' => false],
            ['name' => 'NOSTRO_RUB_01', 'currency' => 'RUB', 'is_suspense' => false],
            ['name' => 'SUSPENSE_USD',  'currency' => 'USD', 'is_suspense' => true],
            ['name' => 'SUSPENSE_EUR',  'currency' => 'EUR', 'is_suspense' => true],
        ];

        foreach ($testAccounts as $accData) {
            $exists = Account::find()
                ->where(['company_id' => $companyId, 'name' => $accData['name']])
                ->exists();
            if (!$exists) {
                $acc             = new Account();
                $acc->company_id = $companyId;
                $acc->pool_id    = $pool->id;
                $acc->name       = $accData['name'];
                $acc->is_suspense = $accData['is_suspense'];
                $acc->save(false);
                $label = $accData['is_suspense'] ? " (Suspense)" : "";
                $this->stdout("   ✓ Счёт: {$acc->name} ({$accData['currency']}{$label})\n");
            }
        }
    }

    private function rDate(string $from, string $to): string
    {
        $tf = strtotime($from);
        $tt = strtotime($to);
        if ($tf >= $tt) return $from;
        return date('Y-m-d', mt_rand($tf, $tt));
    }
}