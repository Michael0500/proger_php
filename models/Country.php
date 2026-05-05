<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Справочник стран (общесистемный).
 *
 * @property int    $id
 * @property string $code        ISO 3166-1 alpha-2 (RU, US, DE...)
 * @property string|null $code3  ISO 3166-1 alpha-3 (RUS, USA, DEU...)
 * @property string $name
 * @property bool   $is_active
 * @property int    $sort_order
 * @property string $created_at
 * @property string $updated_at
 */
class Country extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%countries}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name'], 'required'],
            [['code'], 'string', 'max' => 2],
            [['code'], 'match', 'pattern' => '/^[A-Z]{2}$/', 'message' => 'Код должен состоять из 2 заглавных латинских букв'],
            [['code'], 'unique'],
            [['code3'], 'string', 'max' => 3],
            [['code3'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'message' => 'Alpha-3 код должен состоять из 3 заглавных латинских букв', 'skipOnEmpty' => true],
            [['name'], 'string', 'max' => 150],
            [['is_active'], 'boolean'],
            [['sort_order'], 'integer'],
            [['is_active'], 'default', 'value' => true],
            [['sort_order'], 'default', 'value' => 0],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'         => 'ID',
            'code'       => 'Код (2)',
            'code3'      => 'Код (3)',
            'name'       => 'Название',
            'is_active'  => 'Активна',
            'sort_order' => 'Порядок',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $this->code = strtoupper(trim($this->code));
        if ($this->code3 !== null && $this->code3 !== '') {
            $this->code3 = strtoupper(trim($this->code3));
        } else {
            $this->code3 = null;
        }
        if ($insert && empty($this->created_at)) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        $this->updated_at = date('Y-m-d H:i:s');
        return true;
    }

    /**
     * Возвращает список активных стран, отсортированный по sort_order, name.
     *
     * @return self[]
     */
    public static function activeList(): array
    {
        return self::find()
            ->where(['is_active' => true])
            ->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])
            ->all();
    }
}
