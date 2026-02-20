<?php
/** @var yii\web\View $this */
use yii\helpers\Html;
?>

<div class="table-container">
    <div v-if="selectedPool">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>
                <i class="fas fa-university me-2"></i>
                Счета пула "{{ selectedPool.name }}"
            </h5>
            <button class="btn btn-sm btn-outline-secondary" @click="refreshAccounts">
                <i class="fas fa-sync"></i> Обновить
            </button>
        </div>

        <div v-if="loadingAccounts" class="loading">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <div v-else-if="accounts.length === 0" class="text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <p class="text-muted">В этом пуле нет счетов</p>
        </div>

        <div v-else>
            <table class="table table-hover table-striped">
                <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Валюта</th>
                    <th>Тип счета</th>
                    <th>Код банка</th>
                    <th>Страна</th>
                    <th>Suspense</th>
                    <th>Дата создания</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="account in accounts" :key="account.id">
                    <td>{{ account.id }}</td>
                    <td><strong>{{ account.name }}</strong></td>
                    <td>{{ account.currency || '-' }}</td>
                    <td>{{ account.account_type || '-' }}</td>
                    <td>{{ account.bank_code || '-' }}</td>
                    <td>{{ account.country || '-' }}</td>
                    <td>
                        <span v-if="account.is_suspense" class="badge-suspense">INV</span>
                        <span v-else class="badge-nre">NRE</span>
                    </td>
                    <td>{{ formatDate(account.created_at) }}</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div v-else class="text-center py-5">
        <i class="fas fa-arrow-left fa-3x text-muted mb-3"></i>
        <p class="text-muted">Выберите пул в меню слева, чтобы увидеть список счетов</p>
    </div>
</div>

<!-- Original content for other pages -->
<div v-if="!isAccountPage">
    <?= $content ?>
</div>