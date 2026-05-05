<?php
/** @var yii\web\View $this */
$this->title = 'Архив';
?>

<div id="archive-app" v-cloak @submit.prevent.stop @keydown.enter.prevent.stop>
    <?= $this->render('//layouts/_section-archive') ?>
</div>
