<?php

use yii\db\Migration;

/**
 * Добавляет поля для трассировки источника TDS (CAMT053/MT950/ED211/ED743):
 *   - stmt_id  — идентификатор пакета в ph_tds_stmt_hdr/dtl
 *   - edno     — номер ED-документа (ED211/ED743)
 *   - eddate   — дата ED-документа (ED211/ED743)
 *   - edauthor — автор ED-документа (ED211/ED743)
 *
 * Поля nullable: исторические записи и записи других источников их не имеют.
 */
class m260525_120000_add_tds_fields_to_nostro_tables extends Migration
{
    /**
     * Применяет миграцию `m260525_120000_add_tds_fields_to_nostro_tables`.
     *
     * @return void
     */
    public function safeUp()
    {
        // nostro_balance
        $this->addColumn('{{%nostro_balance}}', 'stmt_id', $this->decimal(12, 0)->null()
            ->comment('ID пакета выписки в ph_tds_stmt_hdr (TDS)'));
        $this->addColumn('{{%nostro_balance}}', 'edno', $this->decimal()->null()
            ->comment('Номер ED-документа (ED211/ED743)'));
        $this->addColumn('{{%nostro_balance}}', 'eddate', $this->date()->null()
            ->comment('Дата ED-документа (ED211/ED743)'));
        $this->addColumn('{{%nostro_balance}}', 'edauthor', $this->string(10)->null()
            ->comment('Автор ED-документа (ED211/ED743)'));

        $this->createIndex('idx_nbalance_stmt_id', '{{%nostro_balance}}', 'stmt_id');

        // nostro_entries
        $this->addColumn('{{%nostro_entries}}', 'stmt_id', $this->decimal(12, 0)->null()
            ->comment('ID пакета выписки в ph_tds_stmt_hdr (TDS)'));
        $this->addColumn('{{%nostro_entries}}', 'edno', $this->decimal()->null()
            ->comment('Номер ED-документа (ED211/ED743)'));
        $this->addColumn('{{%nostro_entries}}', 'eddate', $this->date()->null()
            ->comment('Дата ED-документа (ED211/ED743)'));
        $this->addColumn('{{%nostro_entries}}', 'edauthor', $this->string(10)->null()
            ->comment('Автор ED-документа (ED211/ED743)'));

        $this->createIndex('idx_nostro_entries_stmt_id', '{{%nostro_entries}}', 'stmt_id');
    }

    /**
     * Откатывает миграцию `m260525_120000_add_tds_fields_to_nostro_tables`.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropIndex('idx_nostro_entries_stmt_id', '{{%nostro_entries}}');
        $this->dropColumn('{{%nostro_entries}}', 'edauthor');
        $this->dropColumn('{{%nostro_entries}}', 'eddate');
        $this->dropColumn('{{%nostro_entries}}', 'edno');
        $this->dropColumn('{{%nostro_entries}}', 'stmt_id');

        $this->dropIndex('idx_nbalance_stmt_id', '{{%nostro_balance}}');
        $this->dropColumn('{{%nostro_balance}}', 'edauthor');
        $this->dropColumn('{{%nostro_balance}}', 'eddate');
        $this->dropColumn('{{%nostro_balance}}', 'edno');
        $this->dropColumn('{{%nostro_balance}}', 'stmt_id');
    }
}
