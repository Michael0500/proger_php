<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Category;
use app\models\AccountPool;

class CategoryController extends BaseController
{
    public function actionGetCategories()
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

        $categories = Category::find()
            ->where(['company_id' => $user->company_id])
            ->with('pools')
            ->orderBy('name')
            ->all();

        $data = [];
        foreach ($categories as $category) {
            $data[] = [
                'id'          => $category->id,
                'name'        => $category->name,
                'description' => $category->description,
                'created_at'  => $category->created_at,
                'pools'       => array_map(function (AccountPool $pool) {
                    return [
                        'id'          => $pool->id,
                        'name'        => $pool->name,
                        'description' => $pool->description,
                    ];
                }, $category->pools),
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

        $model = new Category();
        $model->company_id = Yii::$app->user->identity->company_id;
        $model->name = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Категория успешно создана',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при создании категории',
                'errors' => $model->errors
            ];
        }
    }

    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = Category::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Категория не найдена'
            ];
        }

        $model->name = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');
        $model->updated_at = date('Y-m-d H:i:s');

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Категория успешно обновлена',
                'data' => $model
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при обновлении категории',
                'errors' => $model->errors
            ];
        }
    }

    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id = Yii::$app->request->post('id');
        $model = Category::findOne($id);

        if (!$model) {
            return [
                'success' => false,
                'message' => 'Категория не найдена'
            ];
        }

        // Связанные ностро-банки получат category_id = NULL автоматически
        // (FK с ON DELETE SET NULL)

        if ($model->delete()) {
            return [
                'success' => true,
                'message' => 'Категория успешно удалена'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при удалении категории',
                'errors' => $model->errors
            ];
        }
    }
}
