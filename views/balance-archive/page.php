<?php
/** @var yii\web\View $this */
$this->title = 'Архив балансов';
?>

<div id="balance-archive-app" v-cloak @submit.prevent.stop @keydown.enter.prevent.stop>
    <?= $this->render('//layouts/_section-balance-archive') ?>
</div>
