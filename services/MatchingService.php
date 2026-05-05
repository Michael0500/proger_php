<?php

namespace app\services;

use Yii;
use app\models\NostroEntry;
use app\models\MatchingRule;
use app\models\Account;
use app\models\AccountPool;

/**
 * Сервис квитования записей Ностро.
 *
 * Автоматическое квитование:
 *   - по всем счетам пула или по конкретному счёту
 *   - по правилам из таблицы matching_rules
 *
 * Ручное квитование:
 *   - принимает массив ID записей
 *   - проверяет сумму (Ledger vs Statement должна совпадать)
 *   - присваивает единый match_id и статус M
 *
 * Расквитование:
 *   - снимает match_id и возвращает статус U
 */
class MatchingService
{
    // ── Ручное квитование ─────────────────────────────────────────────

    /**
     * Сквитовать набор записей вручную.
     *
     * Для NRE (L+S):
     *   - сумма Ledger = сумма Statement (если разница != 0 — предупреждение)
     *   - минимум 2 записи
     *
     * Для INV (только Ledger):
     *   - балансировка по Debit/Credit (сумма D = сумма C)
     *   - если разница D/C = 0 — разрешена даже 1 запись (например, сумма = 0)
     *
     * @param int[]       $ids      Массив ID записей NostroEntry
     * @param string|null $section  'NRE' | 'INV' (null → NRE-логика)
     * @return array ['success'=>bool, 'match_id'=>string|null, 'warning'=>string|null, 'message'=>string]
     */
    public function matchManual(array $ids, ?string $section = null): array
    {
        $isInv = ($section === 'INV');

        $entries = NostroEntry::find()
            ->where(['id' => $ids, 'match_status' => NostroEntry::STATUS_UNMATCHED])
            ->all();

        if (empty($entries)) {
            return ['success' => false, 'message' => 'Незаквитованные записи не найдены'];
        }

        // Для 1 записи: разрешаем только если сумма = 0
        if (count($entries) === 1) {
            if (round((float) $entries[0]->amount, 2) !== 0.0) {
                return ['success' => false, 'message' => 'Выберите минимум 2 записи (одиночная запись должна иметь сумму 0)'];
            }
            // amount = 0 → квитуем без дополнительных проверок
        } elseif ($isInv) {
            // INV (2+ записей): проверяем баланс по Debit/Credit
            $sumDebit  = 0.0;
            $sumCredit = 0.0;
            foreach ($entries as $e) {
                if ($e->dc === NostroEntry::DC_DEBIT) {
                    $sumDebit  += (float) $e->amount;
                } else {
                    $sumCredit += (float) $e->amount;
                }
            }
            $diffDC = round(abs($sumDebit - $sumCredit), 2);
            if ($diffDC > 0) {
                return [
                    'success' => false,
                    'warning' => true,
                    'diff'    => $diffDC,
                    'message' => 'Дисбаланс Debit/Credit. Разница: ' . number_format($diffDC, 2, '.', ',')
                ];
            }
        } else {
            // NRE (2+ записей): проверяем баланс по L/S
            $sumLedger    = 0.0;
            $sumStatement = 0.0;
            $hasLedger    = false;
            $hasStatement = false;

            foreach ($entries as $e) {
                $signed = ($e->dc === NostroEntry::DC_DEBIT) ? $e->amount : -$e->amount;
                if ($e->ls === NostroEntry::LS_LEDGER) {
                    $sumLedger += $signed;
                    $hasLedger  = true;
                } else {
                    $sumStatement += $signed;
                    $hasStatement = true;
                }
            }

            if ($hasLedger && $hasStatement) {
                $diff = round(abs($sumLedger + $sumStatement), 2);
                if ($diff > 0) {
                    return [
                        'success' => false,
                        'warning' => true,
                        'diff'    => $diff,
                        'message' => 'Суммы не сбалансированы. Разница: ' . number_format($diff, 2, '.', ',')
                    ];
                }
            }
        }

        $matchId = $this->generateMatchId();
        $now     = date('Y-m-d H:i:s');
        $userId  = Yii::$app->user->id;

        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($entries as $e) {
                $e->match_id     = $matchId;
                $e->match_status = NostroEntry::STATUS_MATCHED;
                $e->updated_at   = $now;
                $e->updated_by   = $userId;
                $e->save(false);
            }
            $transaction->commit();
        } catch (\Exception $ex) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка БД: ' . $ex->getMessage()];
        }

        return [
            'success'  => true,
            'match_id' => $matchId,
            'count'    => count($entries),
            'message'  => 'Сквитовано записей: ' . count($entries) . '. Match ID: ' . $matchId,
        ];
    }

    /**
     * Расквитовать записи по match_id.
     * Все записи с этим match_id возвращаются в статус U.
     */
    public function unmatch(string $matchId): array
    {
        $count = NostroEntry::updateAll(
            [
                'match_id'     => null,
                'match_status' => NostroEntry::STATUS_UNMATCHED,
                'updated_at'   => date('Y-m-d H:i:s'),
                'updated_by'   => Yii::$app->user->id,
            ],
            ['match_id' => $matchId]
        );

        if ($count === 0) {
            return ['success' => false, 'message' => 'Записи с Match ID ' . $matchId . ' не найдены'];
        }

        return ['success' => true, 'count' => $count, 'message' => 'Расквитовано записей: ' . $count];
    }

    // ── Автоматическое квитование ─────────────────────────────────────

    /**
     * Запустить автоквитование целиком (для консольной команды).
     *
     * @param int      $companyId
     * @param int|null $accountId  null = по всем счетам
     * @param callable|null $onProgress  callback(int $ruleIndex, string $ruleName, int $matchedInRule, int $totalMatched)
     * @return array ['success'=>bool, 'matched'=>int, 'rules_count'=>int, 'message'=>string]
     */
    public function autoMatch(int $companyId, ?int $accountId = null, ?callable $onProgress = null, ?string $section = null): array
    {
        $where = ['company_id' => $companyId, 'is_active' => true];
        if ($section) {
            $where['section'] = $section;
        }
        $rules = MatchingRule::find()
            ->where($where)
            ->orderBy(['priority' => SORT_ASC])
            ->all();

        if (empty($rules)) {
            return ['success' => false, 'message' => 'Нет активных правил квитования'];
        }

        $totalMatched = 0;
        $errors       = [];

        foreach ($rules as $i => $rule) {
            try {
                $matched = $this->runRule($rule, $companyId, $accountId);
                $totalMatched += $matched;

                if ($onProgress) {
                    $onProgress($i + 1, $rule->name, $matched, $totalMatched);
                }
            } catch (\Exception $e) {
                $errors[] = 'Правило "' . $rule->name . '": ' . $e->getMessage();
                Yii::error('MatchingService rule #' . $rule->id . ': ' . $e->getMessage());
            }
        }

        $message = 'Автоквитование завершено. Сквитовано пар: ' . $totalMatched;
        if ($errors) {
            $message .= '. Ошибки: ' . implode('; ', $errors);
        }

        return [
            'success'     => true,
            'matched'     => $totalMatched,
            'rules_count' => count($rules),
            'message'     => $message,
        ];
    }

    // ── Пошаговое автоквитование (для UI с прогрессом) ──────────────

    /**
     * Разрешить scope в список account_id.
     * scope_type: 'all' | 'pool' | 'category'
     * scope_id:   id соответствующей сущности (null для 'all')
     * Возвращает null = без ограничений, [] = нет подходящих счетов.
     */
    public function resolveScopeAccounts(int $companyId, string $scopeType, ?int $scopeId): ?array
    {
        if ($scopeType === 'all' || !$scopeId) {
            return null; // без ограничений
        }

        if ($scopeType === 'pool') {
            return Account::find()
                ->select('id')
                ->where(['pool_id' => $scopeId, 'company_id' => $companyId])
                ->column();
        }

        if ($scopeType === 'category') {
            $poolIds = AccountPool::find()
                ->select('id')
                ->where(['category_id' => $scopeId, 'company_id' => $companyId])
                ->column();
            if (empty($poolIds)) return [];

            return Account::find()
                ->select('id')
                ->where(['pool_id' => $poolIds, 'company_id' => $companyId])
                ->column();
        }

        return null;
    }

    /**
     * Инициализация пошагового автоквитования.
     * Возвращает job_id и информацию о правилах.
     */
    public function autoMatchStart(int $companyId, ?int $accountId = null, ?string $section = null, string $scopeType = 'all', ?int $scopeId = null): array
    {
        $where = ['company_id' => $companyId, 'is_active' => true];
        if ($section) {
            $where['section'] = $section;
        }
        $rules = MatchingRule::find()
            ->where($where)
            ->orderBy(['priority' => SORT_ASC])
            ->all();

        if (empty($rules)) {
            return ['success' => false, 'message' => 'Нет активных правил квитования'];
        }

        // Разрешаем scope в список account_id
        $scopeAccountIds = $this->resolveScopeAccounts($companyId, $scopeType, $scopeId);

        // Считаем незаквитованные записи
        $query = NostroEntry::find()
            ->where(['company_id' => $companyId, 'match_status' => NostroEntry::STATUS_UNMATCHED]);
        if ($scopeAccountIds !== null) {
            if (empty($scopeAccountIds)) return ['success' => false, 'message' => 'Нет счетов в выбранной области'];
            $query->andWhere(['account_id' => $scopeAccountIds]);
        } elseif ($accountId) {
            $query->andWhere(['account_id' => $accountId]);
        }
        $unmatchedCount = (int) $query->count();

        $jobId = 'job_' . bin2hex(random_bytes(8));

        $rulesInfo = array_map(function (MatchingRule $r) {
            return [
                'id'   => $r->id,
                'name' => $r->name,
            ];
        }, $rules);

        // Сохраняем состояние в кэш
        $state = [
            'company_id'       => $companyId,
            'account_id'       => $accountId,
            'section'          => $section,
            'scope_type'       => $scopeType,
            'scope_id'         => $scopeId,
            'scope_account_ids'=> $scopeAccountIds,
            'rules'            => array_map(fn($r) => $r->id, $rules),
            'current_step'     => 0,
            'total_steps'      => count($rules),
            'total_matched'    => 0,
            'step_results'     => [],
            'is_finished'      => false,
            'started_at'       => date('Y-m-d H:i:s'),
        ];
        Yii::$app->cache->set('automatch_' . $jobId, $state, 3600);

        return [
            'success'         => true,
            'job_id'          => $jobId,
            'rules'           => $rulesInfo,
            'total_steps'     => count($rules),
            'unmatched_count' => $unmatchedCount,
        ];
    }

    /**
     * Выполнить следующий шаг автоквитования.
     * Обрабатывает одно правило за вызов.
     */
    public function autoMatchStep(string $jobId): array
    {
        $cacheKey = 'automatch_' . $jobId;
        $state = Yii::$app->cache->get($cacheKey);

        if (!$state) {
            return ['success' => false, 'message' => 'Задание не найдено или истекло'];
        }

        if ($state['is_finished']) {
            return [
                'success'       => true,
                'is_finished'   => true,
                'total_matched' => $state['total_matched'],
                'step_results'  => $state['step_results'],
                'message'       => 'Автоквитование уже завершено. Сквитовано пар: ' . $state['total_matched'],
            ];
        }

        $step    = $state['current_step'];
        $ruleId  = $state['rules'][$step] ?? null;

        if (!$ruleId) {
            $state['is_finished'] = true;
            Yii::$app->cache->set($cacheKey, $state, 3600);
            return [
                'success'       => true,
                'is_finished'   => true,
                'total_matched' => $state['total_matched'],
                'step_results'  => $state['step_results'],
                'message'       => 'Автоквитование завершено. Сквитовано пар: ' . $state['total_matched'],
            ];
        }

        $rule = MatchingRule::findOne($ruleId);
        $matched = 0;
        $error = null;

        if ($rule) {
            try {
                $scopeIds = $state['scope_account_ids'] ?? null;
                $matched = $this->runRule($rule, $state['company_id'], $state['account_id'], $scopeIds);
            } catch (\Exception $e) {
                $error = $e->getMessage();
                Yii::error("AutoMatch step rule #{$ruleId}: " . $e->getMessage());
            }
        }

        $state['total_matched'] += $matched;
        $state['step_results'][] = [
            'rule_id'   => $ruleId,
            'rule_name' => $rule ? $rule->name : '?',
            'matched'   => $matched,
            'error'     => $error,
        ];
        $state['current_step'] = $step + 1;

        if ($state['current_step'] >= $state['total_steps']) {
            $state['is_finished'] = true;
        }

        Yii::$app->cache->set($cacheKey, $state, 3600);

        return [
            'success'        => true,
            'is_finished'    => $state['is_finished'],
            'current_step'   => $state['current_step'],
            'total_steps'    => $state['total_steps'],
            'total_matched'  => $state['total_matched'],
            'last_rule_name' => $rule ? $rule->name : '?',
            'last_matched'   => $matched,
            'last_error'     => $error,
            'step_results'   => $state['step_results'],
        ];
    }

    /**
     * Выполнить одно правило — найти пары и сквитовать их.
     *
     * Стратегия:
     *   1. Обрабатываем по одному ностро-банку (pool) за раз — меньший рабочий набор.
     *   2. DISTINCT ON вместо ROW_NUMBER: с индексом idx_ne_unmatched_scan PostgreSQL
     *      сканирует записи в порядке id и останавливается на LIMIT, не материализуя
     *      все пары заранее.
     *   3. Всё обновление — в одном SQL (CTE + UPDATE), PHP не держит данные в памяти.
     *
     * Возвращает количество сквитованных пар.
     */
    public function runRule(MatchingRule $rule, int $companyId, ?int $accountId, ?array $limitAccountIds = null): int
    {
        $db = Yii::$app->db;

        [$typeA, $typeB] = $this->pairTypes($rule->pair_type);

        $joinConditions = $this->buildJoinConditions($rule);
        if (empty($joinConditions)) {
            return 0;
        }

        $joinSql     = implode(' AND ', $joinConditions);
        $dcCondition = $rule->match_dc
            ? "AND ((a.dc = 'Debit' AND b.dc = 'Credit') OR (a.dc = 'Credit' AND b.dc = 'Debit'))"
            : '';

        // Для LL/SS: оба типа одинаковы → пара (a,b) и (b,a) найдутся обе.
        // a.id < b.id гарантирует, что каждая пара найдётся ровно один раз.
        $sameSideCondition = ($typeA === $typeB) ? 'AND a.id < b.id' : '';

        $now       = date('Y-m-d H:i:s');
        $userId    = (Yii::$app instanceof \yii\web\Application && !Yii::$app->user->isGuest)
            ? (int) Yii::$app->user->id
            : null;
        $userIdSql = $userId !== null ? $userId : 'NULL';
        $batchSize = 5000;
        $total     = 0;

        // ── Получаем список пулов для обработки ───────────────────────────────
        $poolQuery = "
            SELECT DISTINCT pool_id
            FROM accounts
            WHERE company_id = {$companyId} AND pool_id IS NOT NULL
        ";
        if ($accountId) {
            $poolQuery .= " AND id = {$accountId}";
        }
        if ($limitAccountIds !== null) {
            if (empty($limitAccountIds)) return 0;
            $limitList = implode(',', array_map('intval', $limitAccountIds));
            $poolQuery .= " AND id IN ({$limitList})";
        }
        $poolIds = $db->createCommand($poolQuery)->queryColumn();

        if (empty($poolIds)) {
            return 0;
        }

        // ── Обрабатываем каждый пул отдельно ─────────────────────────────────
        foreach ($poolIds as $poolId) {
            $accountIds = $db->createCommand("
                SELECT id FROM accounts
                WHERE pool_id = {$poolId} AND company_id = {$companyId}
            ")->queryColumn();

            // Применяем scope-ограничение
            if ($limitAccountIds !== null) {
                $accountIds = array_values(array_intersect($accountIds, $limitAccountIds));
            }

            if (empty($accountIds)) {
                continue;
            }

            $accList = implode(',', array_map('intval', $accountIds));
            // При фильтре по конкретному счёту ограничиваем только сторону a
            $filterA = $accountId
                ? "AND a.account_id = {$accountId}"
                : "AND a.account_id IN ({$accList})";

            // DISTINCT ON (a.id) ORDER BY a.id, b.id:
            //   PostgreSQL использует idx_ne_unmatched_scan для обхода a в порядке id,
            //   для каждой a находит первую подходящую b через idx_ne_automatch_v2,
            //   и останавливается после LIMIT — без сортировки всего набора пар.
            $sql = "
                WITH l_matches AS (
                    SELECT DISTINCT ON (a.id)
                        a.id AS id_a,
                        b.id AS id_b
                    FROM nostro_entries a
                    JOIN nostro_entries b
                        ON b.company_id = {$companyId}
                       AND b.ls         = '{$typeB}'
                       AND b.match_status = 'U'
                       AND b.account_id IN ({$accList})
                       AND b.id <> a.id
                       {$sameSideCondition}
                       AND {$joinSql}
                       {$dcCondition}
                    WHERE
                        a.company_id   = {$companyId}
                        AND a.ls       = '{$typeA}'
                        AND a.match_status = 'U'
                        {$filterA}
                    ORDER BY a.id, b.id
                    LIMIT {$batchSize}
                ),
                s_dedup AS (
                    -- Гарантируем что каждая запись b входит максимум в одну пару
                    SELECT DISTINCT ON (id_b) id_a, id_b
                    FROM l_matches
                    ORDER BY id_b, id_a
                ),
                numbered AS (
                    SELECT id_a, id_b,
                           'MTCH' || lpad(nextval('match_id_seq')::text, 8, '0') AS mid
                    FROM s_dedup
                ),
                to_update AS (
                    SELECT id_a AS eid, mid FROM numbered
                    UNION ALL
                    SELECT id_b AS eid, mid FROM numbered
                )
                UPDATE nostro_entries ne
                SET match_id     = u.mid,
                    match_status = 'M',
                    updated_at   = '{$now}',
                    updated_by   = {$userIdSql}
                FROM to_update u
                WHERE ne.id = u.eid
            ";

            do {
                $transaction = $db->beginTransaction();
                try {
                    $affected = $db->createCommand($sql)->execute();
                    $transaction->commit();
                } catch (\Exception $e) {
                    $transaction->rollBack();
                    throw $e;
                }
                $total += (int) ($affected / 2);
            } while ($affected > 0);
        }

        return $total;
    }

    /**
     * Сгенерировать SQL для отладки — без выполнения.
     * Возвращает null если buildJoinConditions пуст.
     */
    public function buildDebugSql(MatchingRule $rule, int $companyId, ?int $accountId): ?string
    {
        $db = Yii::$app->db;
        [$typeA, $typeB] = $this->pairTypes($rule->pair_type);

        $joinConditions = $this->buildJoinConditions($rule);
        if (empty($joinConditions)) {
            return null;
        }

        $accountFilter = $accountId ? "AND a.account_id = $accountId" : '';
        $joinSql       = implode(' AND ', $joinConditions);

        $dcCondition = '';
        if ($rule->match_dc) {
            $dcCondition = "AND ((a.dc = 'Debit' AND b.dc = 'Credit') OR (a.dc = 'Credit' AND b.dc = 'Debit'))";
        }

        return "-- Правило: {$rule->name} | pair_type={$rule->pair_type} | cross_id={$rule->cross_id_search}
WITH pairs AS (
    SELECT a.id AS id_a, b.id AS id_b,
           ROW_NUMBER() OVER (PARTITION BY a.id ORDER BY b.id) AS rn_a,
           ROW_NUMBER() OVER (PARTITION BY b.id ORDER BY a.id) AS rn_b
    FROM nostro_entries a
    JOIN accounts acc_a ON acc_a.id = a.account_id AND acc_a.pool_id IS NOT NULL
    JOIN accounts acc_b ON acc_b.pool_id = acc_a.pool_id
    JOIN nostro_entries b
        ON b.account_id = acc_b.id
       AND a.id <> b.id
       AND {$joinSql}
       {$dcCondition}
    WHERE a.company_id = {$companyId}
      AND b.company_id = {$companyId}
      AND a.ls = '{$typeA}'
      AND b.ls = '{$typeB}'
      AND a.match_status = 'U'
      AND b.match_status = 'U'
      {$accountFilter}
),
unique_pairs AS (SELECT id_a, id_b FROM pairs WHERE rn_a = 1 AND rn_b = 1)
SELECT id_a, id_b FROM unique_pairs;

-- Диагностика: число незаквитованных L и S по этой компании
SELECT ls, COUNT(*) FROM nostro_entries
WHERE company_id = {$companyId} AND match_status = 'U'
GROUP BY ls;

-- Диагностика: заполненность ID-полей
SELECT
  COUNT(*) FILTER (WHERE instruction_id IS NOT NULL)  AS has_instruction_id,
  COUNT(*) FILTER (WHERE end_to_end_id  IS NOT NULL)  AS has_end_to_end_id,
  COUNT(*) FILTER (WHERE transaction_id IS NOT NULL)  AS has_transaction_id,
  COUNT(*) FILTER (WHERE message_id     IS NOT NULL)  AS has_message_id,
  COUNT(*) FILTER (WHERE value_date     IS NOT NULL)  AS has_value_date,
  ls
FROM nostro_entries
WHERE company_id = {$companyId} AND match_status = 'U'
GROUP BY ls;";
    }

    /**
     * Построить условия JOIN для правила.
     * Перекрёстный поиск: ID из любого поля A совпадает с любым полем B.
     */
    private function buildJoinConditions(MatchingRule $rule): array
    {
        $conditions = [];

        if ($rule->match_amount) {
            $conditions[] = 'a.amount = b.amount';
        }

        if ($rule->match_value_date) {
            $conditions[] = 'a.value_date = b.value_date';
        }

        // ID условия
        $idConditions = [];
        $idFields = [];

        if ($rule->match_instruction_id)  $idFields[] = 'instruction_id';
        if ($rule->match_end_to_end_id)   $idFields[] = 'end_to_end_id';
        if ($rule->match_transaction_id)  $idFields[] = 'transaction_id';
        if ($rule->match_message_id)      $idFields[] = 'message_id';

        if (!empty($idFields)) {
            if ($rule->cross_id_search) {
                // Перекрёстный: a.instruction_id IN (b.instruction_id, b.end_to_end_id, ...)
                $allFieldsA = array_map(fn($f) => "a.{$f}", $idFields);
                $allFieldsB = array_map(fn($f) => "b.{$f}", $idFields);
                // Хотя бы одна пара полей совпадает
                foreach ($allFieldsA as $fa) {
                    foreach ($allFieldsB as $fb) {
                        $idConditions[] = "({$fa} IS NOT NULL AND {$fa} = {$fb})";
                    }
                }
            } else {
                // Прямое: одноимённые поля совпадают
                foreach ($idFields as $f) {
                    $idConditions[] = "(a.{$f} IS NOT NULL AND a.{$f} = b.{$f})";
                }
            }

            if (!empty($idConditions)) {
                $conditions[] = '(' . implode(' OR ', $idConditions) . ')';
            }
        }

        return $conditions;
    }

    /**
     * Вернуть [typeA, typeB] по pair_type.
     */
    private function pairTypes(string $pairType): array
    {
        switch ($pairType) {
            case MatchingRule::PAIR_LS:
                return ['L', 'S'];
            case MatchingRule::PAIR_LL:
                return ['L', 'L'];
            case MatchingRule::PAIR_SS:
                return ['S', 'S'];
            default:
                return ['L', 'S'];
        }
    }

    /**
     * Генерация гарантированно уникального Match ID через PostgreSQL sequence.
     * Формат: MTCH00000001, MTCH00000002, ...
     * nextval() атомарен — уникальность гарантирована без дополнительных проверок.
     */
    public function generateMatchId(): string
    {
        $seq = Yii::$app->db->createCommand("SELECT nextval('match_id_seq')")->queryScalar();
        return 'MTCH' . str_pad($seq, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Предварительный подсчёт сумм по выбранным ID (для UI перед квитованием).
     * Возвращает суммы по L и S, и разницу.
     */
    public function calcSummary(array $ids): array
    {
        $entries = NostroEntry::find()->where(['id' => $ids])->all();

        $sumL = 0.0;
        $sumS = 0.0;
        $cntL = 0;
        $cntS = 0;

        foreach ($entries as $e) {
            if ($e->ls === NostroEntry::LS_LEDGER) {
                $sumL += $e->amount;
                $cntL++;
            } else {
                $sumS += $e->amount;
                $cntS++;
            }
        }

        return [
            'sum_ledger'    => $sumL,
            'sum_statement' => $sumS,
            'diff'          => round($sumL - $sumS, 2),
            'cnt_ledger'    => $cntL,
            'cnt_statement' => $cntS,
        ];
    }
}