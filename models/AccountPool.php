<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель группы ностробанков
 *
 * @property int $id
 * @property int $company_id
 * @property int $group_id
 * @property string $name
 * @property string|null $description
 * @property array|null $filter_criteria
 * @property bool $is_active
 * @property string $created_at
 * @property string $updated_at
 *
 * @property AccountGroup $group
 * @property Account[] $accounts
 */
class AccountPool extends ActiveRecord
{
    private $_filterCriteria;

    public static function tableName()
    {
        return '{{%account_pools}}';
    }

    public function rules()
    {
        return [
            [['company_id', 'name','group_id'], 'required'],
            [['group_id'], 'integer'],
            [['description', 'filter_criteria'], 'safe'],
            [['is_active'], 'boolean'],
            [['name'], 'string', 'max' => 100],
            [['group_id'], 'exist', 'skipOnError' => true, 'targetClass' => AccountGroup::className(), 'targetAttribute' => ['group_id' => 'id']],

        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'company_id' => 'Компания',
            'group_id' => 'Группа',
            'name' => 'Название пула',
            'description' => 'Описание',
            'filter_criteria' => 'Критерии фильтрации',
            'is_active' => 'Активен',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
        ];
    }

    /**
     * Связь с компанией
     */
    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Получает группу, которой принадлежит пул
     */
    public function getGroup()
    {
        return $this->hasOne(AccountGroup::className(), ['id' => 'group_id']);
    }

    /**
     * Получает счета, входящие в пул
     */
    public function getAccounts()
    {
        return $this->hasMany(Account::className(), ['pool_id' => 'id']);
    }

    /**
     * Получает отфильтрованные счета на основе критериев
     */
    public function getFilteredAccounts()
    {
        $query = Account::find()->where(['pool_id' => $this->id]);

        if ($this->filter_criteria) {
            $criteria = Json::decode($this->filter_criteria);

            if (!empty($criteria['currency'])) {
                $query->andFilterWhere(['currency' => $criteria['currency']]);
            }
            if (!empty($criteria['account_type'])) {
                $query->andFilterWhere(['account_type' => $criteria['account_type']]);
            }
            if (!empty($criteria['bank_code'])) {
                $query->andFilterWhere(['bank_code' => $criteria['bank_code']]);
            }
            if (!empty($criteria['country'])) {
                $query->andFilterWhere(['country' => $criteria['country']]);
            }
            if (isset($criteria['is_suspense'])) {
                $query->andFilterWhere(['is_suspense' => $criteria['is_suspense']]);
            }
        }

        return $query->all();
    }

    /**
     * Устанавливает критерии фильтрации
     */
    public function setFilterCriteria($criteria)
    {
        $this->_filterCriteria = $criteria;
        $this->filter_criteria = Json::encode($criteria);
    }

    /**
     * Получает критерии фильтрации
     */
    public function getFilterCriteria()
    {
        if ($this->_filterCriteria === null && $this->filter_criteria) {
            $this->_filterCriteria = Json::decode($this->filter_criteria);
        }
        return $this->_filterCriteria;
    }

    /**
     * Проверяет, соответствует ли счет критериям фильтрации
     */
    public function matchesFilter($account)
    {
        if (!$this->filter_criteria) {
            return true;
        }

        $criteria = $this->getFilterCriteria();

        if (!empty($criteria['currency']) && $account->currency != $criteria['currency']) {
            return false;
        }
        if (!empty($criteria['account_type']) && $account->account_type != $criteria['account_type']) {
            return false;
        }
        if (!empty($criteria['bank_code']) && $account->bank_code != $criteria['bank_code']) {
            return false;
        }
        if (!empty($criteria['country']) && $account->country != $criteria['country']) {
            return false;
        }
        if (isset($criteria['is_suspense']) && $account->is_suspense != $criteria['is_suspense']) {
            return false;
        }

        return true;
    }

    /**
     * Автоматическое обновление времени
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            if ($this->isNewRecord) {
                $this->created_at = date('Y-m-d H:i:s');
            }
            $this->updated_at = date('Y-m-d H:i:s');
            return true;
        }
        return false;
    }
}