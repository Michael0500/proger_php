<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\AccountPool;
use app\models\Account;
use app\models\Category;

/**
 * Контроллер ностро-банков (`AccountPool`).
 *
 * Управляет standalone-страницей `/nostro-banks`, CRUD ностро-банков,
 * привязкой/отвязкой счетов и перемещением ностро-банков по категориям.
 * Все операции выполняются в компании текущего пользователя.
 */
class AccountPoolController extends BaseController
{
    /**
     * Отключает CSRF для JSON API ностро-банков.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool Можно ли продолжать выполнение action.
     */
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Возвращает ID компании текущего пользователя.
     *
     * @return int|null ID компании или `null`.
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Рендерит standalone-страницу управления ностро-банками.
     *
     * GET `/nostro-banks`. Передаёт во Vue категории и текущие ностро-банки
     * с привязанными счетами.
     *
     * @return string|\yii\web\Response HTML-страница или redirect на выбор компании.
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

    /**
     * Сериализует ностро-банк для JSON API и начального состояния Vue.
     *
     * @param AccountPool $p Ностро-банк.
     * @return array Данные ностро-банка и привязанных счетов.
     */
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
     * Возвращает список ностро-банков текущей компании.
     *
     * GET `/account-pool/list`.
     *
     * @return array JSON со списком ностро-банков.
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
     * Создаёт ностро-банк и привязывает выбранные счета.
     *
     * POST `/account-pool/create`. Принимает `ledger_accounts[]`,
     * `statement_accounts[]` и опциональный `category_id`. Операция выполняется
     * в транзакции; привязываются только свободные счета текущей компании.
     *
     * @return array JSON-результат создания.
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
     * Обновляет ностро-банк и синхронизирует привязки счетов.
     *
     * POST `/account-pool/update`. Операция в транзакции отвязывает удалённые
     * из формы счета и привязывает новые свободные или уже принадлежащие этому
     * ностро-банку счета.
     *
     * @return array JSON-результат обновления.
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
     * Удаляет ностро-банк текущей компании.
     *
     * POST `/account-pool/delete`. Перед удалением отвязывает все счета
     * этого ностро-банка.
     *
     * @return array JSON-результат удаления.
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
     * Возвращает счета конкретного ностро-банка.
     *
     * GET `/account-pool/get-accounts?id=`.
     *
     * @param int|string $id ID ностро-банка.
     * @return array JSON со счетами или ошибкой доступа.
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
     * Возвращает счета, доступные для привязки к ностро-банку.
     *
     * GET `/account-pool/available-accounts`. Без `pool_id` возвращает
     * свободные счета; с `pool_id` включает счета этого пула для формы редактирования.
     *
     * @return array JSON со счетами и признаком `assigned`.
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
     * Привязывает счёт к ностро-банку.
     *
     * POST `/account-pool/assign-account`, body: `pool_id`, `account_id`.
     *
     * @return array JSON-результат привязки.
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
     * Отвязывает счёт от ностро-банка.
     *
     * POST `/account-pool/unassign-account`, body: `account_id`.
     *
     * @return array JSON-результат отвязки.
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
     * Быстро создаёт ностро-банк из сайдбара выверки.
     *
     * POST `/account-pool/quick-create`. Операция в транзакции создаёт
     * `AccountPool`, опционально привязывает категорию и свободные счета.
     *
     * @return array JSON с минимальными данными созданного ностро-банка.
     */
    public function actionQuickCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $req          = Yii::$app->request;
        $name         = trim((string)$req->post('name', ''));
        $description  = trim((string)$req->post('description', ''));
        $rawCategory  = $req->post('category_id', '');
        $categoryId   = ($rawCategory !== '' && $rawCategory !== null) ? (int)$rawCategory : null;
        $ledgerIds    = array_filter(array_map('intval', (array)$req->post('ledger_accounts', [])));
        $statementIds = array_filter(array_map('intval', (array)$req->post('statement_accounts', [])));

        if ($name === '') {
            return ['success' => false, 'message' => 'Название обязательно'];
        }

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
            $model->name        = $name;
            $model->description = $description !== '' ? $description : null;

            if (!$model->save()) {
                $transaction->rollBack();
                return ['success' => false, 'message' => 'Ошибка создания', 'errors' => $model->errors];
            }

            $allAccountIds = array_merge($ledgerIds, $statementIds);
            if ($allAccountIds) {
                Account::updateAll(
                    ['pool_id' => $model->id],
                    ['id' => $allAccountIds, 'company_id' => $cid, 'pool_id' => null]
                );
            }

            $transaction->commit();

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
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Внутренняя ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Перемещает ностро-банк в категорию или открепляет от неё.
     *
     * POST `/account-pool/move-to-category`, body: `id`, `category_id`.
     *
     * @return array JSON с новым `category_id`.
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
