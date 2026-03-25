<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\AccessControl;
use app\models\User;
use app\models\Company;
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

        return $this->render('view', compact('model', 'companies'));
    }

    // ── JSON API ──────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────

    protected function findModel($id)
    {
        if (($model = User::findOne($id)) !== null) return $model;
        throw new NotFoundHttpException('Пользователь не найден.');
    }
}