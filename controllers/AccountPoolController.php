<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\AccountPool;
use app\models\Account;

class AccountPoolController extends BaseController
{

    public function actionGetAccounts($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $pool = AccountPool::findOne($id);

        if (!$pool) {
            return [
                'success' => false,
                'message' => 'Пул не найден'
            ];
        }

        $accounts = $pool->getFilteredAccounts();

        $data = [];
        foreach ($accounts as $account) {
            $data[] = [
                'id' => $account->id,
                'name' => $account->name,
                'is_suspense' => $account->is_suspense,
                'currency' => $account->currency,
                'account_type' => $account->account_type,
                'bank_code' => '',
                'country' => $account->country,
                'created_at' => $account->created_at
            ];
        }

        return [
            'success' => true,
            'data' => $data,
            'pool_name' => $pool->name,
            'filter_criteria' => $pool->filter_criteria
        ];
    }

    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model = new AccountPool();
        $model->group_id = Yii::$app->request->post('group_id');
        $model->name = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');
        $model->is_active = Yii::$app->request->post('is_active', true) == '1';

        $filterCriteria = Yii::$app->request->post('filter_criteria', []);
        if (!empty($filterCriteria)) {
            $model->setFilterCriteria($filterCriteria);
        }

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Пул успешно создан',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при создании пула',
                'errors' => $model->errors
            ];
        }
    }

    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = AccountPool::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Пул не найден'
            ];
        }

        $model->name = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');
        $model->is_active = Yii::$app->request->post('is_active', true) == '1';

        $filterCriteria = Yii::$app->request->post('filter_criteria', []);
        if (!empty($filterCriteria)) {
            $model->setFilterCriteria($filterCriteria);
        }

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Пул успешно обновлен',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при обновлении пула',
                'errors' => $model->errors
            ];
        }
    }

    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = AccountPool::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Пул не найден'
            ];
        }

        // Отвязываем счета от пула
        Account::updateAll(['pool_id' => null], ['pool_id' => $id]);

        if ($model->delete()) {
            return [
                'success' => true,
                'message' => 'Пул успешно удален'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при удалении пула',
                'errors' => $model->errors
            ];
        }
    }
}