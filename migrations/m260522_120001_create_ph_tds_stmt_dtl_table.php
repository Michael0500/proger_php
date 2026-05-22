<?php

use yii\db\Migration;

/**
 * Создаёт таблицу строк транзакций выписок TDS.
 */
class m260522_120001_create_ph_tds_stmt_dtl_table extends Migration
{
    /**
     * Применяет миграцию `m260522_120001_create_ph_tds_stmt_dtl_table`.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->createTable('{{%ph_tds_stmt_dtl}}', [
            'stmt_id' => $this->decimal(12, 0)->notNull(),
            'line_no' => $this->integer()->notNull()->comment('Sequential transaction number within statement'),
            'value_dt' => $this->date()->comment('Value date (:61: or ValDt)'),
            'entry_dt' => $this->date()->comment('Entry/booking date'),
            'op_type' => $this->string(4),
            'dc_mark' => $this->string(6)->comment('Debit (D) or Credit (C)'),
            'amount' => $this->decimal(18, 2)->comment('Transaction amount (absolute)'),
            'currency' => $this->char(3)->comment('Transaction currency'),
            'txn_type' => $this->string(10)->comment('Transaction type code'),
            'instr_id' => $this->string(35)->comment('Owner reference (MT950 ref_owner / CAMT InstrId)'),
            'tx_id' => $this->string(35),
            'end_to_end_id' => $this->string(35)->comment('Bank reference (MT950 ref_bank / CAMT end_to_end_id)'),
            'ref_edno' => $this->decimal(),
            'ref_eddate' => $this->date(),
            'ref_edauthor' => $this->string(10),
            'entry_ref' => $this->string(40)->comment('reference for ed211 from ph_im'),
            'ed_account' => $this->string(40),
            'ed_bank_name' => $this->string(60),
            'load_dt' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
    }

    /**
     * Откатывает миграцию `m260522_120001_create_ph_tds_stmt_dtl_table`.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropTable('{{%ph_tds_stmt_dtl}}');
    }
}
