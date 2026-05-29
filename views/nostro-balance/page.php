<?php
/** @var yii\web\View $this */
$this->title = 'Баланс по всем ностро-банкам';
?>

<div id="balance-app" v-cloak @submit.prevent.stop @keydown.enter.prevent.stop>
    <?= $this->render('//layouts/_section-balance') ?>
</div>
