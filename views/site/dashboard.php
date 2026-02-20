<?php

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Панель управления';
?>
<div class="site-dashboard">

    <div class="jumbotron text-center bg-light">
        <h1>Добро пожаловать в систему!</h1>
        <p class="lead">
            Вы работаете с компанией:
            <span class="badge badge-primary badge-pill"><?= Html::encode($company->name) ?></span>
        </p>
        <p>
            <?= Html::a('Сменить компанию', ['company/reset'], ['class' => 'btn btn-outline-primary']) ?>
        </p>
    </div>

    <div class="container">
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Пользователи</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="display-4 mb-3">
                            <i class="fas fa-users"></i>
                        </div>
                        <p class="card-text">Управление пользователями системы</p>
                        <?= Html::a('Перейти', ['user/index'], ['class' => 'btn btn-info']) ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-white">
                        <h5 class="mb-0">Настройки</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="display-4 mb-3">
                            <i class="fas fa-cog"></i>
                        </div>
                        <p class="card-text">Настройки вашей учетной записи</p>
                        <?= Html::a('Перейти', ['user/view', 'id' => Yii::$app->user->id], ['class' => 'btn btn-warning']) ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Отчеты</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="display-4 mb-3">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <p class="card-text">Аналитика и отчеты</p>
                        <?= Html::a('Перейти', ['#'], ['class' => 'btn btn-success disabled']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>