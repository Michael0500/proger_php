<?php
/** @var yii\web\View $this */

use app\widgets\Alert;

$this->title = 'Выверка';
?>

<main role="main">
    <div id="entries-app" class="d-flex" v-cloak>
        <?= $this->render('//layouts/_sidebar') ?>
        <div id="main" :class="{ 'sidebar-collapsed': isSidebarCollapsed }">
            <?= Alert::widget() ?>
            <?= $this->render('//layouts/_section-entries') ?>
        </div>
        <?= $this->render('//layouts/_modals') ?>
    </div>
</main>
