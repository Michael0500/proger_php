<?php

namespace app\assets;

use yii\web\AssetBundle;
use yii\web\View;

class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl  = '@web';

    public $css = [
        'css/site.css',
        'css/sweetalert2.min.css',
    ];

    public $js = [
        // ── Внешние библиотеки ───────────────────────────────────────
        'js/vue.min.js',
        'js/axios.min.js',
        'js/sweetalert2.all.min.js',

        // ── Приложение: порядок важен! ───────────────────────────────
        'js/app/api.js',              // API-слой (использует AppRoutes)
        'js/app/mixins/modals.js',    // утилиты Bootstrap-модалок
        'js/app/mixins/groups.js',    // методы групп
        'js/app/mixins/pools.js',     // методы пулов
        'js/app/mixins/entries.js',   // методы записей NostroEntry
        'js/app/app.js',              // Vue-инициализация
    ];

    public $jsOptions = [
        'position' => View::POS_HEAD,
    ];

    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
    ];
}