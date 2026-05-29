<?php
/** @var yii\web\View $this */

use app\widgets\Alert;

$this->title = 'Баланс';
?>

<main role="main">
    <div id="bank-balance-app" class="d-flex" v-cloak @submit.prevent.stop @keydown.enter.prevent.stop>
        <?= $this->render('//layouts/_sidebar') ?>
        <div id="main" :class="{ 'sidebar-collapsed': isSidebarCollapsed }">
            <?= Alert::widget() ?>

            <!-- Пустое состояние: ностро-банк не выбран -->
            <div v-if="!selectedPool" class="empty-pool">
                <i class="fas fa-hand-point-left"></i>
                <p>Выберите ностро-банк в панели слева</p>
            </div>

            <!-- Секция баланса для выбранного ностро-банка -->
            <div v-else>
                <?= $this->render('//layouts/_section-balance', [
                    'showPoolFilter'   => false,
                    'showSidebarTitle' => true,
                ]) ?>
            </div>
        </div>
        <?= $this->render('//layouts/_sidebar-modals') ?>
    </div>
</main>
