<?php

namespace app\controllers;

use yii\web\Controller;
use yii\filters\AccessControl;

/**
 * Базовый контроллер для авторизованных страниц приложения.
 *
 * Подключает Yii `AccessControl` и перенаправляет гостей на страницу входа,
 * сохраняя исходный URL для возврата после авторизации.
 */
class BaseController extends Controller
{
    /**
     * Возвращает общие фильтры доступа для наследников.
     *
     * @return array Конфигурация behaviors Yii.
     */
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
