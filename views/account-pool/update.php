<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\AccountPool $model */

$this->title = 'Update Account Pool: ' . $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Account Pools', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->name, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Update';
?>
<div class="account-pool-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
