<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\AccountPool;
use app\models\Account;
use app\models\NostroEntry;
use app\models\User;

class AccountPoolController extends BaseController
{
    /**
     * При выборе пула в сайдбаре — загружаем записи выверки,
     * сгруппированные по Ностро банкам (счетам).
     *
     * GET /account-pool/get-accounts?id=X
     */
    public function actionGetAccounts($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $pool = AccountPool::findOne($id);
        if (!$pool) {
            return ['success' => false, 'message' => 'Пул не найден'];
        }

        // Все счета (Ностро банки) пула
        $accounts = Account::find()
            ->where(['pool_id' => $id])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $data = [];
        foreach ($accounts as $account) {
            $entries = NostroEntry::find()
                ->where(['account_id' => $account->id])
                ->all();

            $entriesData = [];
            foreach ($entries as $e) {
                $entriesData[] = [
                    'id'             => $e->id,
                    'match_id'       => $e->match_id,
                    'ls'             => $e->ls,
                    'dc'             => $e->dc,
                    'amount'         => $e->formattedAmount(),
                    'amount_raw'     => (float) $e->amount,
                    'currency'       => $e->currency,
                    'value_date'     => $e->value_date,
                    'post_date'      => $e->post_date,
                    'instruction_id' => $e->instruction_id,
                    'end_to_end_id'  => $e->end_to_end_id,
                    'transaction_id' => $e->transaction_id,
                    'message_id'     => $e->message_id,
                    'comment'        => $e->comment,
                    'source'         => $e->source,
                    'match_status'   => $e->match_status,
                    'match_status_badge' => $e->matchStatusBadge(),
                    'created_at'     => $e->created_at,
                ];
            }

            $data[] = [
                'id'          => $account->id,
                'name'        => $account->name,
                'is_suspense' => (bool) $account->is_suspense,
                'currency'    => $account->currency ?? null,
                'entries'     => $entriesData,
            ];
        }

        return [
            'success'   => true,
            'pool_name' => $pool->name,
            'data'      => $data,  // массив Ностро банков с вложенными entries
        ];
    }

    // ----------------------------------------------------------------

    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $user = User::findOne(Yii::$app->user->id);

        $model = new AccountPool();
        $model->company_id   = $user->company_id;
        $model->group_id     = Yii::$app->request->post('group_id');
        $model->name         = Yii::$app->request->post('name');
        $model->description  = Yii::$app->request->post('description');
        $isActive            = Yii::$app->request->post('is_active', true);
        $model->is_active    = ($isActive === true || $isActive === 'true' || $isActive === '1' || $isActive == 1);

        $filterCriteria = Yii::$app->request->post('filter_criteria', []);
        if (!empty($filterCriteria)) {
            $model->setFilterCriteria($filterCriteria);
        }

        if ($model->save()) {
            return ['success' => true, 'message' => 'Пул успешно создан', 'data' => ['id' => $model->id, 'name' => $model->name]];
        }

        return ['success' => false, 'message' => 'Ошибка при создании пула', 'errors' => $model->errors];
    }

    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id    = Yii::$app->request->post('id');
        $model = AccountPool::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'Пул не найден'];
        }

        $model->name        = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');
        $isActive           = Yii::$app->request->post('is_active', true);
        $model->is_active   = ($isActive === true || $isActive === 'true' || $isActive === '1' || $isActive == 1);

        $filterCriteria = Yii::$app->request->post('filter_criteria', []);
        if (!empty($filterCriteria)) {
            $model->setFilterCriteria($filterCriteria);
        }

        if ($model->save()) {
            return ['success' => true, 'message' => 'Пул успешно обновлен', 'data' => ['id' => $model->id]];
        }

        return ['success' => false, 'message' => 'Ошибка при обновлении пула', 'errors' => $model->errors];
    }

    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id    = Yii::$app->request->post('id');
        $model = AccountPool::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'Пул не найден'];
        }

        Account::updateAll(['pool_id' => null], ['pool_id' => $id]);

        if ($model->delete()) {
            return ['success' => true, 'message' => 'Пул успешно удален'];
        }

        return ['success' => false, 'message' => 'Ошибка при удалении пула', 'errors' => $model->errors];
    }
}