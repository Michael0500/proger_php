<?php
/** @var yii\web\View $this */
use yii\helpers\Url;

$currentUser = Yii::$app->user->identity;
$currentComp = ($currentUser && $currentUser->company_id) ? $currentUser->company : null;
$companySection = $currentComp ? strtoupper($currentComp->code) : '';
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
        poolGetFilters: '<?= Url::to(['/account-pool/get-filters']) ?>',
        poolSaveFilters: '<?= Url::to(['/account-pool/save-filters']) ?>',

        // Записи (NostroEntry)
        entryList:           '<?= Url::to(['/nostro-entry/list']) ?>',
        entrySearchAccounts: '<?= Url::to(['/nostro-entry/search-accounts']) ?>',
        entryCreate:         '<?= Url::to(['/nostro-entry/create']) ?>',
        entryUpdate:         '<?= Url::to(['/nostro-entry/update']) ?>',
        entryDelete:         '<?= Url::to(['/nostro-entry/delete']) ?>',
        entryUpdateComment:  '<?= Url::to(['/nostro-entry/update-comment']) ?>',
        entryHistory:        '<?= Url::to(['/nostro-entry/history']) ?>',

        // Квитование
        matchManual: '<?= Url::to(['/matching/match-manual']) ?>',
        unmatch:     '<?= Url::to(['/matching/unmatch']) ?>',
        autoMatch:   '<?= Url::to(['/matching/auto-match']) ?>',
        getRules:    '<?= Url::to(['/matching/get-rules']) ?>',
        saveRule:    '<?= Url::to(['/matching/save-rule']) ?>',
        deleteRule:  '<?= Url::to(['/matching/delete-rule']) ?>',

        // Баланс Ностро
        balanceList:      '<?= Url::to(['/nostro-balance/list']) ?>',
        balanceCreate:    '<?= Url::to(['/nostro-balance/create']) ?>',
        balanceUpdate:    '<?= Url::to(['/nostro-balance/update']) ?>',
        balanceDelete:    '<?= Url::to(['/nostro-balance/delete']) ?>',
        balanceConfirm:   '<?= Url::to(['/nostro-balance/confirm']) ?>',
        balanceHistory:   '<?= Url::to(['/nostro-balance/history']) ?>',
        balanceAccounts:  '<?= Url::to(['/nostro-balance/accounts']) ?>',
        balanceImportBnd: '<?= Url::to(['/nostro-balance/import-bnd']) ?>',
        balanceImportAsb: '<?= Url::to(['/nostro-balance/import-asb']) ?>',

        // ── Архив ────────────────────────────────────────────────
        archiveList:         '<?= Url::to(['/archive/list']) ?>',
        archiveCount:        '<?= Url::to(['/archive/count']) ?>',
        archiveRunBatch:     '<?= Url::to(['/archive/run-batch']) ?>',
        archiveRestore:      '<?= Url::to(['/archive/restore']) ?>',
        archivePurgeExpired: '<?= Url::to(['/archive/purge-expired']) ?>',
        archiveSettings:     '<?= Url::to(['/archive/settings']) ?>',
        archiveSaveSettings: '<?= Url::to(['/archive/save-settings']) ?>',
        archiveStats:        '<?= Url::to(['/archive/stats']) ?>',
        archiveAccounts:     '<?= Url::to(['/archive/accounts']) ?>',
        archiveHistory:      '<?= Url::to(['/archive/history']) ?>',
    };

    window.AppConfig = {
        companySection: '<?= addslashes($companySection) ?>',
    };
</script>