<?php

use yii\db\Migration;

/**
 * Добавляем в nostro_balance и nostro_entries поля extract_no и line_no,
 * чтобы связать записи с первичной строкой в git_no_stro_extract_custom.
 * Поля nullable — исторические записи их не имеют.
 */
class m260424_100002_add_extract_no_to_nostro_tables extends Migration
{
    public function safeUp()
    {
        // nostro_balance
        $this->addColumn('{{%nostro_balance}}', 'extract_no', $this->bigInteger()->null()
            ->comment('Номер выписки в git_no_stro_extract_custom'));
        $this->addColumn('{{%nostro_balance}}', 'line_no', $this->integer()->null()
            ->comment('Номер строки в git_no_stro_extract_custom'));

        $this->createIndex(
            'idx_nbalance_extract_no',
            '{{%nostro_balance}}',
            ['extract_no', 'line_no']
        );

        // nostro_entries
        $this->addColumn('{{%nostro_entries}}', 'extract_no', $this->bigInteger()->null()
            ->comment('Номер выписки в git_no_stro_extract_custom'));
        $this->addColumn('{{%nostro_entries}}', 'line_no', $this->integer()->null()
            ->comment('Номер строки в git_no_stro_extract_custom'));

        $this->createIndex(
            'idx_nostro_entries_extract_no',
            '{{%nostro_entries}}',
            ['extract_no', 'line_no']
        );
    }

    public function safeDown()
    {
        $this->dropIndex('idx_nostro_entries_extract_no', '{{%nostro_entries}}');
        $this->dropColumn('{{%nostro_entries}}', 'line_no');
        $this->dropColumn('{{%nostro_entries}}', 'extract_no');

        $this->dropIndex('idx_nbalance_extract_no', '{{%nostro_balance}}');
        $this->dropColumn('{{%nostro_balance}}', 'line_no');
        $this->dropColumn('{{%nostro_balance}}', 'extract_no');
    }
}
