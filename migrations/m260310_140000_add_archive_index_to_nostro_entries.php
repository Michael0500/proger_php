<?php

use yii\db\Migration;

/**
 * Добавляет составной индекс на nostro_entries для ускорения запросов
 * архивирования в ArchiveController (actionCount, actionStats, actionRunBatch).
 *
 * Проблема (зафиксирована в EXPLAIN ANALYZE):
 *   SELECT COUNT(*) FROM nostro_entries
 *   WHERE company_id   = 1
 *     AND match_status = 'M'
 *     AND match_id     IS NOT NULL
 *     AND updated_at   < '2026-03-08 13:32:37'
 *
 *   PostgreSQL делал Parallel Seq Scan по всей таблице (~3.7 млн строк),
 *   Rows Removed by Filter: ~100 — время выполнения 579 мс.
 *
 * Решение:
 *   Составной частичный индекс (partial index) только по строкам,
 *   где match_status = 'M' AND match_id IS NOT NULL — именно эти строки
 *   являются кандидатами на архивирование.
 *
 *   Partial index даёт два преимущества:
 *     1. Меньший размер индекса (только сквитованные записи с match_id)
 *     2. PostgreSQL точно знает, что WHERE match_status='M' AND match_id IS NOT NULL
 *        уже покрыто предикатом индекса — быстрее планирование
 *
 * Паттерн запросов, которые ускорятся:
 *   WHERE company_id=? AND match_status='M' AND match_id IS NOT NULL AND updated_at < ?
 *   — используется в: actionCount, actionStats, actionRunBatch (COUNT + SELECT батчей)
 *
 * @see ArchiveController::actionCount()
 * @see ArchiveController::actionStats()
 * @see ArchiveController::actionRunBatch()
 * @see commands/ArchiveController.php (консольная команда архивирования)
 */
class m260310_140000_add_archive_index_to_nostro_entries extends Migration
{
    // Partial index: только строки с match_status='M' AND match_id IS NOT NULL
    private const IDX_ARCHIVE_CANDIDATES = 'idx_ne_archive_candidates';

    // Дополнительный: для SELECT батча (ORDER BY id ASC) — нужен id в индексе
    private const IDX_ARCHIVE_BATCH = 'idx_ne_archive_batch';

    /**
     * Отключаем транзакцию — CREATE INDEX CONCURRENTLY запрещён внутри
     * транзакционного блока PostgreSQL.
     */
    public function transaction(): ?string
    {
        return null;
    }

    public function up(): bool
    {
        // ── 1. Частичный индекс для COUNT-запросов ──────────────────────────────
        //
        // Покрывает: WHERE company_id=? AND match_status='M'
        //              AND match_id IS NOT NULL AND updated_at < ?
        //
        // Partial predicate (WHERE в индексе) исключает из индекса все строки
        // со статусом U/I и без match_id — это большинство строк таблицы.
        // Результат: компактный индекс только по реальным кандидатам архивирования.
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_ARCHIVE_CANDIDATES . "\"
            ON nostro_entries (company_id, updated_at)
            WHERE match_status = 'M' AND match_id IS NOT NULL
        ");

        // ── 2. Индекс для батчевой выборки (SELECT ... ORDER BY id ASC LIMIT ?) ─
        //
        // В actionRunBatch используется запрос:
        //   SELECT ... FROM nostro_entries
        //   WHERE company_id=? AND match_status='M'
        //     AND match_id IS NOT NULL AND updated_at < ?
        //   ORDER BY id ASC LIMIT 300
        //
        // Добавляем id в индекс, чтобы PostgreSQL мог вернуть результат
        // уже отсортированным без дополнительного filesort.
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_ARCHIVE_BATCH . "\"
            ON nostro_entries (company_id, updated_at, id)
            WHERE match_status = 'M' AND match_id IS NOT NULL
        ");

        // Обновляем статистику — планировщик сразу видит новые индексы
        $this->execute('ANALYZE nostro_entries');

        return true;
    }

    public function down(): bool
    {
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_ARCHIVE_CANDIDATES . '"');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_ARCHIVE_BATCH      . '"');

        return true;
    }
}