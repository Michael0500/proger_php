<?php

return [
    'validation' => [
        'enable_sequence_check' => true,  // проверка номеров выписок
        'enable_balance_check'  => true,  // проверка Opening=prev.Closing
        'balance_tolerance'     => 0.01,  // допуск для decimal сравнения
    ],
    'adminEmail' => 'admin@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',

    // ── Интеграция с СЦР (Цифровой рубль) → файл IntelliMatch PCRFIHIST ──
    // Сервис выгрузки истории операций и балансов кошельков ФП из ПлЦР.
    // См. commands/PcrController, controllers/PcrCallbackController.
    'pcr' => [
        // Исходящий запрос к API СЦР (PCRConnect).
        'baseUrl'     => 'https://vs4285.imb.ru:44443',
        'balancePath' => '/api/v4/fi/wallet/balance',
        'auth'        => ['username' => 'bank-app', 'password' => 'password'], // Basic Auth к СЦР
        'verifySsl'   => false,   // самоподписанный сертификат на :44443
        'timeout'     => 30,      // таймаут HTTP, сек

        // Параметры формируемого запроса.
        'walletIdList' => [],            // ID кошельков ФП (g.ru.cbrdc.wlt.fi.*); пусто → запрос по всем
        'nodeId'       => 'a59f4e4',     // additionalParameters.nodeId

        // Константы для строки баланса (тег 60) в файле IntelliMatch.
        'nostroAccount' => '',           // «Счёт ностро» — константа счёта ФП (25 символов)
        'dcIn'          => 'D',          // Дт/Кт входящего остатка
        'dcOut'         => 'D',          // Дт/Кт исходящего остатка

        // Входящий callback от СЦР (/api/v4/fi/callback/wallet/FIWalletInfo).
        'callbackAuth' => ['username' => '', 'password' => ''], // Basic Auth, который шлёт нам СЦР

        // Формирование текстового файла.
        'exportDir'    => '@runtime/pcr',
        'filePrefix'   => 'PCRFIHIST',   // PCRFIHIST_YYYYMMDD_His.txt
        'fileEncoding' => 'UTF-8',

        // Перекладка файла по FTP.
        'ftp' => [
            'host'      => '',
            'port'      => 21,
            'username'  => '',
            'password'  => '',
            'remoteDir' => '/',
            'passive'   => true,
            'ssl'       => false,
        ],
    ],
];
