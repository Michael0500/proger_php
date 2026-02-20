<?php

namespace app\controllers;

use yii\web\Controller;
use yii\filters\AccessControl;

class BaseController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
                'denyCallback' => function ($rule, $action) {
                    \Yii::$app->user->setReturnUrl(\Yii::$app->request->getUrl());
                    return $action->controller->redirect(['site/login']);
                },
            ],
        ];
    }
}