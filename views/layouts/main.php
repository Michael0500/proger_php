<?php
/** @var yii\web\View $this */
/** @var string $content */
use app\assets\AppAsset;
use app\widgets\Alert;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;

AppAsset::register($this);
$this->registerCsrfMetaTags();
$this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
$this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, shrink-to-fit=no']);
$this->registerLinkTag(['rel' => 'icon', 'type' => 'image/x-icon', 'href' => Yii::getAlias('@web/favicon.ico')]);
?>
<?php $this->beginPage() ?>
    <!DOCTYPE html>
    <html lang="<?= Yii::$app->language ?>" class="h-100">
    <head>
        <title><?= Html::encode($this->title) ?></title>
        <?php $this->head() ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            /* ═══════════════════════════════════════════════════════
               GLOBAL RESET & BASE
               ═══════════════════════════════════════════════════════ */
            *, *::before, *::after { box-sizing: border-box; }

            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                font-size: 13.5px;
                background: #f4f6f9;
                color: #1a202c;
                overflow-x: hidden;
            }

            /* ── Navbar ──────────────────────────────────────────── */
            #header .navbar {
                background: linear-gradient(135deg, #1a1f36 0%, #2d3561 100%) !important;
                box-shadow: 0 2px 12px rgba(0,0,0,.25);
                padding: 0 20px;
                height: 52px;
            }
            #header .navbar-brand {
                font-weight: 700;
                font-size: 15px;
                letter-spacing: .3px;
                color: #fff !important;
            }
            #header .nav-link { color: rgba(255,255,255,.8) !important; font-size: 13px; }
            #header .nav-link:hover { color: #fff !important; }

            /* ── Layout ──────────────────────────────────────────── */
            #app { min-height: calc(100vh - 52px); margin-top: 52px; }

            #main {
                flex: 1;
                margin-left: 268px;
                padding: 24px 28px;
                transition: margin-left .25s;
                min-width: 0;
            }
            #main.sidebar-collapsed { margin-left: 56px; }

            /* ── Footer ──────────────────────────────────────────── */
            #footer { display: none; } /* убираем, мешает layout */

            /* ═══════════════════════════════════════════════════════
               SIDEBAR
               ═══════════════════════════════════════════════════════ */
            #sidebar {
                width: 268px;
                min-width: 268px;
                height: calc(100vh - 52px);
                position: sticky;
                top: 52px;
                background: #fff;
                border-right: 1px solid #e8eaf0;
                display: flex;
                flex-direction: column;
                transition: width .25s, min-width .25s;
                z-index: 100;
                overflow: hidden;
                flex-shrink: 0;
            }
            #sidebar.collapsed { width: 56px; min-width: 56px; }
            #sidebar.collapsed .sidebar-scroll,
            #sidebar.collapsed .sidebar-footer { opacity: 0; pointer-events: none; }
            #sidebar.collapsed .sidebar-head   { opacity: 0; }

            /* Тоггл */
            #sidebar-toggle {
                position: absolute;
                right: -13px; top: 22px;
                width: 26px; height: 26px;
                background: #fff;
                border: 1px solid #e8eaf0;
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                cursor: pointer;
                z-index: 200;
                box-shadow: 0 2px 8px rgba(0,0,0,.08);
                transition: box-shadow .15s;
            }
            #sidebar-toggle:hover { box-shadow: 0 3px 12px rgba(0,0,0,.15); }
            #sidebar.collapsed #sidebar-toggle { transform: rotate(180deg); }
            #sidebar-toggle i { font-size: 10px; color: #6b7280; }

            /* Заголовок */
            .sidebar-head {
                padding: 16px 16px 10px;
                border-bottom: 1px solid #f1f3f7;
            }
            .sidebar-head h6 {
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .1em;
                color: #9ca3af;
                margin: 0;
            }

            /* Скролл */
            .sidebar-scroll { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 8px 8px 0; }
            .sidebar-scroll::-webkit-scrollbar { width: 3px; }
            .sidebar-scroll::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 2px; }

            /* ── Дерево: Группа ──────────────────────────────────── */
            .tree-group { margin-bottom: 2px; }

            .tree-group-row {
                display: flex;
                align-items: center;
                padding: 6px 8px 6px 6px;
                border-radius: 8px;
                cursor: pointer;
                transition: background .12s;
                position: relative;
                min-height: 36px;
                user-select: none;
            }
            .tree-group-row:hover { background: #f7f8fc; }
            .tree-group-row.active { background: #eff2ff; }

            .tree-chevron {
                width: 18px; height: 18px;
                display: flex; align-items: center; justify-content: center;
                color: #c4c9d6;
                font-size: 9px;
                transition: transform .2s;
                flex-shrink: 0;
                margin-right: 2px;
            }
            .tree-chevron.open { transform: rotate(90deg); color: #6366f1; }

            .tree-group-icon {
                width: 26px; height: 26px;
                background: linear-gradient(135deg, #ede9fe, #dbeafe);
                border-radius: 7px;
                display: flex; align-items: center; justify-content: center;
                flex-shrink: 0;
                margin-right: 8px;
            }
            .tree-group-icon i { font-size: 12px; color: #6366f1; }

            .tree-group-name {
                flex: 1;
                font-size: 12.5px;
                font-weight: 600;
                color: #1e2532;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                min-width: 0;
            }
            .tree-group-count {
                font-size: 10px;
                color: #9ca3af;
                font-weight: 500;
                background: #f3f4f6;
                border-radius: 10px;
                padding: 1px 6px;
                margin-left: 4px;
                flex-shrink: 0;
            }

            /* Кнопки действий (появляются при hover) */
            .tree-actions {
                display: flex;
                gap: 2px;
                opacity: 0;
                transition: opacity .12s;
                flex-shrink: 0;
                margin-left: 4px;
            }
            .tree-group-row:hover .tree-actions,
            .tree-pool-row:hover .tree-actions { opacity: 1; }

            .tree-btn {
                width: 22px; height: 22px;
                border: none;
                border-radius: 5px;
                background: transparent;
                color: #9ca3af;
                display: flex; align-items: center; justify-content: center;
                cursor: pointer;
                font-size: 10px;
                transition: background .12s, color .12s;
                padding: 0;
                flex-shrink: 0;
            }
            .tree-btn:hover          { background: #e5e7eb; color: #374151; }
            .tree-btn.primary:hover  { background: #dbeafe; color: #2563eb; }
            .tree-btn.warning:hover  { background: #fef3c7; color: #d97706; }
            .tree-btn.danger:hover   { background: #fee2e2; color: #dc2626; }

            /* ── Дерево: Пулы ────────────────────────────────────── */
            .tree-pools { padding-left: 34px; }

            .tree-pool-row {
                display: flex;
                align-items: center;
                padding: 5px 8px 5px 6px;
                border-radius: 7px;
                cursor: pointer;
                transition: background .12s;
                position: relative;
                min-height: 30px;
                user-select: none;
                margin-bottom: 1px;
            }
            .tree-pool-row:hover { background: #f7f8fc; }
            .tree-pool-row.active {
                background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
                border: 1px solid #d1fae5;
            }

            .tree-pool-dot {
                width: 6px; height: 6px;
                background: #d1d5db;
                border-radius: 50%;
                flex-shrink: 0;
                margin-right: 8px;
                transition: background .15s, box-shadow .15s;
            }
            .tree-pool-row.active .tree-pool-dot {
                background: #10b981;
                box-shadow: 0 0 0 3px rgba(16,185,129,.15);
            }

            .tree-pool-name {
                flex: 1;
                font-size: 12px;
                color: #4b5563;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                min-width: 0;
            }
            .tree-pool-row.active .tree-pool-name { color: #065f46; font-weight: 600; }

            /* Добавить пул */
            .tree-add-pool {
                display: flex;
                align-items: center;
                gap: 5px;
                width: 100%;
                padding: 4px 8px;
                border-radius: 6px;
                border: 1px dashed #d1d5db;
                background: transparent;
                color: #9ca3af;
                font-size: 11px;
                cursor: pointer;
                transition: all .12s;
                margin: 3px 0 6px;
            }
            .tree-add-pool:hover { border-color: #6366f1; color: #6366f1; background: #f5f3ff; }

            /* Футер сайдбара */
            .sidebar-footer { padding: 8px 8px 12px; border-top: 1px solid #f1f3f7; flex-shrink: 0; }
            .btn-add-group {
                width: 100%;
                display: flex; align-items: center; justify-content: center; gap: 6px;
                padding: 8px;
                background: linear-gradient(135deg, #f5f3ff, #eff6ff);
                color: #6366f1;
                border: 1px solid #e0e7ff;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: all .15s;
            }
            .btn-add-group:hover { background: linear-gradient(135deg, #ede9fe, #dbeafe); border-color: #c7d2fe; }

            /* ═══════════════════════════════════════════════════════
               MAIN CONTENT
               ═══════════════════════════════════════════════════════ */

            /* Заголовок пула */
            .pool-title { font-size: 18px; font-weight: 700; color: #1a202c; }
            .pool-title .pool-tag {
                font-size: 11px;
                font-weight: 600;
                background: #f3f4f6;
                border: 1px solid #e5e7eb;
                color: #6b7280;
                border-radius: 20px;
                padding: 2px 10px;
                vertical-align: middle;
            }

            /* Кнопки в тулбаре */
            .toolbar-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 7px 14px;
                border-radius: 8px;
                font-size: 12.5px;
                font-weight: 600;
                border: 1px solid transparent;
                cursor: pointer;
                transition: all .15s;
                white-space: nowrap;
            }
            .toolbar-btn i { font-size: 12px; }

            .toolbar-btn.outline {
                background: #fff;
                border-color: #e5e7eb;
                color: #4b5563;
            }
            .toolbar-btn.outline:hover { border-color: #6366f1; color: #6366f1; background: #f5f3ff; }

            .toolbar-btn.primary {
                background: linear-gradient(135deg, #6366f1, #4f46e5);
                color: #fff;
                border-color: transparent;
                box-shadow: 0 2px 8px rgba(99,102,241,.3);
            }
            .toolbar-btn.primary:hover { background: linear-gradient(135deg, #4f46e5, #4338ca); }

            .toolbar-btn.success {
                background: linear-gradient(135deg, #10b981, #059669);
                color: #fff;
                border-color: transparent;
                box-shadow: 0 2px 8px rgba(16,185,129,.3);
            }
            .toolbar-btn.success:hover { background: linear-gradient(135deg, #059669, #047857); }
            .toolbar-btn.success:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }

            .toolbar-btn.danger-soft {
                background: #fff;
                border-color: #fecaca;
                color: #ef4444;
            }
            .toolbar-btn.danger-soft:hover { background: #fef2f2; border-color: #ef4444; }

            /* Панель итогов */
            .summary-bar {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 12px;
                padding: 10px 16px;
                border-radius: 10px;
                font-size: 12.5px;
                margin-bottom: 16px;
            }
            .summary-bar.balanced   { background: #f0fdf4; border: 1px solid #bbf7d0; }
            .summary-bar.unbalanced { background: #fffbeb; border: 1px solid #fde68a; }

            /* ── Карточка счёта ──────────────────────────────────── */
            .account-card {
                background: #fff;
                border-radius: 12px;
                border: 1px solid #e8eaf0;
                margin-bottom: 16px;
                overflow: hidden;
                transition: box-shadow .15s;
            }
            .account-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.07); }

            .account-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 16px;
                background: #fafbfd;
                border-bottom: 1px solid #f1f3f7;
            }
            .account-card-header .acc-name {
                font-size: 13.5px;
                font-weight: 700;
                color: #1a202c;
            }
            .account-card-header .acc-meta { display: flex; align-items: center; gap: 6px; }

            .badge-currency {
                background: #f1f5f9;
                color: #475569;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                padding: 2px 7px;
                font-size: 11px;
                font-weight: 700;
            }
            .badge-suspense {
                background: linear-gradient(135deg, #fef3c7, #fde68a);
                color: #92400e;
                border-radius: 6px;
                padding: 2px 7px;
                font-size: 11px;
                font-weight: 700;
                border: 1px solid #fcd34d;
            }
            .acc-count { font-size: 11px; color: #9ca3af; }

            /* Кнопки в заголовке карточки */
            .card-hdr-btn {
                width: 30px; height: 30px;
                display: flex; align-items: center; justify-content: center;
                border-radius: 7px;
                border: 1px solid #e8eaf0;
                background: #fff;
                color: #6b7280;
                cursor: pointer;
                font-size: 12px;
                transition: all .12s;
                flex-shrink: 0;
            }
            .card-hdr-btn:hover        { border-color: #c7d2fe; background: #f5f3ff; color: #6366f1; }
            .card-hdr-btn.green:hover  { border-color: #bbf7d0; background: #f0fdf4; color: #16a34a; }
            .card-hdr-btn.blue:hover   { border-color: #bfdbfe; background: #eff6ff; color: #2563eb; }

            /* ── Таблица записей ─────────────────────────────────── */
            .entries-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12.5px;
            }
            .entries-table thead th {
                padding: 8px 10px;
                font-size: 10.5px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .06em;
                color: #9ca3af;
                background: #f8f9fc;
                border-bottom: 1px solid #eef0f5;
                white-space: nowrap;
            }
            .entries-table tbody td {
                padding: 9px 10px;
                border-bottom: 1px solid #f4f5f8;
                vertical-align: middle;
                color: #374151;
            }
            .entries-table tbody tr:last-child td { border-bottom: none; }
            .entries-table tbody tr:hover td { background: #fafbfe; }

            /* Выделенная строка */
            .entry-selected td { background: #fffbeb !important; }
            .entry-selected td:first-child { border-left: 3px solid #f59e0b; }

            /* Сквитованная строка */
            .entry-matched td { background: #f0fdf4 !important; }
            .entry-matched td:first-child { border-left: 3px solid #10b981; }

            /* Бейджи */
            .badge-ls-l {
                display: inline-flex; align-items: center; justify-content: center;
                width: 20px; height: 20px;
                background: linear-gradient(135deg, #6366f1, #4f46e5);
                color: #fff;
                border-radius: 5px;
                font-size: 10px;
                font-weight: 800;
            }
            .badge-ls-s {
                display: inline-flex; align-items: center; justify-content: center;
                width: 20px; height: 20px;
                background: linear-gradient(135deg, #06b6d4, #0284c7);
                color: #fff;
                border-radius: 5px;
                font-size: 10px;
                font-weight: 800;
            }
            .badge-debit  { color: #ef4444; font-weight: 700; font-size: 12px; }
            .badge-credit { color: #10b981; font-weight: 700; font-size: 12px; }

            .match-id-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                background: linear-gradient(135deg, #d1fae5, #a7f3d0);
                color: #065f46;
                border: 1px solid #6ee7b7;
                border-radius: 6px;
                padding: 2px 7px;
                font-size: 10px;
                font-weight: 700;
                font-family: 'SF Mono', 'Fira Code', monospace;
                cursor: pointer;
                transition: all .12s;
                letter-spacing: .02em;
            }
            .match-id-badge:hover { background: linear-gradient(135deg, #fecaca, #fca5a5); color: #7f1d1d; border-color: #f87171; }

            .status-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                border-radius: 20px;
                padding: 3px 9px;
                font-size: 10.5px;
                font-weight: 600;
            }
            .status-waiting {
                background: #fffbeb;
                color: #92400e;
                border: 1px solid #fde68a;
            }
            .status-waiting::before {
                content: '';
                width: 5px; height: 5px;
                background: #f59e0b;
                border-radius: 50%;
                flex-shrink: 0;
            }
            .status-matched {
                background: #ecfdf5;
                color: #065f46;
                border: 1px solid #a7f3d0;
            }
            .status-matched::before {
                content: '';
                width: 5px; height: 5px;
                background: #10b981;
                border-radius: 50%;
                flex-shrink: 0;
            }
            .status-ignored {
                background: #f9fafb;
                color: #6b7280;
                border: 1px solid #e5e7eb;
            }

            /* Кнопки действий в таблице */
            .row-btn {
                width: 26px; height: 26px;
                display: inline-flex; align-items: center; justify-content: center;
                border-radius: 6px;
                border: 1px solid transparent;
                background: transparent;
                color: #9ca3af;
                cursor: pointer;
                font-size: 11px;
                transition: all .12s;
                padding: 0;
                flex-shrink: 0;
            }
            .row-btn:hover         { background: #f3f4f6; border-color: #e5e7eb; color: #374151; }
            .row-btn.edit:hover    { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
            .row-btn.unlink:hover  { background: #fffbeb; border-color: #fde68a; color: #d97706; }
            .row-btn.delete:hover  { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

            /* inline-edit комментария */
            .comment-inline {
                cursor: pointer;
                border-bottom: 1px dashed #d1d5db;
                color: #6b7280;
                transition: color .12s;
                font-size: 12px;
            }
            .comment-inline:hover { color: #374151; border-bottom-color: #6366f1; }
            .comment-inline.has-value { color: #374151; }

            /* Пустое состояние */
            .empty-row {
                padding: 16px 20px;
                color: #9ca3af;
                font-size: 12.5px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            /* Пустой пул */
            .empty-pool {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 80px 20px;
                color: #9ca3af;
                text-align: center;
            }
            .empty-pool i { font-size: 36px; margin-bottom: 12px; opacity: .3; }
            .empty-pool p { font-size: 14px; margin: 0; }
        </style>
    </head>
    <body class="d-flex flex-column">
    <?php $this->beginBody() ?>

    <header id="header">
        <?php
        NavBar::begin([
                'brandLabel' => '<i class="fas fa-exchange-alt me-2"></i>' . Yii::$app->name,
                'brandUrl'   => Yii::$app->homeUrl,
                'options'    => ['class' => 'navbar-expand-md navbar-dark fixed-top'],
        ]);
        $menuItems = [['label' => 'Главная', 'url' => ['/site/index']]];
        if (Yii::$app->user->isGuest) {
            $menuItems[] = ['label' => 'Вход', 'url' => ['/site/login']];
        } else {
            $menuItems[] = [
                    'label' => '<i class="fas fa-user-circle me-1"></i>' . Yii::$app->user->identity->username,
                    'encode' => false,
                    'items' => [
                            ['label' => 'Профиль', 'url' => ['/user/view', 'id' => Yii::$app->user->id]],
                            '<div class="dropdown-divider"></div>',
                            ['label' => 'Выход', 'url' => ['/site/logout'], 'linkOptions' => ['data-method' => 'post']],
                    ],
            ];
        }
        echo Nav::widget(['options' => ['class' => 'navbar-nav ms-auto'], 'items' => $menuItems]);
        NavBar::end();
        ?>
    </header>

    <?php if (!Yii::$app->user->isGuest && Yii::$app->user->identity->hasCompany()): ?>
        <div id="app" class="d-flex">
            <?= $this->render('_sidebar') ?>
            <main id="main" :class="{ 'sidebar-collapsed': isSidebarCollapsed }" role="main">
                <?= Alert::widget() ?>
                <?= $this->render('_content', ['content' => $content]) ?>
            </main>
            <?= $this->render('_modals') ?>
        </div>
    <?php else: ?>
        <main style="margin-top:52px; padding:24px">
            <div class="container">
                <?= Alert::widget() ?>
                <?= $content ?>
            </div>
        </main>
    <?php endif; ?>

    <?php if (!Yii::$app->user->isGuest && Yii::$app->user->identity->hasCompany()): ?>
        <?= $this->render('_vue-scripts') ?>
    <?php endif; ?>

    <?php $this->endBody() ?>
    </body>
    </html>
<?php $this->endPage() ?>