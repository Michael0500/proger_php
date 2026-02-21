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
$this->registerLinkTag(['rel' => 'preconnect', 'href' => 'https://fonts.googleapis.com']);
$this->registerLinkTag(['rel' => 'stylesheet', 'href' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap']);
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

    <header id="header">
        <?php
        NavBar::begin([
                'brandLabel' => '<i class="fas fa-exchange-alt me-2"></i>' . Html::encode(Yii::$app->name),
                'brandUrl'   => Yii::$app->homeUrl,
                'options'    => ['class' => 'navbar-expand-md navbar-dark fixed-top'],
        ]);
        $menuItems = [['label' => 'Главная', 'url' => ['/site/index']]];
        if (Yii::$app->user->isGuest) {
            $menuItems[] = ['label' => 'Вход', 'url' => ['/site/login']];
        } else {
            $menuItems[] = [
                    'label'  => '<i class="fas fa-user-circle me-1"></i>' . Html::encode(Yii::$app->user->identity->username),
                    'encode' => false,
                    'items'  => [
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