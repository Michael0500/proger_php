<?php

use yii\db\Migration;

/**
 * Удаляет избыточные индексы `nostro_entries` для ускорения записи.
 *
 * Проблема:
 *   На `nostro_entries` накопилось 37 индексов. Автоквитование и архивирование
 *   массово меняют `match_status` (U→M). Так как `match_status` индексируется,
 *   PostgreSQL не может сделать HOT-update и создаёт новую версию строки —
 *   запись добавляется во ВСЕ индексы таблицы. Чем больше индексов, тем дольше
 *   массовый UPDATE: на 1 млн строк это были минуты, почти полностью на
 *   обслуживание индексов.
 *
 * Решение — убрать индексы, которые либо дублируются префиксом составных,
 * либо обслуживают только сортировку по редко используемым колонкам:
 *
 *   1. Одноколоночные дубли (покрыты составными, ведущими с company_id):
 *        idx_nostro_entries_company_id, idx_nostro_entries_match_status,
 *        idx_nostro_entries_account_id.
 *   2. Устаревшие архивные индексы по updated_at (архив перешёл на matched_at)
 *      и дубль candidates_matched_at (покрыт префиксом batch_matched_at):
 *        idx_ne_archive_batch, idx_ne_archive_candidates,
 *        idx_ne_archive_candidates_matched_at.
 *   3. Индексы сортировки по колонкам, кроме самых востребованных в выверке
 *      (value_date и amount оставляем). Сортировка по dc/ls/post_date/currency/
 *      match_status — редкое действие пользователя; без индекса PostgreSQL
 *      отсортирует ограниченный набор пула приемлемо.
 *   4. Узкие status-составные, перекрытые idx_ne_cid_acc_id + фильтром:
 *        idx_ne_cid_status_account_id, idx_ne_cid_status_currency_account_id.
 *
 * Остаются все индексы, критичные для чтения (открытие пула, пагинация, поиск)
 * и записи (автоквитование, архивирование): pkey, idx_ne_cid_acc_id,
 * idx_ne_cid_acc_amount, idx_ne_cid_acc_vdate, idx_ne_cid_amount,
 * idx_ne_cid_vdate, idx_ne_cid_status_id, idx_ne_automatch_v2, idx_ne_uid_*,
 * idx_ne_archive_batch_matched_at, idx_nostro_entries_match_id и функциональные
 * (batch_id, posting_id, stmt_id, extract_no, statement_number, branch_code).
 */
class m260625_130000_prune_redundant_nostro_entries_indexes extends Migration
{
    /** CREATE/DROP INDEX CONCURRENTLY запрещены внутри транзакции. */
    public function transaction(): ?string
    {
        return null;
    }

    /** Индексы под удаление: имя => определение (для отката в down()). */
    private function targets(): array
    {
        return [
            // 1. Одноколоночные дубли
            'idx_nostro_entries_company_id'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_nostro_entries_company_id ON nostro_entries (company_id)',
            'idx_nostro_entries_match_status'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_nostro_entries_match_status ON nostro_entries (match_status)',
            'idx_nostro_entries_account_id'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_nostro_entries_account_id ON nostro_entries (account_id)',

            // 2. Устаревшие архивные индексы по updated_at + дубль
            'idx_ne_archive_batch'
                => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_archive_batch ON nostro_entries (company_id, updated_at, id) WHERE match_status = 'M' AND match_id IS NOT NULL",
            'idx_ne_archive_candidates'
                => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_archive_candidates ON nostro_entries (company_id, updated_at) WHERE match_status = 'M' AND match_id IS NOT NULL",
            'idx_ne_archive_candidates_matched_at'
                => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_archive_candidates_matched_at ON nostro_entries (company_id, matched_at) WHERE match_status = 'M' AND match_id IS NOT NULL AND matched_at IS NOT NULL",

            // 3. Индексы сортировки по редким колонкам (pool-вариант)
            'idx_ne_cid_acc_dc'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_acc_dc ON nostro_entries (company_id, account_id, dc, id)',
            'idx_ne_cid_acc_ls'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_acc_ls ON nostro_entries (company_id, account_id, ls, id)',
            'idx_ne_cid_acc_pdate'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_acc_pdate ON nostro_entries (company_id, account_id, post_date, id)',
            'idx_ne_cid_acc_mstatus'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_acc_mstatus ON nostro_entries (company_id, account_id, match_status, id)',
            'idx_ne_cid_acc_currency'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_acc_currency ON nostro_entries (company_id, account_id, currency, id)',

            // 3. Индексы сортировки по редким колонкам (no-pool-вариант)
            'idx_ne_cid_dc'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_dc ON nostro_entries (company_id, dc, id)',
            'idx_ne_cid_ls'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_ls ON nostro_entries (company_id, ls, id)',
            'idx_ne_cid_pdate'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_pdate ON nostro_entries (company_id, post_date, id)',
            'idx_ne_cid_currency'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_currency ON nostro_entries (company_id, currency, id)',

            // 4. Узкие status-составные
            'idx_ne_cid_status_account_id'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_status_account_id ON nostro_entries (company_id, match_status, account_id, id DESC)',
            'idx_ne_cid_status_currency_account_id'
                => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ne_cid_status_currency_account_id ON nostro_entries (company_id, match_status, currency, account_id, id DESC)',
        ];
    }

    public function up(): void
    {
        foreach (array_keys($this->targets()) as $name) {
            $this->execute("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");
        }
        $this->execute('ANALYZE nostro_entries');
    }

    public function down(): void
    {
        foreach ($this->targets() as $createSql) {
            $this->execute($createSql);
        }
        $this->execute('ANALYZE nostro_entries');
    }
}
