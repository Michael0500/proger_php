<?php

use yii\db\Migration;

/**
 * Добавляет недостающие поля в сырьевые таблицы выписок TDS.
 */
class m260625_120000_add_missing_tds_source_columns extends Migration
{
    /**
     * @return void
     */
    public function safeUp()
    {
        $this->addColumnIfMissing(
            '{{%ph_tds_stmt_hdr}}',
            'stmt_nb',
            $this->string(5)->null()->comment('Номер выписки/сообщения TDS STMT_NB')
        );
        $this->addColumnIfMissing(
            '{{%ph_tds_stmt_hdr}}',
            'pg_nb',
            $this->string(5)->null()->comment('Номер страницы TDS PG_NB')
        );
        $this->addColumnIfMissing(
            '{{%ph_tds_stmt_hdr}}',
            'last_pg_ind',
            $this->string(5)->null()->comment('Признак последней страницы TDS LAST_PG_IND')
        );
        $this->addColumnIfMissing(
            '{{%ph_tds_stmt_hdr}}',
            'opening_is_intim',
            $this->string(5)->null()->comment('Признак промежуточного входящего баланса TDS')
        );
        $this->addColumnIfMissing(
            '{{%ph_tds_stmt_hdr}}',
            'closing_is_intim',
            $this->string(5)->null()->comment('Признак промежуточного исходящего баланса TDS')
        );
        $this->addColumnIfMissing(
            '{{%ph_tds_stmt_hdr}}',
            'tds_status',
            $this->smallInteger()->null()->comment('Статус строки в источнике TDS')
        );

        $this->addColumnIfMissing(
            '{{%ph_tds_stmt_dtl}}',
            'tds_status',
            $this->smallInteger()->null()->comment('Статус строки в источнике TDS')
        );
    }

    /**
     * @return void
     */
    public function safeDown()
    {
        $this->dropColumnIfExists('{{%ph_tds_stmt_dtl}}', 'tds_status');

        $this->dropColumnIfExists('{{%ph_tds_stmt_hdr}}', 'tds_status');
        $this->dropColumnIfExists('{{%ph_tds_stmt_hdr}}', 'closing_is_intim');
        $this->dropColumnIfExists('{{%ph_tds_stmt_hdr}}', 'opening_is_intim');
        $this->dropColumnIfExists('{{%ph_tds_stmt_hdr}}', 'last_pg_ind');
        $this->dropColumnIfExists('{{%ph_tds_stmt_hdr}}', 'pg_nb');
        $this->dropColumnIfExists('{{%ph_tds_stmt_hdr}}', 'stmt_nb');
    }

    /**
     * @param string $table
     * @param string $column
     * @param mixed $type
     * @return void
     */
    private function addColumnIfMissing(string $table, string $column, $type): void
    {
        $schema = $this->db->schema->getTableSchema($table, true);
        if ($schema !== null && $schema->getColumn($column) !== null) {
            return;
        }

        $this->addColumn($table, $column, $type);
    }

    /**
     * @param string $table
     * @param string $column
     * @return void
     */
    private function dropColumnIfExists(string $table, string $column): void
    {
        $schema = $this->db->schema->getTableSchema($table, true);
        if ($schema === null || $schema->getColumn($column) === null) {
            return;
        }

        $this->dropColumn($table, $column);
    }
}
