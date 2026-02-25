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
            'instruction_id','end_to_end_id','transaction_id','message_id',
            'comment','match_status','match_id','account_id'];
        if (!in_array($sort, $sortable, true)) $sort = 'id';

        $q = NostroEntry::find()
            ->from(['ne' => NostroEntry::tableName()])
            ->leftJoin(['a' => 'accounts'], 'a.id = ne.account_id')
            ->where(['ne.company_id' => $cid]);

        if ($poolId > 0) {
            $pool = \app\models\AccountPool::findOne($poolId);

            if (!$pool) {
                return ['success' => false, 'message' => 'Пул не найден'];
            }

            // Загружаем строки фильтров пула из отдельной таблицы
            $poolFilters = \app\models\AccountPoolFilter::find()
                ->where(['pool_id' => $poolId])
                ->orderBy(['sort_order' => SORT_ASC])
                ->all();

            if (!empty($poolFilters)) {
                // Строим subquery счетов, удовлетворяющих фильтрам
                $accountQuery = Account::find()
                    ->select('id')
                    ->where(['company_id' => $cid]);

                $first = true;
                foreach ($poolFilters as $pf) {
                    /** @var \app\models\AccountPoolFilter $pf */
                    $condition = $pf->buildCondition();

                    if ($first) {
                        $accountQuery->andWhere($condition);
                        $first = false;
                    } elseif ($pf->logic === 'OR') {
                        $accountQuery->orWhere($condition);
                    } else {
                        $accountQuery->andWhere($condition);
                    }
                }

                $accountIds = $accountQuery->column();

                if (!empty($accountIds)) {
                    $q->andWhere(['ne.account_id' => $accountIds]);
                } else {
                    // Ни один счёт не прошёл фильтры — возвращаем пустой результат
                    return [
                        'success' => true,
                        'data'    => [],
                        'total'   => 0,
                        'page'    => $page,
                        'limit'   => $limit,
                        'pages'   => 0,
                    ];
                }
            }
        }


        // Текстовые ILIKE-фильтры
        foreach (['ls','dc','currency','match_status','match_id',
                     'instruction_id','end_to_end_id','transaction_id','message_id','comment'] as $f) {
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
}