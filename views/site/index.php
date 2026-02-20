<?php

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Выбор компании';
?>
<div class="site-index">

    <div class="jumbotron text-center">
        <h1>Выберите компанию</h1>
        <p class="lead">Пожалуйста, выберите компанию для работы</p>
    </div>

    <div class="container">
        <div class="row">
            <?php foreach ($companies as $company): ?>
            <div class="col-md-6 mb-4">
                <div class="card shadow-lg h-100">
                    <?php if ($company->code === 'NRE'): ?>
                    <div class="card-header bg-primary text-white">
                        <?php elseif ($company->code === 'INV'): ?>
                        <div class="card-header bg-success text-white">
                            <?php else: ?>
                            <div class="card-header bg-secondary text-white">
                                <?php endif; ?>
                                <h3 class="mb-0"><?= Html::encode($company->name) ?></h3>
                            </div>
                            <div class="card-body text-center">
                                <div class="display-1 mb-4">
                                    <i class="fas fa-building"></i>
                                </div>
                                <p class="lead">
                                    <?= Html::encode($company->name) ?> - ваш партнер в бизнесе
                                </p>
                                <p class="text-muted">
                                    Код компании: <strong><?= Html::encode($company->code) ?></strong>
                                </p>
                            </div>
                            <div class="card-footer">
                                <?= Html::a('Выбрать компанию',
                                        ['company/select', 'id' => $company->id],
                                        ['class' => 'btn btn-lg btn-block btn-outline-primary']
                                ) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center mt-4">
                    <p class="text-muted">
                        <i class="fas fa-info-circle"></i>
                        После выбора компании вы сможете работать с системой
                    </p>
                </div>
            </div>

        </div>

        <style>
            .card {
                transition: transform 0.3s, box-shadow 0.3s;
            }
            .card:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            }
            .card-footer {
                background-color: #f8f9fa;
            }
        </style>