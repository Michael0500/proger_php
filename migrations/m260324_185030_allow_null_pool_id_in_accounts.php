<?php

use yii\db\Migration;

/**
 * Разрешаем NULL в accounts.pool_id — счёт может быть не привязан к ностро-банку.
 */
class m260324_185030_allow_null_pool_id_in_accounts extends Migration
{
    public function safeUp()
    {
        // Убираем NOT NULL
        $this->alterColumn('accounts', 'pool_id', $this->integer()->null()->defaultValue(null));

        // Удаляем существующие FK на pool_id (имя может быть разным)
        $this->execute("
            DO $$
            DECLARE r RECORD;
            BEGIN
                FOR r IN (
                    SELECT tc.constraint_name
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                    WHERE tc.table_name = 'accounts'
                      AND kcu.column_name = 'pool_id'
                      AND tc.constraint_type = 'FOREIGN KEY'
                ) LOOP
                    EXECUTE 'ALTER TABLE accounts DROP CONSTRAINT ' || r.constraint_name;
                END LOOP;
            END $$;
        ");

        // Создаём FK заново с ON DELETE SET NULL
        $this->addForeignKey(
            'fk_accounts_pool_id',
            'accounts',
            'pool_id',
            'account_pools',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_accounts_pool_id', 'accounts');

        // Заполняем NULL значения дефолтным пулом
        $this->execute("UPDATE accounts SET pool_id = (SELECT id FROM account_pools LIMIT 1) WHERE pool_id IS NULL");
        $this->alterColumn('accounts', 'pool_id', $this->integer()->notNull());

        $this->addForeignKey(
            'fk_accounts_pool_id',
            'accounts',
            'pool_id',
            'account_pools',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }
}
