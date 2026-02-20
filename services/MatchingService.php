<?php

namespace app\services;

use Yii;
use app\models\NostroEntry;
use app\models\MatchingRule;

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
     * Правила:
     *   - разные типы (L/S) или одинаковые — оба варианта допустимы по ТЗ
     *   - сумма Ledger = сумма Statement (если разница != 0 — предупреждение, не ошибка)
     *   - противоположные D/C обязательны ТОЛЬКО при смешанном L+S
     *
     * @param int[] $ids     Массив ID записей NostroEntry
     * @return array ['success'=>bool, 'match_id'=>string|null, 'warning'=>string|null, 'message'=>string]
     */
    public function matchManual(array $ids): array
    {
        if (count($ids) < 2) {
            return ['success' => false, 'message' => 'Выберите минимум 2 записи'];
        }

        $entries = NostroEntry::find()
            ->where(['id' => $ids, 'match_status' => NostroEntry::STATUS_UNMATCHED])
            ->all();

        if (count($entries) < 2) {
            return ['success' => false, 'message' => 'Не найдено достаточно незаквитованных записей'];
        }

        // Подсчёт сумм по типу
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

        $warning = null;

        // Если есть и Ledger и Statement — проверяем балансировку
        if ($hasLedger && $hasStatement) {
            $diff = round(abs($sumLedger + $sumStatement), 2);
            if ($diff > 0) {
                $warning = 'Разница сумм: ' . number_format($diff, 2, '.', ',');
                // По ТЗ: если разница != 0 — предупреждение, запись остаётся на ручном разборе
                return [
                    'success' => false,
                    'warning' => true,
                    'diff'    => $diff,
                    'message' => 'Суммы не сбалансированы. Разница: ' . number_format($diff, 2, '.', ',')
                ];
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
     * Запустить автоквитование.
     *
     * @param int      $companyId
     * @param int|null $accountId  null = по всем счетам
     * @return array ['success'=>bool, 'matched'=>int, 'message'=>string]
     */
    public function autoMatch(int $companyId, ?int $accountId = null): array
    {
        $rules = MatchingRule::find()
            ->where(['company_id' => $companyId, 'is_active' => true])
            ->orderBy(['priority' => SORT_ASC])
            ->all();

        if (empty($rules)) {
            return ['success' => false, 'message' => 'Нет активных правил квитования'];
        }

        $totalMatched = 0;
        $errors       = [];

        foreach ($rules as $rule) {
            try {
                $matched = $this->runRule($rule, $companyId, $accountId);
                $totalMatched += $matched;
            } catch (\Exception $e) {
                $errors[] = 'Правило "' . $rule->name . '": ' . $e->getMessage();
                Yii::error('MatchingService rule #' . $rule->id . ': ' . $e->getMessage());
            }
        }

        $message = 'Автоквитование завершено. Сквитовано пар: ' . $totalMatched;
        if ($errors) {
            $message .= '. Ошибки: ' . implode('; ', $errors);
        }

        return ['success' => true, 'matched' => $totalMatched, 'message' => $message];
    }

    /**
     * Выполнить одно правило — найти пары и сквитовать их.
     */
    private function runRule(MatchingRule $rule, int $companyId, ?int $accountId): int
    {
        $db = Yii::$app->db;

        // Определяем типы L/S для левой и правой части пары
        [$typeA, $typeB] = $this->pairTypes($rule->pair_type);

        // Условия JOIN
        $joinConditions = $this->buildJoinConditions($rule);
        if (empty($joinConditions)) {
            return 0; // нечего сравнивать
        }

        $accountFilter = $accountId ? "AND a.account_id = $accountId" : '';
        $joinSql       = implode(' AND ', $joinConditions);

        // Для NRE: D/C должны быть противоположны (если pair_type=LS и match_dc=true)
        $dcCondition = '';
        if ($rule->match_dc && $rule->pair_type === MatchingRule::PAIR_LS) {
            $dcCondition = "AND ((a.dc = 'Debit' AND b.dc = 'Credit') OR (a.dc = 'Credit' AND b.dc = 'Debit'))";
        } elseif ($rule->match_dc && in_array($rule->pair_type, [MatchingRule::PAIR_LL, MatchingRule::PAIR_SS])) {
            // Для INV LL: оба Debit + Credit (противоположные в паре)
            $dcCondition = "AND ((a.dc = 'Debit' AND b.dc = 'Credit') OR (a.dc = 'Credit' AND b.dc = 'Debit'))";
        }

        $section = $db->quoteValue($rule->section);

        // Ищем пары через CTE
        $sql = "
            WITH pairs AS (
                SELECT
                    a.id AS id_a,
                    b.id AS id_b,
                    ROW_NUMBER() OVER (PARTITION BY a.id ORDER BY b.id) AS rn_a,
                    ROW_NUMBER() OVER (PARTITION BY b.id ORDER BY a.id) AS rn_b
                FROM nostro_entries a
                JOIN nostro_entries b
                    ON a.account_id = b.account_id
                   AND a.id <> b.id
                   AND {$joinSql}
                   {$dcCondition}
                WHERE
                    a.company_id = {$companyId}
                    AND a.ls = '{$typeA}'
                    AND b.ls = '{$typeB}'
                    AND a.match_status = 'U'
                    AND b.match_status = 'U'
                    {$accountFilter}
            ),
            unique_pairs AS (
                SELECT id_a, id_b
                FROM pairs
                WHERE rn_a = 1 AND rn_b = 1
            )
            SELECT id_a, id_b FROM unique_pairs
        ";

        $pairs = $db->createCommand($sql)->queryAll();

        if (empty($pairs)) {
            return 0;
        }

        $now    = date('Y-m-d H:i:s');
        $userId = Yii::$app->user->id ?? null;
        $count  = 0;

        $transaction = $db->beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $matchId = $this->generateMatchId();
                $db->createCommand()->update('nostro_entries', [
                    'match_id'     => $matchId,
                    'match_status' => 'M',
                    'updated_at'   => $now,
                    'updated_by'   => $userId,
                ], ['id' => [$pair['id_a'], $pair['id_b']]])->execute();
                $count++;
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $count;
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
     * Генерация уникального Match ID вида MTCHxxxxxxxx
     */
    public function generateMatchId(): string
    {
        return 'MTCH' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
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