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
        'css/app.css?v7',
        'fontawesome/css/all.min.css',
    ];

    public $js = [
        // Порядок важен:
        // 1. jQuery (нужен для Select2)
        'js/jquery.min.js',
        // 2. Bootstrap bundle (нужен для модалок)
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
        'js/app/state-storage.js',
        'js/app/mixins/modals.js?v2',
        'js/app/mixins/categories.js',
        'js/app/mixins/groups.js?v2',
        'js/app/mixins/entries.js?v6',
        'js/app/mixins/matching.js?v2',
        'js/app/mixins/balance.js?v1',
        'js/app/mixins/archive.js?v1',
        'js/app/mixins/state-persistence.js?v1',
        'js/app/app.js?v2',
    ];

    // POS_END чтобы скрипты грузились после DOM
    public $jsOptions = ['position' => View::POS_END];

    public $depends = [
        //
    ];
}