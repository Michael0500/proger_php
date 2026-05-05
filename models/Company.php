<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

class Company extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%company}}';
    }

    public function rules()
    {
        return [
            [['name', 'code'], 'required'],
            [['name', 'code'], 'string', 'max' => 100],
            [['name', 'code'], 'unique'],
            [['created_at', 'updated_at'], 'integer'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id'         => 'ID',
            'name'       => 'Наименование',
            'code'       => 'Код',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
        ];
    }

    public function isInv(): bool
    {
        return strtoupper($this->code) === 'INV';
    }

    /**
     * Получить всех пользователей компании
     */
    public function getUsers()
    {
        return $this->hasMany(User::className(), ['company_id' => 'id']);
    }

    /**
     * Получить все компании
     */
    public static function getAllCompanies()
    {
        return self::find()->all();
    }

    public function getAccounts()
    {
        return $this->hasMany(Account::class, ['company_id' => 'id']);
    }

    public function getAccountPools()
    {
        return $this->hasMany(AccountPool::class, ['company_id' => 'id']);
    }

    public function getCategories()
    {
        return $this->hasMany(Category::class, ['company_id' => 'id']);
    }
}