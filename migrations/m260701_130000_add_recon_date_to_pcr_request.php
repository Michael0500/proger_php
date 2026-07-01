<?php

use yii\db\Migration;

/**
 * Добавляет `recon_date` в `pcr_request` — дату выверки, заданную при запросе
 * (`pcr/request --date=YYYY-MM-DD`). Используется callback'ом для проставления
 * `pcr_wallet_info.from_date_time` в эту дату, чтобы `pcr/export --date` попадал
 * в тот же день, что и исходный запрос.
 */
class m260701_130000_add_recon_date_to_pcr_request extends Migration
{
    /**
     * Применяет миграцию.
     *
     * @return void
     */
    public function safeUp()
    {
        $this->addColumn('{{%pcr_request}}', 'recon_date', $this->date()->null()
            ->comment('Дата выверки из --date (UTC+3 календарный день)'));
    }

    /**
     * Откатывает миграцию.
     *
     * @return void
     */
    public function safeDown()
    {
        $this->dropColumn('{{%pcr_request}}', 'recon_date');
    }
}
