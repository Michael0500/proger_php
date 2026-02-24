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
                'user/get-pools'       => 'user/get-pools',
                'user/get-accounts'    => 'user/get-accounts',
                'user/select-company'  => 'user/select-company',
                'user/reset-company'   => 'user/reset-company',
                'user/create-account'  => 'user/create-account',
                'user/delete-account'  => 'user/delete-account',

                // ── Company (старые роуты оставляем для совместимости) ─
                'company/select' => 'company/select',
                'company/reset'  => 'company/reset',

                // ── Account (JSON API для Vue основного приложения) ───
                'account/create' => 'account/create',
                'account/update' => 'account/update',
                'account/delete' => 'account/delete',
                'account/list'   => 'account/list',

                // ── Account Groups ────────────────────────────────
                'account-group/get-groups' => 'account-group/get-groups',
                'account-group/create'     => 'account-group/create',
                'account-group/update'     => 'account-group/update',
                'account-group/delete'     => 'account-group/delete',

                // ── Account Pools ─────────────────────────────────
                'account-pool/get-accounts' => 'account-pool/get-accounts',
                'account-pool/create'       => 'account-pool/create',
                'account-pool/update'       => 'account-pool/update',
                'account-pool/delete'       => 'account-pool/delete',

                // ── Nostro entries & Matching (универсальные) ─────
                'nostro-entry/<action>' => 'nostro-entry/<action>',
                'matching/<action>'     => 'matching/<action>',

                'nostro-balance'                    => 'nostro-balance/index',
                'nostro-balance/<action>'           => 'nostro-balance/<action>',

                  // ── Отчёты (Раккорд) ─────────────────────────────────────────
                'recon-report'           => 'recon-report/index',
                'recon-report/generate'  => 'recon-report/generate',
                'recon-report/accounts'  => 'recon-report/accounts',

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