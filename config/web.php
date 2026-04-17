<?php

$params = require __DIR__ . '/params.php';
$db     = require __DIR__ . '/db.php';

$config = [
    'id'        => 'basic',
    'basePath'  => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases'   => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'parsers'            => ['application/json' => 'yii\web\JsonParser'],
            'cookieValidationKey'=> '29MGFNfyaA1ztLlumStjOTubdE6AT8mp',
        ],
        'cache'        => ['class' => 'yii\caching\FileCache'],
        'user'         => ['identityClass' => 'app\models\User', 'enableAutoLogin' => true],
        'errorHandler' => ['errorAction' => 'site/error'],
        'mailer'       => [
            'class'            => \yii\symfonymailer\Mailer::class,
            'viewPath'         => '@app/mail',
            'useFileTransport' => true,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets'    => [['class' => 'yii\log\FileTarget', 'levels' => ['error', 'warning']]],
        ],
        'db'         => $db,
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName'  => false,
            'rules'           => [

                // ── User / Profile ────────────────────────────────
                'user/view'            => 'user/view',
                'user/update'          => 'user/update',
                'user/select-company'  => 'user/select-company',
                'user/reset-company'   => 'user/reset-company',

                // ── Пользовательские настройки UI ─────────────────
                'user-preference/<action>' => 'user-preference/<action>',

                // ── Company (старые роуты оставляем для совместимости) ─
                'company/select' => 'company/select',
                'company/reset'  => 'company/reset',

                // ── Счета ────────────────────────────────────────
                'accounts'         => 'account/index',
                'account/create'   => 'account/create',
                'account/update'   => 'account/update',
                'account/delete'   => 'account/delete',
                'account/list'     => 'account/list',

                // ── Категории ────────────────────────────────────
                'category/get-categories' => 'category/get-categories',
                'category/create'         => 'category/create',
                'category/update'         => 'category/update',
                'category/delete'         => 'category/delete',

                // ── Группы ───────────────────────────────────────
                'group/<action>' => 'group/<action>',

                // ── Ностро-банки ─────────────────────────────────
                'nostro-banks'                     => 'account-pool/index',
                'account-pool/<action>'            => 'account-pool/<action>',

                // ── Nostro entries & Matching (универсальные) ─────
                'nostro-entry/<action>' => 'nostro-entry/<action>',
                'matching/<action>'     => 'matching/<action>',

                'balance'                           => 'nostro-balance/page',
                'nostro-balance'                    => 'nostro-balance/index',
                'nostro-balance/<action>'           => 'nostro-balance/<action>',

                  // ── Отчёты (Раккорд) ─────────────────────────────────────────
                'recon-report'           => 'recon-report/index',
                'recon-report/generate'  => 'recon-report/generate',
                'recon-report/accounts'  => 'recon-report/accounts',

                'archive'          => 'archive/page',
                'archive/<action>' => 'archive/<action>',
            ],
        ],
        'authManager' => [
            'class'           => 'yii\rbac\DbManager',
            'itemTable'       => '{{%auth_item}}',
            'itemChildTable'  => '{{%auth_item_child}}',
            'assignmentTable' => '{{%auth_assignment}}',
            'ruleTable'       => '{{%auth_rule}}',
            'cache'           => 'cache',
        ],
    ],
    'params'    => $params,
    'as access' => ['class' => 'app\components\AccessControl'],
];

if (YII_ENV_DEV) {
    $config['bootstrap'][]      = 'debug';
    $config['modules']['debug'] = ['class' => 'yii\debug\Module'];
    $config['bootstrap'][]      = 'gii';
    $config['modules']['gii']   = ['class' => 'yii\gii\Module'];
}

return $config;