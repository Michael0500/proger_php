<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель ностробанка
 *
 * @property int $id
 * @property int $company_id
 * @property int $pool_id
 * @property string $name
 * @property bool $is_suspense
 * @property string $load_status
 * @property string|null $date_open
 * @property string|null $date_close
 * @property bool $load_barsgl
 * @property int|null $created_by
 * @property string $created_at
 * @property int|null $updated_by
 * @property string $updated_at
 */
class Account extends ActiveRecord
{
    const SECTION_NRE = 'nre';
    const SECTION_INV = 'inv';

    public static function tableName()
    {
        return 'accounts';
    }

    public function rules()
    {
        return [
            [['company_id', 'name'], 'required'],
            [['pool_id'], 'integer', 'skipOnEmpty' => true],
            [['company_id', 'pool_id', 'created_by', 'updated_by'], 'integer'],
            [['is_suspense', 'load_barsgl'], 'boolean'],
            [['date_open', 'date_close', 'created_at', 'updated_at'], 'safe'],
            [['name'], 'string', 'max' => 55],
            [['currency'], 'string', 'max' => 3],
            [['account_type'], 'required', 'message' => 'Тип счёта обязателен'],
            [['account_type'], 'string', 'max' => 50],
            [['country'], 'string', 'max' => 50],
            [['load_status'], 'string', 'max' => 1],
            [['load_status'], 'default', 'value' => 'L'],
            [['is_suspense'], 'default', 'value' => false],
            [['load_barsgl'], 'default', 'value' => false],
            [['pool_id'], 'exist', 'skipOnError' => true, 'skipOnEmpty' => true, 'targetClass' => AccountPool::class, 'targetAttribute' => ['pool_id' => 'id']],
            [['company_id'], 'exist', 'skipOnError' => true, 'targetClass' => Company::class, 'targetAttribute' => ['company_id' => 'id']],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'company_id' => 'Компания',
            'pool_id' => 'Группа',
            'name' => 'Название банка',
            'is_suspense' => 'Suspense счет (для INV)',
            'load_status' => 'Статус загрузки',
            'date_open' => 'Дата открытия',
            'date_close' => 'Дата закрытия',
            'load_barsgl' => 'Load BAR/SGL',
            'created_by' => 'Создал',
            'created_at' => 'Создан',
            'updated_by' => 'Обновил',
            'updated_at' => 'Обновлен',
        ];
    }

    /**
     * Связь с пулом (ностробанком)
     */
    public function getPool()
    {
        return $this->hasOne(AccountPool::class, ['id' => 'pool_id']);
    }

    /**
     * Связь с компанией
     */
    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Получает счета для текущей компании пользователя
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

    /**
     * Автоматическое обновление времени и пользователя
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            if ($this->isNewRecord) {
                $this->created_at = date('Y-m-d H:i:s');
                $this->created_by = Yii::$app->user->id ?? 1;
            }
            $this->updated_at = date('Y-m-d H:i:s');
            $this->updated_by = Yii::$app->user->id ?? 1;
            return true;
        }
        return false;
    }
}