<?php

use yii\db\Migration;

/**
 * Добавляет составные индексы на nostro_entries для ускорения
 * NostroEntryController::actionList() при любой комбинации
 * pool_id + sort + dir.
 *
 * ПРОБЛЕМА (диагностика):
 *   Запрос /nostro-entry/list?pool_id=2&sort=dc&dir=asc выполнялся очень долго.
 *   PostgreSQL EXPLAIN ANALYZE показывал Seq Scan / BitmapHeapScan по всей компании,
 *   после чего Sort по 'dc' на полном наборе строк.
 *
 *   Причина: существующие составные индексы содержат match_status между company_id
 *   и account_id (company_id, match_status, account_id, id DESC). Когда фильтр по
 *   match_status не задан (а пул-запрос его не передаёт), PostgreSQL не может
 *   эффективно пройти к account_id через match_status — индекс используется только
 *   по prefix (company_id), а дальше heap scan + sort.
 *
 * РЕШЕНИЕ — три группы индексов:
 *
 *   1. Пул-запросы (account_id IN list): (company_id, account_id, <sort_col>, id)
 *      PostgreSQL может делать отдельные IndexScan по каждому account_id из списка,
 *      каждый уже упорядочен по sort_col, затем MergeAppend — без filesort.
 *
 *   2. Запросы без пула (только company_id): (company_id, <sort_col>, id)
 *      Allows IndexScan ordered by sort_col without sorting all company rows.
 *
 *   3. Индекс для fast-count: (company_id, account_id)
 *      Позволяет COUNT-запросу делать Index Only Scan без чтения heap.
 *
 * Колонки с id в конце позволяют PostgreSQL двигаться по индексу в обоих
 * направлениях (ASC/DESC) без дополнительной сортировки.
 *
 * @see NostroEntryController::actionList()
 */
class m260310_150000_add_pool_sort_indexes_to_nostro_entries extends Migration
{
    private const TABLE = 'nostro_entries';

    // ── Пул-запросы: company_id + account_id + sort_col + id ─────────────────
    private const IDX_POOL_ID       = 'idx_ne_cid_acc_id';
    private const IDX_POOL_DC       = 'idx_ne_cid_acc_dc';
    private const IDX_POOL_LS       = 'idx_ne_cid_acc_ls';
    private const IDX_POOL_AMOUNT   = 'idx_ne_cid_acc_amount';
    private const IDX_POOL_VDATE    = 'idx_ne_cid_acc_vdate';
    private const IDX_POOL_PDATE    = 'idx_ne_cid_acc_pdate';
    private const IDX_POOL_STATUS   = 'idx_ne_cid_acc_mstatus';
    private const IDX_POOL_CURRENCY = 'idx_ne_cid_acc_currency';

    // ── Без пула: company_id + sort_col + id ─────────────────────────────────
    private const IDX_NPOOL_DC       = 'idx_ne_cid_dc';
    private const IDX_NPOOL_LS       = 'idx_ne_cid_ls';
    private const IDX_NPOOL_AMOUNT   = 'idx_ne_cid_amount';
    private const IDX_NPOOL_VDATE    = 'idx_ne_cid_vdate';
    private const IDX_NPOOL_PDATE    = 'idx_ne_cid_pdate';
    private const IDX_NPOOL_CURRENCY = 'idx_ne_cid_currency';

    /** Отключаем транзакцию: CREATE INDEX CONCURRENTLY запрещён в транзакционном блоке */
    public function transaction(): ?string
    {
        return null;
    }

    public function up(): bool
    {
        $t = $this->db->quoteTableName(self::TABLE);

        // ────────────────────────────────────────────────────────────────────
        // ГРУППА 1: Пул-запросы (WHERE company_id=? AND account_id IN (...))
        // ────────────────────────────────────────────────────────────────────

        // Сортировка по id (дефолтная) + покрывающий индекс для fast COUNT
        // Этот индекс покрывает COUNT-запросы через Index Only Scan (no heap)
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_ID . "\"
            ON $t (company_id, account_id, id DESC)
        ");

        // Сортировка по dc (Debit/Credit) — конкретная причина тикета
        // MergeAppend по каждому account_id из пула → без filesort
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_DC . "\"
            ON $t (company_id, account_id, dc, id)
        ");

        // Сортировка по ls (Ledger/Statement)
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_LS . "\"
            ON $t (company_id, account_id, ls, id)
        ");

        // Сортировка по amount — часто используется в рабочем процессе
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_AMOUNT . "\"
            ON $t (company_id, account_id, amount, id)
        ");

        // Сортировка по value_date — наиболее частая в практике квитования
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_VDATE . "\"
            ON $t (company_id, account_id, value_date, id)
        ");

        // Сортировка по post_date
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_PDATE . "\"
            ON $t (company_id, account_id, post_date, id)
        ");

        // Сортировка по match_status (U/M/I)
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_STATUS . "\"
            ON $t (company_id, account_id, match_status, id)
        ");

        // Сортировка по currency
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_POOL_CURRENCY . "\"
            ON $t (company_id, account_id, currency, id)
        ");

        // ────────────────────────────────────────────────────────────────────
        // ГРУППА 2: Запросы без пула (WHERE company_id=?, сортировка любая)
        // Дефолтная сортировка по id покрыта первичным ключом + idx_ne_cid_status_id.
        // Здесь добавляем только те колонки, которых нет в существующих индексах.
        // ────────────────────────────────────────────────────────────────────

        // Сортировка по dc без пула
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_NPOOL_DC . "\"
            ON $t (company_id, dc, id)
        ");

        // Сортировка по ls без пула
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_NPOOL_LS . "\"
            ON $t (company_id, ls, id)
        ");

        // Сортировка по amount без пула
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_NPOOL_AMOUNT . "\"
            ON $t (company_id, amount, id)
        ");

        // Сортировка по value_date без пула
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_NPOOL_VDATE . "\"
            ON $t (company_id, value_date, id)
        ");

        // Сортировка по post_date без пула
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_NPOOL_PDATE . "\"
            ON $t (company_id, post_date, id)
        ");

        // Сортировка по currency без пула
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_NPOOL_CURRENCY . "\"
            ON $t (company_id, currency, id)
        ");

        // Обновляем статистику — планировщик сразу видит новые индексы
        $this->execute('ANALYZE ' . $t);

        return true;
    }

    public function down(): bool
    {
        foreach ([
            self::IDX_POOL_ID,
            self::IDX_POOL_DC,
            self::IDX_POOL_LS,
            self::IDX_POOL_AMOUNT,
            self::IDX_POOL_VDATE,
            self::IDX_POOL_PDATE,
            self::IDX_POOL_STATUS,
            self::IDX_POOL_CURRENCY,
            self::IDX_NPOOL_DC,
            self::IDX_NPOOL_LS,
            self::IDX_NPOOL_AMOUNT,
            self::IDX_NPOOL_VDATE,
            self::IDX_NPOOL_PDATE,
            self::IDX_NPOOL_CURRENCY,
        ] as $idx) {
            $this->execute("DROP INDEX CONCURRENTLY IF EXISTS \"$idx\"");
        }

        return true;
    }
}
