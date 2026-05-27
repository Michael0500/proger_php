<?php

use yii\db\Migration;

/**
 * Добавляет идентификатор DWH-проводки в активные записи выверки.
 */
class m260527_120001_add_posting_id_to_nostro_entries extends Migration
{
    /**
     * Применяет миграцию `m260527_120001_add_posting_id_to_nostro_entries`.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('{{%nostro_entries}}', 'posting_id', $this->bigInteger()->null()
            ->comment('Уникальный идентификатор проводки из suspend_posting (DWH)'));

        $this->createIndex(
            'uq_nostro_entries_posting_id',
            '{{%nostro_entries}}',
            'posting_id',
            true
        );
    }

    /**
     * Откатывает миграцию `m260527_120001_add_posting_id_to_nostro_entries`.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropIndex('uq_nostro_entries_posting_id', '{{%nostro_entries}}');
        $this->dropColumn('{{%nostro_entries}}', 'posting_id');
    }
}
