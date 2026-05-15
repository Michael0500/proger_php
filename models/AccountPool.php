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
    /**
     * Возвращает имя таблицы ностро-банков.
     *
     * @return string Имя таблицы `account_pools` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%account_pools}}';
    }

    /**
     * Описывает правила валидации ностро-банка.
     *
     * Ностро-банк всегда принадлежит компании и может быть привязан к категории
     * для навигации и отчётности.
     *
     * @return array Правила Yii Validator.
     */
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

    /**
     * Возвращает подписи атрибутов ностро-банка.
     *
     * @return array Массив `attribute => label`.
     */
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

    /**
     * Возвращает связь с компанией-владельцем ностро-банка.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `Company`.
     */
    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Возвращает связанную категорию навигации.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `Category`.
     */
    public function getCategory()
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    /**
     * Возвращает счета, входящие в ностро-банк.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `Account`.
     */
    public function getAccounts()
    {
        return $this->hasMany(Account::class, ['pool_id' => 'id']);
    }

    /**
     * Заполняет даты создания и изменения ностро-банка.
     *
     * @param bool $insert Признак создания новой строки.
     * @return bool Можно ли продолжать сохранение.
     */
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
