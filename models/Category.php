<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Категория навигации и группировки ностро-банков.
 *
 * Категория является верхним уровнем сайдбара выверки и может содержать
 * несколько `AccountPool`. Все записи категории должны быть ограничены
 * `company_id` текущего пользователя.
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
    /**
     * Возвращает имя таблицы категорий.
     *
     * @return string Имя таблицы `categories` с учётом префикса Yii.
     */
    public static function tableName()
    {
        return '{{%categories}}';
    }

    /**
     * Описывает правила валидации категории.
     *
     * @return array Правила Yii Validator.
     */
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

    /**
     * Возвращает подписи атрибутов категории.
     *
     * @return array Массив `attribute => label`.
     */
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
     * Возвращает связь с компанией-владельцем категории.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `Company`.
     */
    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Возвращает ностро-банки категории в алфавитном порядке.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `AccountPool`.
     */
    public function getPools()
    {
        return $this->hasMany(AccountPool::class, ['category_id' => 'id'])
            ->orderBy(['name' => SORT_ASC]);
    }

    /**
     * Возвращает запрос категорий текущей компании пользователя.
     *
     * Если пользователь не авторизован или компания не выбрана, возвращается
     * пустой запрос, чтобы не раскрывать данные других компаний.
     *
     * @return \yii\db\ActiveQuery Запрос категорий текущей компании.
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
