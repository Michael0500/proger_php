<?php
namespace app\assets;

use yii\web\AssetBundle;
use yii\web\View;

class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl  = '@web';

    public $css = [
        // Select2 — должны быть файлы в web/css/
        'css/select2.min.css',
        'css/select2-bootstrap-5-theme.min.css',
        'css/sweetalert2.min.css',
        'css/app.css',
    ];

    public $js = [
        // Порядок важен:
        // 1. jQuery (нужен для Select2)
        'js/jquery.min.js',
        // 2. Bootstrap bundle (нужен для модалок)
        'js/bootstrap.bundle.min.js',
        // 3. Select2 (зависит от jQuery)
        'js/select2.min.js',
        // 4. SweetAlert2
        'js/sweetalert2.all.min.js',
        // 5. Vue 2
        'js/vue.min.js',
        // 6. Axios
        'js/axios.min.js',
        // 7. Приложение
        'js/app/api.js',
        'js/app/mixins/modals.js',
        'js/app/mixins/groups.js',
        'js/app/mixins/pools.js',
        'js/app/mixins/entries.js',
        'js/app/mixins/matching.js',
        'js/app/app.js',
    ];

    // POS_END чтобы скрипты грузились после DOM
    public $jsOptions = ['position' => View::POS_END];

    public $depends = [
        'app\assets\FontAwesomeAsset',
    ];
}