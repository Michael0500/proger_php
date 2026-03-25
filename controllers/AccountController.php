<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use yii\web\NotFoundHttpException;
use app\models\Account;
use app\models\AccountPool;

class AccountController extends BaseController
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
     * GET /accounts
     * Standalone-страница управления счетами
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию.');
            return $this->redirect(['/site/index']);
        }

        $accounts = Account::find()
            ->with('pool')
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $pools = AccountPool::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $initData = [
            'accounts' => array_map(function ($a) {
                return [
                    'id'           => $a->id,
                    'name'         => $a->name,
                    'currency'     => $a->currency,
                    'account_type' => $a->account_type,
                    'country'      => $a->country,
                    'is_suspense'  => (bool)$a->is_suspense,
                    'load_barsgl'  => (bool)$a->load_barsgl,
                    'load_status'  => $a->load_status,
                    'date_open'    => $a->date_open ? date('Y-m-d', strtotime($a->date_open)) : null,
                    'date_close'   => $a->date_close ? date('Y-m-d', strtotime($a->date_close)) : null,
                    'pool_id'      => $a->pool_id,
                    'pool_name'    => $a->pool ? $a->pool->name : null,
                ];
            }, $accounts),
            'pools' => array_map(function ($p) {
                return ['id' => $p->id, 'name' => $p->name];
            }, $pools),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    // ── JSON API ─────────────────────────────────────────────────

    /**
     * GET /account/list
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $poolId = Yii::$app->request->get('pool_id');
        $query = Account::find()->with('pool')->where(['company_id' => $cid]);
        if ($poolId) $query->andWhere(['pool_id' => (int)$poolId]);

        $rows = $query->orderBy(['name' => SORT_ASC])->all();

        return [
            'success' => true,
            'data' => array_map(function ($a) {
                return [
                    'id'           => $a->id,
                    'name'         => $a->name,
                    'currency'     => $a->currency,
                    'account_type' => $a->account_type,
                    'country'      => $a->country,
                    'is_suspense'  => (bool)$a->is_suspense,
                    'load_barsgl'  => (bool)$a->load_barsgl,
                    'load_status'  => $a->load_status,
                    'date_open'    => $a->date_open ? date('Y-m-d', strtotime($a->date_open)) : null,
                    'date_close'   => $a->date_close ? date('Y-m-d', strtotime($a->date_close)) : null,
                    'pool_id'      => $a->pool_id,
                    'pool_name'    => $a->pool ? $a->pool->name : null,
                ];
            }, $rows),
        ];
    }

    /**
     * POST /account/create
     */
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $r = Yii::$app->request;
        $model = new Account();
        $model->company_id   = $cid;
        $model->name         = trim($r->post('name', ''));
        $model->currency     = strtoupper(trim($r->post('currency', ''))) ?: null;
        $model->account_type = trim($r->post('account_type', '')) ?: null;
        $model->country      = trim($r->post('country', '')) ?: null;
        $model->is_suspense  = $r->post('is_suspense') == '1';
        $model->load_barsgl  = $r->post('load_barsgl') == '1';
        $model->load_status  = $r->post('load_status', 'L') ?: 'L';
        $model->date_open    = $r->post('date_open') ?: null;
        $model->date_close   = $r->post('date_close') ?: null;

        $poolId = $r->post('pool_id');
        $model->pool_id = ($poolId !== '' && $poolId !== null) ? (int)$poolId : null;

        if ($model->save()) {
            $model->refresh();
            return [
                'success' => true,
                'message' => "Счёт «{$model->name}» создан",
                'data' => [
                    'id'           => $model->id,
                    'name'         => $model->name,
                    'currency'     => $model->currency,
                    'account_type' => $model->account_type,
                    'country'      => $model->country,
                    'is_suspense'  => (bool)$model->is_suspense,
                    'load_barsgl'  => (bool)$model->load_barsgl,
                    'load_status'  => $model->load_status,
                    'date_open'    => $model->date_open ? date('Y-m-d', strtotime($model->date_open)) : null,
                    'date_close'   => $model->date_close ? date('Y-m-d', strtotime($model->date_close)) : null,
                    'pool_id'      => $model->pool_id,
                    'pool_name'    => $model->pool ? $model->pool->name : null,
                ],
            ];
        }

        $errors = [];
        foreach ($model->errors as $msgs) foreach ($msgs as $m) $errors[] = $m;
        return ['success' => false, 'message' => implode('; ', $errors)];
    }

    /**
     * POST /account/update
     */
    public function actionUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $r = Yii::$app->request;

        $id = (int)$r->post('id');
        $model = Account::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$model) return ['success' => false, 'message' => 'Счёт не найден'];

        $model->name         = trim($r->post('name', $model->name));
        $model->currency     = strtoupper(trim($r->post('currency', ''))) ?: $model->currency;
        $model->account_type = trim($r->post('account_type', '')) ?: null;
        $model->country      = trim($r->post('country', '')) ?: null;
        $model->is_suspense  = $r->post('is_suspense') == '1';
        $model->load_barsgl  = $r->post('load_barsgl') == '1';
        $model->load_status  = $r->post('load_status', $model->load_status) ?: $model->load_status;
        $model->date_open    = $r->post('date_open') ?: null;
        $model->date_close   = $r->post('date_close') ?: null;

        $poolId = $r->post('pool_id');
        $model->pool_id = ($poolId !== '' && $poolId !== null) ? (int)$poolId : null;

        if ($model->save()) {
            $model->refresh();
            return [
                'success' => true,
                'message' => 'Счёт обновлён',
                'data' => [
                    'id'           => $model->id,
                    'name'         => $model->name,
                    'currency'     => $model->currency,
                    'account_type' => $model->account_type,
                    'country'      => $model->country,
                    'is_suspense'  => (bool)$model->is_suspense,
                    'load_barsgl'  => (bool)$model->load_barsgl,
                    'load_status'  => $model->load_status,
                    'date_open'    => $model->date_open ? date('Y-m-d', strtotime($model->date_open)) : null,
                    'date_close'   => $model->date_close ? date('Y-m-d', strtotime($model->date_close)) : null,
                    'pool_id'      => $model->pool_id,
                    'pool_name'    => $model->pool ? $model->pool->name : null,
                ],
            ];
        }

        $errors = [];
        foreach ($model->errors as $msgs) foreach ($msgs as $m) $errors[] = $m;
        return ['success' => false, 'message' => implode('; ', $errors)];
    }

    /**
     * POST /account/delete
     */
    public function actionDelete(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id = (int)Yii::$app->request->post('id');
        $model = Account::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$model) return ['success' => false, 'message' => 'Счёт не найден'];

        $name = $model->name;
        if ($model->delete()) {
            return ['success' => true, 'message' => "Счёт «{$name}» удалён"];
        }
        return ['success' => false, 'message' => 'Ошибка удаления'];
    }
}
