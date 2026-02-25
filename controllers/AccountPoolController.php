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

        if (is_string($filterCriteria) && !empty($filterCriteria)) {
            try {
                $filterCriteria = \yii\helpers\Json::decode($filterCriteria);
            } catch (\Exception $e) {
                Yii::warning('Failed to decode filter_criteria: ' . $e->getMessage());
                $filterCriteria = [];
            }
        }

        if (!empty($filterCriteria) && is_array($filterCriteria)) {
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
        if (is_string($filterCriteria) && !empty($filterCriteria)) {
            try {
                $filterCriteria = \yii\helpers\Json::decode($filterCriteria);
            } catch (\Exception $e) {
                Yii::warning('Failed to decode filter_criteria: ' . $e->getMessage());
                $filterCriteria = [];
            }
        }

        if (!empty($filterCriteria) && is_array($filterCriteria)) {
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

    /**
     * Получить все фильтры пула
     * GET /account-pool/get-filters?pool_id=X
     */
    public function actionGetFilters($pool_id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $pool = AccountPool::findOne($pool_id);
        if (!$pool) {
            return ['success' => false, 'message' => 'Пул не найден'];
        }

        $filters = \app\models\AccountPoolFilter::find()
            ->where(['pool_id' => $pool_id])
            ->orderBy(['sort_order' => SORT_ASC])
            ->all();

        $data = array_map(function ($f) {
            return [
                'id'         => $f->id,
                'pool_id'    => $f->pool_id,
                'field'      => $f->field,
                'operator'   => $f->operator,
                'value'      => $f->value,
                'logic'      => $f->logic,
                'sort_order' => $f->sort_order,
            ];
        }, $filters);

        // Загружаем счета компании для Select2 в поле account_id
        $user = \app\models\User::findOne(Yii::$app->user->id);
        $accounts = [];
        if ($user && $user->company_id) {
            $accounts = Account::find()
                ->select(['id', 'name', 'currency'])
                ->where(['company_id' => $user->company_id])
                ->orderBy(['name' => SORT_ASC])
                ->asArray()
                ->all();
        }

        return [
            'success'          => true,
            'data'             => $data,
            // Поля сгруппированные по источнику
            'field_groups' => [
                ['label' => 'По счёту (accounts)', 'fields' => \app\models\AccountPoolFilter::accountFields()],
                ['label' => 'По записям (nostro_entries)', 'fields' => \app\models\AccountPoolFilter::entryFields()],
            ],
            'available_fields' => \app\models\AccountPoolFilter::availableFields(),
            'date_fields'      => \app\models\AccountPoolFilter::dateFields(),
            'select_fields'    => \app\models\AccountPoolFilter::selectFields(),
            'operators_map'    => array_reduce(
                array_keys(\app\models\AccountPoolFilter::availableFields()),
                function ($carry, $field) {
                    $carry[$field] = \app\models\AccountPoolFilter::operatorsForField($field);
                    return $carry;
                },
                []
            ),
            // Фиксированные варианты значений для select-полей
            'field_options' => [
                'ls'           => ['L' => 'L — Ledger', 'S' => 'S — Statement'],
                'dc'           => ['Debit' => 'Debit', 'Credit' => 'Credit'],
                'match_status' => ['U' => 'U — Не сквитовано', 'M' => 'M — Сквитовано', 'I' => 'I — Игнорируется'],
                'is_suspense'  => ['1' => 'Да (Suspense)', '0' => 'Нет'],
            ],
            'accounts' => $accounts, // для Select2 поля account_id
        ];
    }

    /**
     * Сохранить фильтры пула (полная замена — удаляем старые, вставляем новые)
     * POST /account-pool/save-filters
     * Body: { pool_id: X, filters: [ {field, operator, value, logic, sort_order}, … ] }
     */
    public function actionSaveFilters()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $pool_id = Yii::$app->request->post('pool_id');
        $pool    = AccountPool::findOne($pool_id);

        if (!$pool) {
            return ['success' => false, 'message' => 'Пул не найден'];
        }

        $filtersRaw = Yii::$app->request->post('filters', []);
        if (is_string($filtersRaw)) {
            try {
                $filtersRaw = \yii\helpers\Json::decode($filtersRaw);
            } catch (\Exception $e) {
                return ['success' => false, 'message' => 'Некорректный формат фильтров'];
            }
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Удаляем все текущие фильтры пула
            \app\models\AccountPoolFilter::deleteAll(['pool_id' => $pool_id]);

            // Вставляем новые
            foreach ((array) $filtersRaw as $i => $row) {
                $filter             = new \app\models\AccountPoolFilter();
                $filter->pool_id    = $pool_id;
                $filter->field      = $row['field']      ?? 'currency';
                $filter->operator   = $row['operator']   ?? 'eq';
                $filter->value      = trim($row['value'] ?? '');
                $filter->logic      = ($i === 0) ? 'AND' : ($row['logic'] ?? 'AND'); // первая строка — всегда AND
                $filter->sort_order = $i;

                if (!$filter->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Ошибка сохранения фильтра', 'errors' => $filter->errors];
                }
            }

            $transaction->commit();
            return ['success' => true, 'message' => 'Фильтры сохранены'];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error('saveFilters error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка сервера'];
        }
    }
}