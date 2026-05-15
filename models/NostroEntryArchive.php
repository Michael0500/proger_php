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
 * @property string|null $matched_at
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

    /**
     * Возвращает имя таблицы архивных записей выверки.
     *
     * Архив хранит сквитованные операции после batch-переноса из
     * `nostro_entries` и сохраняет `original_id` для восстановления аудита.
     *
     * @return string Имя таблицы `nostro_entries_archive` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%nostro_entries_archive}}';
    }

    /**
     * Описывает правила валидации архивной записи.
     *
     * В архивной строке обязательны ссылка на исходную запись, `match_id`,
     * счёт, компания и основные реквизиты операции. Дата квитования сохраняется
     * для восстановления группы квитования без потери исходной истории.
     *
     * @return array Правила Yii Validator.
     */
    public function rules(): array
    {
        return [
            [['original_id', 'account_id', 'company_id', 'match_id', 'ls', 'dc', 'amount', 'currency'], 'required'],
            [['original_id', 'account_id', 'company_id', 'archived_by'], 'integer'],
            [['amount'], 'number'],
            [['value_date', 'post_date', 'matched_at', 'archived_at', 'expires_at',
                'original_created_at', 'original_updated_at'], 'safe'],
            [['ls', 'match_status'], 'string', 'max' => 1],
            [['dc'], 'string', 'max' => 6],
            [['currency'], 'string', 'max' => 3],
            [['match_id', 'instruction_id', 'end_to_end_id', 'message_id', 'other_id', 'comment'], 'string', 'max' => 40],
            [['transaction_id'], 'string', 'max' => 60],
            [['source'], 'string', 'max' => 20],
        ];
    }

    /**
     * Возвращает подписи архивных атрибутов для UI и ошибок.
     *
     * @return array Массив `attribute => label`.
     */
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
            'matched_at'     => 'Дата квитования',
            'archived_at'    => 'Дата архивирования',
            'expires_at'     => 'Срок хранения до',
            'archived_by'    => 'Заархивировал',
            'original_created_at' => 'Создана (оригинал)',
            'original_updated_at' => 'Изменена (оригинал)',
        ];
    }

    // ─── Отношения ─────────────────────────────────────────────

    /**
     * Возвращает связь архивной записи с ностро-счётом.
     *
     * @return \yii\db\ActiveQuery Запрос связи `account_id -> accounts.id`.
     */
    public function getAccount(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Account::class, ['id' => 'account_id']);
    }

    /**
     * Возвращает связь архивной записи с компанией.
     *
     * @return \yii\db\ActiveQuery Запрос связи `company_id -> company.id`.
     */
    public function getCompany(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Возвращает историю изменений исходной активной записи.
     *
     * История ищется по `original_id`, поэтому остаётся доступной после
     * удаления строки из `nostro_entries`.
     *
     * @return \yii\db\ActiveQuery Запрос к событиям аудита исходной записи.
     */
    public function getAudits(): \yii\db\ActiveQuery
    {
        return $this->hasMany(NostroEntryAudit::class, ['entry_id' => 'original_id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    // ─── Статический хелпер: перенести NostroEntry в архив ─────

    /**
     * Создаёт архивную копию активной записи выверки.
     *
     * Метод переносит бизнес-реквизиты операции, `match_id`, `matched_at`,
     * исходные даты создания/изменения и рассчитывает `expires_at` по сроку
     * хранения. Физическое удаление активной записи и групповой аудит выполняет
     * вызывающий сервис/контроллер.
     *
     * @param NostroEntry $entry Активная запись, которую нужно скопировать в архив.
     * @param int $retentionYears Срок хранения архивной записи в годах.
     * @param int|null $archivedBy ID пользователя или системного процесса.
     * @return self|null Созданная архивная запись или `null`, если сохранить не удалось.
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
        $archive->matched_at          = $entry->matched_at;
        $archive->archived_at         = date('Y-m-d H:i:s');
        $archive->expires_at          = date('Y-m-d H:i:s', strtotime("+{$retentionYears} years"));
        $archive->archived_by         = $archivedBy;
        $archive->original_created_at = $entry->created_at;
        $archive->original_updated_at = $entry->updated_at;

        return $archive->save(false) ? $archive : null;
    }

    // ─── API-форматирование ────────────────────────────────────

    /**
     * Преобразует архивную запись в структуру JSON API.
     *
     * Возвращает только поля, нужные архивному интерфейсу и предпросмотру
     * восстановления, включая ссылку на исходную запись и сроки хранения.
     *
     * @return array Сериализованные данные архивной записи.
     */
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
            'matched_at'     => $this->matched_at,
            'archived_at'    => $this->archived_at,
            'expires_at'     => $this->expires_at,
            'archived_by'    => $this->archived_by,
        ];
    }
}
