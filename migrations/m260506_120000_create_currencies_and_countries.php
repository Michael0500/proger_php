<?php

use yii\db\Migration;

/**
 * Справочники валют и стран.
 *
 * Создаёт таблицы {{%currencies}} и {{%countries}}, которые хранят список
 * допустимых валют (ISO 4217) и стран (ISO 3166-1 alpha-2/3).
 *
 * Изначально наполняются теми значениями, которые встречаются в системе
 * (захардкожены ранее в формах и SeedController).
 */
class m260506_120000_create_currencies_and_countries extends Migration
{
    public function safeUp()
    {
        // ── Валюты ────────────────────────────────────────────────
        $this->createTable('{{%currencies}}', [
            'id'         => $this->primaryKey(),
            'code'       => $this->string(3)->notNull()->unique(),
            'name'       => $this->string(100)->notNull(),
            'symbol'     => $this->string(8),
            'is_active'  => $this->boolean()->notNull()->defaultValue(true),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->createIndex('idx_currencies_is_active', '{{%currencies}}', 'is_active');

        $currencies = [
            ['RUB', 'Российский рубль',     '₽',  10],
            ['USD', 'Доллар США',           '$',  20],
            ['EUR', 'Евро',                 '€',  30],
            ['GBP', 'Фунт стерлингов',      '£',  40],
            ['CHF', 'Швейцарский франк',    'Fr', 50],
            ['CNY', 'Китайский юань',       '¥',  60],
            ['JPY', 'Японская иена',        '¥',  70],
            ['TRY', 'Турецкая лира',        '₺',  80],
            ['AED', 'Дирхам ОАЭ',           'د.إ', 90],
            ['KZT', 'Казахстанский тенге',  '₸', 100],
            ['BYR', 'Белорусский рубль',    'Br', 110],
            ['RUR', 'Российский рубль (старый код)', '₽', 120],
        ];

        foreach ($currencies as [$code, $name, $symbol, $sort]) {
            $this->insert('{{%currencies}}', [
                'code'       => $code,
                'name'       => $name,
                'symbol'     => $symbol,
                'is_active'  => true,
                'sort_order' => $sort,
            ]);
        }

        // ── Страны ────────────────────────────────────────────────
        $this->createTable('{{%countries}}', [
            'id'         => $this->primaryKey(),
            'code'       => $this->string(2)->notNull()->unique(),  // ISO 3166-1 alpha-2
            'code3'      => $this->string(3),                        // ISO 3166-1 alpha-3
            'name'       => $this->string(150)->notNull(),
            'is_active'  => $this->boolean()->notNull()->defaultValue(true),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->createIndex('idx_countries_is_active', '{{%countries}}', 'is_active');

        $countries = [
            ['RU', 'RUS', 'Россия',             10],
            ['US', 'USA', 'США',                20],
            ['DE', 'DEU', 'Германия',           30],
            ['GB', 'GBR', 'Великобритания',     40],
            ['CH', 'CHE', 'Швейцария',          50],
            ['CN', 'CHN', 'Китай',              60],
            ['JP', 'JPN', 'Япония',             70],
            ['TR', 'TUR', 'Турция',             80],
            ['AE', 'ARE', 'ОАЭ',                90],
            ['KZ', 'KAZ', 'Казахстан',         100],
            ['BY', 'BLR', 'Беларусь',          110],
            ['FR', 'FRA', 'Франция',           120],
            ['IT', 'ITA', 'Италия',            130],
            ['ES', 'ESP', 'Испания',           140],
            ['NL', 'NLD', 'Нидерланды',        150],
            ['AT', 'AUT', 'Австрия',           160],
            ['BE', 'BEL', 'Бельгия',           170],
            ['LU', 'LUX', 'Люксембург',        180],
            ['SE', 'SWE', 'Швеция',            190],
            ['NO', 'NOR', 'Норвегия',          200],
            ['FI', 'FIN', 'Финляндия',         210],
            ['DK', 'DNK', 'Дания',             220],
            ['PL', 'POL', 'Польша',            230],
            ['CZ', 'CZE', 'Чехия',             240],
            ['HU', 'HUN', 'Венгрия',           250],
            ['UA', 'UKR', 'Украина',           260],
            ['AM', 'ARM', 'Армения',           270],
            ['AZ', 'AZE', 'Азербайджан',       280],
            ['GE', 'GEO', 'Грузия',            290],
            ['UZ', 'UZB', 'Узбекистан',        300],
            ['KG', 'KGZ', 'Киргизия',          310],
            ['TJ', 'TJK', 'Таджикистан',       320],
            ['IN', 'IND', 'Индия',             330],
            ['BR', 'BRA', 'Бразилия',          340],
            ['CA', 'CAN', 'Канада',            350],
            ['AU', 'AUS', 'Австралия',         360],
            ['SG', 'SGP', 'Сингапур',          370],
            ['HK', 'HKG', 'Гонконг',           380],
            ['KR', 'KOR', 'Южная Корея',       390],
        ];

        foreach ($countries as [$code, $code3, $name, $sort]) {
            $this->insert('{{%countries}}', [
                'code'       => $code,
                'code3'      => $code3,
                'name'       => $name,
                'is_active'  => true,
                'sort_order' => $sort,
            ]);
        }
    }

    public function safeDown()
    {
        $this->dropTable('{{%countries}}');
        $this->dropTable('{{%currencies}}');
    }
}
