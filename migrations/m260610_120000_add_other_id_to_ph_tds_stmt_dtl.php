<?php

use yii\db\Migration;

/**
 * Добавляет Other ID в строки транзакций выписок TDS.
 */
class m260610_120000_add_other_id_to_ph_tds_stmt_dtl extends Migration
{
    /**
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn(
            '{{%ph_tds_stmt_dtl}}',
            'other_id',
            $this->string(40)->null()->comment('Other ID для переноса в nostro_entries.other_id')
        );
    }

    /**
     * @return void
     */
    public function safeDown()
    {
        $this->dropColumn('{{%ph_tds_stmt_dtl}}', 'other_id');
    }
}
