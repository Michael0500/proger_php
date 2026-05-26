<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Лог изменений баланса (аудит).
 *
 * @property int         $id
 * @property int         $balance_id
 * @property int         $user_id
 * @property string      $action       confirm|edit|import|archive|restore
 * @property string|null $old_values   JSON
 * @property string|null $new_values   JSON
 * @property string|null $reason
 * @property int|null    $archived_id
 * @property string      $created_at
 *
 * @property NostroBalance $balance
 */
class NostroBalanceAudit extends ActiveRecord
{
    const ACTION_CONFIRM = 'confirm';
    const ACTION_EDIT    = 'edit';
    const ACTION_IMPORT  = 'import';
    const ACTION_ARCHIVE = 'archive';
    const ACTION_RESTORE = 'restore';

    /**
     * Возвращает имя таблицы аудита балансов.
     *
     * @return string Имя таблицы `nostro_balance_audit` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%nostro_balance_audit}}';
    }

    /**
     * Описывает правила валидации события аудита баланса.
     *
     * События фиксируют подтверждение, ручное изменение и импорт балансов.
     * Снимки старых и новых значений хранятся JSON-строками.
     *
     * @return array Правила Yii Validator.
     */
    public function rules(): array
    {
        return [
            [['balance_id', 'user_id', 'action'], 'required'],
            [['balance_id', 'user_id', 'archived_id'], 'integer'],
            [['action'], 'string', 'max' => 20],
            [['reason'], 'string', 'max' => 255],
            [['old_values', 'new_values'], 'string'],
            [['created_at'], 'safe'],
        ];
    }

    /**
     * Возвращает связь события аудита с балансовой записью.
     *
     * @return \yii\db\ActiveQuery Запрос связи с `NostroBalance`.
     */
    public function getBalance(): \yii\db\ActiveQuery
    {
        return $this->hasOne(NostroBalance::class, ['id' => 'balance_id']);
    }

    /**
     * Записывает событие аудита баланса.
     *
     * Метод используется ручным редактированием, подтверждением и импортом.
     * Переданные массивы кодируются в JSON без дополнительной нормализации.
     *
     * @param int $balanceId ID балансовой записи.
     * @param string $action Действие `confirm`, `edit`, `import`, `archive` или `restore`.
     * @param array|null $oldValues Старые значения баланса.
     * @param array|null $newValues Новые значения баланса.
     * @param string|null $reason Комментарий или причина события.
     * @param int|null $archivedId ID архивной записи для archive/restore.
     * @return void
     */
    public static function log(
        int $balanceId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?int $archivedId = null
    ): void {
        $log             = new self();
        $log->balance_id = $balanceId;
        $log->user_id    = \Yii::$app->user->id ?? 0;
        $log->action     = $action;
        $log->old_values = $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
        $log->new_values = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
        $log->reason     = $reason;
        $log->archived_id = $archivedId;
        $log->save(false);
    }

    /**
     * Преобразует событие аудита баланса в структуру JSON API.
     *
     * @return array Сериализованное событие с декодированными JSON-снимками.
     */
    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'action'     => $this->action,
            'user_id'    => $this->user_id,
            'old_values' => $this->old_values ? json_decode($this->old_values, true) : null,
            'new_values' => $this->new_values ? json_decode($this->new_values, true) : null,
            'reason'     => $this->reason,
            'archived_id'=> $this->archived_id,
            'created_at' => $this->created_at,
        ];
    }
}
