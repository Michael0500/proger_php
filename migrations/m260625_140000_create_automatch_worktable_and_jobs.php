<?php

use yii\db\Migration;

/**
 * Инфраструктура пошагового автоквитования с живым прогрессом (вариант B).
 *
 * `autoMatchStep` теперь делает не «целое правило за вызов», а ограниченный
 * кусок работы, чтобы UI каждые ~1–3 с показывал сколько сквитовано / осталось
 * и оценку времени, и не выглядел зависшим.
 *
 * Две таблицы:
 *   - `automatch_pairs` — рабочая (UNLOGGED) таблица найденных пар текущего пула.
 *     Temp-таблицу здесь использовать нельзя: каждый HTTP-шаг — отдельное
 *     соединение, temp не переживёт между запросами. UNLOGGED = без WAL,
 *     быстрая запись; данные транзиентные, переживать краш не требуется.
 *   - `automatch_jobs` — состояние задания (машина состояний в `state` jsonb) +
 *     счётчики прогресса + блокировка от двойного запуска. Частичный уникальный
 *     индекс `(company_id) WHERE status='running'` гарантирует не более одного
 *     активного задания на компанию.
 */
class m260625_140000_create_automatch_worktable_and_jobs extends Migration
{
    public function safeUp(): void
    {
        // ── Рабочая таблица найденных пар (UNLOGGED) ─────────────────────────
        $this->execute("
            CREATE UNLOGGED TABLE {{%automatch_pairs}} (
                job_id varchar(40) NOT NULL,
                rn     bigint       NOT NULL,
                id_a   integer      NOT NULL,
                id_b   integer      NOT NULL
            )
        ");
        $this->createIndex('idx_automatch_pairs_job_rn', '{{%automatch_pairs}}', ['job_id', 'rn']);

        // ── Задания автоквитования (состояние + прогресс + замок) ─────────────
        $this->createTable('{{%automatch_jobs}}', [
            'job_id'            => $this->string(40)->notNull(),
            'company_id'        => $this->integer()->notNull(),
            'status'            => $this->string(16)->notNull()->defaultValue('running')
                ->comment('running | finished | error | canceled'),
            'phase'             => $this->string(16)->null()->comment('searching | matching'),
            'current_rule_name' => $this->string(255)->null(),
            'matched_pairs'     => $this->integer()->notNull()->defaultValue(0),
            'total_unmatched'   => $this->integer()->notNull()->defaultValue(0)
                ->comment('Незаквитованных в области на момент старта — знаменатель прогресса'),
            'state'             => $this->json()->null()->comment('Машина состояний пошагового квитования'),
            'error_message'     => $this->text()->null(),
            'started_at'        => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at'        => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'finished_at'       => $this->timestamp()->null(),
        ]);
        $this->addPrimaryKey('pk_automatch_jobs', '{{%automatch_jobs}}', 'job_id');

        // Замок от двойного запуска: не более одного running-задания на компанию.
        $this->execute("
            CREATE UNIQUE INDEX uq_automatch_running_company
            ON {{%automatch_jobs}} (company_id)
            WHERE status = 'running'
        ");
        // Для уборки протухших заданий и опроса.
        $this->createIndex('idx_automatch_jobs_status_updated', '{{%automatch_jobs}}', ['status', 'updated_at']);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%automatch_jobs}}');
        $this->execute('DROP TABLE IF EXISTS {{%automatch_pairs}}');
    }
}
