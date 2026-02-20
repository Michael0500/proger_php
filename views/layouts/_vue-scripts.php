<?php
/**
 * _vue-scripts.php
 *
 * Единственная задача этого файла — передать PHP/Yii2 маршруты в JS.
 * Вся логика приложения находится в web/js/app/*.js
 *
 * @var yii\web\View $this
 */
use yii\helpers\Url;
?>
<script>
    /**
     * AppRoutes — глобальный объект с URL-адресами для API вызовов.
     * Генерируется Yii2, чтобы JS не знал о структуре маршрутов напрямую.
     */
    window.AppRoutes = {
        // Группы
        groupGetGroups: '<?= Url::to(['/account-group/get-groups']) ?>',
        groupCreate:    '<?= Url::to(['/account-group/create']) ?>',
        groupUpdate:    '<?= Url::to(['/account-group/update']) ?>',
        groupDelete:    '<?= Url::to(['/account-group/delete']) ?>',

        // Пулы
        poolCreate:      '<?= Url::to(['/account-pool/create']) ?>',
        poolUpdate:      '<?= Url::to(['/account-pool/update']) ?>',
        poolDelete:      '<?= Url::to(['/account-pool/delete']) ?>',
        poolGetAccounts: '<?= Url::to(['/account-pool/get-accounts']) ?>',

        // Записи выверки (NostroEntry)
        entryCreate:        '<?= Url::to(['/nostro-entry/create']) ?>',
        entryUpdate:        '<?= Url::to(['/nostro-entry/update']) ?>',
        entryDelete:        '<?= Url::to(['/nostro-entry/delete']) ?>',
        entryUpdateComment: '<?= Url::to(['/nostro-entry/update-comment']) ?>'
    };
</script>