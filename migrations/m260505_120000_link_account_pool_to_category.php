<?php

use yii\db\Migration;

/**
 * Прямая связь ностро-банка с категорией.
 *
 * До: Category → Group → GroupFilter(field='account_pool_id', value=<pool_id>)
 * После: Category 1—N AccountPool (account_pools.category_id NULLABLE)
 *
 * Шаги:
 *   1. Добавить account_pools.category_id (FK → categories.id, ON DELETE SET NULL)
 *   2. Перенести существующие связи: для каждого group_filters с field='account_pool_id'
 *      и логикой AND/eq записать g.category_id в соответствующий account_pools.category_id.
 *      Если для одного пула найдено несколько категорий — выигрывает первая по group.id.
 *   3. Удалить таблицы group_filters и groups.
 */
class m260505_120000_link_account_pool_to_category extends Migration
{
    public function safeUp()
    {
        // ── 1. Добавляем колонку с FK ──
        $this->addColumn('{{%account_pools}}', 'category_id', $this->integer()->null());
        $this->createIndex('idx_account_pools_category_id', '{{%account_pools}}', 'category_id');
        $this->addForeignKey(
            'fk_account_pools_category_id',
            '{{%account_pools}}', 'category_id',
            '{{%categories}}', 'id',
            'SET NULL', 'CASCADE'
        );

        // ── 2. Переносим данные ──
        // Для каждого пула берём первую (по group.id) найденную категорию через group_filters.
        // Совпадение по company_id обеспечивается равенством pool_id (PK глобально уникален).
        $this->execute("
            UPDATE {{%account_pools}} ap
            SET category_id = sub.category_id
            FROM (
                SELECT DISTINCT ON ((gf.value)::int)
                       (gf.value)::int AS pool_id,
                       g.category_id
                FROM {{%group_filters}} gf
                INNER JOIN {{%groups}} g ON g.id = gf.group_id
                WHERE gf.field = 'account_pool_id'
                  AND gf.operator = 'eq'
                  AND gf.value ~ '^[0-9]+$'
                ORDER BY (gf.value)::int, g.id ASC
            ) sub
            WHERE sub.pool_id = ap.id
        ");

        // ── 3. Удаляем таблицы groups + group_filters ──
        $this->dropTable('{{%group_filters}}');
        $this->dropTable('{{%groups}}');
    }

    public function safeDown()
    {
        // Восстанавливаем таблицу groups
        $this->createTable('{{%groups}}', [
            'id'          => $this->primaryKey(),
            'company_id'  => $this->integer()->notNull(),
            'category_id' => $this->integer()->notNull(),
            'name'        => $this->string(100)->notNull(),
            'description' => $this->text(),
            'is_active'   => $this->boolean()->defaultValue(true),
            'created_at'  => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at'  => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('idx_groups_category_id', '{{%groups}}', 'category_id');
        $this->createIndex('idx_groups_company_id', '{{%groups}}', 'company_id');
        $this->addForeignKey('fk_groups_category_id', '{{%groups}}', 'category_id', '{{%categories}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_groups_company_id', '{{%groups}}', 'company_id', '{{%company}}', 'id', 'CASCADE');

        // Восстанавливаем group_filters
        $this->createTable('{{%group_filters}}', [
            'id'         => $this->primaryKey(),
            'group_id'   => $this->integer()->notNull(),
            'field'      => $this->string(50)->notNull(),
            'operator'   => $this->string(20)->notNull(),
            'value'      => $this->string(255)->defaultValue(''),
            'logic'      => $this->string(10)->defaultValue('AND'),
            'sort_order' => $this->integer()->defaultValue(0),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('idx_group_filters_group_id', '{{%group_filters}}', 'group_id');
        $this->addForeignKey('fk_group_filters_group_id', '{{%group_filters}}', 'group_id', '{{%groups}}', 'id', 'CASCADE', 'CASCADE');

        // Восстановим (но без обратного переноса — связи теряются)
        $this->dropForeignKey('fk_account_pools_category_id', '{{%account_pools}}');
        $this->dropIndex('idx_account_pools_category_id', '{{%account_pools}}');
        $this->dropColumn('{{%account_pools}}', 'category_id');
    }
}
