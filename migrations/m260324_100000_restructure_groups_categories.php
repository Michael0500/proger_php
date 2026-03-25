<?php

use yii\db\Migration;

/**
 * Реструктуризация группировки счетов:
 *
 * account_groups        → categories
 * account_pools         → groups (group_id → category_id)
 * account_pool_filters  → group_filters (pool_id → group_id)
 *
 * + Создаётся новая таблица account_pools (ностробанки)
 *   с данными из старой account_pools (теперь groups).
 *   accounts.pool_id ссылается на новую account_pools.
 */
class m260324_100000_restructure_groups_categories extends Migration
{
    public function safeUp()
    {
        // ── 1. Удаляем внешние ключи, чтобы можно было переименовать таблицы ──

        // account_pool_filters.pool_id → account_pools
        $this->dropForeignKey('fk_pool_filters_pool_id', '{{%account_pool_filters}}');
        $this->dropIndex('idx_pool_filters_pool_id', '{{%account_pool_filters}}');

        // accounts.pool_id → account_pools
        $this->dropForeignKey('fk_accounts_pool_id', '{{%accounts}}');

        // account_pools.group_id → account_groups
        $this->dropForeignKey('fk_account_pools_group_id', '{{%account_pools}}');
        $this->dropIndex('idx_account_pools_group_id', '{{%account_pools}}');

        // account_pools.company_id → company
        $this->dropForeignKey('fk_account_pools_company_id', '{{%account_pools}}');
        $this->dropIndex('idx_account_pools_company_id', '{{%account_pools}}');

        // account_groups.company_id → company
        $this->dropForeignKey('fk_account_groups_company_id', '{{%account_groups}}');
        $this->dropIndex('idx_account_groups_company_id', '{{%account_groups}}');

        // ── 2. Создаём новую таблицу account_pools (ностробанки) ──
        //    Копируем данные ДО переименования старой таблицы
        $this->createTable('{{%account_pools_new}}', [
            'id'          => $this->primaryKey(),
            'company_id'  => $this->integer()->notNull(),
            'name'        => $this->string(100)->notNull(),
            'description' => $this->text(),
            'created_at'  => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at'  => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        // Копируем данные из текущей account_pools
        $this->execute('INSERT INTO {{%account_pools_new}} (id, company_id, name, description, created_at, updated_at) SELECT id, company_id, name, description, created_at, updated_at FROM {{%account_pools}}');

        // Синхронизируем sequence
        $this->execute("SELECT setval(pg_get_serial_sequence('account_pools_new', 'id'), COALESCE((SELECT MAX(id) FROM account_pools_new), 1))");

        // ── 3. Переименование таблиц ──

        // account_pool_filters → group_filters
        $this->renameTable('{{%account_pool_filters}}', '{{%group_filters}}');

        // account_pools → groups
        $this->renameTable('{{%account_pools}}', '{{%groups}}');

        // account_groups → categories
        $this->renameTable('{{%account_groups}}', '{{%categories}}');

        // account_pools_new → account_pools
        $this->renameTable('{{%account_pools_new}}', '{{%account_pools}}');

        // ── 4. Переименование колонок ──

        // groups: group_id → category_id
        $this->renameColumn('{{%groups}}', 'group_id', 'category_id');

        // group_filters: pool_id → group_id
        $this->renameColumn('{{%group_filters}}', 'pool_id', 'group_id');

        // ── 5. Восстанавливаем индексы и внешние ключи ──

        // categories.company_id → company
        $this->createIndex('idx_categories_company_id', '{{%categories}}', 'company_id');
        $this->addForeignKey(
            'fk_categories_company_id',
            '{{%categories}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE', 'CASCADE'
        );

        // groups.category_id → categories
        $this->createIndex('idx_groups_category_id', '{{%groups}}', 'category_id');
        $this->addForeignKey(
            'fk_groups_category_id',
            '{{%groups}}', 'category_id',
            '{{%categories}}', 'id',
            'CASCADE', 'CASCADE'
        );

        // groups.company_id → company
        $this->createIndex('idx_groups_company_id', '{{%groups}}', 'company_id');
        $this->addForeignKey(
            'fk_groups_company_id',
            '{{%groups}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE'
        );

        // group_filters.group_id → groups
        $this->createIndex('idx_group_filters_group_id', '{{%group_filters}}', 'group_id');
        $this->addForeignKey(
            'fk_group_filters_group_id',
            '{{%group_filters}}', 'group_id',
            '{{%groups}}', 'id',
            'CASCADE', 'CASCADE'
        );

        // account_pools.company_id → company
        $this->createIndex('idx_account_pools_company_id', '{{%account_pools}}', 'company_id');
        $this->addForeignKey(
            'fk_account_pools_company_id',
            '{{%account_pools}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE'
        );

        // accounts.pool_id → account_pools (новая таблица)
        $this->addForeignKey(
            'fk_accounts_pool_id',
            '{{%accounts}}', 'pool_id',
            '{{%account_pools}}', 'id',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        // ── Удаляем FK ──
        $this->dropForeignKey('fk_accounts_pool_id', '{{%accounts}}');
        $this->dropForeignKey('fk_account_pools_company_id', '{{%account_pools}}');
        $this->dropIndex('idx_account_pools_company_id', '{{%account_pools}}');
        $this->dropForeignKey('fk_group_filters_group_id', '{{%group_filters}}');
        $this->dropIndex('idx_group_filters_group_id', '{{%group_filters}}');
        $this->dropForeignKey('fk_groups_company_id', '{{%groups}}');
        $this->dropIndex('idx_groups_company_id', '{{%groups}}');
        $this->dropForeignKey('fk_groups_category_id', '{{%groups}}');
        $this->dropIndex('idx_groups_category_id', '{{%groups}}');
        $this->dropForeignKey('fk_categories_company_id', '{{%categories}}');
        $this->dropIndex('idx_categories_company_id', '{{%categories}}');

        // ── Обратное переименование колонок ──
        $this->renameColumn('{{%group_filters}}', 'group_id', 'pool_id');
        $this->renameColumn('{{%groups}}', 'category_id', 'group_id');

        // ── Удаляем новую account_pools ──
        $this->dropTable('{{%account_pools}}');

        // ── Обратное переименование таблиц ──
        $this->renameTable('{{%categories}}', '{{%account_groups}}');
        $this->renameTable('{{%groups}}', '{{%account_pools}}');
        $this->renameTable('{{%group_filters}}', '{{%account_pool_filters}}');

        // ── Восстанавливаем FK ──
        $this->createIndex('idx_account_groups_company_id', '{{%account_groups}}', 'company_id');
        $this->addForeignKey(
            'fk_account_groups_company_id',
            '{{%account_groups}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE', 'CASCADE'
        );

        $this->createIndex('idx_account_pools_group_id', '{{%account_pools}}', 'group_id');
        $this->addForeignKey(
            'fk_account_pools_group_id',
            '{{%account_pools}}', 'group_id',
            '{{%account_groups}}', 'id',
            'CASCADE', 'CASCADE'
        );

        $this->createIndex('idx_account_pools_company_id', '{{%account_pools}}', 'company_id');
        $this->addForeignKey(
            'fk_account_pools_company_id',
            '{{%account_pools}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE'
        );

        $this->createIndex('idx_pool_filters_pool_id', '{{%account_pool_filters}}', 'pool_id');
        $this->addForeignKey(
            'fk_pool_filters_pool_id',
            '{{%account_pool_filters}}', 'pool_id',
            '{{%account_pools}}', 'id',
            'CASCADE', 'CASCADE'
        );

        $this->addForeignKey(
            'fk_accounts_pool_id',
            '{{%accounts}}', 'pool_id',
            '{{%account_pools}}', 'id',
            'CASCADE'
        );
    }
}
