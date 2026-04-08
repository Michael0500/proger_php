<?php
/** @var yii\web\View $this */
/** @var string $content */
use app\assets\AppAsset;
use app\widgets\Alert;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;
use yii\helpers\Url;

AppAsset::register($this);
$this->registerCsrfMetaTags();
$this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
$this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, shrink-to-fit=no']);
$this->registerLinkTag(['rel' => 'icon', 'type' => 'image/x-icon', 'href' => Yii::getAlias('@web/favicon.ico')]);
$this->registerLinkTag(['rel' => 'preconnect', 'href' => 'https://fonts.googleapis.com']);
$this->registerLinkTag(['rel' => 'stylesheet', 'href' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap']);

$isGuest     = Yii::$app->user->isGuest;
$hasCompany  = !$isGuest && Yii::$app->user->identity->hasCompany();
$showApp     = !$isGuest && $hasCompany;
$showNoComp  = !$isGuest && !$hasCompany;

$currentRoute = Yii::$app->controller->route;

// Страницы, которые рендерятся без sidebar/Vue-app (напрямую в $content)
$isProfilePage    = ($currentRoute === 'user/view');
$isReconPage      = (Yii::$app->controller->id === 'recon-report');
$isNostroBankPage = ($currentRoute === 'account-pool/index');
$isAccountsPage   = ($currentRoute === 'account/index');

// Объединяем: страницы без основного Vue-приложения
$isStandalonePage = $isProfilePage || $isReconPage || $isNostroBankPage || $isAccountsPage;

// Отдельные страницы секций (Vue без sidebar)
$isArchivePage  = ($currentRoute === 'archive/page');
$isBalancePage  = ($currentRoute === 'nostro-balance/page');
$isSectionPage  = $isArchivePage || $isBalancePage;
$initialSection = $isArchivePage ? 'archive' : ($isBalancePage ? 'balance' : 'entries');

$currentUser = $isGuest ? null : Yii::$app->user->identity;
$currentComp = ($currentUser && $currentUser->company_id) ? $currentUser->company : null;
?>
<?php $this->beginPage() ?>
    <!DOCTYPE html>
    <html lang="<?= Yii::$app->language ?>">
    <head>
        <title><?= Html::encode($this->title) ?></title>
        <?php $this->head() ?>
    </head>
    <body>
    <?php $this->beginBody() ?>

    <!-- ── Navbar ──────────────────────────────────────────────── -->
    <header id="header">
        <?php
        NavBar::begin([
                'brandLabel' => '<i class="fas fa-exchange-alt me-2"></i>' . Html::encode(Yii::$app->name),
                'brandUrl'   => Yii::$app->homeUrl,
                'options'    => ['class' => 'navbar-expand-md navbar-dark fixed-top'],
        ]);

        $menuItems = [['label' => 'Главная', 'url' => ['/site/index']]];

        if ($isGuest) {
            $menuItems[] = ['label' => 'Вход', 'url' => ['/site/login']];
        } else {
            if ($hasCompany) {
                $menuItems[] = [
                        'label'  => '<i class="fas fa-university me-1"></i>Счета',
                        'encode' => false,
                        'url'    => ['/accounts'],
                        'active' => $isAccountsPage,
                ];
                $menuItems[] = [
                        'label'  => '<i class="fas fa-landmark me-1"></i>Ностро-банки',
                        'encode' => false,
                        'url'    => ['/nostro-banks'],
                        'active' => $isNostroBankPage,
                ];
                $menuItems[] = [
                        'label'  => '<i class="fas fa-balance-scale me-1"></i>Баланс',
                        'encode' => false,
                        'url'    => ['/balance'],
                        'active' => $isBalancePage,
                ];
                $menuItems[] = [
                        'label'  => '<i class="fas fa-archive me-1"></i>Архив',
                        'encode' => false,
                        'url'    => ['/archive'],
                        'active' => $isArchivePage,
                ];
                $menuItems[] = [
                        'label'   => '<i class="fas fa-file-alt me-1"></i>Раккорд',
                        'encode'  => false,
                        'url'     => ['/recon-report/index'],
                        'active'  => Yii::$app->controller->id === 'recon-report',
                        'options' => [
                            'id'    => 'navbar-recon-item',
                            'style' => ($currentComp && $currentComp->isInv()) ? 'display:none' : '',
                        ],
                ];
            }

            $userMenuItems = [
                    [
                            'label'  => '<i class="fas fa-user me-1"></i>Профиль',
                            'encode' => false,
                            'url'    => ['/user/view', 'id' => $currentUser->id],
                    ],
            ];

            // Оба блока всегда в DOM — JS переключает display при смене компании
            $userMenuItems[] = '<div class="dropdown-divider"></div>';
            $userMenuItems[] = '<div id="navbar-with-company" style="display:' . ($currentComp ? 'block' : 'none') . '">'
                . '<div class="dropdown-header" style="font-size:11px;color:#6b7a99;padding:6px 16px 2px">'
                . '<i class="fas fa-building me-1"></i>Компания: <strong id="navbar-company-name">' . ($currentComp ? Html::encode($currentComp->name) : '') . '</strong>'
                . '</div>'
                . '<a class="dropdown-item" href="' . Url::to(['/user/view', 'id' => $currentUser->id]) . '">'
                . '<i class="fas fa-exchange-alt me-1"></i>Сменить компанию'
                . '</a>'
                . '<a class="dropdown-item" href="' . Url::to(['/company/reset']) . '" data-method="post">'
                . '<i class="fas fa-times me-1" style="color:#ef4444"></i><span style="color:#ef4444">Сбросить компанию</span>'
                . '</a>'
                . '</div>';
            $userMenuItems[] = '<div id="navbar-no-company" style="display:' . ($currentComp ? 'none' : 'block') . '">'
                . '<a class="dropdown-item" href="' . Url::to(['/user/view', 'id' => $currentUser->id]) . '">'
                . '<i class="fas fa-building me-1" style="color:#f59e0b"></i><span style="color:#f59e0b">Выбрать компанию</span>'
                . '</a>'
                . '</div>';

            $userMenuItems[] = '<div class="dropdown-divider"></div>';
            $userMenuItems[] = [
                    'label'       => 'Выход',
                    'url'         => ['/site/logout'],
                    'linkOptions' => ['data-method' => 'post'],
            ];

            $companyBadge = $currentComp
                    ? '<span id="navbar-company-badge" style="font-size:10px;background:#4f46e5;color:#fff;border-radius:10px;padding:1px 7px;margin-left:6px;font-weight:600">'
                    . Html::encode($currentComp->code) . '</span>'
                    : '<span id="navbar-company-badge" style="font-size:10px;background:#f59e0b;color:#fff;border-radius:10px;padding:1px 7px;margin-left:6px">!</span>';

            $menuItems[] = [
                    'label'  => '<i class="fas fa-user-circle me-1"></i>' . Html::encode($currentUser->username) . $companyBadge,
                    'encode' => false,
                    'items'  => $userMenuItems,
            ];
        }

        echo Nav::widget(['options' => ['class' => 'navbar-nav ms-auto'], 'items' => $menuItems]);
        NavBar::end();
        ?>
    </header>

    <?php if ($showApp && $isSectionPage): ?>
        <!-- ── Отдельная страница секции (архив / баланс) без sidebar ── -->
        <div id="app">
            <main id="main" class="section-page-main" role="main">
                <?= Alert::widget() ?>
                <?= $this->render('_content') ?>
            </main>
            <?= $this->render('_modals') ?>
        </div>
        <?= $this->render('_vue-scripts', ['initialSection' => $initialSection]) ?>

    <?php elseif ($showApp && !$isStandalonePage): ?>
        <!-- ── Основное приложение с sidebar (NRE/INV) ──── -->
        <div id="app" class="d-flex">
            <?= $this->render('_sidebar') ?>
            <main id="main" :class="{ 'sidebar-collapsed': isSidebarCollapsed }" role="main">
                <?= Alert::widget() ?>
                <?= $this->render('_content', ['content' => $content]) ?>
            </main>
            <?= $this->render('_modals') ?>
        </div>
        <?= $this->render('_vue-scripts') ?>

    <?php elseif ($showNoComp && !$isStandalonePage): ?>
        <!-- ── Нет компании ─────────────────────────── -->
        <main style="margin-top:52px">
            <div class="company-required">
                <div class="company-required-card">
                    <div class="company-required-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <h3>Компания не выбрана</h3>
                    <p>Для работы с системой необходимо выбрать компанию.</p>
                    <?= Html::a(
                            '<i class="fas fa-user-cog me-2"></i>Перейти в профиль',
                            ['/user/view', 'id' => Yii::$app->user->id],
                            ['class' => 'btn-go-profile', 'data-turbolinks' => 'false']
                    ) ?>
                </div>
            </div>
        </main>

    <?php else: ?>
        <!-- ── Профиль / Раккорд / Гость / прочие страницы ── -->
        <main style="margin-top:52px; padding:24px 28px">
            <div class="container-fluid" style="max-width:1400px">
                <?= Alert::widget() ?>
                <?= $content ?>
            </div>
        </main>
    <?php endif; ?>

    <?php $this->endBody() ?>
    </body>
    </html>
<?php $this->endPage() ?>