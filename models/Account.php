<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Ностро-счёт компании.
 *
 * Модель описывает конкретный счёт внутри ностро-банка (`AccountPool`).
 * Счёт используется операциями выверки и балансовыми записями; флаг
 * `is_suspense` переводит начальный баланс в раздел INV.
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

    /**
     * Возвращает имя таблицы ностро-счетов.
     *
     * @return string Имя таблицы `accounts`.
     */
    public static function tableName()
    {
        return 'accounts';
    }

    /**
     * Описывает правила валидации счёта.
     *
     * Валидируются принадлежность компании, опциональная привязка к
     * ностро-банку, тип счёта L/S, валюта, страна и признаки загрузки.
     *
     * @return array Правила Yii Validator.
     */
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

    /**
     * Возвращает подписи атрибутов счёта для форм и таблиц.
     *
     * @return array Массив `attribute => label`.
     */
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
     * Возвращает связь со справочником ностро-банков.
     *
     * @return \yii\db\ActiveQuery Запрос связи `accounts.pool_id -> account_pools.id`.
     */
    public function getPool()
    {
        return $this->hasOne(AccountPool::class, ['id' => 'pool_id']);
    }

    /**
     * Возвращает связь с компанией-владельцем счёта.
     *
     * @return \yii\db\ActiveQuery Запрос связи `accounts.company_id -> company.id`.
     */
    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Возвращает запрос счетов, доступных текущему пользователю.
     *
     * Если у пользователя не выбрана компания, возвращается пустой запрос.
     * Метод используется для сохранения tenant-изоляции на уровне выборок UI.
     *
     * @return \yii\db\ActiveQuery Запрос счетов текущей компании.
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
     * Заполняет служебные поля создания и изменения перед сохранением.
     *
     * При создании фиксирует автора и дату создания, при любом сохранении
     * обновляет автора и дату изменения.
     *
     * @param bool $insert Признак создания новой строки.
     * @return bool Можно ли продолжать сохранение.
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

    /**
     * Создаёт начальный нулевой баланс после добавления счёта.
     *
     * Побочный эффект выполняется только при вставке новой строки. Создаётся
     * один баланс с типом из `account_type`, валютой счёта и разделом NRE/INV.
     *
     * @param bool $insert Признак создания новой строки.
     * @param array $changedAttributes Старые значения изменённых атрибутов.
     * @return void
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert) {
            $this->createInitialBalances();
        }
    }

    /**
     * Создаёт начальную запись баланса с нулевым остатком.
     *
     * Баланс нужен, чтобы новый счёт сразу участвовал в отчётах остатков.
     * Тип L/S берётся из `account_type`, а раздел определяется признаком
     * `is_suspense`.
     *
     * @return void
     */
    private function createInitialBalances(): void
    {
        $balance = new NostroBalance();
        $balance->company_id      = $this->company_id;
        $balance->account_id      = $this->id;
        $balance->ls_type         = $this->account_type ?: NostroBalance::LS_LEDGER;
        $balance->currency        = $this->currency ?: 'RUB';
        $balance->value_date      = $this->date_open ?: date('Y-m-d');
        $balance->opening_balance = 0;
        $balance->opening_dc      = NostroBalance::DC_CREDIT;
        $balance->closing_balance = 0;
        $balance->closing_dc      = NostroBalance::DC_CREDIT;
        $balance->section         = $this->is_suspense ? NostroBalance::SECTION_INV : NostroBalance::SECTION_NRE;
        $balance->source          = NostroBalance::SOURCE_MANUAL;
        $balance->status          = NostroBalance::STATUS_NORMAL;
        $balance->save(false);
    }
}
