<?php

namespace app\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Account;

/**
 * Поисковая модель ностро-счетов.
 *
 * Используется CRUD-страницами Yii для построения `ActiveDataProvider`
 * поверх `Account` с фильтрами по реквизитам счёта.
 */
class AccountSearch extends Account
{
    /**
     * Возвращает правила фильтрации поисковой формы.
     *
     * @return array Правила Yii Validator.
     */
    public function rules()
    {
        return [
            [['id', 'company_id', 'pool_id', 'created_by', 'updated_by'], 'integer'],
            [['name', 'created_at', 'updated_at', 'load_status', 'date_close', 'date_open'], 'safe'],
            [['load_barsgl', 'is_suspense'], 'boolean'],
        ];
    }

    /**
     * Возвращает стандартные сценарии `Model`.
     *
     * Сценарии родительской ActiveRecord-модели не нужны для поисковой формы.
     *
     * @return array Сценарии модели.
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Создаёт data provider со всеми применёнными фильтрами.
     *
     * @param array $params Параметры фильтрации из запроса.
     * @param string|null $formName Имя формы для метода `load()`.
     *
     * @return ActiveDataProvider Провайдер счетов для GridView.
     */
    public function search($params, $formName = null)
    {
        $query = Account::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params, $formName);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'company_id' => $this->company_id,
            'pool_id' => $this->pool_id,
            'load_barsgl' => $this->load_barsgl,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'updated_by' => $this->updated_by,
            'date_close' => $this->date_close,
            'is_suspense' => $this->is_suspense,
            'date_open' => $this->date_open,
        ]);

        $query->andFilterWhere(['ilike', 'name', $this->name])
            ->andFilterWhere(['ilike', 'load_status', $this->load_status]);

        return $dataProvider;
    }
}
