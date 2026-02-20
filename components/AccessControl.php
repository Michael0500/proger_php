<?php

namespace app\components;

use yii\base\ActionFilter;
use Yii;

class AccessControl extends ActionFilter
{
    public $allowActions = [
        'site/login',
        'site/signup',
        'site/error',
        'site/index', // если главная страница публичная
    ];

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