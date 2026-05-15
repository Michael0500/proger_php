<?php

use yii\db\Migration;

/**
 * Миграция `m260325_100001_add_other_id_to_nostro_entries_archive`.
 *
 * Фиксирует изменение схемы PostgreSQL для SmartMatch и должна применяться через `php yii migrate`.
 */
class m260325_100001_add_other_id_to_nostro_entries_archive extends Migration
{
    /**
     * Применяет миграцию `m260325_100001_add_other_id_to_nostro_entries_archive`.
     *
     * Создаёт или изменяет структуру БД согласно назначению файла миграции.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('nostro_entries_archive', 'other_id', $this->string()->null()->defaultValue(null)->after('message_id'));
    }

    /**
     * Откатывает миграцию `m260325_100001_add_other_id_to_nostro_entries_archive`.
     *
     * Возвращает структуру БД к состоянию до применения этой миграции, если откат поддерживается.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropColumn('nostro_entries_archive', 'other_id');
    }
}
