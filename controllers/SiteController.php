<?php

namespace app\controllers;

use app\models\Company;
use app\models\User;
use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use app\models\LoginForm;

class SiteController extends BaseController
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    public function actionIndex()
    {
        $user = Yii::$app->user->identity;

        // Если компания уже выбрана - показать основной контент
        if ($user && $user->hasCompany()) {
            return $this->render('dashboard', [
                'company' => $user->company,
            ]);
        }

        // Иначе показать выбор компании
        $companies = Company::getAllCompanies();

        return $this->render('index', [
            'companies' => $companies,
        ]);
    }

    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            // После входа проверяем, выбрана ли компания
            $user = Yii::$app->user->identity;

            if (!$user->hasCompany()) {
                // Если компания не выбрана - перенаправляем на главную для выбора
                return $this->redirect(['site/index']);
            }

            return $this->goBack();
        }

        $model->password = '';
        return $this->render('login', [
            'model' => $model,
        ]);
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }

    public function actionSignup()
    {
        $model = new User();

        if ($model->load(Yii::$app->request->post())) {
            $model->setPassword($model->password);
            $model->generateAuthKey();
            $model->status = User::STATUS_ACTIVE;

            if ($model->save()) {
                return $this->redirect(['site/login']);
            }
        }

        return $this->render('signup', [
            'model' => $model,
        ]);
    }
}