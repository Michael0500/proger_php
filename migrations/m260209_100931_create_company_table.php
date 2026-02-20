<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%company}}`.
 */
class m260209_100931_create_company_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%company}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(100)->notNull()->unique(),
            'code' => $this->string(10)->notNull()->unique(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        // Создание двух компаний
        $this->insert('{{%company}}', [
            'name' => 'NRE',
            'code' => 'NRE',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->insert('{{%company}}', [
            'name' => 'INV',
            'code' => 'INV',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%company}}');
    }
}
