<?php

use yii\db\Migration;

class m260209_101034_add_company_id_to_user_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'company_id', $this->integer()->null());

        // Создание внешнего ключа
        $this->addForeignKey(
            'fk-user-company_id',
            '{{%user}}',
            'company_id',
            '{{%company}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        // Индекс для ускорения поиска
        $this->createIndex('idx-user-company_id', '{{%user}}', 'company_id');
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-user-company_id', '{{%user}}');
        $this->dropIndex('idx-user-company_id', '{{%user}}');
        $this->dropColumn('{{%user}}', 'company_id');
    }

    /*
    // Use up()/down() to run migration code without a transaction.
    public function up()
    {

    }

    public function down()
    {
        echo "m260209_101034_add_company_id_to_user_table cannot be reverted.\n";

        return false;
    }
    */
}
