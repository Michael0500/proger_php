<?php

$db = require __DIR__ . '/db.php';

// Тесты должны работать в отдельной БД, не в рабочей smartmatch.
$db['dsn'] = getenv('SMARTMATCH_TEST_DSN') ?: 'pgsql:host=PostgreSQL-17;port=5432;dbname=smartmatch_test';
$db['username'] = getenv('SMARTMATCH_TEST_DB_USERNAME') ?: $db['username'];
$password = getenv('SMARTMATCH_TEST_DB_PASSWORD');
if ($password !== false) {
    $db['password'] = $password;
}

return $db;
