<?php

use yii\db\Expression;
use yii\db\Migration;

/**
 * Handles the creation of table `{{%account_pool}}`.
 */
class m260209_110501_create_account_pool_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%account_pools}}', [
            'id' => $this->primaryKey(),
            'company_id' => $this->integer()->notNull(),
            'group_id' => $this->integer()->notNull(),
            'name' => $this->string(100)->notNull(),
            'description' => $this->text(),
            'filter_criteria' => $this->json(), // Настройки фильтрации счетов
            'is_active' => $this->boolean()->defaultValue(true),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->createIndex('idx_account_pools_group_id', '{{%account_pools}}', 'group_id');
        $this->addForeignKey(
            'fk_account_pools_group_id',
            '{{%account_pools}}',
            'group_id',
            '{{%account_groups}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // Внешний ключ на таблицу company
        $this->addForeignKey(
            'fk_account_pools_company_id',
            'account_pools',
            'company_id',
            'company',
            'id',
            'CASCADE'
        );

        // Индекс для ускорения поиска по company_id
        $this->createIndex('idx_account_pools_company_id', 'account_pools', 'company_id');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('account_pools');
    }
}
