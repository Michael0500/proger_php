<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Архивная запись выверки Ностро.
 *
 * @property int         $id
 * @property int         $original_id
 * @property int         $account_id
 * @property int         $company_id
 * @property string      $match_id
 * @property string      $ls
 * @property string      $dc
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
 * @property string      $match_status   всегда 'A'
 * @property string      $archived_at
 * @property string      $expires_at
 * @property int|null    $archived_by
 * @property string|null $original_created_at
 * @property string|null $original_updated_at
 *
 * @property Account     $account
 * @property Company     $company
 * @property NostroEntryAudit[] $audits
 */
class NostroEntryArchive extends ActiveRecord
{
    const STATUS_ARCHIVED = 'A';

    public static function tableName(): string
    {
        return '{{%nostro_entries_archive}}';
    }

    public function rules(): array
    {
        return [
            [['original_id', 'account_id', 'company_id', 'match_id', 'ls', 'dc', 'amount', 'currency'], 'required'],
            [['original_id', 'account_id', 'company_id', 'archived_by'], 'integer'],
            [['amount'], 'number'],
            [['value_date', 'post_date', 'archived_at', 'expires_at',
                'original_created_at', 'original_updated_at'], 'safe'],
            [['ls', 'match_status'], 'string', 'max' => 1],
            [['dc'], 'string', 'max' => 6],
            [['currency'], 'string', 'max' => 3],
            [['match_id', 'instruction_id', 'end_to_end_id', 'message_id', 'other_id', 'comment'], 'string', 'max' => 40],
            [['transaction_id'], 'string', 'max' => 60],
            [['source'], 'string', 'max' => 20],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'           => 'ID',
            'original_id'  => 'Исходный ID',
            'account_id'   => 'Счёт',
            'company_id'   => 'Компания',
            'match_id'     => 'Match ID',
            'ls'           => 'L/S',
            'dc'           => 'D/C',
            'amount'       => 'Сумма',
            'currency'     => 'Валюта',
            'value_date'   => 'Дата валютирования',
            'post_date'    => 'Дата проводки',
            'instruction_id' => 'Instruction ID',
            'end_to_end_id'  => 'EndToEnd ID',
            'transaction_id' => 'Transaction ID',
            'message_id'     => 'Message ID',
            'other_id'       => 'Other ID',
            'comment'        => 'Комментарий',
            'source'         => 'Источник',
            'match_status'   => 'Статус',
            'archived_at'    => 'Дата архивирования',
            'expires_at'     => 'Срок хранения до',
            'archived_by'    => 'Заархивировал',
            'original_created_at' => 'Создана (оригинал)',
            'original_updated_at' => 'Изменена (оригинал)',
        ];
    }

    // ─── Отношения ─────────────────────────────────────────────

    public function getAccount(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Account::class, ['id' => 'account_id']);
    }

    public function getCompany(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Получить историю изменений записи (по original_id).
     * История доступна и после переноса в архив.
     *
     * @return NostroEntryAudit[]
     */
    public function getAudits(): \yii\db\ActiveQuery
    {
        return $this->hasMany(NostroEntryAudit::class, ['entry_id' => 'original_id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    // ─── Статический хелпер: перенести NostroEntry в архив ─────

    /**
     * Архивировать запись из nostro_entries.
     * Возвращает созданную архивную запись или null при ошибке.
     */
    public static function archiveEntry(NostroEntry $entry, int $retentionYears = 5, ?int $archivedBy = null): ?self
    {
        $archive                      = new self();
        $archive->original_id         = $entry->id;
        $archive->account_id          = $entry->account_id;
        $archive->company_id          = $entry->company_id;
        $archive->match_id            = $entry->match_id;
        $archive->ls                  = $entry->ls;
        $archive->dc                  = $entry->dc;
        $archive->amount              = $entry->amount;
        $archive->currency            = $entry->currency;
        $archive->value_date          = $entry->value_date;
        $archive->post_date           = $entry->post_date;
        $archive->instruction_id      = $entry->instruction_id;
        $archive->end_to_end_id       = $entry->end_to_end_id;
        $archive->transaction_id      = $entry->transaction_id;
        $archive->message_id          = $entry->message_id;
        $archive->other_id            = $entry->other_id;
        $archive->comment             = $entry->comment;
        $archive->source              = $entry->source;
        $archive->match_status        = self::STATUS_ARCHIVED;
        $archive->archived_at         = date('Y-m-d H:i:s');
        $archive->expires_at          = date('Y-m-d H:i:s', strtotime("+{$retentionYears} years"));
        $archive->archived_by         = $archivedBy;
        $archive->original_created_at = $entry->created_at;
        $archive->original_updated_at = $entry->updated_at;

        return $archive->save(false) ? $archive : null;
    }

    // ─── API-форматирование ────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'             => $this->id,
            'original_id'    => $this->original_id,
            'account_id'     => $this->account_id,
            'account_name'   => $this->account ? $this->account->name : '—',
            'match_id'       => $this->match_id,
            'ls'             => $this->ls,
            'dc'             => $this->dc,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'value_date'     => $this->value_date,
            'post_date'      => $this->post_date,
            'instruction_id' => $this->instruction_id,
            'end_to_end_id'  => $this->end_to_end_id,
            'transaction_id' => $this->transaction_id,
            'message_id'     => $this->message_id,
            'other_id'       => $this->other_id,
            'comment'        => $this->comment,
            'source'         => $this->source,
            'match_status'   => $this->match_status,
            'archived_at'    => $this->archived_at,
            'expires_at'     => $this->expires_at,
            'archived_by'    => $this->archived_by,
        ];
    }
}