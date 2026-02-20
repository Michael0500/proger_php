<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%account_groups}}`.
 */
class m260209_110500_create_account_groups_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%account_groups}}', [
            'id' => $this->primaryKey(),
            'company_id' => $this->integer()->notNull(),
            'name' => $this->string(100)->notNull(),
            'description' => $this->text(),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->createIndex('idx_account_groups_company_id', '{{%account_groups}}', 'company_id');
        $this->addForeignKey(
            'fk_account_groups_company_id',
            '{{%account_groups}}',
            'company_id',
            '{{%company}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_account_groups_company_id', '{{%account_groups}}');
        $this->dropIndex('idx_account_groups_company_id', '{{%account_groups}}');
        $this->dropTable('{{%account_groups}}');
    }
}
