<?php
namespace app\assets;

use yii\web\AssetBundle;

/**
 * Asset bundle локальной сборки Font Awesome.
 */
class FontAwesomeAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';

    public $css = [
        'fontawesome/css/all.min.css',
    ];

}
