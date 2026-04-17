<?php

use yii\db\Migration;

/**
 * Таблица пользовательских настроек UI.
 *
 * Используется для хранения персональных настроек интерфейса (напр.,
 * видимость и ширина колонок таблицы выверки). Значение — JSONB,
 * чтобы схема была универсальной и расширяемой.
 *
 * Ключи (pref_key):
 *   - entries_table_columns — [{key, visible, width}] для таблицы NostroEntry
 */
class m260415_100000_create_user_preferences_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%user_preferences}}', [
            'id'         => $this->primaryKey(),
            'user_id'    => $this->integer()->notNull(),
            'pref_key'   => $this->string(100)->notNull(),
            'pref_value' => 'JSONB NOT NULL',
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex(
            'ux_user_preferences_user_key',
            '{{%user_preferences}}',
            ['user_id', 'pref_key'],
            true
        );

        $this->addForeignKey(
            'fk_user_preferences_user_id',
            '{{%user_preferences}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_user_preferences_user_id', '{{%user_preferences}}');
        $this->dropIndex('ux_user_preferences_user_key', '{{%user_preferences}}');
        $this->dropTable('{{%user_preferences}}');
    }
}
