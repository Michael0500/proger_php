<?php

namespace app\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Поисковая модель ностро-банков.
 *
 * Формирует `ActiveDataProvider` для административных списков `AccountPool`
 * с фильтрами по компании, названию и служебным датам.
 */
class AccountPoolSearch extends AccountPool
{
    /**
     * Возвращает правила фильтрации поисковой формы.
     *
     * @return array Правила Yii Validator.
     */
    public function rules()
    {
        return [
            [['id', 'company_id'], 'integer'],
            [['name', 'created_at', 'updated_at'], 'safe'],
        ];
    }

    /**
     * Возвращает стандартные сценарии `Model`.
     *
     * @return array Сценарии модели.
     */
    public function scenarios()
    {
        return Model::scenarios();
    }

    /**
     * Создаёт data provider со всеми применёнными фильтрами.
     *
     * @param array $params Параметры фильтрации из запроса.
     * @param string|null $formName Имя формы для метода `load()`.
     * @return ActiveDataProvider Провайдер ностро-банков для GridView.
     */
    public function search($params, $formName = null)
    {
        $query = AccountPool::find();

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params, $formName);

        if (!$this->validate()) {
            return $dataProvider;
        }

        $query->andFilterWhere([
            'id' => $this->id,
            'company_id' => $this->company_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);

        $query->andFilterWhere(['ilike', 'name', $this->name]);

        return $dataProvider;
    }
}
