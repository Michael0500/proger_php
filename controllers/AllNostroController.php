<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\Account;
use app\models\AccountPool;

/**
 * Страница "Выверка по всем ностро-банкам".
 * Показывает записи NostroEntry со всех банков компании с фильтрами,
 * включая мультивыбор ностро-банков.
 */
class AllNostroController extends BaseController
{
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * GET /all-nostro
     * Standalone страница выверки по всем ностро-банкам.
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию.');
            return $this->redirect(['/site/index']);
        }

        $pools = AccountPool::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $initData = [
            'pools' => array_map(function ($p) {
                return ['id' => $p->id, 'name' => $p->name];
            }, $pools),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    /**
     * GET /all-nostro/list
     * Params: page, limit, sort, dir, filters(JSON)
     * filters.pool_ids — массив ID ностро-банков (опционально)
     * filters.account_id — ID счёта (опционально)
     * + все остальные фильтры как в NostroEntryController::actionList
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

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
            ->leftJoin(['ap' => 'account_pools'], 'ap.id = a.pool_id')
            ->where(['ne.company_id' => $cid]);

        // Мультивыбор ностро-банков
        if (isset($filters['pool_ids']) && is_array($filters['pool_ids']) && !empty($filters['pool_ids'])) {
            $poolIds = array_values(array_filter(array_map('intval', $filters['pool_ids'])));
            if (!empty($poolIds)) {
                $q->andWhere(['a.pool_id' => $poolIds]);
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
        if (!empty($filters['post_date_from']))  $q->andWhere(['>=', 'ne.post_date', $filters['post_date_from']]);
        if (!empty($filters['post_date_to']))    $q->andWhere(['<=', 'ne.post_date', $filters['post_date_to']]);

        $total = (int)(clone $q)->count('ne.id');

        $sortExpr = ($sort === 'account_id') ? 'ne.account_id' : "ne.{$sort}";
        $rows = $q
            ->select(['ne.*', 'a.name AS account_name', 'a.is_suspense AS account_is_suspense', 'ap.name AS pool_name'])
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
     * GET /all-nostro/search-accounts?q=&pool_ids[]=
     * Select2 autocomplete счетов. Если заданы pool_ids — фильтрует по ним.
     */
    public function actionSearchAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid     = $this->cid();
        $q       = trim(Yii::$app->request->get('q', ''));
        $poolIds = (array)Yii::$app->request->get('pool_ids', []);
        $poolIds = array_values(array_filter(array_map('intval', $poolIds)));

        $query = Account::find()->where(['company_id' => $cid]);
        if (!empty($poolIds)) {
            $query->andWhere(['pool_id' => $poolIds]);
        }
        if ($q !== '') {
            $query->andWhere(['ilike', 'name', $q]);
        }

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
}
