<?php

use yii\db\Migration;

class m260325_100000_add_other_id_to_nostro_entries extends Migration
{
    public function safeUp()
    {
        $this->addColumn('nostro_entries', 'other_id', $this->string()->null()->defaultValue(null)->after('message_id'));
    }

    public function safeDown()
    {
        $this->dropColumn('nostro_entries', 'other_id');
    }
}
