<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\AccessControl;
use app\models\User;
use app\models\Company;
use app\models\Account;
use app\models\AccountPool;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

class UserController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
        ];
    }

    public function actionIndex()
    {
        $dataProvider = new ActiveDataProvider([
            'query'      => User::find(),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    /**
     * Страница профиля — передаём начальные данные в Vue через JSON в <script>
     */
    public function actionView($id)
    {
        $model = $this->findModel($id);

        if ($model->id != Yii::$app->user->id && !Yii::$app->user->can('admin')) {
            throw new \yii\web\ForbiddenHttpException('Нет доступа.');
        }

        $companies = Company::getAllCompanies();

        $pools = $model->company_id
            ? AccountPool::find()
                ->where(['company_id' => $model->company_id])
                ->orderBy(['name' => SORT_ASC])
                ->all()
            : [];

        $accounts = $model->company_id
            ? Account::find()
                ->with('pool')
                ->where(['company_id' => $model->company_id])
                ->orderBy(['name' => SORT_ASC])
                ->all()
            : [];

        return $this->render('view', compact('model', 'companies', 'pools', 'accounts'));
    }

    // ── JSON API ──────────────────────────────────────────────────────

    /** GET /user/get-pools */
    public function actionGetPools()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = Yii::$app->user->identity;
        if (!$user->company_id) return ['success' => true, 'data' => []];

        $rows = AccountPool::find()
            ->where(['company_id' => $user->company_id])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return [
            'success' => true,
            'data'    => array_map(fn($p) => ['id' => $p->id, 'name' => $p->name], $rows),
        ];
    }

    /** GET /user/get-accounts */
    public function actionGetAccounts()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = Yii::$app->user->identity;
        if (!$user->company_id) return ['success' => true, 'data' => []];

        $rows = Account::find()
            ->with('pool')
            ->where(['company_id' => $user->company_id])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return [
            'success' => true,
            'data'    => array_map(fn($a) => [
                'id'          => $a->id,
                'name'        => $a->name,
                'currency'    => $a->currency,
                'is_suspense' => (bool) $a->is_suspense,
                'pool_id'     => $a->pool_id,
                'pool_name'   => $a->pool ? $a->pool->name : null,
            ], $rows),
        ];
    }

    /** POST /user/select-company */
    public function actionSelectCompany()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $id      = (int) Yii::$app->request->post('id');
        $company = Company::findOne($id);
        if (!$company) return ['success' => false, 'message' => 'Компания не найдена'];

        $user = Yii::$app->user->identity;
        $user->setCompany($company->id);

        return ['success' => true, 'message' => "Компания «{$company->name}» выбрана"];
    }

    /** POST /user/reset-company */
    public function actionResetCompany()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = Yii::$app->user->identity;
        $user->setCompany(null);
        return ['success' => true, 'message' => 'Компания сброшена'];
    }

    /** POST /user/create-account */
    public function actionCreateAccount()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = Yii::$app->user->identity;

        $model             = new Account();
        $model->company_id = (int) $user->company_id;
        $model->pool_id    = (int) Yii::$app->request->post('pool_id');
        $model->name       = trim(Yii::$app->request->post('name', ''));
        $model->currency   = strtoupper(trim(Yii::$app->request->post('currency', ''))) ?: null;
        $model->is_suspense = Yii::$app->request->post('is_suspense') == '1';

        if ($model->save()) {
            $model->refresh();
            return [
                'success' => true,
                'message' => "Счёт «{$model->name}» добавлен",
                'data'    => [
                    'id'          => $model->id,
                    'name'        => $model->name,
                    'currency'    => $model->currency,
                    'is_suspense' => (bool) $model->is_suspense,
                    'pool_id'     => $model->pool_id,
                    'pool_name'   => $model->pool ? $model->pool->name : null,
                ],
            ];
        }

        $errors = [];
        foreach ($model->errors as $msgs) {
            foreach ($msgs as $m) $errors[] = $m;
        }
        return ['success' => false, 'message' => implode('; ', $errors)];
    }

    /** POST /user/delete-account */
    public function actionDeleteAccount()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $id    = (int) Yii::$app->request->post('id');
        $model = Account::findOne($id);
        if (!$model) return ['success' => false, 'message' => 'Счёт не найден'];

        $name = $model->name;
        $model->delete();
        return ['success' => true, 'message' => "Счёт «{$name}» удалён"];
    }

    // ─────────────────────────────────────────────────────────────────

    protected function findModel($id)
    {
        if (($model = User::findOne($id)) !== null) return $model;
        throw new NotFoundHttpException('Пользователь не найден.');
    }
}