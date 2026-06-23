<?php
/** @var yii\web\View $this */
/** @var int|null $batchId Предустановленный фильтр по номеру пакета (переход со страницы отката) */
$this->title = 'Баланс по всем ностро-банкам';
$batchId = $batchId ?? null;
?>

<div id="balance-app" v-cloak @submit.prevent.stop @keydown.enter.prevent.stop>
    <?= $this->render('//layouts/_section-balance', ['showBatchFilter' => true]) ?>
</div>

<script>
    window.BalancePageInit = { batchId: <?= $batchId !== null ? (int)$batchId : 'null' ?> };
</script>
