<?php

use yii\db\Migration;

/**
 * Таблица балансов Ностро счетов (Opening/Closing Balance).
 * Раздельно от nostro_entries — хранит входящий/исходящий остаток
 * по каждому счёту за каждую дату валютирования.
 *
 * Тип L = Ledger (FCC12, BARS GL) — внутренние данные
 * Тип S = Statement (выписки)     — внешние данные
 */
class m260223_000001_create_nostro_balance_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%nostro_balance}}', [
            'id'               => $this->primaryKey(),
            'company_id'       => $this->integer()->notNull(),
            'account_id'       => $this->integer()->notNull()->comment('FK → accounts.id'),

            // L = Ledger | S = Statement
            'ls_type'          => $this->char(1)->notNull()->comment('L или S'),

            // Только для S-записей
            'statement_number' => $this->string(35)->null()->comment('Номер выписки (только S)'),

            // Валюта ISO 4217
            'currency'         => $this->char(3)->notNull(),

            // Дата валютирования — хранится как DATE, отображается DD/MM/YYYY
            'value_date'       => $this->date()->notNull(),

            // Opening balance
            'opening_balance'  => $this->decimal(18, 2)->notNull()->defaultValue(0),
            'opening_dc'       => $this->char(1)->notNull()->comment('D или C'),

            // Closing balance
            'closing_balance'  => $this->decimal(18, 2)->notNull()->defaultValue(0),
            'closing_dc'       => $this->char(1)->notNull()->comment('D или C'),

            // Раздел системы
            'section'          => $this->char(3)->notNull()->comment('NRE или INV'),

            // Источник данных
            'source'           => $this->string(20)->notNull(),

            // Статус: normal | error | confirmed
            'status'           => $this->string(10)->notNull()->defaultValue('normal'),

            // Причина ошибки / комментарий
            'comment'          => $this->string(255)->null(),

            // Служебные поля
            'created_by'       => $this->integer()->null(),
            'updated_by'       => $this->integer()->null(),
            'created_at'       => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'updated_at'       => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        // Индексы
        $this->createIndex('idx_nbalance_company',    '{{%nostro_balance}}', 'company_id');
        $this->createIndex('idx_nbalance_account',    '{{%nostro_balance}}', 'account_id');
        $this->createIndex('idx_nbalance_value_date', '{{%nostro_balance}}', 'value_date');
        $this->createIndex('idx_nbalance_status',     '{{%nostro_balance}}', 'status');
        $this->createIndex('idx_nbalance_section',    '{{%nostro_balance}}', 'section');
        $this->createIndex('idx_nbalance_ls_type',    '{{%nostro_balance}}', 'ls_type');

        // Уникальность: один баланс на (account, ls_type, currency, value_date, section, source)
        $this->createIndex(
            'uq_nbalance_entry',
            '{{%nostro_balance}}',
            ['account_id', 'ls_type', 'currency', 'value_date', 'section', 'source'],
            true
        );

        // FK
        $this->addForeignKey(
            'fk_nbalance_account_id',
            '{{%nostro_balance}}', 'account_id',
            '{{%accounts}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            'fk_nbalance_company_id',
            '{{%nostro_balance}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_nbalance_account_id', '{{%nostro_balance}}');
        $this->dropForeignKey('fk_nbalance_company_id', '{{%nostro_balance}}');
        $this->dropTable('{{%nostro_balance}}');
    }
}