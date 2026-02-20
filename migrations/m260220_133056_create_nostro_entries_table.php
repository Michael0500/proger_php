<?php

use yii\db\Expression;
use yii\db\Migration;

/**
 * Таблица записей (данных) для выверки Ностро.
 * Каждая запись привязана к счёту (account) и содержит
 * данные по бух. проводкам Ledger и выпискам Statement.
 */
class m260220_133056_create_nostro_entries_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%nostro_entries}}', [
            'id'             => $this->primaryKey(),
            'account_id'     => $this->integer()->notNull()->comment('Ностро банк (счёт)'),
            'company_id'     => $this->integer()->notNull(),

            // Идентификатор квитования — одинаковый для Ledger и Statement при совпадении
            'match_id'       => $this->string()->null()->comment('Match ID'),

            // L = Ledger (бух. проводка), S = Statement (выписка)
            'ls'             => $this->char(1)->notNull()->comment('L или S'),

            // D = Debit, C = Credit
            'dc'             => $this->string(6)->notNull()->comment('Debit или Credit'),

            'amount'         => $this->decimal(18, 2)->notNull()->comment('Сумма'),
            'currency'       => $this->char(3)->notNull()->comment('Валюта ISO 4217'),
            'value_date'     => $this->date()->null()->comment('Дата валютирования'),
            'post_date'      => $this->date()->null()->comment('Дата проводки / дата выписки'),

            'instruction_id' => $this->string(40)->null(),
            'end_to_end_id'  => $this->string(40)->null(),
            'transaction_id' => $this->string(60)->null(),
            'message_id'     => $this->string(40)->null(),

            'comment'        => $this->string(40)->null()->comment('Комментарий сотрудника'),

            // Источник загрузки: FCC12, MT950, ED211, BARS_GL и т.д.
            'source'         => $this->string(20)->null()->comment('Источник данных'),

            // Статус квитования: U=unmatched, M=matched, I=ignored
            'match_status'   => $this->char(1)->defaultValue('U')->comment('U/M/I'),

            'created_by'     => $this->integer()->null(),
            'updated_by'     => $this->integer()->null(),
            'created_at'     => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at'     => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        // Индексы
        $this->createIndex('idx_nostro_entries_account_id',   '{{%nostro_entries}}', 'account_id');
        $this->createIndex('idx_nostro_entries_company_id',   '{{%nostro_entries}}', 'company_id');
        $this->createIndex('idx_nostro_entries_match_id',     '{{%nostro_entries}}', 'match_id');
        $this->createIndex('idx_nostro_entries_match_status', '{{%nostro_entries}}', 'match_status');

        // Внешние ключи
        $this->addForeignKey(
            'fk_nostro_entries_account_id',
            '{{%nostro_entries}}', 'account_id',
            'accounts', 'id',
            'CASCADE', 'CASCADE'
        );

        $this->addForeignKey(
            'fk_nostro_entries_company_id',
            '{{%nostro_entries}}', 'company_id',
            'company', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_nostro_entries_account_id', '{{%nostro_entries}}');
        $this->dropForeignKey('fk_nostro_entries_company_id', '{{%nostro_entries}}');
        $this->dropTable('{{%nostro_entries}}');
    }
}