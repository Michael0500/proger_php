<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Одна строка условия фильтрации группы (ранее AccountPoolFilter).
 *
 * @property int    $id
 * @property int    $group_id
 * @property string $field
 * @property string $operator    — eq | neq | between | gte | lte
 * @property string $value       — значение (для between: "дата1|дата2")
 * @property string $logic       — AND | OR
 * @property int    $sort_order
 * @property string $created_at
 */
class GroupFilter extends ActiveRecord
{
    public static function accountFields(): array
    {
        return [
            'account_id'      => 'Счёт (название)',
            'currency'        => 'Валюта счёта',
            'account_type'    => 'Тип счёта',
            'country'         => 'Страна',
            'is_suspense'     => 'Suspense счёт',
            'account_pool_id' => 'Ностро банк (пул)',
        ];
    }

    public static function entryFields(): array
    {
        return [
            'ls'             => 'L/S (Ledger/Statement)',
            'dc'             => 'D/C (Debit/Credit)',
            'match_status'   => 'Статус квитования',
            'entry_currency' => 'Валюта записи',
            'value_date'     => 'Дата валютирования',
            'post_date'      => 'Дата проводки',
        ];
    }

    public static function availableFields(): array
    {
        return array_merge(self::accountFields(), self::entryFields());
    }

    /** Поля с выбором из фиксированного списка */
    public static function selectFields(): array
    {
        return ['account_id', 'account_pool_id', 'ls', 'dc', 'match_status', 'is_suspense'];
    }

    /** Поля с выбором даты / диапазона */
    public static function dateFields(): array
    {
        return ['value_date', 'post_date'];
    }

    /** Операторы для конкретного поля */
    public static function operatorsForField(string $field): array
    {
        if (in_array($field, self::dateFields(), true)) {
            return [
                'eq'      => 'равно (конкретная дата)',
                'neq'     => 'не равно',
                'between' => 'диапазон дат',
                'gte'     => 'с даты (>=)',
                'lte'     => 'по дату (<=)',
            ];
        }
        return ['eq' => 'равно', 'neq' => 'не равно'];
    }

    public static function tableName(): string
    {
        return '{{%group_filters}}';
    }

    public function rules(): array
    {
        return [
            [['group_id', 'field', 'operator'], 'required'],
            [['group_id', 'sort_order'], 'integer'],
            [['field'], 'in', 'range' => array_keys(self::availableFields())],
            [['operator'], 'in', 'range' => ['eq', 'neq', 'between', 'gte', 'lte']],
            [['logic'], 'in', 'range' => ['AND', 'OR']],
            [['value'], 'string', 'max' => 255],
            [['value'], 'default', 'value' => ''],
            [['logic'], 'default', 'value' => 'AND'],
            [['sort_order'], 'default', 'value' => 0],
        ];
    }

    public function getGroup()
    {
        return $this->hasOne(Group::class, ['id' => 'group_id']);
    }

    public function isAccountField(): bool
    {
        return array_key_exists($this->field, self::accountFields());
    }

    public function isEntryField(): bool
    {
        return array_key_exists($this->field, self::entryFields());
    }

    /**
     * Условие для фильтрации по таблице accounts.
     * Возвращает null если поле — из nostro_entries.
     */
    public function buildAccountCondition(string $alias = ''): ?array
    {
        if ($this->isEntryField()) {
            return null;
        }

        $p     = $alias ? $alias . '.' : '';
        $field = $this->field;
        $value = $this->value;

        if ($field === 'account_id') {
            $col = $p . 'id';
            return $this->operator === 'eq' ? [$col => (int)$value] : ['<>', $col, (int)$value];
        }

        // Фильтр по ностробанку (account_pool_id → accounts.pool_id)
        if ($field === 'account_pool_id') {
            $col = $p . 'pool_id';
            return $this->operator === 'eq' ? [$col => (int)$value] : ['<>', $col, (int)$value];
        }

        if ($field === 'is_suspense') {
            $boolVal = in_array(strtolower($value), ['1', 'true', 'yes']);
            $col     = $p . 'is_suspense';
            return $this->operator === 'eq' ? [$col => $boolVal] : ['<>', $col, $boolVal];
        }

        $col = $p . $field;
        return $this->operator === 'eq' ? [$col => $value] : ['<>', $col, $value];
    }

    /**
     * Условие для фильтрации по таблице nostro_entries.
     * Возвращает null если поле — из accounts.
     */
    public function buildEntryCondition(string $alias = 'ne'): ?array
    {
        if ($this->isAccountField()) {
            return null;
        }

        $p     = $alias ? $alias . '.' : '';
        $field = $this->field;
        $value = $this->value;

        // entry_currency → реальная колонка "currency"
        $col = $p . ($field === 'entry_currency' ? 'currency' : $field);

        if (in_array($field, self::dateFields(), true)) {
            switch ($this->operator) {
                case 'between':
                    $parts = explode('|', $value, 2);
                    $from  = trim($parts[0] ?? '');
                    $to    = trim($parts[1] ?? '');
                    if ($from && $to)  return ['between', $col, $from, $to];
                    if ($from)         return ['>=', $col, $from];
                    if ($to)           return ['<=', $col, $to];
                    return null;
                case 'gte':  return ['>=', $col, $value];
                case 'lte':  return ['<=', $col, $value];
                case 'neq':  return ['<>', $col, $value];
                default:     return [$col => $value];
            }
        }

        return $this->operator === 'eq' ? [$col => $value] : ['<>', $col, $value];
    }
}
