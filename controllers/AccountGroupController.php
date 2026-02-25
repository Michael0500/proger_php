<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\AccountGroup;
use app\models\AccountPool;

class AccountGroupController extends BaseController
{

    public function actionGetGroups()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $userId = Yii::$app->user->id;
        $user = \app\models\User::findOne($userId);

        if (!$user || !$user->company_id) {
            return [
                'success' => false,
                'message' => 'Компания не выбрана'
            ];
        }

        $groups = AccountGroup::find()
            ->where(['company_id' => $user->company_id])
            ->with('accountPools')
            ->orderBy('name')
            ->all();

        $data = [];
        foreach ($groups as $group) {
            $data[] = [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'created_at' => $group->created_at,
                'pools' => array_map(function($pool) {
                    return [
                        'id' => $pool->id,
                        'name' => $pool->name,
                        'description' => $pool->description,
                        'is_active' => $pool->is_active,
                        'filters' => array_map(function ($f) {
                               return [
                                   'id'       => $f->id,
                                   'field'    => $f->field,
                                   'operator' => $f->operator,
                                   'value'    => $f->value,
                                   'logic'    => $f->logic,
                               ];
                           }, $pool->filters),
                    ];
                }, $group->accountPools)
            ];
        }

        return [
            'success' => true,
            'data' => $data
        ];
    }

    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model = new AccountGroup();
        $model->company_id = Yii::$app->user->identity->company_id;
        $model->name = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Группа успешно создана',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при создании группы',
                'errors' => $model->errors
            ];
        }
    }

    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = AccountGroup::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Группа не найдена'
            ];
        }

        $model->name = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');
        $model->updated_at = date('Y-m-d H:i:s');

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Группа успешно обновлена',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при обновлении группы',
                'errors' => $model->errors
            ];
        }
    }

    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = AccountGroup::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Группа не найдена'
            ];
        }

        // Удаляем связанные пулы
        $pools = AccountPool::find()->where(['group_id' => $id])->all();
        foreach ($pools as $pool) {
            \app\models\Account::updateAll(['pool_id' => null], ['pool_id' => $pool->id]);
            $pool->delete();
        }

        if ($model->delete()) {
            return [
                'success' => true,
                'message' => 'Группа успешно удалена'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при удалении группы',
                'errors' => $model->errors
            ];
        }
    }
}