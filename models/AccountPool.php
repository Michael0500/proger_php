<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель ностробанка (пула счетов) — техническая группировка.
 * Балансы и выверка привязаны к account_pools.
 *
 * @property int    $id
 * @property int    $company_id
 * @property string $name
 * @property string|null $description
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Company   $company
 * @property Account[] $accounts
 */
class AccountPool extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%account_pools}}';
    }

    public function rules(): array
    {
        return [
            [['company_id', 'name'], 'required'],
            [['company_id'], 'integer'],
            [['description'], 'safe'],
            [['name'], 'string', 'max' => 100],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'          => 'ID',
            'company_id'  => 'Компания',
            'name'        => 'Название',
            'description' => 'Описание',
            'created_at'  => 'Создано',
            'updated_at'  => 'Обновлено',
        ];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getAccounts()
    {
        return $this->hasMany(Account::class, ['pool_id' => 'id']);
    }

    public function beforeSave($insert): bool
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
