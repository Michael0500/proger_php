<?php

use yii\db\Migration;

/**
 * Миграция `m260325_100000_add_other_id_to_nostro_entries`.
 *
 * Фиксирует изменение схемы PostgreSQL для SmartMatch и должна применяться через `php yii migrate`.
 */
class m260325_100000_add_other_id_to_nostro_entries extends Migration
{
    /**
     * Применяет миграцию `m260325_100000_add_other_id_to_nostro_entries`.
     *
     * Создаёт или изменяет структуру БД согласно назначению файла миграции.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('nostro_entries', 'other_id', $this->string()->null()->defaultValue(null)->after('message_id'));
    }

    /**
     * Откатывает миграцию `m260325_100000_add_other_id_to_nostro_entries`.
     *
     * Возвращает структуру БД к состоянию до применения этой миграции, если откат поддерживается.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropColumn('nostro_entries', 'other_id');
    }
}
