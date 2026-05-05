<?php

use yii\db\Migration;

/**
 * Сырой приёмник выписок FCC (разбор построчно).
 * Каждая строка — либо заголовок/баланс (opening_bal, closing_bal, dt),
 * либо транзакция (amount, drcr_ind, trn_dt, value_dt и т.д.).
 * После переноса в nostro_balance / nostro_entries строки удаляются.
 */
class m260424_100000_create_git_no_stro_extract_custom_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%git_no_stro_extract_custom}}', [
            'extract_no'     => $this->bigInteger()->notNull(),
            'line_no'        => $this->integer()->notNull(),
            'line_content'   => $this->text(),
            'data_section'   => $this->integer(),
            'branch_code'    => $this->char(3),
            'cbr_cc_no'      => $this->string(20),
            'ccy'            => $this->char(3),
            'dt'             => $this->date(),
            'opening_bal'    => $this->decimal(),
            'opening_bal_dc' => $this->char(1),
            'closing_bal'    => $this->decimal(),
            'closing_bal_dc' => $this->char(1),
            'obj_ref'        => $this->string(256),
            'trn_ref_sr_no'  => $this->string(48),
            'amount'         => $this->decimal(),
            'drcr_ind'       => $this->char(1),
            'trn_dt'         => $this->date(),
            'value_dt'       => $this->date(),
            'ed_no'          => $this->string(40),
            'err_msg'        => $this->text(),
        ]);

        $this->createIndex(
            'idx_git_no_stro_extract_custom_extract_no',
            '{{%git_no_stro_extract_custom}}',
            'extract_no'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%git_no_stro_extract_custom}}');
    }
}
