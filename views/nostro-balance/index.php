<?php
/** @var yii\web\View $this */
use yii\helpers\Url;
?>

<div id="balance-app">

    <!-- ══════════════════════════════════════════════
         TOOLBAR
    ══════════════════════════════════════════════ -->
    <div class="pool-toolbar d-flex align-items-center gap-2 mb-3 flex-wrap">

        <div class="pool-title">
            <span style="font-size:15px;font-weight:700;color:#1a1f36">Баланс Ностро</span>
            <span class="badge bg-light text-muted ms-2" style="font-size:11px">{{ balancesTotal }}</span>
        </div>

        <div class="ms-auto d-flex gap-2 flex-wrap">
            <!-- Импорт БНД -->
            <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
                    @click="openImportModal('bnd')">
                <i class="fas fa-file-code"></i> Импорт БНД
            </button>
            <!-- Импорт АСБ -->
            <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
                    @click="openImportModal('asb')">
                <i class="fas fa-file-alt"></i> Импорт АСБ
            </button>
            <!-- Фильтры -->
            <button class="btn btn-sm"
                    :class="balanceFiltersOpen ? 'btn-primary' : 'btn-outline-secondary'"
                    @click="balanceFiltersOpen = !balanceFiltersOpen">
                <i class="fas fa-filter"></i> Фильтры
            </button>
            <!-- Добавить запись -->
            <button class="btn btn-sm btn-primary d-flex align-items-center gap-1"
                    @click="openCreateBalanceModal">
                <i class="fas fa-plus"></i> Добавить
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         ФИЛЬТРЫ
    ══════════════════════════════════════════════ -->
    <div v-if="balanceFiltersOpen" class="filters-panel mb-3">
        <div class="row g-2 align-items-end">

            <!-- L/S -->
            <div class="col-auto">
                <label class="filter-label">Тип</label>
                <div class="filter-toggle-group">
                    <button class="ftg-btn" :class="{active: !balanceFilters.ls_type}"
                            @click="balanceFilters.ls_type=''; onBalanceFilterChange()">Все</button>
                    <button class="ftg-btn" :class="{active: balanceFilters.ls_type==='L'}"
                            @click="balanceFilters.ls_type='L'; onBalanceFilterChange()">L</button>
                    <button class="ftg-btn" :class="{active: balanceFilters.ls_type==='S'}"
                            @click="balanceFilters.ls_type='S'; onBalanceFilterChange()">S</button>
                </div>
            </div>

            <!-- Раздел NRE/INV -->
            <div class="col-auto">
                <label class="filter-label">Раздел</label>
                <div class="filter-toggle-group">
                    <button class="ftg-btn" :class="{active: !balanceFilters.section}"
                            @click="balanceFilters.section=''; onBalanceFilterChange()">Все</button>
                    <button class="ftg-btn" :class="{active: balanceFilters.section==='NRE'}"
                            @click="balanceFilters.section='NRE'; onBalanceFilterChange()">NRE</button>
                    <button class="ftg-btn" :class="{active: balanceFilters.section==='INV'}"
                            @click="balanceFilters.section='INV'; onBalanceFilterChange()">INV</button>
                </div>
            </div>

            <!-- Статус -->
            <div class="col-auto">
                <label class="filter-label">Статус</label>
                <div class="filter-toggle-group">
                    <button class="ftg-btn" :class="{active: !balanceFilters.status}"
                            @click="balanceFilters.status=''; onBalanceFilterChange()">Все</button>
                    <button class="ftg-btn" title="normal"
                            :class="{active: balanceFilters.status==='normal'}"
                            @click="balanceFilters.status='normal'; onBalanceFilterChange()">⚪</button>
                    <button class="ftg-btn" title="error"
                            :class="{active: balanceFilters.status==='error'}"
                            @click="balanceFilters.status='error'; onBalanceFilterChange()">🔴</button>
                    <button class="ftg-btn" title="confirmed"
                            :class="{active: balanceFilters.status==='confirmed'}"
                            @click="balanceFilters.status='confirmed'; onBalanceFilterChange()">⚫</button>
                </div>
            </div>

            <!-- Счёт -->
            <div class="col-md-3">
                <label class="filter-label">Счёт</label>
                <select class="form-select form-select-sm balance-account-filter"
                        v-model="balanceFilters.account_id"
                        @change="onBalanceFilterChange()">
                    <option value="">Все счета</option>
                    <option v-for="a in balanceAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
            </div>

            <!-- Валюта -->
            <div class="col-auto">
                <label class="filter-label">Валюта</label>
                <select class="form-select form-select-sm" v-model="balanceFilters.currency"
                        @change="onBalanceFilterChange()" style="width:90px">
                    <option value="">—</option>
                    <option>RUB</option><option>USD</option><option>EUR</option>
                    <option>RUR</option>
                </select>
            </div>

            <!-- Дата от/до -->
            <div class="col-auto">
                <label class="filter-label">Дата с</label>
                <input type="date" class="form-control form-control-sm"
                       v-model="balanceFilters.value_date_from"
                       @change="onBalanceFilterChange()" style="width:140px">
            </div>
            <div class="col-auto">
                <label class="filter-label">по</label>
                <input type="date" class="form-control form-control-sm"
                       v-model="balanceFilters.value_date_to"
                       @change="onBalanceFilterChange()" style="width:140px">
            </div>

            <!-- Источник -->
            <div class="col-auto">
                <label class="filter-label">Источник</label>
                <select class="form-select form-select-sm" v-model="balanceFilters.source"
                        @change="onBalanceFilterChange()" style="width:130px">
                    <option value="">Все</option>
                    <option value="BND">БНД</option>
                    <option value="ASB">АСБ</option>
                    <option value="MT950">MT950</option>
                    <option value="CAMT">camt</option>
                    <option value="ED211">ED211</option>
                    <option value="FCC12">FCC12</option>
                    <option value="BARS_GL">BARS GL</option>
                    <option value="MANUAL">Ручной</option>
                </select>
            </div>

            <!-- Сброс -->
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-danger"
                        @click="balanceFilters={}; onBalanceFilterChange()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         ТАБЛИЦА
    ══════════════════════════════════════════════ -->
    <div class="table-card">
        <div class="table-scroll-wrap balance-table-wrap">
            <table class="table table-hover entries-table mb-0" style="font-size:12.5px">
                <thead class="table-light">
                <tr>
                    <th style="width:36px">#</th>
                    <th class="th-sort" :class="{sorted:balanceSortCol==='ls_type'}"
                        @click="sortBalance('ls_type')">
                        L/S <i class="fas fa-sort"></i>
                    </th>
                    <th>Раздел</th>
                    <th class="th-sort" :class="{sorted:balanceSortCol==='account_id'}"
                        @click="sortBalance('account_id')">
                        Счёт <i class="fas fa-sort"></i>
                    </th>
                    <th class="th-sort" :class="{sorted:balanceSortCol==='currency'}"
                        @click="sortBalance('currency')">
                        Валюта <i class="fas fa-sort"></i>
                    </th>
                    <th class="th-sort" :class="{sorted:balanceSortCol==='value_date'}"
                        @click="sortBalance('value_date')">
                        Дата вал. <i class="fas fa-sort"></i>
                    </th>
                    <th>№ выписки</th>
                    <th class="text-end">Opening</th>
                    <th style="width:36px" title="D/C">D/C</th>
                    <th class="text-end">Closing</th>
                    <th style="width:36px" title="D/C">D/C</th>
                    <th>Источник</th>
                    <th>Статус</th>
                    <th>Комментарий</th>
                    <th style="width:90px"></th>
                </tr>
                </thead>

                <tbody>
                <!-- Загрузка -->
                <tr v-if="balancesLoading">
                    <td colspan="15" class="text-center py-4 text-muted">
                        <i class="fas fa-circle-notch fa-spin me-1"></i> Загрузка...
                    </td>
                </tr>
                <!-- Пусто -->
                <tr v-else-if="!balances.length">
                    <td colspan="15" class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-lg mb-2 d-block"></i>
                        Нет записей
                    </td>
                </tr>

                <!-- Строки -->
                <tr v-for="row in balances" :key="row.id"
                    :class="{
                        'table-danger':  row.status === 'error',
                        'table-dark':    row.status === 'confirmed',
                        'bg-light':      row.status === 'normal'
                    }">
                    <td class="text-muted" style="font-size:11px">{{ row.id }}</td>
                    <td>
                        <span :class="row.ls_type==='L' ? 'badge bg-info' : 'badge bg-warning text-dark'">
                            {{ row.ls_type }}
                        </span>
                    </td>
                    <td><span class="badge bg-secondary">{{ row.section }}</span></td>
                    <td class="td-mono-truncate" :title="row.account_name">{{ row.account_name }}</td>
                    <td><strong>{{ row.currency }}</strong></td>
                    <td style="white-space:nowrap">{{ row.value_date_fmt || row.value_date }}</td>
                    <td class="td-mono-truncate" :title="row.statement_number">
                        {{ row.statement_number || '—' }}
                    </td>
                    <!-- Opening -->
                    <td class="text-end font-monospace" style="font-size:12px">
                        {{ formatBalanceAmount(row.opening_balance, row.opening_dc) }}
                    </td>
                    <td class="text-center">
                        <span :class="row.opening_dc==='D' ? 'text-danger' : 'text-success'"
                              style="font-weight:700;font-size:11px">{{ row.opening_dc }}</span>
                    </td>
                    <!-- Closing -->
                    <td class="text-end font-monospace" style="font-size:12px">
                        {{ formatBalanceAmount(row.closing_balance, row.closing_dc) }}
                    </td>
                    <td class="text-center">
                        <span :class="row.closing_dc==='D' ? 'text-danger' : 'text-success'"
                              style="font-weight:700;font-size:11px">{{ row.closing_dc }}</span>
                    </td>
                    <td><span style="font-size:11px;color:#6b7280">{{ row.source }}</span></td>
                    <!-- Статус -->
                    <td>
                        <span :title="row.comment">{{ balanceStatusIcon(row.status) }}</span>
                    </td>
                    <!-- Комментарий -->
                    <td class="td-mono-truncate" :title="row.comment" style="max-width:160px">
                        {{ row.comment || '' }}
                    </td>
                    <!-- Действия -->
                    <td style="white-space:nowrap">
                        <!-- Подтвердить (только для error) -->
                        <button v-if="row.status === 'error'"
                                class="btn btn-xs btn-warning me-1"
                                title="Подтвердить корректировку"
                                @click="openConfirmModal(row)">
                            <i class="fas fa-check"></i>
                        </button>
                        <!-- История (для confirmed) -->
                        <button v-if="row.status === 'confirmed'"
                                class="btn btn-xs btn-dark me-1"
                                title="История изменений"
                                @click="openHistoryModal(row)">
                            <i class="fas fa-history"></i>
                        </button>
                        <!-- Редактировать -->
                        <button class="btn btn-xs btn-outline-primary me-1"
                                title="Редактировать"
                                @click="openEditBalanceModal(row)">
                            <i class="fas fa-pen"></i>
                        </button>
                        <!-- Удалить -->
                        <button class="btn btn-xs btn-outline-danger"
                                title="Удалить"
                                @click="deleteBalance(row)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>

            <!-- Подгрузка -->
            <div v-if="balancesLoadingMore" class="text-center py-3 text-muted" style="font-size:12px">
                <i class="fas fa-circle-notch fa-spin me-1"></i> Загрузка...
            </div>
            <div v-else-if="hasMoreBalances" class="text-center py-2">
                <button class="btn btn-sm btn-outline-secondary" @click="loadMoreBalances">
                    Загрузить ещё
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         МОДАЛ: Создать / Редактировать
    ══════════════════════════════════════════════ -->
    <div v-if="balanceModalOpen" class="modal-backdrop-custom" @click.self="closeBalanceModal">
        <div class="modal-card" style="max-width:600px">
            <div class="modal-card-header">
                <span>{{ editingBalance.id ? 'Редактировать запись баланса' : 'Новая запись баланса' }}</span>
                <button class="btn-close" @click="closeBalanceModal"></button>
            </div>
            <div class="modal-card-body">
                <div class="row g-3">

                    <!-- Тип L/S -->
                    <div class="col-md-3">
                        <label class="form-label">Тип <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" v-model="editingBalance.ls_type">
                            <option value="L">L — Ledger</option>
                            <option value="S">S — Statement</option>
                        </select>
                    </div>

                    <!-- Раздел -->
                    <div class="col-md-3">
                        <label class="form-label">Раздел <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" v-model="editingBalance.section">
                            <option value="NRE">NRE</option>
                            <option value="INV">INV</option>
                        </select>
                    </div>

                    <!-- Счёт -->
                    <div class="col-md-6">
                        <label class="form-label">Счёт <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" v-model="editingBalance.account_id">
                            <option :value="null">— выберите —</option>
                            <option v-for="a in balanceAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>

                    <!-- Валюта -->
                    <div class="col-md-3">
                        <label class="form-label">Валюта <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" v-model="editingBalance.currency">
                            <option>RUB</option><option>USD</option><option>EUR</option><option>RUR</option>
                        </select>
                    </div>

                    <!-- Дата -->
                    <div class="col-md-4">
                        <label class="form-label">Дата вал. <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm"
                               v-model="editingBalance.value_date">
                    </div>

                    <!-- Номер выписки (только S) -->
                    <div class="col-md-5" v-if="editingBalance.ls_type === 'S'">
                        <label class="form-label">№ выписки <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm"
                               v-model="editingBalance.statement_number"
                               placeholder="Номер выписки">
                    </div>

                    <!-- Opening Balance -->
                    <div class="col-md-5">
                        <label class="form-label">Opening Balance <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm font-monospace"
                               v-model="editingBalance.opening_balance"
                               placeholder="0.00">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">D/C</label>
                        <select class="form-select form-select-sm" v-model="editingBalance.opening_dc">
                            <option value="D">D</option>
                            <option value="C">C</option>
                        </select>
                    </div>

                    <!-- Closing Balance -->
                    <div class="col-md-5">
                        <label class="form-label">Closing Balance <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm font-monospace"
                               v-model="editingBalance.closing_balance"
                               placeholder="0.00">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">D/C</label>
                        <select class="form-select form-select-sm" v-model="editingBalance.closing_dc">
                            <option value="D">D</option>
                            <option value="C">C</option>
                        </select>
                    </div>

                    <!-- Источник -->
                    <div class="col-md-4">
                        <label class="form-label">Источник</label>
                        <select class="form-select form-select-sm" v-model="editingBalance.source">
                            <option value="MANUAL">Ручной ввод</option>
                            <option value="BND">БНД</option>
                            <option value="ASB">АСБ</option>
                            <option value="MT950">MT950</option>
                            <option value="CAMT">camt</option>
                            <option value="ED211">ED211</option>
                            <option value="FCC12">FCC12</option>
                            <option value="BARS_GL">BARS GL</option>
                        </select>
                    </div>

                    <!-- Комментарий -->
                    <div class="col-12">
                        <label class="form-label">Комментарий</label>
                        <input type="text" class="form-control form-control-sm"
                               v-model="editingBalance.comment"
                               placeholder="Необязательно">
                    </div>
                </div>
            </div>
            <div class="modal-card-footer d-flex justify-content-end gap-2">
                <button class="btn btn-sm btn-outline-secondary" @click="closeBalanceModal">Отмена</button>
                <button class="btn btn-sm btn-primary" :disabled="balanceSaving" @click="saveBalance">
                    <i class="fas fa-save me-1"></i>
                    {{ balanceSaving ? 'Сохранение...' : 'Сохранить' }}
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         МОДАЛ: Подтвердить ошибку
    ══════════════════════════════════════════════ -->
    <div v-if="confirmModalOpen" class="modal-backdrop-custom" @click.self="closeConfirmModal">
        <div class="modal-card" style="max-width:460px">
            <div class="modal-card-header">
                <span>🔴 Подтверждение корректировки</span>
                <button class="btn-close" @click="closeConfirmModal"></button>
            </div>
            <div class="modal-card-body">
                <p class="text-muted mb-3" style="font-size:13px">
                    Запись будет переведена в статус <strong>⚫ confirmed</strong>.
                    Укажите причину.
                </p>
                <div v-if="confirmingBalance" class="alert alert-warning py-2 mb-3" style="font-size:12px">
                    <strong>{{ confirmingBalance.account_name }}</strong>
                    &nbsp;·&nbsp;{{ confirmingBalance.currency }}
                    &nbsp;·&nbsp;{{ confirmingBalance.value_date_fmt || confirmingBalance.value_date }}
                    <br><small class="text-danger">{{ confirmingBalance.comment }}</small>
                </div>
                <label class="form-label">Причина корректировки <span class="text-danger">*</span></label>
                <textarea class="form-control form-control-sm" rows="3"
                          v-model="confirmReason"
                          placeholder="Введите объяснение..."></textarea>
            </div>
            <div class="modal-card-footer d-flex justify-content-end gap-2">
                <button class="btn btn-sm btn-outline-secondary" @click="closeConfirmModal">Отмена</button>
                <button class="btn btn-sm btn-warning" :disabled="confirmSaving" @click="submitConfirm">
                    <i class="fas fa-check me-1"></i>
                    {{ confirmSaving ? 'Сохранение...' : 'Подтвердить корректировку' }}
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         МОДАЛ: История изменений
    ══════════════════════════════════════════════ -->
    <div v-if="historyModalOpen" class="modal-backdrop-custom" @click.self="closeHistoryModal">
        <div class="modal-card" style="max-width:700px">
            <div class="modal-card-header">
                <span>⚫ История изменений</span>
                <button class="btn-close" @click="closeHistoryModal"></button>
            </div>
            <div class="modal-card-body">
                <div v-if="historyLoading" class="text-center py-3 text-muted">
                    <i class="fas fa-circle-notch fa-spin me-1"></i> Загрузка...
                </div>
                <div v-else-if="!historyLogs.length" class="text-muted text-center py-3">
                    Нет записей в истории
                </div>
                <table v-else class="table table-sm" style="font-size:12px">
                    <thead>
                    <tr>
                        <th>Дата</th><th>Действие</th><th>Причина</th><th>Пользователь</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="log in historyLogs" :key="log.id">
                        <td style="white-space:nowrap">{{ log.created_at }}</td>
                        <td><span class="badge bg-secondary">{{ log.action }}</span></td>
                        <td>{{ log.reason || '—' }}</td>
                        <td>{{ log.user_id }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-card-footer d-flex justify-content-end">
                <button class="btn btn-sm btn-outline-secondary" @click="closeHistoryModal">Закрыть</button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         МОДАЛ: Импорт файла
    ══════════════════════════════════════════════ -->
    <div v-if="importModalOpen" class="modal-backdrop-custom" @click.self="closeImportModal">
        <div class="modal-card" style="max-width:480px">
            <div class="modal-card-header">
                <span>
                    <i class="fas fa-upload me-1"></i>
                    Импорт {{ importType === 'bnd' ? 'Банк-клиент БНД (XML)' : 'Банк-клиент АСБ (TXT)' }}
                </span>
                <button class="btn-close" @click="closeImportModal"></button>
            </div>
            <div class="modal-card-body">
                <div class="mb-3">
                    <label class="form-label">Счёт <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" v-model="importAccountId">
                        <option :value="null">— выберите счёт —</option>
                        <option v-for="a in balanceAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Раздел</label>
                    <div class="filter-toggle-group" style="width:120px">
                        <button class="ftg-btn" :class="{active: importSection==='NRE'}"
                                @click="importSection='NRE'">NRE</button>
                        <button class="ftg-btn" :class="{active: importSection==='INV'}"
                                @click="importSection='INV'">INV</button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">
                        Файл <span class="text-danger">*</span>
                        <small class="text-muted ms-1">
                            {{ importType === 'bnd' ? '(.xml)' : '(.txt, .csv)' }}
                        </small>
                    </label>
                    <input type="file"
                           class="form-control form-control-sm"
                           :accept="importType === 'bnd' ? '.xml' : '.txt,.csv'"
                           @change="onImportFileChange">
                </div>

                <!-- Результат -->
                <div v-if="importResult" class="mt-3">
                    <div :class="importResult.success ? 'alert alert-success' : 'alert alert-danger'"
                         class="py-2" style="font-size:12px">
                        {{ importResult.message }}
                    </div>
                    <div v-if="importResult.parse_errors && importResult.parse_errors.length"
                         class="alert alert-warning py-2 mt-2" style="font-size:11px">
                        <strong>Ошибки парсинга:</strong>
                        <ul class="mb-0 mt-1">
                            <li v-for="e in importResult.parse_errors" :key="e">{{ e }}</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-card-footer d-flex justify-content-end gap-2">
                <button class="btn btn-sm btn-outline-secondary" @click="closeImportModal">Закрыть</button>
                <button class="btn btn-sm btn-primary" :disabled="importLoading" @click="submitImport">
                    <i class="fas fa-upload me-1"></i>
                    {{ importLoading ? 'Загрузка...' : 'Загрузить' }}
                </button>
            </div>
        </div>
    </div>

</div><!-- #balance-app -->

<script>
    (function () {
        document.addEventListener('DOMContentLoaded', function () {
            new Vue({
                el: '#balance-app',
                mixins: [BalanceMixin],
                created: function () {
                    this.loadBalanceAccounts();
                    this.loadBalances(true);
                },
                mounted: function () {
                    this.initBalanceInfiniteScroll();
                },
                methods: {
                    showToast: function (msg, type) {
                        // Используем тот же toast что в основном app.js если он доступен
                        if (window.SmartMatchApp && window.SmartMatchApp.showToast) {
                            window.SmartMatchApp.showToast(msg, type);
                            return;
                        }
                        // Fallback: простой alert
                        if (type === 'error') console.error(msg);
                        else console.log(msg);
                        // Bootstrap toast fallback
                        var bg = type === 'error'   ? 'bg-danger' :
                            type === 'warning' ? 'bg-warning text-dark' : 'bg-success';
                        var el = document.createElement('div');
                        el.className = 'toast align-items-center ' + bg + ' text-white border-0 show position-fixed';
                        el.style.cssText = 'bottom:20px;right:20px;z-index:9999;min-width:240px';
                        el.innerHTML = '<div class="d-flex"><div class="toast-body">' + msg + '</div>'
                            + '<button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.parentElement.parentElement.remove()"></button></div>';
                        document.body.appendChild(el);
                        setTimeout(function () { el.remove(); }, 4000);
                    }
                }
            });
        });
    })();
</script>