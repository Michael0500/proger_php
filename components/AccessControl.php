<?php

namespace app\components;

use yii\base\ActionFilter;
use Yii;

/**
 * Глобальный фильтр доступа веб-приложения.
 *
 * Пропускает публичные actions из `allowActions`, а гостей на остальных
 * маршрутах перенаправляет на login с сохранением returnUrl.
 */
class AccessControl extends ActionFilter
{
    public $allowActions = [
        'site/login',
        'site/signup',
        'site/error',
        'site/index', // если главная страница публичная
    ];

    /**
     * Проверяет доступ перед выполнением action.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool `true`, если action можно выполнять.
     */
    public function beforeAction($action)
    {
        $currentAction = $action->controller->id . '/' . $action->id;

        if (Yii::$app->user->isGuest && !in_array($currentAction, $this->allowActions)) {
            Yii::$app->user->setReturnUrl(Yii::$app->request->getUrl());
            Yii::$app->response->redirect(['/site/login']);
            return false;
        }

        return parent::beforeAction($action);
    }
}
