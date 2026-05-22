<?php

use yii\db\Migration;

/**
 * Создаёт таблицу заголовков и балансов выписок TDS.
 */
class m260522_120000_create_ph_tds_stmt_hdr_table extends Migration
{
    /**
     * Применяет миграцию `m260522_120000_create_ph_tds_stmt_hdr_table`.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->createTable('{{%ph_tds_stmt_hdr}}', [
            'stmt_id' => $this->decimal(12, 0)->notNull(),
            'msg_key' => $this->decimal(12, 0),
            'format_type' => $this->string(10)->notNull()->comment('Message format'),
            'stmt_ref' => $this->string(35)->comment('MT950 :20: or CAMT053 Stmt/Id'),
            'account_no' => $this->string(35)->comment('MT950 :25: or CAMT053 Acct/Id'),
            'opening_dc' => $this->char(1)->comment('Opening balance D/C indicator (:60F: or OPBD)'),
            'opening_value_dt' => $this->date()->comment('Opening value date'),
            'opening_currency' => $this->char(3)->comment('Opening balance currency'),
            'opening_amount' => $this->decimal(18, 2)->comment('Opening balance amount'),
            'closing_dc' => $this->char(1)->comment('Closing balance D/C indicator (:62F: or CLBD)'),
            'closing_currency' => $this->char(3)->comment('Closing balance currency'),
            'closing_amount' => $this->decimal(18, 2)->comment('Closing balance amount'),
            // Oracle NUMBER без параметров переносится как numeric без ограничения точности.
            'edno' => $this->decimal(),
            'eddate' => $this->date(),
            'edbranch' => $this->string(3),
            'proc_status' => $this->string(20),
            'load_dt' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
    }

    /**
     * Откатывает миграцию `m260522_120000_create_ph_tds_stmt_hdr_table`.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropTable('{{%ph_tds_stmt_hdr}}');
    }
}
