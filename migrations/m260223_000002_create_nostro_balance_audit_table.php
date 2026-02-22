<?php

use yii\db\Migration;

/**
 * Таблица аудита изменений баланса.
 * Пишется при каждом изменении статуса на 'confirmed'
 * или ручной корректировке записи.
 */
class m260223_000002_create_nostro_balance_audit_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%nostro_balance_audit}}', [
            'id'          => $this->primaryKey(),
            'balance_id'  => $this->integer()->notNull()->comment('FK → nostro_balance.id'),
            'user_id'     => $this->integer()->notNull(),
            'action'      => $this->string(20)->notNull()->comment('confirm | edit | import'),
            'old_values'  => $this->text()->null()->comment('JSON: старые значения'),
            'new_values'  => $this->text()->null()->comment('JSON: новые значения'),
            'reason'      => $this->string(255)->null()->comment('Причина корректировки'),
            'created_at'  => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        $this->createIndex('idx_nbalance_audit_balance', '{{%nostro_balance_audit}}', 'balance_id');
        $this->createIndex('idx_nbalance_audit_user',    '{{%nostro_balance_audit}}', 'user_id');

        $this->addForeignKey(
            'fk_nbalance_audit_balance',
            '{{%nostro_balance_audit}}', 'balance_id',
            '{{%nostro_balance}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_nbalance_audit_balance', '{{%nostro_balance_audit}}');
        $this->dropTable('{{%nostro_balance_audit}}');
    }
}