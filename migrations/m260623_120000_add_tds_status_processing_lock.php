<?php

use yii\db\Migration;

/**
 * Блокировка обработки пачки `tds_status` для взаимоисключения ручного и
 * фонового процессов загрузки.
 *
 * Ручной запуск (из интерфейса) и фоновые merge-команды атомарно захватывают
 * строку: `UPDATE ... SET is_processing=TRUE WHERE id=:id AND is_processing=FALSE`.
 * Кто захватил — тот и обрабатывает; занятые строки пропускаются. Таймаута
 * «протухания» нет: зависшую блокировку снимают вручную.
 */
class m260623_120000_add_tds_status_processing_lock extends Migration
{
    /**
     * Применяет миграцию: добавляет колонки блокировки обработки.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('{{%tds_status}}', 'is_processing', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%tds_status}}', 'processing_started_at', $this->timestamp()->null());
        $this->addColumn('{{%tds_status}}', 'processing_owner', $this->string(20));

        $this->createIndex('idx_tds_status_is_processing', '{{%tds_status}}', 'is_processing');
    }

    /**
     * Откатывает миграцию: удаляет колонки блокировки обработки.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropIndex('idx_tds_status_is_processing', '{{%tds_status}}');
        $this->dropColumn('{{%tds_status}}', 'processing_owner');
        $this->dropColumn('{{%tds_status}}', 'processing_started_at');
        $this->dropColumn('{{%tds_status}}', 'is_processing');
    }
}
