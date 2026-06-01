<?php

use yii\db\Migration;

/**
 * Создаёт таблицы сервиса СЦР (Цифровой рубль) → файл IntelliMatch PCRFIHIST.
 *
 *  - pcr_request      — трекинг исходящих запросов к API СЦР
 *  - pcr_callback     — сырой лог входящих callback FIWalletInfo (идемпотентность)
 *  - pcr_wallet_info  — нормализованный report (баланс кошелька ФП)
 *  - pcr_operation    — операции из operationsInformation
 */
class m260601_120000_create_pcr_tables extends Migration
{
    /**
     * Применяет миграцию `m260601_120000_create_pcr_tables`.
     *
     * @return void
     */
    public function safeUp()
    {
        // ── Исходящие запросы к СЦР ─────────────────────────────────
        $this->createTable('{{%pcr_request}}', [
            'id'             => $this->primaryKey(),
            'request_type'   => $this->string(20)->notNull()->comment('balance | operationHistory'),
            'wallet_id_list' => $this->json()->comment('dcWalletIdList'),
            'date_from'      => $this->timestamp()->null(),
            'date_to'        => $this->timestamp()->null(),
            'node_id'        => $this->string(64),
            'correlation_id' => $this->string(64)->comment('из 200-ответа'),
            'operation_id'   => $this->string(64)->comment('из 200-ответа'),
            'http_status'    => $this->integer(),
            'response_raw'   => $this->json(),
            'status'         => $this->string(20)->notNull()->defaultValue('sent')->comment('sent | accepted | failed'),
            'error'          => $this->text(),
            'created_at'     => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('idx_pcr_request_correlation', '{{%pcr_request}}', 'correlation_id');

        // ── Сырой лог входящих callback ─────────────────────────────
        $this->createTable('{{%pcr_callback}}', [
            'id'                         => $this->primaryKey(),
            'correlation_id'             => $this->string(64),
            'operation_id'               => $this->string(64),
            'part_no'                    => $this->integer(),
            'part_quantity'              => $this->integer(),
            'part_id'                    => $this->string(64),
            'message_creation_date_time' => $this->timestamp()->null(),
            'payload'                    => $this->json()->notNull(),
            'received_at'                => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        // Идемпотентность: один и тот же part одного operation_id не обрабатываем дважды.
        $this->createIndex('uq_pcr_callback_op_part', '{{%pcr_callback}}', ['operation_id', 'part_id'], true);

        // ── Нормализованный report (баланс кошелька ФП) ─────────────
        $this->createTable('{{%pcr_wallet_info}}', [
            'id'                      => $this->primaryKey(),
            'callback_id'             => $this->integer()->notNull(),
            'correlation_id'          => $this->string(64),
            'operation_id'            => $this->string(64),
            'fi_wallet_id'            => $this->string(80),
            'dc_account_number'       => $this->string(40),
            // balanceInfo
            'total_amount'            => $this->decimal(20, 2),
            'total_amount_ccy'        => $this->char(3),
            'total_blocked_amount'    => $this->decimal(20, 2),
            'total_blocked_amount_ccy'=> $this->char(3),
            'current_balance'         => $this->decimal(20, 2),
            'current_balance_ccy'     => $this->char(3),
            // остатки и обороты
            'opening_balance'         => $this->decimal(20, 2),
            'opening_balance_ccy'     => $this->char(3),
            'outgoing_balance'        => $this->decimal(20, 2),
            'outgoing_balance_ccy'    => $this->char(3),
            'total_amount_debit'      => $this->decimal(20, 2),
            'total_amount_debit_ccy'  => $this->char(3),
            'total_amount_credit'     => $this->decimal(20, 2),
            'total_amount_credit_ccy' => $this->char(3),
            'wallet_status'           => $this->string(20),
            'from_date_time'          => $this->timestamp()->null(),
            'to_date_time'            => $this->timestamp()->null(),
            'created_at'              => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('idx_pcr_wallet_info_callback', '{{%pcr_wallet_info}}', 'callback_id');
        $this->createIndex('idx_pcr_wallet_info_correlation', '{{%pcr_wallet_info}}', 'correlation_id');
        $this->createIndex('idx_pcr_wallet_info_from', '{{%pcr_wallet_info}}', 'from_date_time');
        $this->addForeignKey(
            'fk_pcr_wallet_info_callback',
            '{{%pcr_wallet_info}}', 'callback_id',
            '{{%pcr_callback}}', 'id',
            'CASCADE', 'CASCADE'
        );

        // ── Операции ────────────────────────────────────────────────
        $this->createTable('{{%pcr_operation}}', [
            'id'                     => $this->primaryKey(),
            'wallet_info_id'         => $this->integer()->notNull(),
            'operation_id'           => $this->string(64),
            'type'                   => $this->string(40),
            'amount'                 => $this->decimal(20, 2),
            'amount_ccy'             => $this->char(3),
            'credit_debit_indicator' => $this->string(10)->comment('Debit | Credit'),
            'settlement_date_time'   => $this->timestamp()->null(),
            'other_details'          => $this->json(),
            'created_at'             => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('idx_pcr_operation_wallet_info', '{{%pcr_operation}}', 'wallet_info_id');
        $this->addForeignKey(
            'fk_pcr_operation_wallet_info',
            '{{%pcr_operation}}', 'wallet_info_id',
            '{{%pcr_wallet_info}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    /**
     * Откатывает миграцию `m260601_120000_create_pcr_tables`.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropTable('{{%pcr_operation}}');
        $this->dropTable('{{%pcr_wallet_info}}');
        $this->dropTable('{{%pcr_callback}}');
        $this->dropTable('{{%pcr_request}}');
    }
}
