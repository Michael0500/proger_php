<?php
/** @var yii\web\View $this */
/** @var string $initialSection */
use yii\helpers\Url;

$currentUser = Yii::$app->user->identity;
$currentComp = ($currentUser && $currentUser->company_id) ? $currentUser->company : null;
$companySection = $currentComp ? strtoupper($currentComp->code) : '';
$initialSection = $initialSection ?? 'entries';
?>
<script>
    window.AppRoutes = {
        // Категории
        categoryGetCategories: '<?= Url::to(['/category/get-categories']) ?>',
        categoryCreate:        '<?= Url::to(['/category/create']) ?>',
        categoryUpdate:        '<?= Url::to(['/category/update']) ?>',
        categoryDelete:        '<?= Url::to(['/category/delete']) ?>',

        // Группы
        groupCreate:      '<?= Url::to(['/group/create']) ?>',
        groupUpdate:      '<?= Url::to(['/group/update']) ?>',
        groupDelete:      '<?= Url::to(['/group/delete']) ?>',
        groupGetAccounts: '<?= Url::to(['/group/get-accounts']) ?>',
        groupGetFilters:  '<?= Url::to(['/group/get-filters']) ?>',
        groupSaveFilters: '<?= Url::to(['/group/save-filters']) ?>',

        // Ностро банки
        accountPoolList: '<?= Url::to(['/account-pool/list']) ?>',

        // Записи (NostroEntry)
        entryList:           '<?= Url::to(['/nostro-entry/list']) ?>',
        entrySearchAccounts: '<?= Url::to(['/nostro-entry/search-accounts']) ?>',
        entryCreate:         '<?= Url::to(['/nostro-entry/create']) ?>',
        entryUpdate:         '<?= Url::to(['/nostro-entry/update']) ?>',
        entryDelete:         '<?= Url::to(['/nostro-entry/delete']) ?>',
        entryUpdateComment:  '<?= Url::to(['/nostro-entry/update-comment']) ?>',
        entryHistory:        '<?= Url::to(['/nostro-entry/history']) ?>',

        // Квитование
        matchManual:     '<?= Url::to(['/matching/match-manual']) ?>',
        unmatch:         '<?= Url::to(['/matching/unmatch']) ?>',
        autoMatch:       '<?= Url::to(['/matching/auto-match']) ?>',
        autoMatchStart:  '<?= Url::to(['/matching/auto-match-start']) ?>',
        autoMatchStep:   '<?= Url::to(['/matching/auto-match-step']) ?>',
        getRules:        '<?= Url::to(['/matching/get-rules']) ?>',
        saveRule:        '<?= Url::to(['/matching/save-rule']) ?>',
        deleteRule:      '<?= Url::to(['/matching/delete-rule']) ?>',

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
        companySection:  '<?= addslashes($companySection) ?>',
        userId:          <?= Yii::$app->user->isGuest ? 'null' : (int)Yii::$app->user->id ?>,
        initialSection:  '<?= addslashes($initialSection) ?>',
    };
</script>