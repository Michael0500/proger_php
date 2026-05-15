<?php

namespace app\controllers;

use yii\web\Controller;
use yii\filters\AccessControl;

/**
 * Административный контроллер.
 *
 * Доступ ограничен ролью `admin`; гости перенаправляются на страницу входа
 * с сохранением исходного URL.
 */
class AdminController extends Controller
{
    /**
     * Возвращает правила доступа для админ-раздела.
     *
     * @return array Конфигурация фильтра `AccessControl`.
     */
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

    /**
     * Рендерит административный dashboard.
     *
     * @return string HTML страницы dashboard.
     */
    public function actionDashboard()
    {
        return $this->render('dashboard');
    }
}
