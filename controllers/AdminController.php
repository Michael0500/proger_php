<?php

namespace app\controllers;

use yii\web\Controller;
use yii\filters\AccessControl;

class AdminController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['admin'], // только администраторы
                    ],
                    [
                        'allow' => false,
                        'roles' => ['?'], // для гостей
                        'denyCallback' => function ($rule, $action) {
                            \Yii::$app->user->setReturnUrl(\Yii::$app->request->getUrl());
                            return $action->controller->redirect(['site/login']);
                        }
                    ],
                ],
            ],
        ];
    }

    public function actionDashboard()
    {
        return $this->render('dashboard');
    }
}