<?php
/** @var yii\web\View $this */
use yii\helpers\Url;
?>
<script>
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

        // Записи (NostroEntry) — НОВЫЕ роуты
        entryList:          '<?= Url::to(['/nostro-entry/list']) ?>',
        entrySearchAccounts:'<?= Url::to(['/nostro-entry/search-accounts']) ?>',
        entryCreate:        '<?= Url::to(['/nostro-entry/create']) ?>',
        entryUpdate:        '<?= Url::to(['/nostro-entry/update']) ?>',
        entryDelete:        '<?= Url::to(['/nostro-entry/delete']) ?>',
        entryUpdateComment: '<?= Url::to(['/nostro-entry/update-comment']) ?>',

        // Квитование
        matchManual: '<?= Url::to(['/matching/match-manual']) ?>',
        unmatch:     '<?= Url::to(['/matching/unmatch']) ?>',
        autoMatch:   '<?= Url::to(['/matching/auto-match']) ?>',
        getRules:    '<?= Url::to(['/matching/get-rules']) ?>',
        saveRule:    '<?= Url::to(['/matching/save-rule']) ?>',
        deleteRule:  '<?= Url::to(['/matching/delete-rule']) ?>',


        // ── Баланс Ностро ─────────────────────────────────────────────
        balanceList:      '<?= Url::to(['/nostro-balance/list']) ?>',
        balanceCreate:    '<?= Url::to(['/nostro-balance/create']) ?>',
        balanceUpdate:    '<?= Url::to(['/nostro-balance/update']) ?>',
        balanceDelete:    '<?= Url::to(['/nostro-balance/delete']) ?>',
        balanceConfirm:   '<?= Url::to(['/nostro-balance/confirm']) ?>',
        balanceHistory:   '<?= Url::to(['/nostro-balance/history']) ?>',
        balanceAccounts:  '<?= Url::to(['/nostro-balance/accounts']) ?>',
        balanceImportBnd: '<?= Url::to(['/nostro-balance/import-bnd']) ?>',
        balanceImportAsb: '<?= Url::to(['/nostro-balance/import-asb']) ?>',
    };
</script>