<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\AccountPool;
use app\models\Account;
use app\models\Category;

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

        $initData = [
            'categories' => array_map(function (Category $c) {
                return ['id' => $c->id, 'name' => $c->name];
            }, $categories),
            'pools' => array_map(function (AccountPool $p) {
                return $this->serializePool($p);
            }, $pools),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    private function serializePool(AccountPool $p): array
    {
        return [
            'id'          => $p->id,
            'name'        => $p->name,
            'description' => $p->description,
            'created_at'  => $p->created_at,
            'category_id' => $p->category_id ? (int)$p->category_id : null,
            'accounts'    => array_map(function (Account $a) {
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

        $data = array_map(function (AccountPool $p) {
            return $this->serializePool($p);
        }, $pools);

        return ['success' => true, 'data' => $data];
    }

    /**
     * POST /account-pool/create
     * Дополнительно принимает:
     *   ledger_accounts[]    — ID счетов типа L для привязки
     *   statement_accounts[] — ID счетов типа S для привязки
     *   category_id          — (необязательно) ID категории для прямой привязки
     */
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $req          = Yii::$app->request;
        $ledgerIds    = array_filter(array_map('intval', (array)$req->post('ledger_accounts', [])));
        $statementIds = array_filter(array_map('intval', (array)$req->post('statement_accounts', [])));
        $categoryId   = $req->post('category_id') !== '' && $req->post('category_id') !== null
            ? (int)$req->post('category_id') : null;

        if ($categoryId !== null) {
            $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
            if (!$category) {
                return ['success' => false, 'message' => 'Категория не найдена'];
            }
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $model = new AccountPool();
            $model->company_id  = $cid;
            $model->category_id = $categoryId;
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
     *   category_id          — ID категории (пусто/null = без категории)
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

        $hasCategoryParam = $req->post('category_id') !== null;
        $rawCategoryId    = (string)$req->post('category_id', '');
        $categoryId       = ($hasCategoryParam && $rawCategoryId !== '') ? (int)$rawCategoryId : null;

        if ($hasCategoryParam && $categoryId !== null) {
            $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
            if (!$category) {
                return ['success' => false, 'message' => 'Категория не найдена'];
            }
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $model->name        = $req->post('name', $model->name);
            $model->description = $req->post('description', $model->description);
            if ($hasCategoryParam) {
                $model->category_id = $categoryId;
            }

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

    /**
     * POST /account-pool/quick-create
     * Быстрое создание ностро-банка из сайдбара выверки.
     * Body: { name, description?, category_id? }
     */
    public function actionQuickCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $req         = Yii::$app->request;
        $name        = trim((string)$req->post('name', ''));
        $description = trim((string)$req->post('description', ''));
        $rawCategory = $req->post('category_id', '');
        $categoryId  = ($rawCategory !== '' && $rawCategory !== null) ? (int)$rawCategory : null;

        if ($name === '') {
            return ['success' => false, 'message' => 'Название обязательно'];
        }

        if ($categoryId !== null) {
            $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
            if (!$category) {
                return ['success' => false, 'message' => 'Категория не найдена'];
            }
        }

        $model = new AccountPool();
        $model->company_id  = $cid;
        $model->category_id = $categoryId;
        $model->name        = $name;
        $model->description = $description !== '' ? $description : null;

        if (!$model->save()) {
            return ['success' => false, 'message' => 'Ошибка создания', 'errors' => $model->errors];
        }

        return [
            'success' => true,
            'message' => 'Ностро-банк создан',
            'data'    => [
                'id'          => (int)$model->id,
                'name'        => $model->name,
                'description' => $model->description,
                'category_id' => $model->category_id ? (int)$model->category_id : null,
            ],
        ];
    }

    /**
     * POST /account-pool/move-to-category
     * Переместить ностро-банк в другую категорию (или открепить).
     * Body: { id, category_id (число или пусто/null для открепления) }
     */
    public function actionMoveToCategory(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $req         = Yii::$app->request;
        $id          = (int)$req->post('id');
        $rawCategory = $req->post('category_id', '');
        $categoryId  = ($rawCategory !== '' && $rawCategory !== null) ? (int)$rawCategory : null;

        $model = AccountPool::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$model) return ['success' => false, 'message' => 'Ностро-банк не найден'];

        if ($categoryId !== null) {
            $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
            if (!$category) {
                return ['success' => false, 'message' => 'Категория не найдена'];
            }
        }

        $model->category_id = $categoryId;
        if (!$model->save(false)) {
            return ['success' => false, 'message' => 'Ошибка перемещения'];
        }

        return [
            'success' => true,
            'message' => $categoryId === null
                ? 'Ностро-банк откреплён от категории'
                : 'Ностро-банк перемещён',
            'data'    => [
                'id'          => (int)$model->id,
                'category_id' => $model->category_id ? (int)$model->category_id : null,
            ],
        ];
    }
}
