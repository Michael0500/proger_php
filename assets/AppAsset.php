<?php

namespace app\assets;

use yii\web\AssetBundle;

class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl  = '@web';

    public $jsOptions = [
        'position' => \yii\web\View::POS_HEAD,
    ];

    public $css = [
        'css/site.css',
        'css/sweetalert2.min.css',
    ];

    public $js = [
        'js/vue.min.js',
        'js/axios.min.js',
        'js/sweetalert2.all.min.js',
    ];

    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
    ];
}