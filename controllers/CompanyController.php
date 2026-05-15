<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use app\models\Company;
use yii\web\NotFoundHttpException;

/**
 * Контроллер выбора компании пользователя.
 *
 * Выбранная компания записывается в профиль пользователя и далее определяет
 * tenant-контекст всех бизнес-страниц.
 */
class CompanyController extends Controller
{
    /**
     * Разрешает действия только авторизованным пользователям.
     *
     * @return array Конфигурация фильтров доступа.
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
            ],
        ];
    }

    /**
     * Устанавливает текущую компанию пользователя.
     *
     * @param int|string $id ID выбираемой компании.
     * @return \yii\web\Response Redirect на сохранённый URL или главную.
     * @throws NotFoundHttpException Если компания не найдена.
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
     * Сбрасывает текущую компанию пользователя.
     *
     * @return \yii\web\Response Redirect на главную страницу.
     */
    public function actionReset()
    {
        $user = Yii::$app->user->identity;
        $user->setCompany(null);

        Yii::$app->session->setFlash('info', 'Выбор компании сброшен.');

        return $this->redirect(['site/index']);
    }
}
