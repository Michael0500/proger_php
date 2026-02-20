<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\AccountPool $model */

$this->title = 'Create Account Pool';
$this->params['breadcrumbs'][] = ['label' => 'Account Pools', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="account-pool-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
