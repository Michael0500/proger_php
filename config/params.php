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
];
