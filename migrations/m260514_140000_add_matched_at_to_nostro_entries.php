<?php

use yii\db\Migration;

/**
 * Добавляет отдельную дату квитования.
 *
 * Архивация должна считать срок хранения активной записи от момента квитования,
 * а не от updated_at: updated_at меняется при комментариях, правках и восстановлении
 * из архива.
 */
class m260514_140000_add_matched_at_to_nostro_entries extends Migration
{
    private const IDX_ARCHIVE_CANDIDATES = 'idx_ne_archive_candidates_matched_at';
    private const IDX_ARCHIVE_BATCH = 'idx_ne_archive_batch_matched_at';
    private const IDX_ARCHIVE_MATCHED_AT = 'idx_nea_matched_at';

    /**
     * CREATE INDEX CONCURRENTLY нельзя выполнять внутри транзакции PostgreSQL.
     */
    public function transaction(): ?string
    {
        return null;
    }

    public function up(): bool
    {
        $this->addColumn(
            '{{%nostro_entries}}',
            'matched_at',
            $this->timestamp()->null()->comment('Дата квитования')
        );
        $this->addColumn(
            '{{%nostro_entries_archive}}',
            'matched_at',
            $this->timestamp()->null()->comment('Дата квитования исходной записи')
        );

        $this->execute("
            UPDATE {{%nostro_entries}}
               SET matched_at = updated_at
             WHERE match_status = 'M'
               AND match_id IS NOT NULL
               AND matched_at IS NULL
        ");
        $this->execute("
            UPDATE {{%nostro_entries_archive}}
               SET matched_at = original_updated_at
             WHERE matched_at IS NULL
               AND original_updated_at IS NOT NULL
        ");

        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_ARCHIVE_CANDIDATES . "\"
            ON nostro_entries (company_id, matched_at)
            WHERE match_status = 'M' AND match_id IS NOT NULL AND matched_at IS NOT NULL
        ");
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_ARCHIVE_BATCH . "\"
            ON nostro_entries (company_id, matched_at, id)
            WHERE match_status = 'M' AND match_id IS NOT NULL AND matched_at IS NOT NULL
        ");
        $this->execute("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS \"" . self::IDX_ARCHIVE_MATCHED_AT . "\"
            ON nostro_entries_archive (matched_at)
        ");
        $this->execute('ANALYZE nostro_entries');
        $this->execute('ANALYZE nostro_entries_archive');

        return true;
    }

    public function down(): bool
    {
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_ARCHIVE_MATCHED_AT . '"');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_ARCHIVE_BATCH . '"');
        $this->execute('DROP INDEX CONCURRENTLY IF EXISTS "' . self::IDX_ARCHIVE_CANDIDATES . '"');
        $this->dropColumn('{{%nostro_entries_archive}}', 'matched_at');
        $this->dropColumn('{{%nostro_entries}}', 'matched_at');

        return true;
    }
}
