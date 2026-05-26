<?php

use yii\db\Migration;

/**
 * Добавляет архив балансов Ностро.
 *
 * Балансы архивируются отдельно от проводок: активные строки из
 * `nostro_balance` переносятся в `nostro_balance_archive`, а аудит сохраняется
 * по исходному `balance_id`. Поэтому FK аудита на активную таблицу удаляется:
 * иначе PostgreSQL каскадно удалит историю при архивировании.
 */
class m260526_100000_create_nostro_balance_archive_table extends Migration
{
    /**
     * Применяет миграцию.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->createTable('{{%nostro_balance_archive}}', [
            'id'                 => $this->primaryKey(),
            'original_id'        => $this->integer()->notNull()->comment('ID из nostro_balance'),
            'company_id'         => $this->integer()->notNull(),
            'account_id'         => $this->integer()->notNull(),
            'ls_type'            => $this->char(1)->notNull(),
            'statement_number'   => $this->string(35)->null(),
            'currency'           => $this->char(3)->notNull(),
            'value_date'         => $this->date()->notNull(),
            'opening_balance'    => $this->decimal(20, 2)->notNull()->defaultValue(0),
            'opening_dc'         => $this->char(1)->notNull(),
            'closing_balance'    => $this->decimal(20, 2)->notNull()->defaultValue(0),
            'closing_dc'         => $this->char(1)->notNull(),
            'section'            => $this->char(3)->notNull(),
            'source'             => $this->string(20)->notNull(),
            'status'             => $this->string(10)->notNull()->defaultValue('normal'),
            'comment'            => $this->string(255)->null(),
            'branch_code'        => $this->char(3)->null(),
            'extract_no'         => $this->bigInteger()->null(),
            'line_no'            => $this->integer()->null(),
            'stmt_id'            => $this->decimal(12, 0)->null(),
            'edno'               => $this->decimal()->null(),
            'eddate'             => $this->date()->null(),
            'edauthor'           => $this->string(10)->null(),
            'created_by'         => $this->integer()->null(),
            'updated_by'         => $this->integer()->null(),
            'archived_at'        => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'expires_at'         => $this->timestamp()->notNull(),
            'archived_by'        => $this->integer()->null(),
            'original_created_at'=> $this->timestamp()->null(),
            'original_updated_at'=> $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_nbalance_archive_company',     '{{%nostro_balance_archive}}', 'company_id');
        $this->createIndex('idx_nbalance_archive_account',     '{{%nostro_balance_archive}}', 'account_id');
        $this->createIndex('idx_nbalance_archive_original',    '{{%nostro_balance_archive}}', 'original_id');
        $this->createIndex('idx_nbalance_archive_value_date',  '{{%nostro_balance_archive}}', 'value_date');
        $this->createIndex('idx_nbalance_archive_archived_at', '{{%nostro_balance_archive}}', 'archived_at');
        $this->createIndex('idx_nbalance_archive_expires_at',  '{{%nostro_balance_archive}}', 'expires_at');
        $this->createIndex('idx_nbalance_archive_currency',    '{{%nostro_balance_archive}}', 'currency');
        $this->createIndex('idx_nbalance_archive_ls_type',     '{{%nostro_balance_archive}}', 'ls_type');
        $this->createIndex('idx_nbalance_archive_status',      '{{%nostro_balance_archive}}', 'status');
        $this->createIndex('idx_nbalance_archive_section',     '{{%nostro_balance_archive}}', 'section');
        $this->createIndex('idx_nbalance_archive_stmt_id',     '{{%nostro_balance_archive}}', 'stmt_id');

        $this->addColumn('{{%nostro_balance_audit}}', 'archived_id', $this->integer()->null()
            ->comment('ID архивной записи (для archive/restore)'));
        $this->createIndex('idx_nbalance_audit_archived_id', '{{%nostro_balance_audit}}', 'archived_id');

        $this->dropForeignKey('fk_nbalance_audit_balance', '{{%nostro_balance_audit}}');
    }

    /**
     * Откатывает миграцию.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->delete('{{%nostro_balance_audit}}', [
            'not in',
            'balance_id',
            (new \yii\db\Query())->select('id')->from('{{%nostro_balance}}'),
        ]);

        $this->addForeignKey(
            'fk_nbalance_audit_balance',
            '{{%nostro_balance_audit}}', 'balance_id',
            '{{%nostro_balance}}', 'id',
            'CASCADE', 'CASCADE'
        );

        $this->dropIndex('idx_nbalance_audit_archived_id', '{{%nostro_balance_audit}}');
        $this->dropColumn('{{%nostro_balance_audit}}', 'archived_id');

        $this->dropTable('{{%nostro_balance_archive}}');
    }
}
