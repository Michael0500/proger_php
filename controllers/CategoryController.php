<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Category;
use app\models\Group;

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
            ->with('groups')
            ->orderBy('name')
            ->all();

        $data = [];
        foreach ($categories as $category) {
            $data[] = [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'created_at' => $category->created_at,
                'groups' => array_map(function($group) {
                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'description' => $group->description,
                        'is_active' => $group->is_active,
                        'filters' => array_map(function ($f) {
                               return [
                                   'id'       => $f->id,
                                   'field'    => $f->field,
                                   'operator' => $f->operator,
                                   'value'    => $f->value,
                                   'logic'    => $f->logic,
                               ];
                           }, $group->filters),
                    ];
                }, $category->groups)
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

        // Удаляем связанные группы
        $groups = Group::find()->where(['category_id' => $id])->all();
        foreach ($groups as $group) {
            $group->delete();
        }

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
