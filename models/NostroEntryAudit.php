<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Лог изменений записей Ностро (аудит).
 *
 * @property int         $id
 * @property int|null    $entry_id       ID записи из nostro_entries или original_id из архива
 * @property int         $user_id        Кто выполнил действие
 * @property string      $action         create | update | delete | archive
 * @property string|null $old_values     JSON: старые значения
 * @property string|null $new_values     JSON: новые значения
 * @property string|null $changed_field  Какое поле изменилось (для update)
 * @property int|null    $archived_id    ID архивной записи (для action=archive)
 * @property string|null $reason         Причина изменения
 * @property string      $created_at     Дата создания записи аудита
 *
 * @property NostroEntry       $entry
 * @property NostroEntryArchive $archivedEntry
 */
class NostroEntryAudit extends ActiveRecord
{
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_ARCHIVE = 'archive';

    public static function tableName(): string
    {
        return '{{%nostro_entry_audit}}';
    }

    public function rules(): array
    {
        return [
            [['user_id', 'action'], 'required'],
            [['entry_id', 'user_id', 'archived_id'], 'integer'],
            [['action'], 'string', 'max' => 20],
            [['action'], 'in', 'range' => [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE, self::ACTION_ARCHIVE]],
            [['changed_field', 'reason'], 'string', 'max' => 255],
            [['old_values', 'new_values'], 'string'],
            [['created_at'], 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'            => 'ID',
            'entry_id'      => 'ID записи',
            'user_id'       => 'Пользователь',
            'action'        => 'Действие',
            'old_values'    => 'Старые значения',
            'new_values'    => 'Новые значения',
            'changed_field' => 'Изменённое поле',
            'archived_id'   => 'ID архива',
            'reason'        => 'Причина',
            'created_at'    => 'Дата',
        ];
    }

    /**
     * Связь с записью в nostro_entries (может быть null, если запись удалена).
     */
    public function getEntry(): \yii\db\ActiveQuery
    {
        return $this->hasOne(NostroEntry::class, ['id' => 'entry_id']);
    }

    /**
     * Связь с архивной записью (для action=archive).
     */
    public function getArchivedEntry(): \yii\db\ActiveQuery
    {
        return $this->hasOne(NostroEntryArchive::class, ['id' => 'archived_id']);
    }

    /**
     * Записать аудит-событие.
     *
     * @param int|null $entryId ID записи (из nostro_entries или original_id из архива)
     * @param string $action Действие: create|update|delete|archive
     * @param array|null $oldValues Старые значения полей
     * @param array|null $newValues Новые значения полей
     * @param string|null $changedField Какое поле изменилось
     * @param int|null $archivedId ID архивной записи (для action=archive)
     * @param string|null $reason Причина изменения
     */
    public static function log(
        ?int $entryId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $changedField = null,
        ?int $archivedId = null,
        ?string $reason = null
    ): void {
        $log = new self();
        $log->entry_id      = $entryId;
        $log->user_id       = \Yii::$app->user->id ?? 0;
        $log->action        = $action;
        $log->old_values    = $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
        $log->new_values    = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
        $log->changed_field = $changedField;
        $log->archived_id   = $archivedId;
        $log->reason        = $reason;
        $log->save(false);
    }

    /**
     * Получить историю изменений для записи (включая архив).
     *
     * @param int $entryId ID записи (из nostro_entries или original_id из архива)
     * @return self[]
     */
    public static function getHistory(int $entryId): array
    {
        return self::find()
            ->where(['entry_id' => $entryId])
            ->orderBy(['created_at' => SORT_DESC])
            ->all();
    }

    /**
     * Преобразование в API-формат.
     */
    public function toApiArray(): array
    {
        return [
            'id'            => $this->id,
            'entry_id'      => $this->entry_id,
            'action'        => $this->action,
            'user_id'       => $this->user_id,
            'old_values'    => $this->old_values ? json_decode($this->old_values, true) : null,
            'new_values'    => $this->new_values ? json_decode($this->new_values, true) : null,
            'changed_field' => $this->changed_field,
            'archived_id'   => $this->archived_id,
            'reason'        => $this->reason,
            'created_at'    => $this->created_at,
        ];
    }
}
