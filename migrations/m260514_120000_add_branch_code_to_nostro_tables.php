<?php

use yii\db\Migration;

/**
 * Добавляет код филиала FCC12 в целевые таблицы выверки и балансов.
 */
class m260514_120000_add_branch_code_to_nostro_tables extends Migration
{
    /**
     * Применяет миграцию `m260514_120000_add_branch_code_to_nostro_tables`.
     *
     * Создаёт или изменяет структуру БД согласно назначению файла миграции.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('{{%nostro_entries}}', 'branch_code', $this->char(3)->null()
            ->comment('Код филиала из gitb_nostro_extract_custom.branch_code'));
        $this->createIndex(
            'idx_nostro_entries_branch_code',
            '{{%nostro_entries}}',
            'branch_code'
        );

        $this->addColumn('{{%nostro_balance}}', 'branch_code', $this->char(3)->null()
            ->comment('Код филиала из gitb_nostro_extract_custom.branch_code'));
        $this->createIndex(
            'idx_nbalance_branch_code',
            '{{%nostro_balance}}',
            'branch_code'
        );
    }

    /**
     * Откатывает миграцию `m260514_120000_add_branch_code_to_nostro_tables`.
     *
     * Возвращает структуру БД к состоянию до применения этой миграции, если откат поддерживается.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropIndex('idx_nbalance_branch_code', '{{%nostro_balance}}');
        $this->dropColumn('{{%nostro_balance}}', 'branch_code');

        $this->dropIndex('idx_nostro_entries_branch_code', '{{%nostro_entries}}');
        $this->dropColumn('{{%nostro_entries}}', 'branch_code');
    }
}
