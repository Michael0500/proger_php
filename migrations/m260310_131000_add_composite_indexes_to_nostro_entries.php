<?php

use yii\db\Migration;

/**
 * Добавляет составные индексы на таблицу nostro_entries для ускорения
 * основного запроса выборки в NostroEntryController::actionList().
 *
 * Проблема (зафиксирована в EXPLAIN ANALYZE):
 *   SELECT ... FROM nostro_entries ne
 *   LEFT JOIN account a ON a.id = ne.account_id
 *   WHERE ne.company_id = :cid
 *     AND ne.account_id IN (...)   -- список из пула (может быть 50-200 значений)
 *     AND ne.currency = 'RUB'
 *     AND ne.match_status = 'M'
 *   ORDER BY ne.id DESC
 *   LIMIT 50
 *
 * PostgreSQL выбирал Index Scan по PRIMARY KEY (id), читал ~271 000 строк,
 * фильтровал 771 000 строк — время выполнения ~13 000 мс.
 *
 * Решение:
 *   1. Составной индекс (company_id, match_status, currency, account_id, id DESC)
 *      — для запросов с фильтром по currency.
 *   2. Составной индекс (company_id, match_status, account_id, id DESC)
 *      — для запросов без фильтра по currency.
 *   3. Составной индекс (company_id, match_status, id DESC)
 *      — для запросов только по company_id + match_status (без пула/валюты).
 *
 * Порядок колонок:
 *   - Сначала equality-условия (company_id, match_status, currency)
 *   - Затем IN-список (account_id)
 *   - В конце id DESC для покрытия ORDER BY без дополнительной сортировки
 *
 * @see NostroEntryController::actionList()
 */
class m260310_131000_add_composite_indexes_to_nostro_entries extends Migration
{
    /**
     * Имена индексов — чтобы не дублировать при повторных запусках и для down().
     */
    private const IDX_FULL    = 'idx_ne_cid_status_currency_account_id';
    private const IDX_NO_CUR  = 'idx_ne_cid_status_account_id';
    private const IDX_MINIMAL = 'idx_ne_cid_status_id';

    private const TABLE = '{{%nostro_entries}}';

    /** Отключаем транзакцию: CREATE INDEX CONCURRENTLY запрещён в транзакционном блоке */
    public function transaction(): ?string
    {
        return null;
    }

    public function up(): bool
    {
        // ── 1. Основной индекс: company_id + match_status + currency + account_id ──
        // Используется когда в запросе есть все три equality-фильтра + IN по account_id.
        // Включает id DESC, чтобы PostgreSQL мог взять результат без filesort.
        //
        // Паттерн: WHERE company_id=? AND match_status=? AND currency=?
        //            AND account_id IN (...)
        //          ORDER BY id DESC
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_FULL . "\"
            ON " . $this->db->quoteTableName('nostro_entries') . "
            (company_id, match_status, currency, account_id, id DESC)
        ");

        // ── 2. Индекс без currency ──────────────────────────────────────────────
        // Используется при запросах без фильтра по currency (или когда currency
        // не указана в WHERE). Покрывает account_id IN (...).
        //
        // Паттерн: WHERE company_id=? AND match_status=?
        //            AND account_id IN (...)
        //          ORDER BY id DESC
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_NO_CUR . "\"
            ON " . $this->db->quoteTableName('nostro_entries') . "
            (company_id, match_status, account_id, id DESC)
        ");

        // ── 3. Минимальный индекс: только company_id + match_status ────────────
        // Используется при запросах без пула (account_id не фильтруется)
        // и без currency. Типичный сценарий: вкладка "Выверка" без выбранного пула.
        //
        // Паттерн: WHERE company_id=?
        //          ORDER BY id DESC
        // или:     WHERE company_id=? AND match_status=?
        //          ORDER BY id DESC
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_MINIMAL . "\"
            ON " . $this->db->quoteTableName('nostro_entries') . "
            (company_id, match_status, id DESC)
        ");

        // ── Подсказка для планировщика (необязательно, но помогает) ────────────
        // Обновляем статистику таблицы, чтобы планировщик сразу «увидел» индексы.
        $this->execute('ANALYZE ' . $this->db->quoteTableName('nostro_entries'));

        return true;
    }

    public function down(): bool
    {
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_FULL    . '"');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_NO_CUR  . '"');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_MINIMAL . '"');

        return true;
    }
}