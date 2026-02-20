<?php

return [
    'class' => 'yii\db\Connection',
    'dsn' => 'pgsql:host=PostgreSQL-17;port=5432;dbname=smartmatch',
    'username' => 'postgres',
    'password' => '',
    'charset' => 'utf8',
    'schemaMap' => [
        'pgsql' => [
            'class' => 'yii\db\pgsql\Schema',
            'defaultSchema' => 'public'
        ]
    ],
];