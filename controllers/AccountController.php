<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use yii\web\NotFoundHttpException;
use app\models\Account;

class AccountController extends BaseController
{
    /**
     * POST /account/create  — JSON API (из Vue)
     */
    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user  = Yii::$app->user->identity;

        $model             = new Account();
        $model->company_id = (int) (Yii::$app->request->post('company_id') ?: $user->company_id);
        $model->pool_id    = (int) Yii::$app->request->post('pool_id');
        $model->name       = trim(Yii::$app->request->post('name', ''));
        $model->currency   = strtoupper(trim(Yii::$app->request->post('currency', ''))) ?: null;
        $model->is_suspense = Yii::$app->request->post('is_suspense') == '1';

        if ($model->save()) {
            return ['success' => true, 'message' => 'Счёт добавлен',
                'data' => ['id' => $model->id, 'name' => $model->name,
                    'currency' => $model->currency, 'is_suspense' => (bool) $model->is_suspense]];
        }
        return ['success' => false, 'message' => 'Ошибка', 'errors' => $model->errors];
    }

    /**
     * POST /account/create-from-profile  — HTML-форма, редирект обратно
     */
    public function actionCreateFromProfile()
    {
        $user  = Yii::$app->user->identity;

        $model             = new Account();
        $model->company_id = (int) (Yii::$app->request->post('company_id') ?: $user->company_id);
        $model->pool_id    = (int) Yii::$app->request->post('pool_id');
        $model->name       = trim(Yii::$app->request->post('name', ''));
        $model->currency   = strtoupper(trim(Yii::$app->request->post('currency', ''))) ?: null;
        $model->is_suspense = Yii::$app->request->post('is_suspense') == '1';

        if ($model->save()) {
            Yii::$app->session->setFlash('success', "Счёт «{$model->name}» добавлен.");
        } else {
            $err = [];
            foreach ($model->errors as $msgs) {
                foreach ($msgs as $m) { $err[] = $m; }
            }
            Yii::$app->session->setFlash('error', 'Ошибка: ' . implode('; ', $err));
        }
        return $this->redirect(['/user/view', 'id' => $user->id, '#' => 'accounts']);
    }

    /**
     * POST /account/update  — JSON API
     */
    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $id    = (int) Yii::$app->request->post('id');
        $model = Account::findOne($id);
        if (!$model) return ['success' => false, 'message' => 'Счёт не найден'];

        $model->name        = trim(Yii::$app->request->post('name', $model->name));
        $model->pool_id     = (int) (Yii::$app->request->post('pool_id') ?: $model->pool_id);
        $model->currency    = strtoupper(trim(Yii::$app->request->post('currency', ''))) ?: $model->currency;
        $model->is_suspense = Yii::$app->request->post('is_suspense') == '1';

        if ($model->save()) return ['success' => true, 'message' => 'Счёт обновлён'];
        return ['success' => false, 'message' => 'Ошибка', 'errors' => $model->errors];
    }

    /**
     * POST /account/delete  — работает и как JSON API и как HTML form
     */
    public function actionDelete()
    {
        $id    = (int) Yii::$app->request->post('id');
        $model = Account::findOne($id);

        if (!$model) {
            if (Yii::$app->request->isAjax) {
                Yii::$app->response->format = Response::FORMAT_JSON;
                return ['success' => false, 'message' => 'Счёт не найден'];
            }
            throw new NotFoundHttpException('Счёт не найден.');
        }

        $name = $model->name;
        $model->delete();

        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return ['success' => true, 'message' => 'Счёт удалён'];
        }

        Yii::$app->session->setFlash('success', "Счёт «{$name}» удалён.");
        return $this->redirect(['/user/view', 'id' => Yii::$app->user->id, '#' => 'accounts']);
    }

    /**
     * GET /account/list?pool_id=X  — JSON API (для Vue/Select2)
     */
    public function actionList()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user   = Yii::$app->user->identity;
        $poolId = (int) Yii::$app->request->get('pool_id');

        $query = Account::find()->where(['company_id' => $user->company_id]);
        if ($poolId > 0) $query->andWhere(['pool_id' => $poolId]);

        $rows = $query->orderBy(['name' => SORT_ASC])->all();
        $data = array_map(fn(Account $a) => [
            'id'          => $a->id,
            'name'        => $a->name,
            'currency'    => $a->currency,
            'is_suspense' => (bool) $a->is_suspense,
            'pool_id'     => $a->pool_id,
        ], $rows);

        return ['success' => true, 'data' => $data];
    }
}