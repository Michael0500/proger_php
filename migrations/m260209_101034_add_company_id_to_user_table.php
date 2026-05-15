<?php

use yii\db\Migration;

/**
 * Миграция `m260209_101034_add_company_id_to_user_table`.
 *
 * Фиксирует изменение схемы PostgreSQL для SmartMatch и должна применяться через `php yii migrate`.
 */
class m260209_101034_add_company_id_to_user_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'company_id', $this->integer()->null());

        // Создание внешнего ключа
        $this->addForeignKey(
            'fk-user-company_id',
            '{{%user}}',
            'company_id',
            '{{%company}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        // Индекс для ускорения поиска
        $this->createIndex('idx-user-company_id', '{{%user}}', 'company_id');
    }

    /**
     * Откатывает миграцию `m260209_101034_add_company_id_to_user_table`.
     *
     * Возвращает структуру БД к состоянию до применения этой миграции, если откат поддерживается.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropForeignKey('fk-user-company_id', '{{%user}}');
        $this->dropIndex('idx-user-company_id', '{{%user}}');
        $this->dropColumn('{{%user}}', 'company_id');
    }

}
