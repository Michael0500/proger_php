<?php

use yii\db\Migration;

/**
 * Составные partial-индексы для ускорения автоквитования.
 *
 * Проблема:
 *   Self-JOIN на nostro_entries (20M+ строк) фильтрует по:
 *     WHERE company_id=? AND ls=? AND match_status='U'
 *   и джойнит по:
 *     ON a.account_id = b.account_id AND a.amount = b.amount [AND a.value_date = b.value_date]
 *
 * Решение:
 *   Один partial index на незаквитованные записи (match_status='U').
 *   Ключ: (company_id, account_id, ls, amount, value_date) — покрывает
 *   WHERE-фильтр + JOIN-условия в одном B-tree lookup.
 *   Partial предикат сужает индекс только до строк со статусом 'U'.
 */
class m260326_110000_add_automatch_indexes extends Migration
{
    // up/down вместо safeUp/safeDown — CONCURRENTLY не работает внутри транзакции

    public function up()
    {
        // Удаляем возможные невалидные индексы от предыдущей попытки
        $this->db->createCommand('DROP INDEX IF EXISTS idx_ne_automatch_ledger')->execute();
        $this->db->createCommand('DROP INDEX IF EXISTS idx_ne_automatch_statement')->execute();

        // Один лёгкий partial index — без INCLUDE, только ключевые колонки
        $this->db->createCommand("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_automatch
            ON nostro_entries (company_id, account_id, ls, amount, value_date)
            WHERE match_status = 'U'
        ")->execute();
    }

    public function down()
    {
        $this->db->createCommand('DROP INDEX CONCURRENTLY IF EXISTS idx_ne_automatch')->execute();
    }
}
