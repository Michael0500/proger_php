<?php

use yii\db\Migration;

/**
 * Убирает FK audit.entry_id -> nostro_entries.id.
 *
 * Аудит должен хранить original_id записи даже после переноса в архив.
 * FK с ON DELETE SET NULL обнулял entry_id при удалении строки из nostro_entries.
 */
class m260514_130000_drop_nostro_entry_audit_entry_fk extends Migration
{
    public function safeUp()
    {
        $this->dropForeignKey('fk_nea_entry', '{{%nostro_entry_audit}}');
    }

    public function safeDown()
    {
        $this->addForeignKey(
            'fk_nea_entry',
            '{{%nostro_entry_audit}}',
            'entry_id',
            '{{%nostro_entries}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }
}
