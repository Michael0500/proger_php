<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\AccountPool;
use app\models\Account;

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

        $initData = [
            'pools' => array_map(function ($p) {
                return [
                    'id'          => $p->id,
                    'name'        => $p->name,
                    'description' => $p->description,
                    'created_at'  => $p->created_at,
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

        $data = array_map(function ($p) {
            return [
                'id'          => $p->id,
                'name'        => $p->name,
                'description' => $p->description,
                'created_at'  => $p->created_at,
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
     */
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $model = new AccountPool();
        $model->company_id  = $cid;
        $model->name        = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');

        if ($model->save()) {
            return ['success' => true, 'message' => 'Ностро-банк создан', 'data' => ['id' => $model->id, 'name' => $model->name]];
        }

        return ['success' => false, 'message' => 'Ошибка создания', 'errors' => $model->errors];
    }

    /**
     * POST /account-pool/update
     */
    public function actionUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id    = Yii::$app->request->post('id');
        $model = AccountPool::findOne(['id' => $id, 'company_id' => $cid]);

        if (!$model) return ['success' => false, 'message' => 'Ностро-банк не найден'];

        $model->name        = Yii::$app->request->post('name', $model->name);
        $model->description = Yii::$app->request->post('description', $model->description);

        if ($model->save()) {
            return ['success' => true, 'message' => 'Ностро-банк обновлён'];
        }

        return ['success' => false, 'message' => 'Ошибка обновления', 'errors' => $model->errors];
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
     * Счета компании, не привязанные ни к одному ностро-банку
     */
    public function actionAvailableAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $accounts = Account::find()
            ->where(['company_id' => $cid, 'pool_id' => null])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $data = array_map(function ($a) {
            return [
                'id'       => $a->id,
                'name'     => $a->name,
                'currency' => $a->currency ?? null,
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
