<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Одна строка условия фильтрации пула.
 *
 * @property int    $id
 * @property int    $pool_id
 * @property string $field       — currency | account_type | bank_code | country | is_suspense | name | account_number
 * @property string $operator    — eq | neq
 * @property string $value
 * @property string $logic       — AND | OR
 * @property int    $sort_order
 * @property string $created_at
 *
 * @property AccountPool $pool
 */
class AccountPoolFilter extends ActiveRecord
{
    /** Доступные поля для фильтрации */
    public static function availableFields(): array
    {
        return [
            'currency'       => 'Валюта',
            'account_type'   => 'Тип счёта',
            'bank_code'      => 'Код банка (SWIFT/BIC)',
            'country'        => 'Страна',
            'name'           => 'Название счёта',
            'account_number' => 'Номер счёта',
            'is_suspense'    => 'Suspense счёт',
        ];
    }

    /** Доступные операторы */
    public static function availableOperators(): array
    {
        return [
            'eq'  => 'равно',
            'neq' => 'не равно',
        ];
    }

    public static function tableName(): string
    {
        return '{{%account_pool_filters}}';
    }

    public function rules(): array
    {
        return [
            [['pool_id', 'field', 'operator', 'value'], 'required'],
            [['pool_id', 'sort_order'], 'integer'],
            [['field'], 'in', 'range' => array_keys(self::availableFields())],
            [['operator'], 'in', 'range' => ['eq', 'neq']],
            [['logic'], 'in', 'range' => ['AND', 'OR']],
            [['value'], 'string', 'max' => 255],
            [['logic'], 'default', 'value' => 'AND'],
            [['sort_order'], 'default', 'value' => 0],
        ];
    }

    public function getPool()
    {
        return $this->hasOne(AccountPool::class, ['id' => 'pool_id']);
    }

    /**
     * Применяет условие к ActiveQuery на таблицу счетов.
     * Возвращает массив для andWhere/orWhere.
     */
    public function buildCondition(): array
    {
        $field = $this->field;
        $value = $this->value;

        // Для булевых полей
        if ($field === 'is_suspense') {
            $boolValue = in_array(strtolower($value), ['1', 'true', 'yes']) ? true : false;
            if ($this->operator === 'eq') {
                return [$field => $boolValue];
            } else {
                return ['<>', $field, $boolValue];
            }
        }

        if ($this->operator === 'eq') {
            return [$field => $value];
        } else {
            // neq
            return ['<>', $field, $value];
        }
    }
}