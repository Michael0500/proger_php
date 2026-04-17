<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Модель баланса Ностро счёта (Opening / Closing Balance).
 *
 * @property int         $id
 * @property int         $company_id
 * @property int         $account_id
 * @property string      $ls_type           L|S
 * @property string|null $statement_number  Только для S
 * @property string      $currency          ISO 4217
 * @property string      $value_date        DATE (YYYY-MM-DD в БД)
 * @property float       $opening_balance
 * @property string      $opening_dc        D|C
 * @property float       $closing_balance
 * @property string      $closing_dc        D|C
 * @property string      $section           NRE|INV
 * @property string      $source
 * @property string      $status            normal|error|confirmed
 * @property string|null $comment
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property string      $created_at
 * @property string      $updated_at
 *
 * @property Account     $account
 * @property Company     $company
 */
class NostroBalance extends ActiveRecord
{
    // ls_type
    const LS_LEDGER    = 'L';
    const LS_STATEMENT = 'S';

    // D/C
    const DC_DEBIT  = 'D';
    const DC_CREDIT = 'C';

    // Статусы
    const STATUS_NORMAL    = 'normal';
    const STATUS_ERROR     = 'error';
    const STATUS_CONFIRMED = 'confirmed';

    // Разделы
    const SECTION_NRE = 'NRE';
    const SECTION_INV = 'INV';

    // Источники
    const SOURCE_BND     = 'BND';
    const SOURCE_ASB     = 'ASB';
    const SOURCE_MT950   = 'MT950';
    const SOURCE_CAMT    = 'CAMT';
    const SOURCE_ED211   = 'ED211';
    const SOURCE_FCC12   = 'FCC12';
    const SOURCE_BARS_GL = 'BARS_GL';
    const SOURCE_MANUAL  = 'MANUAL';

    public static function tableName(): string
    {
        return '{{%nostro_balance}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class'              => TimestampBehavior::class,
                'value'              => new Expression('NOW()'),
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
            ],
            [
                'class'              => BlameableBehavior::class,
                'createdByAttribute' => 'created_by',
                'updatedByAttribute' => 'updated_by',
            ],
        ];
    }

    public function rules(): array
    {
        return [
            // Обязательные
            [['company_id', 'account_id', 'ls_type', 'currency', 'value_date',
                'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
                'section', 'source'], 'required'],

            // Числа
            [['company_id', 'account_id', 'created_by', 'updated_by'], 'integer'],
            [['opening_balance', 'closing_balance'], 'number'],

            // Строки
            [['ls_type', 'opening_dc', 'closing_dc'], 'string', 'max' => 1],
            [['currency'], 'string', 'max' => 3],
            [['section'], 'string', 'max' => 3],
            [['source'], 'string', 'max' => 20],
            [['status'], 'string', 'max' => 10],
            [['statement_number'], 'string', 'max' => 35],
            [['comment'], 'string', 'max' => 255],

            // Даты
            [['value_date'], 'date', 'format' => 'php:Y-m-d'],
            [['created_at', 'updated_at'], 'safe'],

            // Enum-проверки
            [['ls_type'],    'in', 'range' => [self::LS_LEDGER, self::LS_STATEMENT]],
            [['opening_dc', 'closing_dc'], 'in', 'range' => [self::DC_DEBIT, self::DC_CREDIT]],
            [['status'],     'in', 'range' => [self::STATUS_NORMAL, self::STATUS_ERROR, self::STATUS_CONFIRMED]],
            [['section'],    'in', 'range' => [self::SECTION_NRE, self::SECTION_INV]],

            // statement_number обязателен для S
            [['statement_number'], 'required', 'when' => function ($model) {
                return $model->ls_type === self::LS_STATEMENT;
            }, 'whenClient' => "function(attribute, value) { return $('#ls_type').val() === 'S'; }"],

            // Defaults
            [['status'], 'default', 'value' => self::STATUS_NORMAL],

            // FK
            [['account_id'], 'exist', 'targetClass' => Account::class,
                'targetAttribute' => ['account_id' => 'id']],
            [['company_id'], 'exist', 'targetClass' => Company::class,
                'targetAttribute' => ['company_id' => 'id']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'               => 'ID',
            'company_id'       => 'Компания',
            'account_id'       => 'Ностро счёт',
            'ls_type'          => 'Тип (L/S)',
            'statement_number' => 'Номер выписки',
            'currency'         => 'Валюта',
            'value_date'       => 'Дата валютирования',
            'opening_balance'  => 'Opening Balance',
            'opening_dc'       => 'D/C Opening',
            'closing_balance'  => 'Closing Balance',
            'closing_dc'       => 'D/C Closing',
            'section'          => 'Раздел',
            'source'           => 'Источник',
            'status'           => 'Статус',
            'comment'          => 'Комментарий',
            'created_by'       => 'Создал',
            'updated_by'       => 'Изменил',
            'created_at'       => 'Создано',
            'updated_at'       => 'Изменено',
        ];
    }

    // ─── Отношения ────────────────────────────────────────────────

    public function getAccount(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Account::class, ['id' => 'account_id']);
    }

    public function getCompany(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getAuditLogs(): \yii\db\ActiveQuery
    {
        return $this->hasMany(NostroBalanceAudit::class, ['balance_id' => 'id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    // ─── Хелперы ──────────────────────────────────────────────────

    /**
     * Дата в формате DD/MM/YYYY для вывода в UI
     */
    public function getValueDateFormatted(): string
    {
        if (!$this->value_date) return '';
        return date('d.m.Y', strtotime($this->value_date));
    }

    /**
     * Иконка статуса
     */
    public function getStatusIcon(): string
    {
        if ($this->status === self::STATUS_ERROR)     return '🔴';
        if ($this->status === self::STATUS_CONFIRMED) return '⚫';
        return '⚪';
    }

    /**
     * Список источников для формы
     */
    public static function sourceList(): array
    {
        return [
            self::SOURCE_BND     => 'Банк-клиент БНД',
            self::SOURCE_ASB     => 'Банк-клиент АСБ',
            self::SOURCE_MT950   => 'MT950',
            self::SOURCE_CAMT    => 'camt.053',
            self::SOURCE_ED211   => 'ED211',
            self::SOURCE_FCC12   => 'FCC12',
            self::SOURCE_BARS_GL => 'BARS GL',
            self::SOURCE_MANUAL  => 'Ручной ввод',
        ];
    }

    /**
     * Преобразовать модель в массив для JSON-ответа
     */
    public function toApiArray(): array
    {
        return [
            'id'               => $this->id,
            'account_id'       => $this->account_id,
            'account_name'     => $this->account ? $this->account->name : '—',
            'ls_type'          => $this->ls_type,
            'statement_number' => $this->statement_number,
            'currency'         => $this->currency,
            'value_date'       => $this->value_date,
            'value_date_fmt'   => $this->getValueDateFormatted(),
            'opening_balance'  => (float)$this->opening_balance,
            'opening_dc'       => $this->opening_dc,
            'closing_balance'  => (float)$this->closing_balance,
            'closing_dc'       => $this->closing_dc,
            'section'          => $this->section,
            'source'           => $this->source,
            'status'           => $this->status,
            'comment'          => $this->comment,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }

    // ─── Валидация последовательности и балансов ─────────────────

    /**
     * Проверить, совпадает ли opening_balance текущей записи
     * с closing_balance предыдущей (по той же account+currency+section).
     * Возвращает null если ОК, или строку с описанием расхождения.
     */
    public function checkBalanceContinuity(float $tolerance = 0.01): ?string
    {
        if ($this->ls_type !== self::LS_STATEMENT) {
            return null; // для Ledger не проверяем
        }

        $prev = static::find()
            ->where([
                'account_id' => $this->account_id,
                'currency'   => $this->currency,
                'section'    => $this->section,
                'ls_type'    => self::LS_STATEMENT,
            ])
            ->andWhere(['<', 'value_date', $this->value_date])
            ->andWhere(['!=', 'id', $this->id ?? 0])
            ->orderBy(['value_date' => SORT_DESC])
            ->one();

        if (!$prev) return null;

        // Сравниваем со знаком: D = отрицательный, C = положительный
        $prevClosing  = $this->signedAmount($prev->closing_balance,  $prev->closing_dc);
        $thisOpening  = $this->signedAmount($this->opening_balance, $this->opening_dc);

        if (abs($prevClosing - $thisOpening) > $tolerance) {
            return sprintf(
                'Opening Balance (%.2f %s) не совпадает с Closing Balance предыдущей выписки (%.2f %s) за %s',
                $this->opening_balance,
                $this->opening_dc,
                $prev->closing_balance,
                $prev->closing_dc,
                $prev->getValueDateFormatted()
            );
        }

        return null;
    }

    /**
     * Проверить уникальность и последовательность номеров выписок
     * для данного account+currency+section.
     */
    public function checkStatementSequence(): ?string
    {
        if ($this->ls_type !== self::LS_STATEMENT || !$this->statement_number) {
            return null;
        }

        // Проверка на дубликат номера
        $dup = static::find()
            ->where([
                'account_id'       => $this->account_id,
                'currency'         => $this->currency,
                'section'          => $this->section,
                'ls_type'          => self::LS_STATEMENT,
                'statement_number' => $this->statement_number,
            ])
            ->andWhere(['!=', 'id', $this->id ?? 0])
            ->exists();

        if ($dup) {
            return "Дублирующийся номер выписки: {$this->statement_number}";
        }

        return null;
    }

    /**
     * Запускает все проверки и устанавливает status/comment
     */
    public function runValidations(array $settings = []): void
    {
        $enableSeq = $settings['enable_sequence_check'] ?? true;
        $enableBal = $settings['enable_balance_check']  ?? true;
        $tolerance = (float)($settings['balance_tolerance'] ?? 0.01);

        $errors = [];

        if ($enableSeq) {
            $e = $this->checkStatementSequence();
            if ($e) $errors[] = $e;
        }

        if ($enableBal) {
            $e = $this->checkBalanceContinuity($tolerance);
            if ($e) $errors[] = $e;
        }

        if (!empty($errors)) {
            $this->status  = self::STATUS_ERROR;
            $this->comment = mb_substr(implode('; ', $errors), 0, 255);
        } elseif ($this->status === self::STATUS_ERROR) {
            // Если ошибок больше нет — сбрасываем в normal
            $this->status  = self::STATUS_NORMAL;
            $this->comment = null;
        }
    }

    // ─── Приватные хелперы ────────────────────────────────────────

    private function signedAmount(float $amount, string $dc): float
    {
        return $dc === self::DC_DEBIT ? -$amount : $amount;
    }
}