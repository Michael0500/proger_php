<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Лог изменений баланса (аудит).
 *
 * @property int         $id
 * @property int         $balance_id
 * @property int         $user_id
 * @property string      $action       confirm|edit|import
 * @property string|null $old_values   JSON
 * @property string|null $new_values   JSON
 * @property string|null $reason
 * @property string      $created_at
 *
 * @property NostroBalance $balance
 */
class NostroBalanceAudit extends ActiveRecord
{
    const ACTION_CONFIRM = 'confirm';
    const ACTION_EDIT    = 'edit';
    const ACTION_IMPORT  = 'import';

    public static function tableName(): string
    {
        return '{{%nostro_balance_audit}}';
    }

    public function rules(): array
    {
        return [
            [['balance_id', 'user_id', 'action'], 'required'],
            [['balance_id', 'user_id'], 'integer'],
            [['action'], 'string', 'max' => 20],
            [['reason'], 'string', 'max' => 255],
            [['old_values', 'new_values'], 'string'],
            [['created_at'], 'safe'],
        ];
    }

    public function getBalance(): \yii\db\ActiveQuery
    {
        return $this->hasOne(NostroBalance::class, ['id' => 'balance_id']);
    }

    /**
     * Записать аудит-событие
     */
    public static function log(
        int $balanceId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null
    ): void {
        $log             = new self();
        $log->balance_id = $balanceId;
        $log->user_id    = \Yii::$app->user->id ?? 0;
        $log->action     = $action;
        $log->old_values = $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
        $log->new_values = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
        $log->reason     = $reason;
        $log->save(false);
    }

    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'action'     => $this->action,
            'user_id'    => $this->user_id,
            'old_values' => $this->old_values ? json_decode($this->old_values, true) : null,
            'new_values' => $this->new_values ? json_decode($this->new_values, true) : null,
            'reason'     => $this->reason,
            'created_at' => $this->created_at,
        ];
    }
}