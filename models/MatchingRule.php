<?php


namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Правило автоматического квитования.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $section          NRE | INV
 * @property string $pair_type        LS | LL | SS
 * @property bool $match_dc
 * @property bool $match_amount
 * @property bool $match_value_date
 * @property bool $match_instruction_id
 * @property bool $match_end_to_end_id
 * @property bool $match_transaction_id
 * @property bool $match_message_id
 * @property bool $cross_id_search
 * @property bool $is_active
 * @property int $priority
 * @property string $description
 */
class MatchingRule extends ActiveRecord
{
    const SECTION_NRE = 'NRE';
    const SECTION_INV = 'INV';

    // Типы пар
    const PAIR_LS = 'LS'; // Ledger + Statement
    const PAIR_LL = 'LL'; // Ledger + Ledger
    const PAIR_SS = 'SS'; // Statement + Statement

    public static function tableName(): string
    {
        return '{{%matching_rules}}';
    }

    public function rules(): array
    {
        return [
            [['company_id', 'name', 'section', 'pair_type'], 'required'],
            [['company_id', 'priority'], 'integer'],
            [['section'], 'in', 'range' => [self::SECTION_NRE, self::SECTION_INV]],
            [['pair_type'], 'in', 'range' => [self::PAIR_LS, self::PAIR_LL, self::PAIR_SS]],
            [['match_dc', 'match_amount', 'match_value_date',
                'match_instruction_id', 'match_end_to_end_id',
                'match_transaction_id', 'match_message_id',
                'cross_id_search', 'is_active'], 'boolean'],
            [['name'], 'string', 'max' => 100],
            [['description'], 'string'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Название правила',
            'section' => 'Раздел',
            'pair_type' => 'Тип пары',
            'match_dc' => 'Противоположный Дебет/Кредит',
            'match_amount' => 'Совпадение суммы',
            'match_value_date' => 'Совпадение даты валютирования',
            'match_instruction_id' => 'Instruction ID',
            'match_end_to_end_id' => 'EndToEnd ID',
            'match_transaction_id' => 'Transaction ID',
            'match_message_id' => 'Message ID',
            'cross_id_search' => 'Перекрёстный поиск ID',
            'is_active' => 'Активно',
            'priority' => 'Приоритет',
            'description' => 'Описание',
        ];
    }

    public function beforeSave($insert): bool
    {
        if (parent::beforeSave($insert)) {
            $this->updated_at = date('Y-m-d H:i:s');
            if ($insert) $this->created_at = date('Y-m-d H:i:s');
            return true;
        }
        return false;
    }

    public static function sectionList(): array
    {
        return [self::SECTION_NRE => 'NRE', self::SECTION_INV => 'INV'];
    }

    public static function pairTypeList(): array
    {
        return [
            self::PAIR_LS => 'Ledger ↔ Statement',
            self::PAIR_LL => 'Ledger ↔ Ledger',
            self::PAIR_SS => 'Statement ↔ Statement',
        ];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Описание условий правила одной строкой (для отображения в таблице)
     */
    public function getConditionsSummary(): string
    {
        $parts = [];
        if ($this->match_dc) $parts[] = 'D/C';
        if ($this->match_amount) $parts[] = 'Сумма';
        if ($this->match_value_date) $parts[] = 'Дата';
        if ($this->match_instruction_id) $parts[] = 'Instruction';
        if ($this->match_end_to_end_id) $parts[] = 'EndToEnd';
        if ($this->match_transaction_id) $parts[] = 'Transaction';
        if ($this->match_message_id) $parts[] = 'Message';
        if ($this->cross_id_search) $parts[] = '⟷ Перекрёстный';
        return implode(', ', $parts) ?: '—';
    }
}