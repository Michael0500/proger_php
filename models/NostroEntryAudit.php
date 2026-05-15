<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Лог изменений записей Ностро (аудит).
 *
 * @property int         $id
 * @property int|null    $entry_id       ID записи из nostro_entries или original_id из архива
 * @property int         $user_id        Кто выполнил действие
 * @property string      $action         create | update | delete | archive | restore
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
    const ACTION_RESTORE = 'restore';

    /**
     * Возвращает имя таблицы аудита операций выверки.
     *
     * @return string Имя таблицы `nostro_entry_audit` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%nostro_entry_audit}}';
    }

    /**
     * Описывает правила валидации события аудита.
     *
     * Аудит допускает `entry_id = null` для исторических или технических
     * событий, а значения изменений хранятся JSON-строками без FK на активную
     * таблицу, чтобы история переживала архивирование и удаление.
     *
     * @return array Правила Yii Validator.
     */
    public function rules(): array
    {
        return [
            [['user_id', 'action'], 'required'],
            [['entry_id', 'user_id', 'archived_id'], 'integer'],
            [['action'], 'string', 'max' => 20],
            [['action'], 'in', 'range' => [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE, self::ACTION_ARCHIVE, self::ACTION_RESTORE]],
            [['changed_field', 'reason'], 'string', 'max' => 255],
            [['old_values', 'new_values'], 'string'],
            [['created_at'], 'safe'],
        ];
    }

    /**
     * Возвращает подписи полей аудита.
     *
     * @return array Массив `attribute => label`.
     */
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
     * Возвращает связь с активной записью выверки.
     *
     * Связь может не найти строку, если запись уже удалена или перенесена
     * в архив; это ожидаемое состояние для исторического аудита.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `NostroEntry`.
     */
    public function getEntry(): \yii\db\ActiveQuery
    {
        return $this->hasOne(NostroEntry::class, ['id' => 'entry_id']);
    }

    /**
     * Возвращает связь с архивной копией для события `archive`.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `NostroEntryArchive`.
     */
    public function getArchivedEntry(): \yii\db\ActiveQuery
    {
        return $this->hasOne(NostroEntryArchive::class, ['id' => 'archived_id']);
    }

    /**
     * Записывает событие аудита операции выверки.
     *
     * Метод используется хуками модели и batch-процессами архива/импорта.
     * Старые и новые значения кодируются в JSON без дополнительной валидации,
     * поэтому вызывающий код должен передавать уже подготовленные снимки.
     *
     * @param int|null $entryId ID записи (из nostro_entries или original_id из архива)
     * @param string $action Действие: create|update|delete|archive|restore
     * @param array|null $oldValues Старые значения полей
     * @param array|null $newValues Новые значения полей
     * @param string|null $changedField Какое поле изменилось
     * @param int|null $archivedId ID архивной записи (для action=archive)
     * @param string|null $reason Причина изменения
     * @return void
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
     * Возвращает историю изменений по идентификатору исходной записи.
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
     * Преобразует событие аудита в структуру JSON API.
     *
     * JSON-снимки `old_values` и `new_values` декодируются обратно в массивы,
     * чтобы фронтенд мог строить историю изменения полей.
     *
     * @return array Сериализованное событие аудита.
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
