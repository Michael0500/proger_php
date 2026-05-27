<?php

use yii\db\Migration;

/**
 * Создаёт сырой приёмник проводок DWH для будущего переноса в INV.
 */
class m260527_120000_create_suspend_posting_table extends Migration
{
    /**
     * Применяет миграцию `m260527_120000_create_suspend_posting_table`.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->createTable('{{%suspend_posting}}', [
            'id'                 => $this->primaryKey(),
            'posting_id'         => $this->bigInteger()->notNull(),
            'abs_branch_code'    => $this->string(3),
            'cbaccount'          => $this->string(50)->notNull(),
            'ccy'                => $this->string(3),
            'start_date'         => $this->date()->notNull(),
            'end_date'           => $this->date()->notNull(),
            'saldo_in_amt'       => $this->decimal(18, 6),
            'saldo_out_amt'      => $this->decimal(18, 6),
            'dc_indicator_saldo' => $this->string(1),
            'valuedate'          => $this->date(),
            'amount'             => $this->decimal(18, 6),
            'dc_indicator'       => $this->string(1),
            'originaltran_ref'   => $this->string(32),
            'narrative'          => $this->string(300),
            'valid_from_dttm'    => $this->timestamp(0)->notNull(),
            'valid_to_dttm'      => $this->timestamp(0)->notNull(),
            'processed_dttm'     => $this->timestamp(0)->notNull(),
            'is_merged'          => $this->boolean()->notNull()->defaultValue(false),
        ]);

        $this->createIndex('idx_suspend_posting_is_merged', '{{%suspend_posting}}', 'is_merged');
        $this->createIndex('idx_suspend_posting_cbaccount', '{{%suspend_posting}}', 'cbaccount');
        $this->createIndex('idx_suspend_posting_posting_id', '{{%suspend_posting}}', 'posting_id');
    }

    /**
     * Откатывает миграцию `m260527_120000_create_suspend_posting_table`.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropTable('{{%suspend_posting}}');
    }
}
