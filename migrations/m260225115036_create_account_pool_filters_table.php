<?php

use yii\db\Migration;

/**
 * Создаёт таблицу account_pool_filters — отдельные строки условий фильтрации пула.
 * Структура каждой строки:
 *   field    — поле счёта (currency, account_type, bank_code, country, is_suspense, name, account_number)
 *   operator — eq | neq (равно / не равно)
 *   value    — строковое значение
 *   logic    — AND | OR (как эта строка объединяется с предыдущими)
 *   sort_order — порядок отображения (0, 1, 2…)
 */
class m260225115036_create_account_pool_filters_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%account_pool_filters}}', [
            'id'         => $this->primaryKey(),
            'pool_id'    => $this->integer()->notNull(),
            'field'      => $this->string(50)->notNull(),
            'operator'   => $this->string(10)->notNull()->defaultValue('eq'), // eq | neq
            'value'      => $this->string(255)->notNull()->defaultValue(''),
            'logic'      => $this->string(3)->notNull()->defaultValue('AND'), // AND | OR
            'sort_order' => $this->smallInteger()->notNull()->defaultValue(0),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->createIndex(
            'idx_pool_filters_pool_id',
            '{{%account_pool_filters}}',
            'pool_id'
        );

        $this->addForeignKey(
            'fk_pool_filters_pool_id',
            '{{%account_pool_filters}}',
            'pool_id',
            '{{%account_pools}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // Удаляем старое поле filter_criteria из account_pools (данные миграции не нужны — формат несовместим)
        $this->dropColumn('{{%account_pools}}', 'filter_criteria');
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_pool_filters_pool_id', '{{%account_pool_filters}}');
        $this->dropTable('{{%account_pool_filters}}');

        // Возвращаем поле обратно
        $this->addColumn('{{%account_pools}}', 'filter_criteria', $this->json());
    }
}