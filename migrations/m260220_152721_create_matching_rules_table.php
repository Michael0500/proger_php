<?php

use yii\db\Migration;

/**
 * Таблица правил автоматического квитования.
 * Каждое правило описывает набор условий для сопоставления пары записей.
 */
class m260220_152721_create_matching_rules_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%matching_rules}}', [
            'id'            => $this->primaryKey(),
            'company_id'    => $this->integer()->notNull(),
            'name'          => $this->string(100)->notNull(),

            // К какому разделу применяется: NRE или INV
            'section'       => $this->char(3)->notNull(), // NRE | INV

            // Тип пары: LS=Ledger+Statement, LL=Ledger+Ledger, SS=Statement+Statement
            'pair_type'     => $this->char(2)->notNull()->defaultValue('LS'),

            // Обязательные условия
            'match_dc'      => $this->boolean()->notNull()->defaultValue(true),  // Дебет/Кредит противоположны
            'match_amount'  => $this->boolean()->notNull()->defaultValue(true),  // Суммы совпадают
            'match_value_date' => $this->boolean()->notNull()->defaultValue(true), // Дата валютирования

            // Опциональные условия: совпадение идентификаторов (любое из выбранных)
            'match_instruction_id'  => $this->boolean()->notNull()->defaultValue(false),
            'match_end_to_end_id'   => $this->boolean()->notNull()->defaultValue(false),
            'match_transaction_id'  => $this->boolean()->notNull()->defaultValue(false),
            'match_message_id'      => $this->boolean()->notNull()->defaultValue(false),

            // Перекрёстный поиск: искать id в любом из полей другой записи
            'cross_id_search' => $this->boolean()->notNull()->defaultValue(false),

            'is_active'     => $this->boolean()->notNull()->defaultValue(true),
            'priority'      => $this->smallInteger()->notNull()->defaultValue(100), // меньше = выше приоритет
            'description'   => $this->text()->null(),

            'created_at'    => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'updated_at'    => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        $this->createIndex('idx_matching_rules_company', '{{%matching_rules}}', 'company_id');
        $this->createIndex('idx_matching_rules_section', '{{%matching_rules}}', 'section');

        $this->addForeignKey(
            'fk_matching_rules_company',
            '{{%matching_rules}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_matching_rules_company', '{{%matching_rules}}');
        $this->dropTable('{{%matching_rules}}');
    }
}