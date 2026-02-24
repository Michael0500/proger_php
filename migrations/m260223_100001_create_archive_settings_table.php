<?php

use yii\db\Migration;

/**
 * Таблица настроек архивирования по компании.
 * Позволяет задать настраиваемый период перед архивированием.
 */
class m260223_100001_create_archive_settings_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%archive_settings}}', [
            'id'                  => $this->primaryKey(),
            'company_id'          => $this->integer()->notNull()->unique(),

            // Через сколько ДНЕЙ после квитования запись уходит в архив
            'archive_after_days'  => $this->integer()->notNull()->defaultValue(90)
                ->comment('Дней с момента квитования до архивирования'),

            // Срок хранения в архиве (лет, по умолчанию 5)
            'retention_years'     => $this->integer()->notNull()->defaultValue(5)
                ->comment('Лет хранения в архиве'),

            // Включить/выключить автоархивирование
            'auto_archive_enabled' => $this->boolean()->notNull()->defaultValue(true),

            'updated_by'          => $this->integer()->null(),
            'created_at'          => $this->timestamp()->notNull()->defaultExpression('NOW()'),
            'updated_at'          => $this->timestamp()->notNull()->defaultExpression('NOW()'),
        ]);

        $this->createIndex('idx_archive_settings_company', '{{%archive_settings}}', 'company_id');

        $this->addForeignKey(
            'fk_archive_settings_company',
            '{{%archive_settings}}', 'company_id',
            '{{%company}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_archive_settings_company', '{{%archive_settings}}');
        $this->dropTable('{{%archive_settings}}');
    }
}