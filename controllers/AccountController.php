<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\Account;

class AccountController extends Controller
{
    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            if (Yii::$app->user->isGuest) {
                $this->redirect(['/site/login']);
                return false;
            }
            return true;
        }
        return false;
    }

    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model = new Account();
        $model->company_id = Yii::$app->request->post('company_id');
        $model->pool_id = Yii::$app->request->post('pool_id');
        $model->name = Yii::$app->request->post('name');
        $model->is_suspense = Yii::$app->request->post('is_suspense') == '1';

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Ностробанк успешно добавлен',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при добавлении ностробанка',
                'errors' => $model->errors
            ];
        }
    }

    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = Account::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Ностробанк не найден'
            ];
        }

        $model->name = Yii::$app->request->post('name');
        $model->pool_id = Yii::$app->request->post('pool_id');
        $model->is_suspense = Yii::$app->request->post('is_suspense') == '1';

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Ностробанк успешно обновлен',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при обновлении ностробанка',
                'errors' => $model->errors
            ];
        }
    }

    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = Account::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Ностробанк не найден'
            ];
        }

        if ($model->delete()) {
            return [
                'success' => true,
                'message' => 'Ностробанк успешно удален'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при удалении ностробанка',
                'errors' => $model->errors
            ];
        }
    }
}