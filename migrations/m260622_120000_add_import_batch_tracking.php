<?php

use yii\db\Migration;

/**
 * Поддержка отката пачек импорта из интерфейса.
 *
 * 1. В `tds_status` добавляются поля трекинга пачки и отката:
 *    - company_id / account_id — для скоупа по компании и привязки ручных загрузок;
 *    - source_label — человекочитаемая метка (имя файла для ASB/БНД);
 *    - entries_count / balances_count — сколько строк было вставлено пачкой
 *      (сохраняются на момент импорта, чтобы остаться видимыми и после отката);
 *    - is_rolled_back / rolled_back_at / rolled_back_by — флаг и метаданные отката.
 *      `is_merged` при откате НЕ снимается — выставляется отдельный флаг.
 *
 * 2. В `nostro_entries`, `nostro_balance` и `nostro_entries_archive` добавляется
 *    `batch_id` — ссылка на `tds_status.id`, по которой выполняется откат.
 *    FK намеренно не ставим (как и для аудита), чтобы пачку можно было удалить
 *    из tds_status, не затрагивая исторические данные.
 */
class m260622_120000_add_import_batch_tracking extends Migration
{
    /**
     * Применяет миграцию: добавляет поля отката и batch_id.
     *
     * @return void
     */
    public function safeUp()
    {
        // ── tds_status: трекинг пачки и отката ───────────────────────
        $this->addColumn('{{%tds_status}}', 'company_id',     $this->integer());
        $this->addColumn('{{%tds_status}}', 'account_id',     $this->integer());
        $this->addColumn('{{%tds_status}}', 'source_label',   $this->string(255));
        $this->addColumn('{{%tds_status}}', 'entries_count',  $this->integer());
        $this->addColumn('{{%tds_status}}', 'balances_count', $this->integer());
        $this->addColumn('{{%tds_status}}', 'is_rolled_back', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%tds_status}}', 'rolled_back_at', $this->timestamp()->null());
        $this->addColumn('{{%tds_status}}', 'rolled_back_by', $this->integer());

        $this->createIndex('idx_tds_status_company_id',     '{{%tds_status}}', 'company_id');
        $this->createIndex('idx_tds_status_is_rolled_back', '{{%tds_status}}', 'is_rolled_back');

        // ── batch_id в целевых таблицах ──────────────────────────────
        $this->addColumn('{{%nostro_entries}}',         'batch_id', $this->integer());
        $this->addColumn('{{%nostro_balance}}',         'batch_id', $this->integer());
        $this->addColumn('{{%nostro_entries_archive}}', 'batch_id', $this->integer());

        $this->createIndex('idx_nostro_entries_batch_id',         '{{%nostro_entries}}',         'batch_id');
        $this->createIndex('idx_nostro_balance_batch_id',         '{{%nostro_balance}}',         'batch_id');
        $this->createIndex('idx_nostro_entries_archive_batch_id', '{{%nostro_entries_archive}}', 'batch_id');
    }

    /**
     * Откатывает миграцию: удаляет добавленные поля и индексы.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropIndex('idx_nostro_entries_archive_batch_id', '{{%nostro_entries_archive}}');
        $this->dropIndex('idx_nostro_balance_batch_id',         '{{%nostro_balance}}');
        $this->dropIndex('idx_nostro_entries_batch_id',         '{{%nostro_entries}}');
        $this->dropColumn('{{%nostro_entries_archive}}', 'batch_id');
        $this->dropColumn('{{%nostro_balance}}',         'batch_id');
        $this->dropColumn('{{%nostro_entries}}',         'batch_id');

        $this->dropIndex('idx_tds_status_is_rolled_back', '{{%tds_status}}');
        $this->dropIndex('idx_tds_status_company_id',     '{{%tds_status}}');
        $this->dropColumn('{{%tds_status}}', 'rolled_back_by');
        $this->dropColumn('{{%tds_status}}', 'rolled_back_at');
        $this->dropColumn('{{%tds_status}}', 'is_rolled_back');
        $this->dropColumn('{{%tds_status}}', 'balances_count');
        $this->dropColumn('{{%tds_status}}', 'entries_count');
        $this->dropColumn('{{%tds_status}}', 'source_label');
        $this->dropColumn('{{%tds_status}}', 'account_id');
        $this->dropColumn('{{%tds_status}}', 'company_id');
    }
}
