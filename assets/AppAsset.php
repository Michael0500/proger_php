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
        'css/app.css',
        'css/sweetalert2.min.css',
    ];

    public $js = [
        // Внешние библиотеки
        'js/vue.min.js',
        'js/axios.min.js',
        'js/sweetalert2.all.min.js',

        // API-слой
        'js/app/api.js',

        // Миксины (порядок важен!)
        'js/app/mixins/modals.js',
        'js/app/mixins/groups.js',
        'js/app/mixins/pools.js',
        'js/app/mixins/entries.js',
        'js/app/mixins/matching.js',

        // Главный файл
        'js/app/app.js',
    ];

    public $jsOptions = [
        'position' => View::POS_HEAD,
    ];

    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
        'app\assets\FontAwesomeAsset',
    ];
}