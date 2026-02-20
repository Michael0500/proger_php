<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\Account;
use app\models\AccountPool;
use app\models\User;

class NostroEntryController extends BaseController
{
    /**
     * Возвращает записи, сгруппированные по счетам пула.
     * GET /nostro-entry/get-by-pool?pool_id=X
     *
     * Структура ответа:
     * {
     *   success: true,
     *   pool_name: "...",
     *   accounts: [
     *     {
     *       id: 1, name: "...", is_suspense: false,
     *       entries: [ { id, match_id, ls, dc, amount, ... }, ... ]
     *     },
     *     ...
     *   ]
     * }
     */
    public function actionGetByPool($pool_id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $pool = AccountPool::findOne($pool_id);
        if (!$pool) {
            return ['success' => false, 'message' => 'Пул не найден'];
        }

        // Получаем все счета пула
        $accounts = Account::find()
            ->where(['pool_id' => $pool_id])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $result = [];
        foreach ($accounts as $account) {
            $entries = NostroEntry::find()
                ->where(['account_id' => $account->id])
                ->orderBy(['post_date' => SORT_DESC, 'id' => SORT_DESC])
                ->all();

            $entriesData = [];
            foreach ($entries as $e) {
                $entriesData[] = [
                    'id'             => $e->id,
                    'match_id'       => $e->match_id,
                    'ls'             => $e->ls,
                    'dc'             => $e->dc,
                    'amount'         => $e->formattedAmount(),
                    'amount_raw'     => $e->amount,
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
                    'match_status_label' => NostroEntry::matchStatusLabels()[$e->match_status] ?? $e->match_status,
                    'match_status_badge' => $e->matchStatusBadge(),
                    'created_at'     => $e->created_at,
                ];
            }

            $result[] = [
                'id'          => $account->id,
                'name'        => $account->name,
                'is_suspense' => $account->is_suspense,
                'currency'    => $account->currency ?? null,
                'entries'     => $entriesData,
            ];
        }

        return [
            'success'   => true,
            'pool_name' => $pool->name,
            'accounts'  => $result,
        ];
    }

    /**
     * Создание записи вручную.
     * POST /nostro-entry/create
     */
    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $user = User::findOne(Yii::$app->user->id);
        if (!$user || !$user->company_id) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        $model = new NostroEntry();
        $model->company_id     = $user->company_id;
        $model->account_id     = (int) Yii::$app->request->post('account_id');
        $model->ls             = Yii::$app->request->post('ls');
        $model->dc             = Yii::$app->request->post('dc');
        $model->amount         = Yii::$app->request->post('amount');
        $model->currency       = Yii::$app->request->post('currency');
        $model->value_date     = Yii::$app->request->post('value_date') ?: null;
        $model->post_date      = Yii::$app->request->post('post_date') ?: null;
        $model->instruction_id = Yii::$app->request->post('instruction_id') ?: null;
        $model->end_to_end_id  = Yii::$app->request->post('end_to_end_id') ?: null;
        $model->transaction_id = Yii::$app->request->post('transaction_id') ?: null;
        $model->message_id     = Yii::$app->request->post('message_id') ?: null;
        $model->comment        = Yii::$app->request->post('comment') ?: null;
        $model->source         = 'manual';

        if ($model->save()) {
            return [
                'success' => true,
                'message' => 'Запись успешно добавлена',
                'data'    => ['id' => $model->id],
            ];
        }

        return [
            'success' => false,
            'message' => 'Ошибка при сохранении записи',
            'errors'  => $model->errors,
        ];
    }

    /**
     * Обновление записи (только ручные поля + comment).
     * POST /nostro-entry/update
     */
    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id    = (int) Yii::$app->request->post('id');
        $model = NostroEntry::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'Запись не найдена'];
        }

        $model->ls             = Yii::$app->request->post('ls', $model->ls);
        $model->dc             = Yii::$app->request->post('dc', $model->dc);
        $model->amount         = Yii::$app->request->post('amount', $model->amount);
        $model->currency       = Yii::$app->request->post('currency', $model->currency);
        $model->value_date     = Yii::$app->request->post('value_date') ?: $model->value_date;
        $model->post_date      = Yii::$app->request->post('post_date') ?: $model->post_date;
        $model->instruction_id = Yii::$app->request->post('instruction_id') ?: $model->instruction_id;
        $model->end_to_end_id  = Yii::$app->request->post('end_to_end_id') ?: $model->end_to_end_id;
        $model->transaction_id = Yii::$app->request->post('transaction_id') ?: $model->transaction_id;
        $model->message_id     = Yii::$app->request->post('message_id') ?: $model->message_id;
        $model->comment        = Yii::$app->request->post('comment', $model->comment);

        if ($model->save()) {
            return ['success' => true, 'message' => 'Запись обновлена'];
        }

        return [
            'success' => false,
            'message' => 'Ошибка при обновлении',
            'errors'  => $model->errors,
        ];
    }

    /**
     * Удаление записи.
     * POST /nostro-entry/delete
     */
    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id    = (int) Yii::$app->request->post('id');
        $model = NostroEntry::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'Запись не найдена'];
        }

        if ($model->delete()) {
            return ['success' => true, 'message' => 'Запись удалена'];
        }

        return ['success' => false, 'message' => 'Ошибка при удалении'];
    }

    /**
     * Обновление только комментария (быстрый inline-edit).
     * POST /nostro-entry/update-comment
     */
    public function actionUpdateComment()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id      = (int) Yii::$app->request->post('id');
        $comment = Yii::$app->request->post('comment', '');

        $model = NostroEntry::findOne($id);
        if (!$model) {
            return ['success' => false, 'message' => 'Запись не найдена'];
        }

        $model->comment = mb_substr($comment, 0, 40);

        if ($model->save(true, ['comment', 'updated_at', 'updated_by'])) {
            return ['success' => true, 'comment' => $model->comment];
        }

        return ['success' => false, 'errors' => $model->errors];
    }
}