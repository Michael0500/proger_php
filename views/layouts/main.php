<?php
/** @var yii\web\View $this */
/** @var string $content */
use app\assets\AppAsset;
use app\widgets\Alert;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;

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

// ДОБАВИТЬ: Проверяем, не находимся ли мы на странице профиля
$currentRoute = Yii::$app->controller->route;
$isProfilePage = ($currentRoute === 'user/view');

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
            // Пункт "Счета" — только если есть компания
            if ($hasCompany) {
                $menuItems[] = [
                        'label'  => '<i class="fas fa-university me-1"></i>Счета',
                        'encode' => false,
                        'url'    => ['/user/view', 'id' => $currentUser->id, '#' => 'accounts'],
                ];
                $menuItems[] = ['label' => 'Баланс', 'url' => ['/nostro-balance']];
            }

            // Меню пользователя
            $userMenuItems = [
                    [
                            'label'  => '<i class="fas fa-user me-1"></i>Профиль',
                            'encode' => false,
                            'url'    => ['/user/view', 'id' => $currentUser->id],
                    ],
            ];

            // Блок смены компании
            if ($currentComp) {
                $userMenuItems[] = '<div class="dropdown-divider"></div>';
                $userMenuItems[] = '<div class="dropdown-header" style="font-size:11px;color:#6b7a99;padding:6px 16px 2px">
                <i class="fas fa-building me-1"></i>Компания: <strong>' . Html::encode($currentComp->name) . '</strong>
            </div>';
                $userMenuItems[] = [
                        'label'  => '<i class="fas fa-exchange-alt me-1"></i>Сменить компанию',
                        'encode' => false,
                        'url'    => ['/user/view', 'id' => $currentUser->id],
                ];
                $userMenuItems[] = [
                        'label'       => '<i class="fas fa-times me-1" style="color:#ef4444"></i><span style="color:#ef4444">Сбросить компанию</span>',
                        'encode'      => false,
                        'url'         => ['/company/reset'],
                ];
            } else {
                $userMenuItems[] = '<div class="dropdown-divider"></div>';
                $userMenuItems[] = [
                        'label'  => '<i class="fas fa-building me-1" style="color:#f59e0b"></i><span style="color:#f59e0b">Выбрать компанию</span>',
                        'encode' => false,
                        'url'    => ['/user/view', 'id' => $currentUser->id],
                ];
            }

            $userMenuItems[] = '<div class="dropdown-divider"></div>';
            $userMenuItems[] = [
                    'label'       => 'Выход',
                    'url'         => ['/site/logout'],
                    'linkOptions' => ['data-method' => 'post'],
            ];

            // Лейбл с именем + значок компании
            $companyBadge = $currentComp
                    ? '<span style="font-size:10px;background:#4f46e5;color:#fff;border-radius:10px;padding:1px 7px;margin-left:6px;font-weight:600">'
                    . Html::encode($currentComp->code) . '</span>'
                    : '<span style="font-size:10px;background:#f59e0b;color:#fff;border-radius:10px;padding:1px 7px;margin-left:6px">!</span>';

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

    <?php if ($showApp && !$isProfilePage): ?>
        <!-- ── Основное приложение (есть компания) ──── -->
        <div id="app" class="d-flex">
            <?= $this->render('_sidebar') ?>
            <main id="main" :class="{ 'sidebar-collapsed': isSidebarCollapsed }" role="main">
                <?= Alert::widget() ?>
                <?= $this->render('_content', ['content' => $content]) ?>
            </main>
            <?= $this->render('_modals') ?>
        </div>
        <?= $this->render('_vue-scripts') ?>

    <?php elseif ($showNoComp && !$isProfilePage): ?>
        <!-- ── Нет компании ─────────────────────────── -->
        <main style="margin-top:52px">
            <div class="company-required">
                <div class="company-required-card">
                    <div class="company-required-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <h3>Компания не выбрана</h3>
                    <p>
                        Для работы с системой необходимо выбрать компанию.
                    </p>
                    <?= Html::a(
                            '<i class="fas fa-user-cog me-2"></i>Перейти в профиль',
                            ['/user/view', 'id' => Yii::$app->user->id],
                            ['class' => 'btn-go-profile', 'data-turbolinks' => 'false']
                    ) ?>
                </div>
            </div>
        </main>

    <?php else: ?>
        <!-- ── Гость / другие страницы ──────────────── -->
        <main style="margin-top:52px; padding:32px 24px">
            <div class="container">
                <?= Alert::widget() ?>
                <?= $content ?>
            </div>
        </main>
    <?php endif; ?>

    <?php $this->endBody() ?>
    </body>
    </html>
<?php $this->endPage() ?>