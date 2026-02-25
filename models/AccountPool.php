<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель пула ностробанков
 *
 * @property int    $id
 * @property int    $company_id
 * @property int    $group_id
 * @property string $name
 * @property string|null $description
 * @property bool   $is_active
 * @property string $created_at
 * @property string $updated_at
 *
 * @property AccountGroup      $group
 * @property Account[]         $accounts
 * @property AccountPoolFilter[] $filters
 */
class AccountPool extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%account_pools}}';
    }

    public function rules(): array
    {
        return [
            [['company_id', 'name', 'group_id'], 'required'],
            [['group_id', 'company_id'], 'integer'],
            [['description'], 'safe'],
            [['is_active'], 'boolean'],
            [['name'], 'string', 'max' => 100],
            [['group_id'], 'exist', 'skipOnError' => true,
                'targetClass' => AccountGroup::class,
                'targetAttribute' => ['group_id' => 'id']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'          => 'ID',
            'company_id'  => 'Компания',
            'group_id'    => 'Группа',
            'name'        => 'Название пула',
            'description' => 'Описание',
            'is_active'   => 'Активен',
            'created_at'  => 'Создано',
            'updated_at'  => 'Обновлено',
        ];
    }

    // ─── Связи ───────────────────────────────────────────────────────────

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getGroup()
    {
        return $this->hasOne(AccountGroup::class, ['id' => 'group_id']);
    }

    public function getAccounts()
    {
        return $this->hasMany(Account::class, ['pool_id' => 'id']);
    }

    /** Условия фильтрации пула, упорядоченные по sort_order */
    public function getFilters()
    {
        return $this->hasMany(AccountPoolFilter::class, ['pool_id' => 'id'])
            ->orderBy(['sort_order' => SORT_ASC]);
    }

    // ─── Фильтрация счетов ────────────────────────────────────────────────

    /**
     * Возвращает все счета компании, подходящие под критерии фильтров.
     * Если фильтров нет — возвращает все счета компании.
     */
    public function getFilteredAccounts(): array
    {
        $query = Account::find()
            ->where(['company_id' => Yii::$app->user->identity->company_id]);

        $filters = $this->filters; // загружается через связь

        if (empty($filters)) {
            return $query->all();
        }

        $this->applyFiltersToQuery($query, $filters);

        return $query->all();
    }

    /**
     * Применяет набор AccountPoolFilter к ActiveQuery.
     * Условия с logic=AND добавляются через andWhere,
     * условия с logic=OR — через orWhere.
     * Первое условие всегда andWhere (не зависит от его logic).
     */
    private function applyFiltersToQuery(\yii\db\ActiveQuery $query, array $filters): void
    {
        $first = true;
        foreach ($filters as $filter) {
            /** @var AccountPoolFilter $filter */
            $condition = $filter->buildCondition();
            if ($first) {
                $query->andWhere($condition);
                $first = false;
            } elseif ($filter->logic === 'OR') {
                $query->orWhere($condition);
            } else {
                $query->andWhere($condition);
            }
        }
    }

    /**
     * Проверяет, соответствует ли счёт всем активным фильтрам пула (PHP-сторона).
     * Используется, если нужна проверка без запроса в БД.
     */
    public function matchesFilter(Account $account): bool
    {
        $filters = $this->filters;
        if (empty($filters)) {
            return true;
        }

        $result = null;
        foreach ($filters as $filter) {
            /** @var AccountPoolFilter $filter */
            $matches = $this->evaluateFilter($filter, $account);

            if ($result === null) {
                $result = $matches;
            } elseif ($filter->logic === 'OR') {
                $result = $result || $matches;
            } else {
                $result = $result && $matches;
            }
        }

        return (bool) $result;
    }

    private function evaluateFilter(AccountPoolFilter $filter, Account $account): bool
    {
        $field    = $filter->field;
        $value    = $filter->value;
        $operator = $filter->operator;

        $accountValue = $account->$field ?? null;

        if ($field === 'is_suspense') {
            $boolValue    = in_array(strtolower($value), ['1', 'true', 'yes']);
            $accountBool  = (bool) $accountValue;
            return $operator === 'eq' ? ($accountBool === $boolValue) : ($accountBool !== $boolValue);
        }

        if ($operator === 'eq') {
            return (string) $accountValue === (string) $value;
        } else {
            return (string) $accountValue !== (string) $value;
        }
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