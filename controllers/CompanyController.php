<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use app\models\Company;
use yii\web\NotFoundHttpException;

class CompanyController extends Controller
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
            ],
        ];
    }

    /**
     * Выбор компании
     */
    public function actionSelect($id)
    {
        $company = Company::findOne($id);

        if (!$company) {
            throw new NotFoundHttpException('Компания не найдена.');
        }

        $user = Yii::$app->user->identity;
        $user->setCompany($company->id);

        Yii::$app->session->setFlash('success', "Компания «{$company->name}» выбрана!");

        // Перенаправление на главную страницу или предыдущую
        $url = Yii::$app->session->get('returnUrl', ['site/index']);
        Yii::$app->session->remove('returnUrl');

        return $this->redirect($url);
    }

    /**
     * Сброс выбора компании
     */
    public function actionReset()
    {
        $user = Yii::$app->user->identity;
        $user->setCompany(null);

        Yii::$app->session->setFlash('info', 'Выбор компании сброшен.');

        return $this->redirect(['site/index']);
    }
}