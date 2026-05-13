<?php

use yii\db\Migration;

/**
 * Расширяет денежные поля до decimal(20,2).
 */
class m260513_120000_expand_money_precision_to_20_2 extends Migration
{
    public function safeUp()
    {
        $this->alterColumn('{{%nostro_entries}}', 'amount', $this->decimal(20, 2)->notNull()->comment('Сумма'));
        $this->alterColumn('{{%nostro_entries_archive}}', 'amount', $this->decimal(20, 2)->notNull());
        $this->alterColumn('{{%nostro_balance}}', 'opening_balance', $this->decimal(20, 2)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance}}', 'closing_balance', $this->decimal(20, 2)->notNull()->defaultValue(0));
    }

    public function safeDown()
    {
        $this->alterColumn('{{%nostro_entries}}', 'amount', $this->decimal(18, 2)->notNull()->comment('Сумма'));
        $this->alterColumn('{{%nostro_entries_archive}}', 'amount', $this->decimal(18, 2)->notNull());
        $this->alterColumn('{{%nostro_balance}}', 'opening_balance', $this->decimal(18, 2)->notNull()->defaultValue(0));
        $this->alterColumn('{{%nostro_balance}}', 'closing_balance', $this->decimal(18, 2)->notNull()->defaultValue(0));
    }
}
