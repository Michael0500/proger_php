<?php

use yii\db\Migration;

/**
 * Статус пакетов выписок (FCC12 и т.п.).
 * Консольная команда fcc-merge/run находит is_merged = false и
 * переносит данные из git_no_stro_extract_custom в nostro_balance / nostro_entries.
 */
class m260424_100001_create_tds_status_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%tds_status}}', [
            'id'              => $this->primaryKey(),
            'type'            => $this->string(20),
            'date_time'       => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'is_merged'       => $this->boolean()->notNull()->defaultValue(false),
            'fcc_extract_no'  => $this->integer(),
        ]);

        $this->createIndex('idx_tds_status_type',      '{{%tds_status}}', 'type');
        $this->createIndex('idx_tds_status_is_merged', '{{%tds_status}}', 'is_merged');
        $this->createIndex('idx_tds_status_fcc_extract_no', '{{%tds_status}}', 'fcc_extract_no');
    }

    public function safeDown()
    {
        $this->dropTable('{{%tds_status}}');
    }
}
