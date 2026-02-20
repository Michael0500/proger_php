<?php

use yii\db\Expression;
use yii\db\Migration;

/**
 * Handles the creation of table `{{%account}}`.
 */
class m260209_110621_create_account_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%accounts}}', [
            'id' => $this->primaryKey(),
            'company_id' => $this->integer()->notNull(),
            'pool_id' => $this->integer()->notNull(),
            'name' => $this->string(55)->notNull(),
            'currency' => $this->string(3),
            'account_type' => $this->string(20),
            'country' => $this->string(2),
            'load_barsgl' => $this->boolean(),
            'created_by' => $this->integer(),
            'created_at' => $this->timestamp()->notNull()->defaultValue(new Expression('CURRENT_TIMESTAMP')),
            'updated_at' => $this->timestamp()->notNull()->defaultValue(new Expression('CURRENT_TIMESTAMP')),
            'updated_by' => $this->integer(),
            'load_status' => $this->char(1)->defaultValue('L'),
            'date_close' => $this->timestamp(),
            'is_suspense' => $this->boolean(),
            'date_open' => $this->timestamp(),
        ]);

        // Внешние ключи
        $this->addForeignKey(
            'fk_accounts_company_id',
            'accounts',
            'company_id',
            'company',
            'id',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk_accounts_pool_id',
            'accounts',
            'pool_id',
            'account_pools',
            'id',
            'CASCADE'
        );

        // Индексы для ускорения запросов
        $this->createIndex('idx_accounts_company_id', 'accounts', 'company_id');
        $this->createIndex('idx_accounts_pool_id', 'accounts', 'pool_id');
        $this->createIndex('idx_account_is_suspense', 'accounts', 'is_suspense');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('accounts');
    }
}
