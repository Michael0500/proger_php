<?php

use yii\db\Migration;

/**
 * Таблица аудита изменений записей Ностро.
 *
 * Логируются все изменения записей:
 * - создание (create)
 * - редактирование (update)
 * - удаление (delete)
 * - архивирование (archive)
 *
 * При архивировании сохраняется original_id для связи с архивной записью.
 */
class m260225_000001_create_nostro_entry_audit_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%nostro_entry_audit}}', [
            'id'           => $this->primaryKey(),
            'entry_id'     => $this->integer()->null()->comment('ID записи из nostro_entries или original_id из архива'),
            'user_id'      => $this->integer()->notNull()->comment('Кто выполнил действие'),
            'action'       => $this->string(20)->notNull()->comment('create | update | delete | archive'),
            'old_values'   => $this->text()->null()->comment('JSON: старые значения полей'),
            'new_values'   => $this->text()->null()->comment('JSON: новые значения полей'),
            'changed_field'=> $this->string(255)->null()->comment('Какое поле изменилось (для update)'),
            'archived_id'  => $this->integer()->null()->comment('ID архивной записи (для action=archive)'),
            'reason'       => $this->string(255)->null()->comment('Причина изменения'),
            'created_at'   => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        // Индексы для быстрого поиска
        $this->createIndex('idx_nea_entry_id',   '{{%nostro_entry_audit}}', 'entry_id');
        $this->createIndex('idx_nea_user_id',    '{{%nostro_entry_audit}}', 'user_id');
        $this->createIndex('idx_nea_action',     '{{%nostro_entry_audit}}', 'action');
        $this->createIndex('idx_nea_archived_id', '{{%nostro_entry_audit}}', 'archived_id');

        // Внешний ключ на nostro_entries (опционально, т.к. запись может быть удалена)
        // ON DELETE SET NULL — чтобы аудит сохранялся даже после удаления записи
        $this->addForeignKey(
            'fk_nea_entry',
            '{{%nostro_entry_audit}}', 'entry_id',
            '{{%nostro_entries}}', 'id',
            'SET NULL', 'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_nea_entry', '{{%nostro_entry_audit}}');
        $this->dropTable('{{%nostro_entry_audit}}');
    }
}
