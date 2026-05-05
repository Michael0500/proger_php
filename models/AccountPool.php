<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель ностробанка — основная сущность группировки счетов.
 * Балансы и выверка привязаны к account_pools.
 * Может быть привязан к категории (Category) для отображения в сайдбаре выверки.
 *
 * @property int    $id
 * @property int    $company_id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $description
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Company   $company
 * @property Category|null $category
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
            [['company_id', 'category_id'], 'integer'],
            [['description'], 'safe'],
            [['name'], 'string', 'max' => 100],
            [['category_id'], 'exist', 'skipOnError' => true, 'skipOnEmpty' => true,
                'targetClass' => Category::class, 'targetAttribute' => ['category_id' => 'id']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'          => 'ID',
            'company_id'  => 'Компания',
            'category_id' => 'Категория',
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

    public function getCategory()
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
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
