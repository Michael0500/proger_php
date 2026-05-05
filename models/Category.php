<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Категория группировки счетов (ранее AccountGroup)
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Company $company
 * @property AccountPool[] $pools
 */
class Category extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%categories}}';
    }

    public function rules()
    {
        return [
            [['company_id', 'name'], 'required'],
            [['company_id'], 'integer'],
            [['description'], 'string'],
            [['name'], 'string', 'max' => 100],
            [['company_id'], 'exist', 'skipOnError' => true, 'targetClass' => Company::class, 'targetAttribute' => ['company_id' => 'id']],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'company_id' => 'Компания',
            'name' => 'Название',
            'description' => 'Описание',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
        ];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getPools()
    {
        return $this->hasMany(AccountPool::class, ['category_id' => 'id'])
            ->orderBy(['name' => SORT_ASC]);
    }

    public static function findForCurrentUser()
    {
        $userId = Yii::$app->user->id;
        $user = User::findOne($userId);

        if (!$user || !$user->company_id) {
            return static::find()->where('1=0');
        }

        return static::find()->where(['company_id' => $user->company_id]);
    }
}
