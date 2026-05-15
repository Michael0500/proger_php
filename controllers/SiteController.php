<?php

namespace app\controllers;

use app\models\Company;
use app\models\User;
use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use app\models\LoginForm;

/**
 * Контроллер базовых страниц, входа и выбора компании.
 *
 * Главная страница либо показывает рабочую выверку, если у пользователя уже
 * выбран `company_id`, либо предлагает выбрать компанию для tenant-контекста.
 */
class SiteController extends BaseController
{
    /**
     * Ограничивает действие logout авторизованными пользователями.
     *
     * @return array Конфигурация фильтров доступа.
     */
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

    /**
     * Подключает стандартные Yii actions.
     *
     * @return array Конфигурация внешних actions, включая обработчик ошибок.
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    /**
     * Отображает главную страницу приложения.
     *
     * Если у пользователя выбрана компания, рендерит страницу выверки,
     * иначе показывает выбор компании.
     *
     * @return string|\yii\web\Response HTML-страница или redirect.
     */
    public function actionIndex()
    {
        $user = Yii::$app->user->identity;

        // Если компания уже выбрана — показываем страницу выверки
        if ($user && $user->hasCompany()) {
            return $this->render('entries');
        }

        // Иначе показать выбор компании
        $companies = Company::getAllCompanies();

        return $this->render('index', [
            'companies' => $companies,
        ]);
    }

    /**
     * Обрабатывает вход пользователя.
     *
     * После успешной авторизации возвращает пользователя на исходный URL или
     * на выбор компании, если `company_id` ещё не задан.
     *
     * @return string|\yii\web\Response HTML формы или redirect.
     */
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

    /**
     * Завершает пользовательскую сессию.
     *
     * @return \yii\web\Response Redirect на главную страницу.
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }

    /**
     * Создаёт нового пользователя через простую форму регистрации.
     *
     * Побочный эффект: при успешном сохранении генерирует хэш пароля и auth key.
     *
     * @return string|\yii\web\Response HTML формы или redirect на login.
     */
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
