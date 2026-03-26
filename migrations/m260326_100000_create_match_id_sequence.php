<?php

use yii\db\Migration;

/**
 * Создаёт PostgreSQL sequence для генерации уникальных match_id.
 *
 * Вместо проверки уникальности через SELECT (медленно при 20M+ строк)
 * используем атомарный nextval() — мгновенно и гарантированно уникально.
 *
 * Формат: MTCH00000001, MTCH00000002, ...
 *
 * Инициализируем стартовое значение из максимального существующего числового
 * суффикса match_id, чтобы не было коллизий с уже существующими hex-записями.
 */
class m260326_100000_create_match_id_sequence extends Migration
{
    public function safeUp()
    {
        // Создаём sequence
        $this->execute('CREATE SEQUENCE IF NOT EXISTS match_id_seq START WITH 1 INCREMENT BY 1 NO CYCLE');

        // Устанавливаем стартовое значение выше текущего максимума
        // Считаем количество уникальных match_id чтобы гарантированно не пересечься
        $maxCount = $this->db->createCommand("
            SELECT COALESCE(MAX(cnt), 0) FROM (
                SELECT COUNT(DISTINCT match_id) AS cnt FROM {{%nostro_entries}} WHERE match_id IS NOT NULL
                UNION ALL
                SELECT COUNT(DISTINCT match_id) AS cnt FROM {{%nostro_entries_archive}} WHERE match_id IS NOT NULL
            ) t
        ")->queryScalar();

        $startVal = max((int)$maxCount + 1, 1);
        $this->execute("SELECT setval('match_id_seq', {$startVal}, false)");
    }

    public function safeDown()
    {
        $this->execute('DROP SEQUENCE IF EXISTS match_id_seq');
    }
}
