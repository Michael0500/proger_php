<?php

use yii\db\Migration;

/**
 * Индексы для ускорения автоквитования с перекрёстным поиском по ID.
 *
 * Проблема:
 *   На 8M+ записей запрос JOIN по OR-условиям на ID-полях (16 комбинаций)
 *   делает Seq Scan, т.к. нет индексов на instruction_id/end_to_end_id/etc.
 *   Также JOIN nostro_entries → accounts → accounts (через pool_id) медленный
 *   без индекса на accounts.pool_id.
 *
 * Решение:
 *   1. Partial-индексы на каждое ID-поле (только незаквитованные, только IS NOT NULL)
 *      → PostgreSQL делает BitmapIndexScan для каждого OR-условия
 *   2. Индекс accounts(pool_id) → ускоряет JOIN через ностробанк
 *   3. Обновлённый partial-индекс для основного фильтра автоквитования
 */
class m260327_120000_add_crossid_and_pool_indexes extends Migration
{
    public function transaction(): ?string
    {
        return null; // CONCURRENTLY нельзя внутри транзакции
    }

    public function up(): void
    {
        // ── 1. Индексы на ID-поля (для перекрёстного поиска) ─────────────────
        // Partial: только незаквитованные И только заполненные поля.
        // Размер индекса = доля строк с match_status='U' AND field IS NOT NULL.

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_uid_instruction_id
            ON nostro_entries (instruction_id)
            WHERE match_status = 'U' AND instruction_id IS NOT NULL
        ");

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_uid_end_to_end_id
            ON nostro_entries (end_to_end_id)
            WHERE match_status = 'U' AND end_to_end_id IS NOT NULL
        ");

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_uid_transaction_id
            ON nostro_entries (transaction_id)
            WHERE match_status = 'U' AND transaction_id IS NOT NULL
        ");

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_uid_message_id
            ON nostro_entries (message_id)
            WHERE match_status = 'U' AND message_id IS NOT NULL
        ");

        // ── 2. Индекс на accounts.pool_id ────────────────────────────────────
        // Используется в JOIN accounts acc_b ON acc_b.pool_id = acc_a.pool_id

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_accounts_pool_id
            ON accounts (pool_id)
            WHERE pool_id IS NOT NULL
        ");

        // ── 3. Основной partial-индекс для автоквитования ────────────────────
        // Покрывает: WHERE company_id=? AND ls=? AND match_status='U'
        // + JOIN по account_id + сравнение amount, value_date.
        // Заменяет старый idx_ne_automatch (тот был без ls).

        $this->execute("DROP INDEX CONCURRENTLY IF EXISTS idx_ne_automatch");

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_automatch_v2
            ON nostro_entries (company_id, ls, account_id, amount, value_date)
            WHERE match_status = 'U'
        ");

        // ── 4. Индекс для сканирования незаквитованных записей по id ────────
        // DISTINCT ON (a.id) ORDER BY a.id требует обхода записей в порядке id.
        // Этот индекс позволяет PostgreSQL делать Index Scan в id-порядке и
        // останавливаться сразу после LIMIT — не сортируя весь набор записей.

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_unmatched_scan
            ON nostro_entries (company_id, ls, id)
            WHERE match_status = 'U'
        ");

        $this->execute('ANALYZE nostro_entries');
        $this->execute('ANALYZE accounts');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_ne_uid_instruction_id');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_ne_uid_end_to_end_id');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_ne_uid_transaction_id');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_ne_uid_message_id');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_accounts_pool_id');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_ne_automatch_v2');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_ne_unmatched_scan');

        // Восстанавливаем старый индекс
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_automatch
            ON nostro_entries (company_id, account_id, ls, amount, value_date)
            WHERE match_status = 'U'
        ");
    }
}
