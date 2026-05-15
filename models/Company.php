<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Компания-владелец данных в мультиарендной модели SmartMatch.
 *
 * Все бизнес-данные системы скоупятся по `company_id`. Код компании также
 * используется для определения специальных режимов, например INV.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int|null $created_at
 * @property int|null $updated_at
 *
 * @property User[] $users
 * @property Account[] $accounts
 * @property AccountPool[] $accountPools
 * @property Category[] $categories
 */
class Company extends ActiveRecord
{
    /**
     * Возвращает имя таблицы компаний.
     *
     * @return string Имя таблицы `company` с учётом префикса Yii.
     */
    public static function tableName()
    {
        return '{{%company}}';
    }

    /**
     * Описывает правила валидации компании.
     *
     * Название и код должны быть уникальны, потому что код используется
     * как бизнес-идентификатор компании.
     *
     * @return array Правила Yii Validator.
     */
    public function rules()
    {
        return [
            [['name', 'code'], 'required'],
            [['name', 'code'], 'string', 'max' => 100],
            [['name', 'code'], 'unique'],
            [['created_at', 'updated_at'], 'integer'],
        ];
    }

    /**
     * Возвращает подписи атрибутов компании.
     *
     * @return array Массив `attribute => label`.
     */
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

    /**
     * Проверяет, работает ли компания в режиме INV.
     *
     * @return bool `true`, если код компании равен `INV` без учёта регистра.
     */
    public function isInv(): bool
    {
        return strtoupper($this->code) === 'INV';
    }

    /**
     * Возвращает пользователей компании.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `User`.
     */
    public function getUsers()
    {
        return $this->hasMany(User::className(), ['company_id' => 'id']);
    }

    /**
     * Возвращает все компании системы.
     *
     * @return self[] Список компаний без дополнительной фильтрации.
     */
    public static function getAllCompanies()
    {
        return self::find()->all();
    }

    /**
     * Возвращает счета компании.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `Account`.
     */
    public function getAccounts()
    {
        return $this->hasMany(Account::class, ['company_id' => 'id']);
    }

    /**
     * Возвращает ностро-банки компании.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `AccountPool`.
     */
    public function getAccountPools()
    {
        return $this->hasMany(AccountPool::class, ['company_id' => 'id']);
    }

    /**
     * Возвращает категории компании.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `Category`.
     */
    public function getCategories()
    {
        return $this->hasMany(Category::class, ['company_id' => 'id']);
    }
}
