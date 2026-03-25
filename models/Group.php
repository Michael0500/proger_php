<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Группа счетов внутри категории (ранее AccountPool)
 *
 * @property int    $id
 * @property int    $company_id
 * @property int    $category_id
 * @property string $name
 * @property string|null $description
 * @property bool   $is_active
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Category      $category
 * @property Company       $company
 * @property GroupFilter[]  $filters
 */
class Group extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%groups}}';
    }

    public function rules(): array
    {
        return [
            [['company_id', 'name', 'category_id'], 'required'],
            [['category_id', 'company_id'], 'integer'],
            [['description'], 'safe'],
            [['is_active'], 'boolean'],
            [['name'], 'string', 'max' => 100],
            [['category_id'], 'exist', 'skipOnError' => true,
                'targetClass' => Category::class,
                'targetAttribute' => ['category_id' => 'id']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'          => 'ID',
            'company_id'  => 'Компания',
            'category_id' => 'Категория',
            'name'        => 'Название группы',
            'description' => 'Описание',
            'is_active'   => 'Активна',
            'created_at'  => 'Создано',
            'updated_at'  => 'Обновлено',
        ];
    }

    // ─── Связи ───────────────────────────────────────────────────────────

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getCategory()
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    /** Условия фильтрации группы, упорядоченные по sort_order */
    public function getFilters()
    {
        return $this->hasMany(GroupFilter::class, ['group_id' => 'id'])
            ->orderBy(['sort_order' => SORT_ASC]);
    }

    // ─── Хуки ────────────────────────────────────────────────────────────

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
