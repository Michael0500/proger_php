<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\AccountPool;
use app\models\Account;
use app\models\Category;
use app\models\Group;
use app\models\GroupFilter;

/**
 * CRUD для ностро-банков (AccountPool) + управление привязанными счетами.
 * Standalone страница /nostro-banks.
 */
class AccountPoolController extends BaseController
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
     * GET /nostro-banks
     * Standalone страница управления ностро-банками
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

        $categories = Category::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        // Привязки пулов к категориям через GroupFilter
        $poolIds = array_map(function ($p) { return $p->id; }, $pools);
        $poolCategoryMap = [];
        if ($poolIds) {
            $rows = (new \yii\db\Query())
                ->select(['gf.value AS pool_id', 'g.category_id'])
                ->from('{{%group_filters}} gf')
                ->innerJoin('{{%groups}} g', 'g.id = gf.group_id')
                ->where(['gf.field' => 'account_pool_id', 'gf.value' => array_map('strval', $poolIds), 'g.company_id' => $cid])
                ->all();
            foreach ($rows as $row) {
                $poolCategoryMap[(int)$row['pool_id']] = (int)$row['category_id'];
            }
        }

        $initData = [
            'categories' => array_map(function ($c) {
                return ['id' => $c->id, 'name' => $c->name];
            }, $categories),
            'pools' => array_map(function ($p) use ($poolCategoryMap) {
                return [
                    'id'          => $p->id,
                    'name'        => $p->name,
                    'description' => $p->description,
                    'created_at'  => $p->created_at,
                    'category_id' => $poolCategoryMap[$p->id] ?? null,
                    'accounts'    => array_map(function ($a) {
                        return [
                            'id'          => $a->id,
                            'name'        => $a->name,
                            'currency'    => $a->currency ?? null,
                            'is_suspense' => (bool)$a->is_suspense,
                            'date_open'   => $a->date_open,
                            'date_close'  => $a->date_close,
                        ];
                    }, $p->accounts),
                ];
            }, $pools),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    // ── JSON API ─────────────────────────────────────────────────

    /**
     * GET /account-pool/list
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $pools = AccountPool::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        // Найти привязки пулов к категориям через GroupFilter(field='account_pool_id')
        $poolIds = array_map(function ($p) { return $p->id; }, $pools);
        $poolCategoryMap = [];
        if ($poolIds) {
            $rows = (new \yii\db\Query())
                ->select(['gf.value AS pool_id', 'g.category_id'])
                ->from('{{%group_filters}} gf')
                ->innerJoin('{{%groups}} g', 'g.id = gf.group_id')
                ->where(['gf.field' => 'account_pool_id', 'gf.value' => array_map('strval', $poolIds), 'g.company_id' => $cid])
                ->all();
            foreach ($rows as $row) {
                $poolCategoryMap[(int)$row['pool_id']] = (int)$row['category_id'];
            }
        }

        $data = array_map(function ($p) use ($poolCategoryMap) {
            return [
                'id'          => $p->id,
                'name'        => $p->name,
                'description' => $p->description,
                'created_at'  => $p->created_at,
                'category_id' => $poolCategoryMap[$p->id] ?? null,
                'accounts'    => array_map(function ($a) {
                    return [
                        'id'          => $a->id,
                        'name'        => $a->name,
                        'currency'    => $a->currency ?? null,
                        'is_suspense' => (bool)$a->is_suspense,
                        'date_open'   => $a->date_open,
                        'date_close'  => $a->date_close,
                    ];
                }, $p->accounts),
            ];
        }, $pools);

        return ['success' => true, 'data' => $data];
    }

    /**
     * POST /account-pool/create
     * Дополнительно принимает:
     *   ledger_accounts[]   — ID счетов с load_status='L' для привязки
     *   statement_accounts[] — ID счетов с load_status='S' для привязки
     *   category_id          — (необязательно) создать Group+GroupFilter в этой категории
     */
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $req            = Yii::$app->request;
        $ledgerIds      = array_filter(array_map('intval', (array)$req->post('ledger_accounts', [])));
        $statementIds   = array_filter(array_map('intval', (array)$req->post('statement_accounts', [])));
        $categoryId     = $req->post('category_id') ? (int)$req->post('category_id') : null;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $model = new AccountPool();
            $model->company_id  = $cid;
            $model->name        = $req->post('name');
            $model->description = $req->post('description');

            if (!$model->save()) {
                $transaction->rollBack();
                return ['success' => false, 'message' => 'Ошибка создания', 'errors' => $model->errors];
            }

            // Привязка счетов (только те, что принадлежат компании и не привязаны к другому банку)
            $allAccountIds = array_merge($ledgerIds, $statementIds);
            if ($allAccountIds) {
                Account::updateAll(
                    ['pool_id' => $model->id],
                    ['id' => $allAccountIds, 'company_id' => $cid, 'pool_id' => null]
                );
            }

            // Создание группы в категории с фильтром по этому ностробанку
            if ($categoryId) {
                $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
                if (!$category) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Категория не найдена'];
                }

                $group = new Group();
                $group->company_id  = $cid;
                $group->category_id = $categoryId;
                $group->name        = $model->name;
                $group->is_active   = true;
                if (!$group->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Ошибка создания группы', 'errors' => $group->errors];
                }

                $filter = new GroupFilter();
                $filter->group_id   = $group->id;
                $filter->field      = 'account_pool_id';
                $filter->operator   = 'eq';
                $filter->value      = (string)$model->id;
                $filter->logic      = 'AND';
                $filter->sort_order = 0;
                if (!$filter->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Ошибка создания фильтра группы', 'errors' => $filter->errors];
                }
            }

            $transaction->commit();
            return ['success' => true, 'message' => 'Ностро-банк создан', 'data' => ['id' => $model->id, 'name' => $model->name]];

        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Внутренняя ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * POST /account-pool/update
     * Дополнительно принимает:
     *   ledger_accounts[]    — ID новых счетов типа L для привязки
     *   statement_accounts[] — ID новых счетов типа S для привязки
     *   category_id          — (необязательно) создать Group+GroupFilter в этой категории
     */
    public function actionUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $req  = Yii::$app->request;
        $id   = (int)$req->post('id');
        $model = AccountPool::findOne(['id' => $id, 'company_id' => $cid]);

        if (!$model) return ['success' => false, 'message' => 'Ностро-банк не найден'];

        $ledgerIds    = array_filter(array_map('intval', (array)$req->post('ledger_accounts', [])));
        $statementIds = array_filter(array_map('intval', (array)$req->post('statement_accounts', [])));
        $categoryId   = $req->post('category_id') ? (int)$req->post('category_id') : null;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $model->name        = $req->post('name', $model->name);
            $model->description = $req->post('description', $model->description);

            if (!$model->save()) {
                $transaction->rollBack();
                return ['success' => false, 'message' => 'Ошибка обновления', 'errors' => $model->errors];
            }

            // Синхронизация привязок: отвязать убранные, привязать новые
            $newAccountIds = array_merge($ledgerIds, $statementIds);

            // Отвязать счета, которых нет в новом списке
            $condition = ['pool_id' => $model->id, 'company_id' => $cid];
            if ($newAccountIds) {
                $condition = ['and', $condition, ['not in', 'id', $newAccountIds]];
            }
            Account::updateAll(['pool_id' => null], $condition);

            // Привязать новые (свободные или уже принадлежащие этому пулу)
            if ($newAccountIds) {
                Account::updateAll(
                    ['pool_id' => $model->id],
                    ['and',
                        ['id' => $newAccountIds, 'company_id' => $cid],
                        ['or', ['pool_id' => null], ['pool_id' => $model->id]],
                    ]
                );
            }

            // Создание группы в категории
            if ($categoryId) {
                $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
                if (!$category) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Категория не найдена'];
                }

                $group = new Group();
                $group->company_id  = $cid;
                $group->category_id = $categoryId;
                $group->name        = $model->name;
                $group->is_active   = true;
                if (!$group->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Ошибка создания группы', 'errors' => $group->errors];
                }

                $filter = new GroupFilter();
                $filter->group_id   = $group->id;
                $filter->field      = 'account_pool_id';
                $filter->operator   = 'eq';
                $filter->value      = (string)$model->id;
                $filter->logic      = 'AND';
                $filter->sort_order = 0;
                if (!$filter->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Ошибка создания фильтра группы', 'errors' => $filter->errors];
                }
            }

            $transaction->commit();
            return ['success' => true, 'message' => 'Ностро-банк обновлён'];

        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Внутренняя ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * POST /account-pool/delete
     */
    public function actionDelete(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id    = Yii::$app->request->post('id');
        $model = AccountPool::findOne(['id' => $id, 'company_id' => $cid]);

        if (!$model) return ['success' => false, 'message' => 'Ностро-банк не найден'];

        // Отвязываем все счета
        Account::updateAll(['pool_id' => null], ['pool_id' => $id]);

        if ($model->delete()) {
            return ['success' => true, 'message' => 'Ностро-банк удалён'];
        }

        return ['success' => false, 'message' => 'Ошибка удаления'];
    }

    /**
     * GET /account-pool/get-accounts?id=X
     * Счета конкретного ностро-банка
     */
    public function actionGetAccounts($id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $pool = AccountPool::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$pool) return ['success' => false, 'message' => 'Ностро-банк не найден'];

        $accounts = Account::find()
            ->where(['pool_id' => $id, 'company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $data = array_map(function ($a) {
            return [
                'id'          => $a->id,
                'name'        => $a->name,
                'currency'    => $a->currency ?? null,
                'is_suspense' => (bool)$a->is_suspense,
                'date_open'   => $a->date_open,
                'date_close'  => $a->date_close,
            ];
        }, $accounts);

        return ['success' => true, 'pool_name' => $pool->name, 'data' => $data];
    }

    /**
     * GET /account-pool/available-accounts
     * Счета компании, не привязанные ни к одному ностро-банку.
     * Если передан pool_id — включает также счета этого пула (для формы редактирования).
     */
    public function actionAvailableAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $poolId = Yii::$app->request->get('pool_id') ? (int)Yii::$app->request->get('pool_id') : null;

        $query = Account::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC]);

        if ($poolId) {
            $query->andWhere(['or', ['pool_id' => null], ['pool_id' => $poolId]]);
        } else {
            $query->andWhere(['pool_id' => null]);
        }

        $accounts = $query->all();

        $data = array_map(function ($a) use ($poolId) {
            return [
                'id'           => $a->id,
                'name'         => $a->name,
                'currency'     => $a->currency ?? null,
                'account_type' => $a->account_type ?? '',
                'assigned'     => $poolId && (int)$a->pool_id === $poolId,
            ];
        }, $accounts);

        return ['success' => true, 'data' => $data];
    }

    /**
     * POST /account-pool/assign-account
     * Привязать счёт к ностро-банку
     * Body: { pool_id, account_id }
     */
    public function actionAssignAccount(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $poolId    = (int)Yii::$app->request->post('pool_id');
        $accountId = (int)Yii::$app->request->post('account_id');

        $pool = AccountPool::findOne(['id' => $poolId, 'company_id' => $cid]);
        if (!$pool) return ['success' => false, 'message' => 'Ностро-банк не найден'];

        $account = Account::findOne(['id' => $accountId, 'company_id' => $cid]);
        if (!$account) return ['success' => false, 'message' => 'Счёт не найден'];

        $account->pool_id = $poolId;
        if ($account->save(false)) {
            return ['success' => true, 'message' => 'Счёт привязан к ностро-банку'];
        }

        return ['success' => false, 'message' => 'Ошибка привязки'];
    }

    /**
     * POST /account-pool/unassign-account
     * Отвязать счёт от ностро-банка
     * Body: { account_id }
     */
    public function actionUnassignAccount(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $accountId = (int)Yii::$app->request->post('account_id');
        $account   = Account::findOne(['id' => $accountId, 'company_id' => $cid]);

        if (!$account) return ['success' => false, 'message' => 'Счёт не найден'];

        $account->pool_id = null;
        if ($account->save(false)) {
            return ['success' => true, 'message' => 'Счёт отвязан'];
        }

        return ['success' => false, 'message' => 'Ошибка отвязки'];
    }
}
