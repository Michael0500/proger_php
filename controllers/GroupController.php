<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Group;
use app\models\Account;
use app\models\NostroEntry;
use app\models\User;

class GroupController extends BaseController
{
    /**
     * При выборе группы в сайдбаре — загружаем записи выверки,
     * сгруппированные по Ностро банкам (счетам).
     *
     * GET /group/get-accounts?id=X
     */
    public function actionGetAccounts($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $group = Group::findOne($id);
        if (!$group) {
            return ['success' => false, 'message' => 'Группа не найдена'];
        }

        // Получаем account_ids через фильтры группы
        $cid = Yii::$app->user->identity->company_id;
        $filters = $group->filters;

        $accountQuery = Account::find()
            ->where(['company_id' => $cid]);

        if (!empty($filters)) {
            $first = true;
            foreach ($filters as $filter) {
                $condition = $filter->buildAccountCondition();
                if ($condition === null) continue;
                if ($first) {
                    $accountQuery->andWhere($condition);
                    $first = false;
                } elseif ($filter->logic === 'OR') {
                    $accountQuery->orWhere($condition);
                } else {
                    $accountQuery->andWhere($condition);
                }
            }
        }

        $accounts = $accountQuery->orderBy(['name' => SORT_ASC])->all();

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
            'success'    => true,
            'group_name' => $group->name,
            'data'       => $data,
        ];
    }

    // ----------------------------------------------------------------

    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $user = User::findOne(Yii::$app->user->id);

        $model = new Group();
        $model->company_id   = $user->company_id;
        $model->category_id  = Yii::$app->request->post('category_id');
        $model->name         = Yii::$app->request->post('name');
        $model->description  = Yii::$app->request->post('description');
        $isActive            = Yii::$app->request->post('is_active', true);
        $model->is_active    = ($isActive === true || $isActive === 'true' || $isActive === '1' || $isActive == 1);

        if ($model->save()) {
            return ['success' => true, 'message' => 'Группа успешно создана', 'data' => ['id' => $model->id, 'name' => $model->name]];
        }

        return ['success' => false, 'message' => 'Ошибка при создании группы', 'errors' => $model->errors];
    }

    public function actionUpdate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id    = Yii::$app->request->post('id');
        $model = Group::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'Группа не найдена'];
        }

        $model->name        = Yii::$app->request->post('name');
        $model->description = Yii::$app->request->post('description');
        $isActive           = Yii::$app->request->post('is_active', true);
        $model->is_active   = ($isActive === true || $isActive === 'true' || $isActive === '1' || $isActive == 1);

        if ($model->save()) {
            return ['success' => true, 'message' => 'Группа успешно обновлена', 'data' => ['id' => $model->id]];
        }

        return ['success' => false, 'message' => 'Ошибка при обновлении группы', 'errors' => $model->errors];
    }

    public function actionDelete()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $id    = Yii::$app->request->post('id');
        $model = Group::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'Группа не найдена'];
        }

        if ($model->delete()) {
            return ['success' => true, 'message' => 'Группа успешно удалена'];
        }

        return ['success' => false, 'message' => 'Ошибка при удалении группы', 'errors' => $model->errors];
    }

    /**
     * Получить все фильтры группы
     * GET /group/get-filters?group_id=X
     */
    public function actionGetFilters($group_id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $group = Group::findOne($group_id);
        if (!$group) {
            return ['success' => false, 'message' => 'Группа не найдена'];
        }

        $filters = \app\models\GroupFilter::find()
            ->where(['group_id' => $group_id])
            ->orderBy(['sort_order' => SORT_ASC])
            ->all();

        $data = array_map(function ($f) {
            return [
                'id'         => $f->id,
                'group_id'   => $f->group_id,
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
        $accountPools = [];
        if ($user && $user->company_id) {
            $accounts = Account::find()
                ->select(['id', 'name', 'currency'])
                ->where(['company_id' => $user->company_id])
                ->orderBy(['name' => SORT_ASC])
                ->asArray()
                ->all();

            $accountPools = \app\models\AccountPool::find()
                ->select(['id', 'name'])
                ->where(['company_id' => $user->company_id])
                ->orderBy(['name' => SORT_ASC])
                ->asArray()
                ->all();
        }

        return [
            'success'          => true,
            'data'             => $data,
            'field_groups' => [
                ['label' => 'По счёту (accounts)', 'fields' => \app\models\GroupFilter::accountFields()],
                ['label' => 'По записям (nostro_entries)', 'fields' => \app\models\GroupFilter::entryFields()],
            ],
            'available_fields' => \app\models\GroupFilter::availableFields(),
            'date_fields'      => \app\models\GroupFilter::dateFields(),
            'select_fields'    => \app\models\GroupFilter::selectFields(),
            'operators_map'    => array_reduce(
                array_keys(\app\models\GroupFilter::availableFields()),
                function ($carry, $field) {
                    $carry[$field] = \app\models\GroupFilter::operatorsForField($field);
                    return $carry;
                },
                []
            ),
            'field_options' => [
                'ls'              => ['L' => 'L — Ledger', 'S' => 'S — Statement'],
                'dc'              => ['Debit' => 'Debit', 'Credit' => 'Credit'],
                'match_status'    => ['U' => 'U — Не сквитовано', 'M' => 'M — Сквитовано', 'I' => 'I — Игнорируется'],
                'is_suspense'     => ['1' => 'Да (Suspense)', '0' => 'Нет'],
                'account_pool_id' => array_combine(
                    array_column($accountPools, 'id'),
                    array_column($accountPools, 'name')
                ),
            ],
            'accounts'      => $accounts,
            'account_pools' => $accountPools,
        ];
    }

    /**
     * Сохранить фильтры группы (полная замена)
     * POST /group/save-filters
     */
    public function actionSaveFilters()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $group_id = Yii::$app->request->post('group_id');
        $group    = Group::findOne($group_id);

        if (!$group) {
            return ['success' => false, 'message' => 'Группа не найдена'];
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
            \app\models\GroupFilter::deleteAll(['group_id' => $group_id]);

            foreach ((array) $filtersRaw as $i => $row) {
                $filter             = new \app\models\GroupFilter();
                $filter->group_id   = $group_id;
                $filter->field      = $row['field']      ?? 'currency';
                $filter->operator   = $row['operator']   ?? 'eq';
                $filter->value      = trim($row['value'] ?? '');
                $filter->logic      = ($i === 0) ? 'AND' : ($row['logic'] ?? 'AND');
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
