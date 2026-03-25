<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\Account;

class NostroEntryController extends BaseController
{
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /** company_id текущего пользователя */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * GET /nostro-entry/list
     * Params: pool_id, page, limit, sort, dir, filters(JSON)
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $poolId  = (int)Yii::$app->request->get('pool_id', 0);
        $page    = max(1, (int)Yii::$app->request->get('page', 1));
        $limit   = min(200, max(10, (int)Yii::$app->request->get('limit', 50)));
        $sort    = Yii::$app->request->get('sort', 'id');
        $dirRaw  = Yii::$app->request->get('dir', 'desc');
        $dir     = strtolower($dirRaw) === 'asc' ? SORT_ASC : SORT_DESC;
        $filters = json_decode(Yii::$app->request->get('filters', '{}'), true) ?: [];

        $sortable = ['id','ls','dc','amount','currency','value_date','post_date',
            'instruction_id','end_to_end_id','transaction_id','message_id','other_id',
            'comment','match_status','match_id','account_id'];
        if (!in_array($sort, $sortable, true)) $sort = 'id';

        $q = NostroEntry::find()
            ->from(['ne' => NostroEntry::tableName()])
            ->leftJoin(['a' => 'accounts'], 'a.id = ne.account_id')
            ->where(['ne.company_id' => $cid]);

        // pool_id теперь означает group_id (группа с фильтрами)
        if ($poolId > 0) {
            $group = \app\models\Group::findOne($poolId);

            if (!$group) {
                return ['success' => false, 'message' => 'Группа не найдена'];
            }

            $groupFilters = \app\models\GroupFilter::find()
                ->where(['group_id' => $poolId])
                ->orderBy(['sort_order' => SORT_ASC])
                ->all();

            if (!empty($groupFilters)) {
                // ── Шаг 1: account-фильтры → находим подходящие account_id ─────────
                /** @var \app\models\GroupFilter[] $accountFilters */
                $accountFilters = array_values(array_filter($groupFilters, function($f) { return $f->isAccountField(); }));
                /** @var \app\models\GroupFilter[] $entryFilters */
                $entryFilters   = array_values(array_filter($groupFilters, function($f) { return $f->isEntryField(); }));

                if (!empty($accountFilters)) {
                    $accountQuery = Account::find()
                        ->select('id')
                        ->where(['company_id' => $cid]);

                    $firstAcc = true;
                    foreach ($accountFilters as $pf) {
                        $condition = $pf->buildAccountCondition();
                        if ($condition === null) continue;
                        if ($firstAcc) {
                            $accountQuery->andWhere($condition);
                            $firstAcc = false;
                        } elseif ($pf->logic === 'OR') {
                            $accountQuery->orWhere($condition);
                        } else {
                            $accountQuery->andWhere($condition);
                        }
                    }

                    $accountIds = $accountQuery->column();

                    if (empty($accountIds)) {
                        return ['success' => true, 'data' => [], 'total' => 0,
                            'page' => $page, 'limit' => $limit, 'pages' => 0];
                    }
                    $q->andWhere(['ne.account_id' => $accountIds]);
                }

                // ── Шаг 2: entry-фильтры → применяем к основному запросу ──────────
                $firstEntry = true;
                foreach ($entryFilters as $pf) {
                    $condition = $pf->buildEntryCondition('ne');
                    if ($condition === null) continue;
                    if ($firstEntry) {
                        $q->andWhere($condition);
                        $firstEntry = false;
                    } elseif ($pf->logic === 'OR') {
                        $q->orWhere($condition);
                    } else {
                        $q->andWhere($condition);
                    }
                }
            }
        }


        // Текстовые ILIKE-фильтры
        foreach (['ls','dc','currency','match_status','match_id',
                     'instruction_id','end_to_end_id','transaction_id','message_id','other_id','comment'] as $f) {
            if (isset($filters[$f]) && $filters[$f] !== '') {
                $q->andWhere(['ilike', "ne.$f", $filters[$f]]);
            }
        }
        if (isset($filters['account_id']) && $filters['account_id'] !== '') {
            $q->andWhere(['ne.account_id' => (int)$filters['account_id']]);
        }
        if (isset($filters['amount_min']) && $filters['amount_min'] !== '') {
            $q->andWhere(['>=', 'ne.amount', (float)$filters['amount_min']]);
        }
        if (isset($filters['amount_max']) && $filters['amount_max'] !== '') {
            $q->andWhere(['<=', 'ne.amount', (float)$filters['amount_max']]);
        }
        if (!empty($filters['value_date_from'])) $q->andWhere(['>=', 'ne.value_date', $filters['value_date_from']]);
        if (!empty($filters['value_date_to']))   $q->andWhere(['<=', 'ne.value_date', $filters['value_date_to']]);

        // Считаем total через отдельный запрос
        $total = (int)(clone $q)->count('ne.id');

        $sortExpr = ($sort === 'account_id') ? 'ne.account_id' : "ne.{$sort}";
        $rows = $q
            ->select(['ne.*', 'a.name AS account_name', 'a.is_suspense AS account_is_suspense'])
            ->orderBy([$sortExpr => $dir])
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->asArray()
            ->all();

        return [
            'success' => true,
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'pages'   => (int)ceil($total / max(1, $limit)),
        ];
    }

    /**
     * GET /nostro-entry/search-accounts?pool_id=&q=
     * Для Select2 autocomplete
     */
    public function actionSearchAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid    = $this->cid();
        $poolId = (int)Yii::$app->request->get('pool_id', 0);
        $q      = trim(Yii::$app->request->get('q', ''));

        $query = Account::find()->where(['company_id' => $cid]);
        if ($poolId > 0) $query->andWhere(['pool_id' => $poolId]);
        if ($q !== '')   $query->andWhere(['ilike', 'name', $q]);

        $items = [];
        foreach ($query->orderBy('name')->limit(40)->all() as $acc) {
            $items[] = [
                'id'          => $acc->id,
                'text'        => $acc->name,
                'currency'    => $acc->currency ?? '',
                'is_suspense' => (bool)$acc->is_suspense,
            ];
        }
        return ['results' => $items];
    }

    /** POST /nostro-entry/create */
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $p = Yii::$app->request->post();
        $m = new NostroEntry();
        $m->company_id     = $cid;
        $m->account_id     = (int)($p['account_id'] ?? 0);
        $m->ls             = $p['ls']       ?? 'L';
        $m->dc             = $p['dc']       ?? 'Debit';
        $m->amount         = (float)($p['amount'] ?? 0);
        $m->currency       = strtoupper(trim($p['currency'] ?? 'USD'));
        $m->value_date     = ($p['value_date']     ?? '') ?: null;
        $m->post_date      = ($p['post_date']      ?? '') ?: null;
        $m->instruction_id = ($p['instruction_id'] ?? '') ?: null;
        $m->end_to_end_id  = ($p['end_to_end_id']  ?? '') ?: null;
        $m->transaction_id = ($p['transaction_id'] ?? '') ?: null;
        $m->message_id     = ($p['message_id']     ?? '') ?: null;
        $m->other_id       = ($p['other_id']       ?? '') ?: null;
        $m->comment        = ($p['comment']        ?? '') ?: null;
        $m->match_status   = NostroEntry::STATUS_UNMATCHED;

        if (!$m->save()) return ['success' => false, 'message' => 'Ошибка', 'errors' => $m->errors];

        $row = $m->toArray();
        $acc = Account::findOne($m->account_id);
        $row['account_name']        = $acc ? $acc->name : '—';
        $row['account_is_suspense'] = $acc ? (bool)$acc->is_suspense : false;
        return ['success' => true, 'message' => 'Запись добавлена', 'data' => $row];
    }

    /** POST /nostro-entry/update */
    public function actionUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $p   = Yii::$app->request->post();
        $m   = NostroEntry::findOne(['id' => (int)($p['id'] ?? 0), 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];

        $m->account_id     = (int)($p['account_id']    ?? $m->account_id);
        $m->ls             = $p['ls']                  ?? $m->ls;
        $m->dc             = $p['dc']                  ?? $m->dc;
        $m->amount         = (float)($p['amount']      ?? $m->amount);
        $m->currency       = strtoupper(trim($p['currency'] ?? $m->currency));
        $m->value_date     = ($p['value_date']         ?? '') ?: null;
        $m->post_date      = ($p['post_date']          ?? '') ?: null;
        $m->instruction_id = ($p['instruction_id']     ?? '') ?: null;
        $m->end_to_end_id  = ($p['end_to_end_id']      ?? '') ?: null;
        $m->transaction_id = ($p['transaction_id']     ?? '') ?: null;
        $m->message_id     = ($p['message_id']         ?? '') ?: null;
        $m->other_id       = ($p['other_id']           ?? '') ?: null;
        $m->comment        = ($p['comment']            ?? '') ?: null;

        if (!$m->save()) return ['success' => false, 'message' => 'Ошибка', 'errors' => $m->errors];

        $row = $m->toArray();
        $acc = Account::findOne($m->account_id);
        $row['account_name']        = $acc ? $acc->name : '—';
        $row['account_is_suspense'] = $acc ? (bool)$acc->is_suspense : false;
        return ['success' => true, 'message' => 'Запись обновлена', 'data' => $row];
    }

    /** POST /nostro-entry/delete */
    public function actionDelete(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $m   = NostroEntry::findOne(['id' => (int)Yii::$app->request->post('id'), 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];
        if ($m->match_status === NostroEntry::STATUS_MATCHED)
            return ['success' => false, 'message' => 'Нельзя удалить сквитованную запись'];
        $m->delete();
        return ['success' => true, 'message' => 'Запись удалена'];
    }

    /** POST /nostro-entry/update-comment */
    public function actionUpdateComment(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $m   = NostroEntry::findOne(['id' => (int)Yii::$app->request->post('id'), 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];
        $m->comment = Yii::$app->request->post('comment') ?: null;
        $m->save(false);
        return ['success' => true];
    }

    /**
     * GET /nostro-entry/history?id=
     * Получить историю изменений записи.
     *
     * Логика:
     *  1. Загружаем все аудит-записи (ASC — от старых к новым).
     *  2. Группируем по (created_at до секунды + user_id + action):
     *     одно редактирование может создавать несколько строк (по одной на поле).
     *  3. Реконструируем полный снапшот записи на момент каждого изменения:
     *     идём снизу вверх, накапливая текущее состояние.
     *  4. Возвращаем результат в DESC-порядке (новые сверху).
     */
    public function actionHistory(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $id  = (int)Yii::$app->request->get('id', 0);

        $entry = NostroEntry::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$entry) {
            return ['success' => false, 'message' => 'Запись не найдена'];
        }

        // Загружаем от старых к новым (ASC) — нужно для реконструкции снапшотов
        $audits = \app\models\NostroEntryAudit::find()
            ->where(['entry_id' => $id])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->asArray()
            ->all();

        if (empty($audits)) {
            return ['success' => true, 'data' => []];
        }

        // ── Кэш пользователей (username / email) ─────────────────────────
        $userIds = array_unique(array_column($audits, 'user_id'));
        $users   = [];
        if (!empty($userIds)) {
            $userRows = \app\models\User::find()
                ->select(['id', 'username', 'email'])
                ->where(['id' => $userIds])
                ->asArray()
                ->all();
            foreach ($userRows as $u) {
                $users[$u['id']] = $u['username'] ?: $u['email'];
            }
        }

        // ── Группировка аудитов (по created_at до секунды + user_id + action) ─
        // Ключ группы: "YYYY-MM-DD HH:MM:SS|user_id|action"
        $groups = [];   // ['key' => ['meta' => ..., 'changes' => [field => [old, new]]]]
        $groupOrder = [];

        foreach ($audits as $audit) {
            // Приводим created_at к точности до секунды (обрезаем дробную часть если есть)
            $ts  = substr($audit['created_at'], 0, 19);
            $key = $ts . '|' . $audit['user_id'] . '|' . $audit['action'];

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'        => $key,
                    'action'     => $audit['action'],
                    'user_id'    => $audit['user_id'],
                    'username'   => $users[$audit['user_id']] ?? ('User #' . $audit['user_id']),
                    'reason'     => $audit['reason'],
                    'created_at' => $ts,
                    'changes'    => [],   // field => ['old' => ..., 'new' => ...]
                    // Снапшот всей записи будет добавлен ниже
                    'snapshot'      => [],
                    'changed_fields' => [],
                ];
                $groupOrder[] = $key;
            }

            // Накапливаем изменения в группе
            $field   = $audit['changed_field'];
            $oldVals = $audit['old_values'] ? json_decode($audit['old_values'], true) : null;
            $newVals = $audit['new_values'] ? json_decode($audit['new_values'], true) : null;

            if ($field) {
                $oldV = is_array($oldVals) ? ($oldVals[$field] ?? null) : null;
                $newV = is_array($newVals) ? ($newVals[$field] ?? null) : null;
                $groups[$key]['changes'][$field] = ['old' => $oldV, 'new' => $newV];
                $groups[$key]['changed_fields'][] = $field;
            } elseif ($oldVals || $newVals) {
                // create / delete — берём весь объект
                $groups[$key]['changes'] = array_merge(
                    $groups[$key]['changes'],
                    array_map(function($k) use ($oldVals, $newVals) {
                        return [
                            'old' => is_array($oldVals) ? ($oldVals[$k] ?? null) : null,
                            'new' => is_array($newVals) ? ($newVals[$k] ?? null) : null,
                        ];
                    }, array_unique(array_merge(
                        is_array($oldVals) ? array_keys($oldVals) : [],
                        is_array($newVals) ? array_keys($newVals) : []
                    )))
                );
            }
        }

        // ── Реконструкция снапшотов (от старых к новым) ──────────────────
        // Поля, которые показываем в таблице
        $fields = ['account_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'other_id', 'comment', 'match_status', 'match_id'];

        // Стартовое состояние — текущие данные записи (самое актуальное)
        // Идём от новых к старым, чтобы вычислить снапшоты в обратном порядке.
        // Проще: идём ASC (groupOrder уже ASC), строим «живое» состояние нарастающим итогом.
        $current = [];
        foreach ($fields as $f) {
            $current[$f] = null;
        }

        // Инициализируем из первого события (create) если есть, иначе берём текущую запись
        $firstGroup = $groups[$groupOrder[0]];
        if ($firstGroup['action'] === 'create') {
            foreach ($fields as $f) {
                $v = $firstGroup['changes'][$f]['new'] ?? null;
                $current[$f] = $v;
            }
        } else {
            // Нет события создания — берём данные живой записи и "откатываемся" назад
            // Проще: просто используем current entry как базу, идём ASC, применяем изменения
            foreach ($fields as $f) {
                $current[$f] = $entry->$f ?? null;
            }
            // Откатываем к начальному состоянию: идём по всем аудитам и инвертируем
            foreach (array_reverse($groupOrder) as $key) {
                foreach ($groups[$key]['changes'] as $field => $change) {
                    if (array_key_exists($field, array_flip($fields))) {
                        $current[$field] = $change['old'];
                    }
                }
            }
        }

        // Теперь идём ASC и для каждой группы записываем снапшот ПОСЛЕ применения изменений
        foreach ($groupOrder as $key) {
            $group  = &$groups[$key];
            $action = $group['action'];

            if ($action === 'create') {
                // Снапшот = new_values из create
                foreach ($fields as $f) {
                    $current[$f] = $group['changes'][$f]['new'] ?? $current[$f];
                }
                $group['snapshot'] = $current;
            } elseif ($action === 'delete') {
                // Снапшот = состояние до удаления (old_values)
                $snap = $current;
                foreach ($group['changes'] as $field => $change) {
                    if (in_array($field, $fields, true)) {
                        $snap[$field] = $change['old'];
                    }
                }
                $group['snapshot'] = $snap;
            } else {
                // update / archive — применяем new_values к текущему состоянию
                foreach ($group['changes'] as $field => $change) {
                    if (in_array($field, $fields, true)) {
                        $current[$field] = $change['new'];
                    }
                }
                $group['snapshot'] = $current;
            }
            unset($group);
        }

        // ── Кэш имён счетов ──────────────────────────────────────────────
        $accountIds = [];
        foreach ($groups as $g) {
            if (!empty($g['snapshot']['account_id'])) {
                $accountIds[] = (int)$g['snapshot']['account_id'];
            }
        }
        $accountIds = array_unique($accountIds);
        $accountNames = [];
        if (!empty($accountIds)) {
            $accRows = \app\models\Account::find()
                ->select(['id', 'name'])
                ->where(['id' => $accountIds])
                ->asArray()
                ->all();
            foreach ($accRows as $a) {
                $accountNames[$a['id']] = $a['name'];
            }
        }

        // ── Формируем результат (DESC — новые сверху) ─────────────────────
        $rows = [];
        foreach (array_reverse($groupOrder) as $key) {
            $g = $groups[$key];
            $snap = $g['snapshot'];
            // Добавляем удобочитаемое имя счёта
            if (!empty($snap['account_id'])) {
                $snap['account_name'] = $accountNames[$snap['account_id']] ?? ('ID: ' . $snap['account_id']);
            } else {
                $snap['account_name'] = '—';
            }
            $rows[] = [
                'action'         => $g['action'],
                'user_id'        => $g['user_id'],
                'username'       => $g['username'],
                'reason'         => $g['reason'],
                'created_at'     => $g['created_at'],
                'changed_fields' => array_values(array_unique($g['changed_fields'])),
                'snapshot'       => $snap,
                'changes'        => $g['changes'],
            ];
        }

        return ['success' => true, 'data' => $rows];
    }
}