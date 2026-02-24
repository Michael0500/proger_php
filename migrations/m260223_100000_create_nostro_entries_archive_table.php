<?php

use yii\db\Migration;

/**
 * Таблица архива сквитованных записей Ностро.
 *
 * Архивируются ТОЛЬКО сквитованные записи (match_status = 'M') с ненулевым match_id.
 * Срок хранения в архиве — 5 лет с момента переноса (archived_at).
 */
class m260223_100000_create_nostro_entries_archive_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%nostro_entries_archive}}', [
            'id'             => $this->primaryKey(),

            // Оригинальный ID из nostro_entries (для трассировки)
            'original_id'    => $this->integer()->notNull()->comment('ID из nostro_entries'),

            'account_id'     => $this->integer()->notNull(),
            'company_id'     => $this->integer()->notNull(),
            'match_id'       => $this->string()->notNull()->comment('Match ID (обязателен для архива)'),
            'ls'             => $this->char(1)->notNull(),
            'dc'             => $this->string(6)->notNull(),
            'amount'         => $this->decimal(18, 2)->notNull(),
            'currency'       => $this->char(3)->notNull(),
            'value_date'     => $this->date()->null(),
            'post_date'      => $this->date()->null(),
            'instruction_id' => $this->string(40)->null(),
            'end_to_end_id'  => $this->string(40)->null(),
            'transaction_id' => $this->string(60)->null(),
            'message_id'     => $this->string(40)->null(),
            'comment'        => $this->string(40)->null(),
            'source'         => $this->string(20)->null(),

            // Статус: всегда 'A' = Archived
            'match_status'   => $this->char(1)->notNull()->defaultValue('A')->comment('A = Archived'),

            // Дата, когда запись была заархивирована
            'archived_at'    => $this->timestamp()->notNull()->defaultExpression('NOW()')->comment('Дата архивирования'),

            // Дата, после которой запись подлежит удалению из архива (archived_at + 5 лет)
            'expires_at'     => $this->timestamp()->notNull()->comment('Дата истечения срока хранения'),

            // Кто заархивировал (null = автоматически)
            'archived_by'    => $this->integer()->null()->comment('NULL = автоматически'),

            // Оригинальные даты создания/обновления из nostro_entries
            'original_created_at' => $this->timestamp()->null(),
            'original_updated_at' => $this->timestamp()->null(),
        ]);

        // Индексы
        $this->createIndex('idx_nea_company',     '{{%nostro_entries_archive}}', 'company_id');
        $this->createIndex('idx_nea_account',     '{{%nostro_entries_archive}}', 'account_id');
        $this->createIndex('idx_nea_match_id',    '{{%nostro_entries_archive}}', 'match_id');
        $this->createIndex('idx_nea_archived_at', '{{%nostro_entries_archive}}', 'archived_at');
        $this->createIndex('idx_nea_expires_at',  '{{%nostro_entries_archive}}', 'expires_at');
        $this->createIndex('idx_nea_original_id', '{{%nostro_entries_archive}}', 'original_id');
        $this->createIndex('idx_nea_value_date',  '{{%nostro_entries_archive}}', 'value_date');
        $this->createIndex('idx_nea_currency',    '{{%nostro_entries_archive}}', 'currency');
        $this->createIndex('idx_nea_ls',          '{{%nostro_entries_archive}}', 'ls');
        $this->createIndex('idx_nea_dc',          '{{%nostro_entries_archive}}', 'dc');

        // Текстовый поиск по ID-полям (PostgreSQL)
        $this->execute("
            CREATE INDEX idx_nea_fulltext ON {{%nostro_entries_archive}}
            USING gin(
                to_tsvector('simple',
                    coalesce(match_id, '') || ' ' ||
                    coalesce(instruction_id, '') || ' ' ||
                    coalesce(end_to_end_id, '') || ' ' ||
                    coalesce(transaction_id, '') || ' ' ||
                    coalesce(message_id, '') || ' ' ||
                    coalesce(comment, '')
                )
            )
        ");
    }

    public function safeDown()
    {
        $this->dropIndex('idx_nea_fulltext', '{{%nostro_entries_archive}}');
        $this->dropTable('{{%nostro_entries_archive}}');
    }
}