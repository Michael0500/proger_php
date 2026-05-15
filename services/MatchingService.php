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
 * Сервис инкапсулирует ручное квитование, расквитование, пакетное
 * автоквитование и пошаговое автоквитование для UI. Все операции должны
 * выполняться в рамках `company_id`, чтобы не смешивать данные разных компаний.
 *
 * Автоквитование строит SQL по активным правилам из `matching_rules`, ищет
 * уникальные пары в пределах ностро-банка и присваивает общий `match_id`.
 * Ручное квитование проверяет баланс набора записей перед сменой статуса.
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
     * Побочный эффект: в одной транзакции присваивает общий `match_id`,
     * переводит записи в статус `M`, заполняет `matched_at/updated_at/updated_by`.
     *
     * @param int[] $ids Массив ID незаквитованных `NostroEntry`.
     * @param string|null $section Раздел `NRE` или `INV`; `null` использует NRE-логику.
     * @param int|null $companyId Если задан, ограничивает квитование одной компанией.
     * @return array Результат операции: `success`, `match_id`, `count`, `message` и возможное `warning/diff`.
     */
    public function matchManual(array $ids, ?string $section = null, ?int $companyId = null): array
    {
        $isInv = ($section === 'INV');
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $query = NostroEntry::find()
            ->where(['id' => $ids, 'match_status' => NostroEntry::STATUS_UNMATCHED]);
        if ($companyId !== null) {
            $query->andWhere(['company_id' => $companyId]);
        }
        $entries = $query->all();

        if (empty($entries)) {
            return ['success' => false, 'message' => 'Незаквитованные записи не найдены'];
        }
        if (count($entries) !== count($ids)) {
            return ['success' => false, 'message' => 'Часть записей недоступна или уже сквитована'];
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
                $e->matched_at   = $now;
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
     * Расквитовывает все активные записи с указанным `match_id`.
     *
     * Побочный эффект: массовым SQL-обновлением очищает `match_id/matched_at`,
     * возвращает статус `U` и обновляет служебные поля изменения. Если передан
     * `companyId`, операция ограничена одной компанией.
     *
     * @param string $matchId Идентификатор группы квитования.
     * @param int|null $companyId ID компании для tenant-ограничения.
     * @return array Результат операции с количеством изменённых записей.
     */
    public function unmatch(string $matchId, ?int $companyId = null): array
    {
        $condition = ['match_id' => $matchId];
        if ($companyId !== null) {
            $condition['company_id'] = $companyId;
        }

        $count = NostroEntry::updateAll(
            [
                'match_id'     => null,
                'match_status' => NostroEntry::STATUS_UNMATCHED,
                'matched_at'   => null,
                'updated_at'   => date('Y-m-d H:i:s'),
                'updated_by'   => Yii::$app->user->id,
            ],
            $condition
        );

        if ($count === 0) {
            return ['success' => false, 'message' => 'Записи с Match ID ' . $matchId . ' не найдены'];
        }

        return ['success' => true, 'count' => $count, 'message' => 'Расквитовано записей: ' . $count];
    }

    // ── Автоматическое квитование ─────────────────────────────────────

    /**
     * Запускает автоквитование по всем активным правилам.
     *
     * Используется консольной командой и синхронными API-вызовами. Правила
     * выполняются по приоритету; ошибка отдельного правила логируется и не
     * останавливает обработку следующих правил.
     *
     * @param int $companyId ID компании.
     * @param int|null $accountId ID конкретного счёта или `null` для всех счетов.
     * @param callable|null $onProgress Callback `(int $ruleIndex, string $ruleName, int $matchedInRule, int $totalMatched)`.
     * @param string|null $section Фильтр раздела `NRE` или `INV`.
     * @return array Итог автоквитования: `success`, `matched`, `rules_count`, `message`.
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
     * Разрешает область запуска автоквитования в список `account_id`.
     *
     * `scopeType` принимает `all`, `pool` или `category`. `null` означает,
     * что ограничения по счетам нет, а пустой массив означает выбранную область
     * без подходящих счетов.
     *
     * @param int $companyId ID компании.
     * @param string $scopeType Тип области: `all`, `pool` или `category`.
     * @param int|null $scopeId ID ностро-банка или категории для выбранной области.
     * @return int[]|null Список ID счетов, `null` без ограничения.
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
     * Инициализирует пошаговое автоквитование для UI.
     *
     * Метод выбирает активные правила, считает количество незаквитованных
     * записей в выбранной области и сохраняет состояние задания в `FileCache`
     * на один час. Следующие шаги выполняются методом `autoMatchStep()`.
     *
     * @param int $companyId ID компании.
     * @param int|null $accountId ID конкретного счёта или `null`.
     * @param string|null $section Фильтр раздела `NRE` или `INV`.
     * @param string $scopeType Область запуска: `all`, `pool`, `category`.
     * @param int|null $scopeId ID области запуска.
     * @return array Данные задания: `job_id`, список правил, число шагов и незаквитованных записей.
     * @throws \Exception Если не удалось сгенерировать случайный `job_id`.
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
     * Выполняет следующий шаг пошагового автоквитования.
     *
     * За один вызов обрабатывается одно правило из состояния задания. После
     * выполнения результат правила сохраняется обратно в кэш, чтобы фронтенд
     * мог показывать прогресс и продолжить обработку следующим запросом.
     *
     * @param string $jobId ID задания, полученный из `autoMatchStart()`.
     * @return array Состояние прогресса, результат последнего правила и общий итог.
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
     * Выполняет одно правило автоквитования.
     *
     * Стратегия:
     *   1. Обрабатываем по одному ностро-банку (pool) за раз — меньший рабочий набор.
     *   2. DISTINCT ON вместо ROW_NUMBER: с индексом idx_ne_unmatched_scan PostgreSQL
     *      сканирует записи в порядке id и останавливается на LIMIT, не материализуя
     *      все пары заранее.
     *   3. Всё обновление — в одном SQL (CTE + UPDATE), PHP не держит данные в памяти.
     *
     * Побочный эффект: пакетно обновляет найденные записи в `nostro_entries`,
     * присваивает новый `match_id`, статус `M`, `matched_at` и служебные поля.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @param int $companyId ID компании.
     * @param int|null $accountId Ограничение по конкретному счёту или `null`.
     * @param int[]|null $limitAccountIds Дополнительный список допустимых счетов из scope UI.
     * @return int Количество сквитованных пар.
     * @throws \Exception Если SQL-обновление правила завершилось ошибкой.
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
                    matched_at   = '{$now}',
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
     * Генерирует диагностический SQL для правила без выполнения.
     *
     * Используется консольной командой для анализа условий правила,
     * заполненности ID-полей и количества незаквитованных записей.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @param int $companyId ID компании.
     * @param int|null $accountId Ограничение по счёту или `null`.
     * @return string|null SQL-текст или `null`, если правило не содержит условий JOIN.
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
     * Строит SQL-условия JOIN для правила автоквитования.
     *
     * При включённом `cross_id_search` любой выбранный ID-поле стороны A
     * может совпасть с любым выбранным ID-полем стороны B. При выключенном
     * режиме сравниваются только одноимённые поля.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @return string[] Список SQL-условий для соединения записей.
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
     * Возвращает типы сторон пары по коду `pair_type`.
     *
     * @param string $pairType Код пары из `MatchingRule::PAIR_*`.
     * @return string[] Массив из двух значений L/S для сторон A и B.
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
     * Генерирует уникальный `match_id` через PostgreSQL sequence.
     *
     * Формат результата: `MTCH00000001`. `nextval()` атомарен, поэтому
     * дополнительных проверок на коллизии в активной и архивной таблицах не нужно.
     *
     * @return string Новый идентификатор группы квитования.
     */
    public function generateMatchId(): string
    {
        $seq = Yii::$app->db->createCommand("SELECT nextval('match_id_seq')")->queryScalar();
        return 'MTCH' . str_pad($seq, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Считает предварительную сводку по выбранным записям.
     *
     * Используется UI перед ручным квитованием, чтобы показать пользователю
     * суммы Ledger/Statement и разницу в выбранном наборе.
     *
     * @param int[] $ids ID записей для расчёта.
     * @param int|null $companyId ID компании для tenant-ограничения.
     * @return array Суммы и количества по L/S: `sum_ledger`, `sum_statement`, `diff`, `cnt_*`.
     */
    public function calcSummary(array $ids, ?int $companyId = null): array
    {
        $query = NostroEntry::find()->where(['id' => array_map('intval', $ids)]);
        if ($companyId !== null) {
            $query->andWhere(['company_id' => $companyId]);
        }
        $entries = $query->all();

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
