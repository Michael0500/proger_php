<?php

use yii\db\Migration;

/**
 * Добавляет номер выписки в активные и архивные записи выверки.
 */
class m260608_120000_add_statement_number_to_nostro_entries extends Migration
{
    /**
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn(
            '{{%nostro_entries}}',
            'statement_number',
            $this->string(35)->null()->comment('Номер выписки из ph_tds_stmt_hdr.stmt_ref')
        );
        $this->addColumn(
            '{{%nostro_entries_archive}}',
            'statement_number',
            $this->string(35)->null()->comment('Номер выписки из ph_tds_stmt_hdr.stmt_ref')
        );

        $this->createIndex('idx_nostro_entries_statement_number', '{{%nostro_entries}}', 'statement_number');
        $this->createIndex('idx_nea_statement_number', '{{%nostro_entries_archive}}', 'statement_number');
    }

    /**
     * @return void
     */
    public function safeDown()
    {
        $this->dropIndex('idx_nea_statement_number', '{{%nostro_entries_archive}}');
        $this->dropIndex('idx_nostro_entries_statement_number', '{{%nostro_entries}}');
        $this->dropColumn('{{%nostro_entries_archive}}', 'statement_number');
        $this->dropColumn('{{%nostro_entries}}', 'statement_number');
    }
}
