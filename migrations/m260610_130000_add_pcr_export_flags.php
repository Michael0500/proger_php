<?php

use yii\db\Migration;

/**
 * Добавляет признаки экспорта файлов PCRFIHIST по correlation_id.
 */
class m260610_130000_add_pcr_export_flags extends Migration
{
    /**
     * Применяет миграцию.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('{{%pcr_request}}', 'is_exported', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%pcr_request}}', 'exported_at', $this->timestamp()->null());
        $this->addColumn('{{%pcr_request}}', 'export_file', $this->text());
        $this->createIndex('idx_pcr_request_exported', '{{%pcr_request}}', ['is_exported', 'correlation_id']);
    }

    /**
     * Откатывает миграцию.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropIndex('idx_pcr_request_exported', '{{%pcr_request}}');
        $this->dropColumn('{{%pcr_request}}', 'export_file');
        $this->dropColumn('{{%pcr_request}}', 'exported_at');
        $this->dropColumn('{{%pcr_request}}', 'is_exported');
    }
}
