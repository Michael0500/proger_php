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

/**
 * Контроллер пользователей и выбора компании в профиле.
 *
 * Отвечает за список пользователей, страницу профиля и JSON API установки
 * `company_id` для текущего пользователя.
 */
class UserController extends Controller
{
    /**
     * Разрешает доступ только авторизованным пользователям.
     *
     * @return array Конфигурация фильтров доступа.
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
        ];
    }

    /**
     * Рендерит список пользователей.
     *
     * @return string HTML страницы списка.
     */
    public function actionIndex()
    {
        $dataProvider = new ActiveDataProvider([
            'query'      => User::find(),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    /**
     * Рендерит страницу профиля пользователя.
     *
     * Пользователь может смотреть только свой профиль, кроме пользователей
     * с правом `admin`.
     *
     * @param int|string $id ID пользователя.
     * @return string HTML страницы профиля.
     * @throws \yii\web\ForbiddenHttpException Если нет доступа к чужому профилю.
     * @throws NotFoundHttpException Если пользователь не найден.
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

    /**
     * Устанавливает компанию текущего пользователя из профиля.
     *
     * POST `/user/select-company`.
     *
     * @return array JSON-результат выбора компании.
     */
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

    /**
     * Сбрасывает компанию текущего пользователя.
     *
     * POST `/user/reset-company`.
     *
     * @return array JSON-результат сброса.
     */
    public function actionResetCompany()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = Yii::$app->user->identity;
        $user->setCompany(null);
        return ['success' => true, 'message' => 'Компания сброшена'];
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Находит пользователя по ID.
     *
     * @param int|string $id ID пользователя.
     * @return User Найденная модель пользователя.
     * @throws NotFoundHttpException Если пользователь не найден.
     */
    protected function findModel($id)
    {
        if (($model = User::findOne($id)) !== null) return $model;
        throw new NotFoundHttpException('Пользователь не найден.');
    }
}
