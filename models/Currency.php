<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Справочник валют (общесистемный).
 *
 * @property int    $id
 * @property string $code        ISO 4217 (USD, EUR, RUB...)
 * @property string $name
 * @property string|null $symbol
 * @property bool   $is_active
 * @property int    $sort_order
 * @property string $created_at
 * @property string $updated_at
 */
class Currency extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%currencies}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name'], 'required'],
            [['code'], 'string', 'max' => 3],
            [['code'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'message' => 'Код должен состоять из 3 заглавных латинских букв'],
            [['code'], 'unique'],
            [['name'], 'string', 'max' => 100],
            [['symbol'], 'string', 'max' => 8],
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
            'code'       => 'Код',
            'name'       => 'Название',
            'symbol'     => 'Символ',
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
        if ($insert && empty($this->created_at)) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        $this->updated_at = date('Y-m-d H:i:s');
        return true;
    }

    /**
     * Возвращает список активных валют, отсортированный по sort_order, name.
     *
     * @return self[]
     */
    public static function activeList(): array
    {
        return self::find()
            ->where(['is_active' => true])
            ->orderBy(['sort_order' => SORT_ASC, 'code' => SORT_ASC])
            ->all();
    }

    /**
     * Простой массив кодов активных валют для select-ов и select2.
     *
     * @return string[]
     */
    public static function activeCodes(): array
    {
        return self::find()
            ->select('code')
            ->where(['is_active' => true])
            ->orderBy(['sort_order' => SORT_ASC, 'code' => SORT_ASC])
            ->column();
    }
}
