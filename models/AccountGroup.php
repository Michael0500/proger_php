<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Company $company
 * @property AccountPool[] $accountPools
 */
class AccountGroup extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%account_groups}}';
    }

    public function rules()
    {
        return [
            [['company_id', 'name'], 'required'],
            [['company_id'], 'integer'],
            [['description'], 'string'],
            [['name'], 'string', 'max' => 100],
            [['company_id'], 'exist', 'skipOnError' => true, 'targetClass' => Company::className(), 'targetAttribute' => ['company_id' => 'id']],
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

    /**
     * Получает компанию, которой принадлежит группа
     */
    public function getCompany()
    {
        return $this->hasOne(Company::className(), ['id' => 'company_id']);
    }

    /**
     * Получает пулы, входящие в группу
     */
    public function getAccountPools()
    {
        return $this->hasMany(AccountPool::className(), ['group_id' => 'id']);
    }

    /**
     * Получает все счета через пулы
     */
    public function getAccounts()
    {
        return $this->hasMany(Account::className(), ['id' => 'account_id'])
            ->via('accountPools');
    }

    /**
     * Получает группы для текущей компании пользователя
     */
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