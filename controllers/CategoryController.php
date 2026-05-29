<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Category;
use app\models\AccountPool;

/**
 * JSON-контроллер категорий навигации выверки.
 *
 * Категории группируют ностро-банки в сайдбаре и используются как верхний
 * уровень выбора области отчётов. Данные должны относиться к компании
 * текущего пользователя.
 */
class CategoryController extends BaseController
{
    /**
     * Отключает CSRF для JSON API категорий.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool Можно ли продолжать выполнение action.
     */
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Возвращает ID компании текущего пользователя.
     *
     * @return int|null ID компании или `null`.
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Возвращает категории текущей компании вместе с ностро-банками.
     *
     * GET `/category/get-categories`.
     *
     * @return array JSON-список категорий и вложенных пулов.
     */
    public function actionGetCategories()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();

        if (!$cid) {
            return [
                'success' => false,
                'message' => 'Компания не выбрана'
            ];
        }

        $categories = Category::find()
            ->where(['company_id' => $cid])
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

    /**
     * Создаёт категорию для компании текущего пользователя.
     *
     * POST `/category/create`.
     *
     * @return array JSON с созданной категорией или ошибками валидации.
     */
    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) {
            return ['success' => false, 'message' => 'Компания не выбрана'];
        }

        $model = new Category();
        $model->company_id = $cid;
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

    /**
     * Обновляет название и описание категории.
     *
     * POST `/category/update`.
     *
     * @return array JSON с обновлённой категорией или ошибкой.
     */
    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) {
            return ['success' => false, 'message' => 'Компания не выбрана'];
        }

        $id = Yii::$app->request->post('id');
        $model = Category::findOne(['id' => $id, 'company_id' => $cid]);

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

    /**
     * Удаляет категорию.
     *
     * При удалении связанные ностро-банки открепляются на уровне FK
     * `ON DELETE SET NULL`.
     *
     * @return array JSON-результат удаления.
     */
    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) {
            return ['success' => false, 'message' => 'Компания не выбрана'];
        }

        $id = Yii::$app->request->post('id');
        $model = Category::findOne(['id' => $id, 'company_id' => $cid]);

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
