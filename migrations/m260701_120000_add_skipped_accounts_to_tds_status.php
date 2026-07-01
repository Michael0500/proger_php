<?php

use yii\db\Migration;

/**
 * Хранение причины частичной загрузки пачки `tds_status`.
 *
 * Если merge (FCC12 / PH_TDS / SUSPENSE_POSTING) пропустил часть строк из-за
 * отсутствия счёта в системе, список ненайденных счетов сохраняется в колонке
 * `skipped_accounts` (JSON-массив строк). Интерфейс `/imports` показывает его,
 * чтобы объяснить, почему пачка загружена частично и каких счетов не хватает.
 * После успешной (полной) загрузки колонка очищается.
 */
class m260701_120000_add_skipped_accounts_to_tds_status extends Migration
{
    /**
     * Применяет миграцию: добавляет колонку `skipped_accounts`.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('{{%tds_status}}', 'skipped_accounts', $this->text());
    }

    /**
     * Откатывает миграцию: удаляет колонку `skipped_accounts`.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropColumn('{{%tds_status}}', 'skipped_accounts');
    }
}
