<?php
/** @var yii\web\View $this */
/** @var string $content */
use app\assets\AppAsset;
use app\widgets\Alert;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;
use app\models\Company;

AppAsset::register($this);
$this->registerCsrfMetaTags();
$this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
$this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, shrink-to-fit=no']);
$this->registerMetaTag(['name' => 'description', 'content' => $this->params['meta_description'] ?? '']);
$this->registerMetaTag(['name' => 'keywords', 'content' => $this->params['meta_keywords'] ?? '']);
$this->registerLinkTag(['rel' => 'icon', 'type' => 'image/x-icon', 'href' => Yii::getAlias('@web/favicon.ico')]);
?>
<?php $this->beginPage() ?>
    <!DOCTYPE html>
    <html lang="<?= Yii::$app->language ?>" class="h-100">
    <head>
        <title><?= Html::encode($this->title) ?></title>
        <?php $this->head() ?>

        <!-- Vue.js and Axios -->
        <script src="https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

        <style>
            :root {
                --sidebar-width: 300px;
                --sidebar-collapsed-width: 70px;
            }
            body {
                overflow-x: hidden;
            }
            #sidebar {
                position: fixed;
                top: 56px;
                left: 0;
                bottom: 0;
                width: var(--sidebar-width);
                background-color: #f8f9fa;
                border-right: 1px solid #dee2e6;
                overflow-y: auto;
                transition: all 0.3s ease;
                z-index: 1000;
            }
            #sidebar.collapsed {
                width: var(--sidebar-collapsed-width);
            }
            #sidebar.collapsed .sidebar-text,
            #sidebar.collapsed .group-name,
            #sidebar.collapsed .pool-name,
            #sidebar.collapsed hr {
                display: none;
            }
            #main {
                margin-left: var(--sidebar-width);
                transition: margin-left 0.3s ease;
            }
            #main.sidebar-collapsed {
                margin-left: var(--sidebar-collapsed-width);
            }
            #sidebar-toggle {
                position: absolute;
                top: 10px;
                right: -15px;
                z-index: 1001;
                background-color: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 50%;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            #sidebar-toggle i {
                transition: transform 0.3s ease;
            }
            #sidebar.collapsed #sidebar-toggle i {
                transform: rotate(180deg);
            }
            .group-item {
                margin-bottom: 15px;
                border-radius: 5px;
            }
            .group-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px 15px;
                background-color: #e9ecef;
                cursor: pointer;
                border-radius: 5px 5px 0 0;
                font-weight: 500;
            }
            .group-header:hover {
                background-color: #dde0e3;
            }
            .group-header.active {
                background-color: #0d6efd;
                color: white;
            }
            .group-header.active:hover {
                background-color: #0b5ed7;
            }
            .pool-item {
                padding: 8px 15px 8px 35px;
                cursor: pointer;
                transition: background-color 0.2s;
                border-left: 3px solid transparent;
                position: relative;
            }
            .pool-item:hover {
                background-color: #e9ecef;
            }
            .pool-item.active {
                background-color: #198754;
                color: white;
                border-left-color: #198754;
            }
            .pool-item.active:hover {
                background-color: #157347;
            }
            .action-btn {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                opacity: 0;
                transition: opacity 0.2s;
                z-index: 10;
                background-color: rgba(255,255,255,0.9);
                border: 1px solid #dee2e6;
            }
            .pool-item:hover .action-btn,
            .group-header:hover .action-btn {
                opacity: 1;
            }
            .dropdown-menu {
                font-size: 0.875rem;
                min-width: 180px;
            }
            .pool-list {
                display: none;
                background-color: white;
                border-radius: 0 0 5px 5px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            .group-header.active + .pool-list {
                display: block;
            }
            .group-collapse-icon {
                transition: transform 0.3s ease;
                margin-right: 8px;
            }
            .group-header.active .group-collapse-icon {
                transform: rotate(90deg);
            }
            .table-container {
                background-color: white;
                border-radius: 5px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                padding: 20px;
                margin-top: 20px;
            }
            .loading {
                display: flex;
                justify-content: center;
                align-items: center;
                height: 200px;
            }
            .badge-suspense {
                background-color: #ffc107;
                color: #000;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.85em;
            }
            .badge-nre {
                background-color: #6c757d;
                color: white;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.85em;
            }
        </style>
    </head>
    <body class="d-flex flex-column h-100">
    <?php $this->beginBody() ?>
    <header id="header">
        <?php
        NavBar::begin([
                'brandLabel' => Yii::$app->name,
                'brandUrl' => Yii::$app->homeUrl,
                'options' => ['class' => 'navbar-expand-md navbar-dark bg-dark fixed-top']
        ]);
        $menuItems = [
                ['label' => 'Главная', 'url' => ['/site/index']],
        ];
        if (Yii::$app->user->isGuest) {
            $menuItems[] = ['label' => 'Регистрация', 'url' => ['/site/signup']];
            $menuItems[] = ['label' => 'Вход', 'url' => ['/site/login']];
        } else {
            $menuItems[] = ['label' => 'Пользователи', 'url' => ['/user/index']];
            $menuItems[] = [
                    'label' => Yii::$app->user->identity->username,
                    'items' => [
                            ['label' => 'Профиль', 'url' => ['/user/view', 'id' => Yii::$app->user->id]],
                            '<div class="dropdown-divider"></div>',
                            ['label' => 'Выход (' . Yii::$app->user->identity->username . ')',
                                    'url' => ['/site/logout'],
                                    'linkOptions' => [
                                            'data-method' => 'post'
                                    ],
                            ],
                    ],
            ];
        }
        echo Nav::widget([
                'options' => ['class' => 'navbar-nav'],
                'items' => $menuItems
        ]);
        NavBar::end();
        ?>
    </header>

    <?php if (!Yii::$app->user->isGuest && Yii::$app->user->identity->hasCompany()): ?>
        <div id="app" class="d-flex">
            <?= $this->render('_sidebar') ?>

            <main id="main" class="flex-shrink-0" :class="{ 'sidebar-collapsed': isSidebarCollapsed }" role="main">
                <div class="container">
                    <?php if (!empty($this->params['breadcrumbs'])): ?>
                        <?= Breadcrumbs::widget(['links' => $this->params['breadcrumbs']]) ?>
                    <?php endif ?>
                    <?= Alert::widget() ?>

                    <?= $this->render('_content', ['content' => $content]) ?>
                </div>
            </main>

            <!-- Модальные окна должны быть внутри #app -->
            <?= $this->render('_modals') ?>
        </div>

    <?php else: ?>
        <main id="main" class="flex-shrink-0" role="main">
            <div class="container">
                <?php if (!empty($this->params['breadcrumbs'])): ?>
                    <?= Breadcrumbs::widget(['links' => $this->params['breadcrumbs']]) ?>
                <?php endif ?>
                <?= Alert::widget() ?>
                <?= $content ?>
            </div>
        </main>
    <?php endif; ?>

    <footer id="footer" class="mt-auto py-3 bg-light">
        <div class="container">
            <div class="row text-muted">
                <div class="col-md-6 text-center text-md-start">&copy; Unicredit <?= date('Y') ?></div>
                <div class="col-md-6 text-center text-md-end">
                    <?php if (!Yii::$app->user->isGuest && Yii::$app->user->identity->hasCompany()): ?>
                        <p>Компания: <?= Html::encode(Yii::$app->user->identity->company->name) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <?php if (!Yii::$app->user->isGuest && Yii::$app->user->identity->hasCompany()): ?>
        <?= $this->render('_vue-scripts') ?>
    <?php endif; ?>

    <?php $this->endBody() ?>
    </body>
    </html>
<?php $this->endPage() ?>