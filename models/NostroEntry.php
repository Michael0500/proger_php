<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Запись (данные) для выверки Ностро.
 *
 * @property int         $id
 * @property int         $account_id
 * @property int         $company_id
 * @property string|null $match_id
 * @property string      $ls           L=Ledger, S=Statement
 * @property string      $dc           Debit|Credit
 * @property float       $amount
 * @property string      $currency
 * @property string|null $value_date
 * @property string|null $post_date
 * @property string|null $instruction_id
 * @property string|null $end_to_end_id
 * @property string|null $transaction_id
 * @property string|null $message_id
 * @property string|null $other_id
 * @property string|null $comment
 * @property string|null $source
 * @property string      $match_status  U/M/I
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property string      $created_at
 * @property string      $updated_at
 *
 * @property Account     $account
 * @property Company     $company
 * @property NostroEntryAudit[] $audits
 */
class NostroEntry extends ActiveRecord
{
    // Типы записей
    const LS_LEDGER    = 'L';
    const LS_STATEMENT = 'S';

    // Тип операции
    const DC_DEBIT  = 'Debit';
    const DC_CREDIT = 'Credit';

    // Статусы квитования
    const STATUS_UNMATCHED = 'U';
    const STATUS_MATCHED   = 'M';
    const STATUS_IGNORED   = 'I';

    public static function tableName(): string
    {
        return '{{%nostro_entries}}';
    }

    public function rules(): array
    {
        return [
            [['account_id', 'company_id', 'ls', 'dc', 'amount', 'currency'], 'required'],
            [['account_id', 'company_id', 'created_by', 'updated_by'], 'integer'],
            [['amount'], 'number'],
            [['value_date', 'post_date', 'created_at', 'updated_at'], 'safe'],
            [['ls'], 'string', 'max' => 1],
            [['ls'], 'in', 'range' => [self::LS_LEDGER, self::LS_STATEMENT]],
            [['dc'], 'string', 'max' => 6],
            [['dc'], 'in', 'range' => [self::DC_DEBIT, self::DC_CREDIT]],
            [['currency'], 'string', 'max' => 3],
            [['match_id'], 'string', 'max' => 255],
            [['instruction_id', 'message_id', 'end_to_end_id', 'other_id'], 'string', 'max' => 40],
            [['transaction_id'], 'string', 'max' => 60],
            [['comment'], 'string', 'max' => 40],
            [['source'], 'string', 'max' => 20],
            [['match_status'], 'string', 'max' => 1],
            [['match_status'], 'in', 'range' => [self::STATUS_UNMATCHED, self::STATUS_MATCHED, self::STATUS_IGNORED]],
            [['match_status'], 'default', 'value' => self::STATUS_UNMATCHED],
            [['account_id'], 'exist', 'targetClass' => Account::class, 'targetAttribute' => ['account_id' => 'id']],
            [['company_id'], 'exist', 'targetClass' => Company::class, 'targetAttribute' => ['company_id' => 'id']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'             => 'ID',
            'account_id'     => 'Ностро банк',
            'company_id'     => 'Компания',
            'match_id'       => 'Match ID',
            'ls'             => 'L/S',
            'dc'             => 'D/C',
            'amount'         => 'Сумма',
            'currency'       => 'Валюта',
            'value_date'     => 'Дата валютирования',
            'post_date'      => 'Дата проводки',
            'instruction_id' => 'Instruction ID',
            'end_to_end_id'  => 'EndToEnd ID',
            'transaction_id' => 'Transaction ID',
            'message_id'     => 'Message ID',
            'other_id'       => 'Other ID',
            'comment'        => 'Комментарий',
            'source'         => 'Источник',
            'match_status'   => 'Статус квитования',
            'created_by'     => 'Создал',
            'updated_by'     => 'Обновил',
            'created_at'     => 'Создано',
            'updated_at'     => 'Обновлено',
        ];
    }

    // -----------------------------------------------------------------
    // Связи
    // -----------------------------------------------------------------

    public function getAccount(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Account::class, ['id' => 'account_id']);
    }

    public function getCompany(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    // -----------------------------------------------------------------
    // Хелперы
    // -----------------------------------------------------------------

    /** Метки статусов квитования */
    public static function matchStatusLabels(): array
    {
        return [
            self::STATUS_UNMATCHED => 'Не квитовано',
            self::STATUS_MATCHED   => 'Квитовано',
            self::STATUS_IGNORED   => 'Игнорировано',
        ];
    }

    /** CSS-класс бейджа для статуса */
    public function matchStatusBadge(): string
    {
        $map = [self::STATUS_MATCHED => 'success', self::STATUS_IGNORED => 'secondary'];
        return $map[$this->match_status] ?? 'warning';
    }

    /** Форматирование суммы: 455566558754.23 → 455,566,558,754.23 */
    public function formattedAmount(): string
    {
        return number_format((float)$this->amount, 2, '.', ',');
    }

    // -----------------------------------------------------------------
    // Хуки
    // -----------------------------------------------------------------

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($insert) {
            $this->created_at = date('Y-m-d H:i:s');
            $this->created_by = Yii::$app->user->id ?? null;
        }
        $this->updated_at = date('Y-m-d H:i:s');
        $this->updated_by = Yii::$app->user->id ?? null;

        return true;
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert) {
            // Логирование создания
            NostroEntryAudit::log(
                $this->id,
                NostroEntryAudit::ACTION_CREATE,
                null,
                $this->getAttributesForAudit()
            );
        } else {
            // Логирование обновлений (только изменённые поля)
            foreach ($changedAttributes as $field => $oldValue) {
                // Пропускаем служебные поля.updated_at и updated_by
                if (in_array($field, ['updated_at', 'updated_by'])) {
                    continue;
                }

                $newValue = $this->$field;
                if ($oldValue !== $newValue) {
                    NostroEntryAudit::log(
                        $this->id,
                        NostroEntryAudit::ACTION_UPDATE,
                        [$field => $oldValue],
                        [$field => $newValue],
                        $field
                    );
                }
            }
        }
    }

    public function beforeDelete()
    {
        // Логирование удаления (ДО фактического удаления, чтобы entry_id ещё существовал)
        NostroEntryAudit::log(
            $this->id,
            NostroEntryAudit::ACTION_DELETE,
            $this->getAttributesForAudit(),
            null,
            null,
            null,
            'Запись удалена'
        );

        return parent::beforeDelete();
    }

    public function afterDelete()
    {
        parent::afterDelete();
    }

    /**
     * Получить историю изменений записи.
     *
     * @return NostroEntryAudit[]
     */
    public function getAudits(): \yii\db\ActiveQuery
    {
        return $this->hasMany(NostroEntryAudit::class, ['entry_id' => 'id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    /**
     * Логирование архивирования записи.
     * Вызывается при переносе в архив.
     *
     * @param int $archivedId ID созданной архивной записи
     */
    public function logArchive(int $archivedId): void
    {
        NostroEntryAudit::log(
            $this->id,
            NostroEntryAudit::ACTION_ARCHIVE,
            $this->getAttributesForAudit(),
            null,
            null,
            $archivedId,
            'Запись заархивирована'
        );
    }

    /**
     * Получить атрибуты для аудита (без служебных полей).
     */
    private function getAttributesForAudit(): array
    {
        $attrs = $this->getAttributes();
        // Можно исключить служебные поля при необходимости
        return $attrs;
    }
}