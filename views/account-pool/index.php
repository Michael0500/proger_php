<?php
/** @var yii\web\View $this */
/** @var array $initData */

use yii\helpers\Url;

$this->title = 'Ностро-банки — SmartMatch';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="nostro-banks-app" v-cloak>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-landmark" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Ностро-банки</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">Управление ностро-банками и привязанными счетами</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <div class="nb-search-wrap">
                <i class="fas fa-search nb-search-icon"></i>
                <input
                    type="text"
                    class="form-control nb-search-input"
                    v-model="searchQuery"
                    placeholder="Поиск по названию, описанию, счетам…"
                >
                <button
                    v-if="searchQuery"
                    type="button"
                    class="nb-search-clear"
                    @click="searchQuery = ''"
                    title="Очистить"
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <button class="btn-action btn-primary-violet" @click="showCreateModal">
                <i class="fas fa-plus me-1"></i> Добавить ностро-банк
            </button>
        </div>
    </div>

    <!-- ══ СПИСОК НОСТРО-БАНКОВ ═══════════════════════════════ -->
    <div v-if="loading" style="text-align:center;padding:60px">
        <i class="fas fa-spinner fa-spin" style="font-size:24px;color:#9ca3af"></i>
    </div>

    <div v-else-if="pools.length === 0" class="sm-card" style="text-align:center;padding:60px">
        <i class="fas fa-landmark" style="font-size:48px;color:#d1d5db;margin-bottom:16px"></i>
        <p style="color:#9ca3af;font-size:14px">Ностро-банки ещё не созданы</p>
        <button class="btn-action btn-primary-violet" @click="showCreateModal" style="margin-top:8px">
            <i class="fas fa-plus me-1"></i> Создать первый
        </button>
    </div>

    <div v-else-if="filteredPools.length === 0" class="sm-card" style="text-align:center;padding:40px">
        <i class="fas fa-search" style="font-size:36px;color:#d1d5db;margin-bottom:12px"></i>
        <p style="color:#9ca3af;font-size:14px;margin:0">Ничего не найдено по запросу «{{ searchQuery }}»</p>
        <button type="button" class="btn btn-link" @click="searchQuery = ''" style="margin-top:6px">
            Сбросить поиск
        </button>
    </div>

    <div v-else>
        <div v-for="pool in filteredPools" :key="pool.id" class="sm-card" style="margin-bottom:16px">
            <!-- Заголовок ностро-банка (кликабельный для сворачивания) -->
            <div class="sm-card-header nb-pool-header" @click="togglePool(pool.id)" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none">
                <div style="display:flex;align-items:center;gap:8px">
                    <i class="fas fa-chevron-right nb-chevron" :class="{ 'nb-chevron-open': expandedPools[pool.id] }"></i>
                    <i class="fas fa-landmark" style="color:#4f46e5"></i>
                    <span style="font-weight:700;font-size:15px">{{ pool.name }}</span>
                    <span style="font-size:11px;color:#9ca3af;background:#f3f4f6;padding:1px 8px;border-radius:10px">
                        {{ pool.accounts.length }} {{ accountWord(pool.accounts.length) }}
                    </span>
                </div>
                <div style="display:flex;gap:6px" @click.stop>
                    <button class="btn-icon" @click="showAssignModal(pool)" title="Добавить счёт">
                        <i class="fas fa-plus-circle" style="color:#059669"></i>
                    </button>
                    <button class="btn-icon" @click="editPool(pool)" title="Редактировать">
                        <i class="fas fa-pen" style="color:#6366f1"></i>
                    </button>
                    <button class="btn-icon" @click="deletePool(pool)" title="Удалить">
                        <i class="fas fa-trash-alt" style="color:#ef4444"></i>
                    </button>
                </div>
            </div>

            <!-- Описание (видно всегда) -->
            <div v-if="pool.description && !expandedPools[pool.id]" style="padding:6px 16px 10px;font-size:12px;color:#6b7280">
                {{ pool.description }}
            </div>

            <!-- Развёрнутое содержимое -->
            <div v-if="expandedPools[pool.id]">
                <div v-if="pool.description" style="padding:6px 16px 0;font-size:12px;color:#6b7280">
                    {{ pool.description }}
                </div>

                <!-- Таблица счетов -->
                <div class="sm-card-body" style="padding:8px 0 0">
                    <div v-if="pool.accounts.length === 0" style="text-align:center;padding:20px;color:#9ca3af;font-size:13px">
                        <i class="fas fa-inbox me-1"></i> Нет привязанных счетов
                    </div>
                    <table v-else class="nb-accounts-table">
                        <thead>
                            <tr>
                                <th>Название счёта</th>
                                <th style="width:100px">Валюта</th>
                                <th style="width:100px">Suspense</th>
                                <th style="width:120px">Дата открытия</th>
                                <th style="width:120px">Дата закрытия</th>
                                <th style="width:60px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="acc in pool.accounts" :key="acc.id">
                                <td style="font-weight:600">{{ acc.name }}</td>
                                <td><span class="currency-badge">{{ acc.currency || '—' }}</span></td>
                                <td>
                                    <span v-if="acc.is_suspense" class="badge-yes">Да</span>
                                    <span v-else style="color:#9ca3af">Нет</span>
                                </td>
                                <td>{{ acc.date_open || '—' }}</td>
                                <td>{{ acc.date_close || '—' }}</td>
                                <td style="text-align:center">
                                    <button class="btn-icon" @click="unassignAccount(pool, acc)" title="Отвязать счёт">
                                        <i class="fas fa-unlink" style="color:#ef4444;font-size:12px"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ МОДАЛКА: Создать / Редактировать ностро-банк ═══════ -->
    <div class="modal fade" id="poolModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-landmark me-2" style="color:#4f46e5"></i>
                        {{ formPool.id ? 'Редактировать' : 'Создать' }} ностро-банк
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                        <input type="text" class="form-control" v-model="formPool.name" placeholder="Например: Deutsche Bank AG" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="formPool.description" rows="2" placeholder="Необязательное описание"></textarea>
                    </div>

                    <!-- Привязка счетов (создание и редактирование) -->
                    <hr style="border-color:#e5e7eb;margin:16px 0 12px">
                    <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px">
                        <i class="fas fa-link me-1"></i> Привязка счетов
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label" style="font-size:13px">
                                <span class="nb-ls-badge nb-ls-l">L</span> Ledger счета
                            </label>
                            <div v-if="loadingCreateAccounts" style="font-size:12px;color:#9ca3af;padding:6px 0">
                                <i class="fas fa-spinner fa-spin me-1"></i> Загрузка...
                            </div>
                            <select v-else id="create-ledger-select2" style="width:100%"></select>
                            <div style="font-size:11px;color:#9ca3af;margin-top:3px">Необязательно · можно выбрать несколько</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:13px">
                                <span class="nb-ls-badge nb-ls-s">S</span> Statement счета
                            </label>
                            <div v-if="loadingCreateAccounts" style="font-size:12px;color:#9ca3af;padding:6px 0">
                                <i class="fas fa-spinner fa-spin me-1"></i> Загрузка...
                            </div>
                            <select v-else id="create-statement-select2" style="width:100%"></select>
                            <div style="font-size:11px;color:#9ca3af;margin-top:3px">Необязательно · можно выбрать несколько</div>
                        </div>
                    </div>

                    <hr style="border-color:#e5e7eb;margin:16px 0 12px">
                    <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px">
                        <i class="fas fa-layer-group me-1"></i> Привязка к категории
                    </div>
                    <div class="mb-1">
                        <label class="form-label" style="font-size:13px">Категория <span style="color:#9ca3af;font-weight:400">(необязательно)</span></label>
                        <select id="create-category-select2" style="width:100%"></select>
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px">
                            Ностро-банк отобразится в выбранной категории на странице выверки
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="savePool" :disabled="!formPool.name || saving">
                        <i v-if="saving" class="fas fa-spinner fa-spin me-1"></i>
                        {{ formPool.id ? 'Сохранить' : 'Создать' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ МОДАЛКА: Привязать счёт ════════════════════════════ -->
    <div class="modal fade" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-link me-2" style="color:#059669"></i>
                        Привязать счёт к «{{ assignTarget ? assignTarget.name : '' }}»
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div v-if="loadingAvailable" style="text-align:center;padding:20px">
                        <i class="fas fa-spinner fa-spin"></i> Загрузка...
                    </div>
                    <div v-else-if="availableAccounts.length === 0" style="text-align:center;padding:20px;color:#9ca3af">
                        <i class="fas fa-check-circle me-1"></i> Все счета уже привязаны к ностро-банкам
                    </div>
                    <div v-else>
                        <label class="form-label">Выберите счёт</label>
                        <select id="assign-account-select2" style="width:100%"></select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-success" @click="assignAccount" :disabled="!selectedAccountId || saving">
                        <i v-if="saving" class="fas fa-spinner fa-spin me-1"></i>
                        <i v-else class="fas fa-link me-1"></i> Привязать
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
.nb-accounts-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.nb-accounts-table th {
    padding: 8px 16px;
    font-size: 11px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .3px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}
.nb-accounts-table td {
    padding: 8px 16px;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
}
.nb-accounts-table tr:last-child td {
    border-bottom: none;
}
.nb-accounts-table tr:hover td {
    background: #f9fafb;
}
.currency-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    background: #ede9fe;
    color: #6d28d9;
    font-size: 11px;
    font-weight: 700;
}
.badge-yes {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    background: #fef3c7;
    color: #b45309;
    font-size: 11px;
    font-weight: 600;
}
.btn-icon {
    border: none;
    background: none;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: background .15s;
}
.btn-icon:hover {
    background: #f3f4f6;
}
.nb-chevron {
    font-size: 11px;
    color: #9ca3af;
    transition: transform .2s ease;
}
.nb-chevron-open {
    transform: rotate(90deg);
}
.nb-pool-header:hover {
    background: #f9fafb;
}
.nb-ls-badge {
    display: inline-block;
    width: 18px;
    height: 18px;
    line-height: 18px;
    text-align: center;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 800;
    margin-right: 4px;
}
.nb-ls-l {
    background: #dbeafe;
    color: #1d4ed8;
}
.nb-ls-s {
    background: #dcfce7;
    color: #15803d;
}
.nb-search-wrap {
    position: relative;
    width: 320px;
    max-width: 100%;
}
.nb-search-input {
    padding-left: 34px;
    padding-right: 30px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    font-size: 13px;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.nb-search-input:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79,70,229,.12);
    outline: none;
}
.nb-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 12px;
    pointer-events: none;
}
.nb-search-clear {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: #9ca3af;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    font-size: 12px;
}
.nb-search-clear:hover {
    background: #f3f4f6;
    color: #374151;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
new Vue({
    el: '#nostro-banks-app',
    data: function () {
        var init = <?= $initJson ?>;
        return {
            pools: init.pools || [],
            categories: init.categories || [],
            loading: false,
            saving: false,

            // Поиск по ностро-банкам
            searchQuery: '',

            // Форма создания/редактирования
            formPool: { id: null, name: '', description: '' },

            // Свёрнутые/развёрнутые банки
            expandedPools: {},

            // Привязка счёта (существующий modal)
            assignTarget: null,
            availableAccounts: [],
            loadingAvailable: false,
            selectedAccountId: '',

            // Данные для create modal
            loadingCreateAccounts: false,
            createLedgerAccounts: [],
            createStatementAccounts: [],
            createSelectedLedger: [],
            createSelectedStatement: [],
            createSelectedCategoryId: '',
        };
    },

    computed: {
        filteredPools: function () {
            var q = (this.searchQuery || '').trim().toLowerCase();
            if (!q) return this.pools;
            return this.pools.filter(function (p) {
                if ((p.name || '').toLowerCase().indexOf(q) !== -1) return true;
                if ((p.description || '').toLowerCase().indexOf(q) !== -1) return true;
                if (Array.isArray(p.accounts)) {
                    for (var i = 0; i < p.accounts.length; i++) {
                        var a = p.accounts[i];
                        if ((a.name || '').toLowerCase().indexOf(q) !== -1) return true;
                        if ((a.currency || '').toLowerCase().indexOf(q) !== -1) return true;
                    }
                }
                return false;
            });
        },
    },

    methods: {
        // ── Свернуть/развернуть ─────────────────────────
        togglePool: function (poolId) {
            this.$set(this.expandedPools, poolId, !this.expandedPools[poolId]);
        },

        // ── Helpers ──────────────────────────────────────
        accountWord: function (n) {
            if (n % 10 === 1 && n % 100 !== 11) return 'счёт';
            if (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) return 'счёта';
            return 'счетов';
        },

        _modal: function (id, action) {
            var el = document.getElementById(id);
            if (!el) return;
            var inst = bootstrap.Modal.getInstance(el);
            if (action === 'show') {
                inst ? inst.show() : new bootstrap.Modal(el).show();
            } else if (inst) {
                inst.hide();
            }
        },

        reload: function () {
            var self = this;
            self.loading = true;
            axios.get('<?= Url::to(['/account-pool/list']) ?>').then(function (r) {
                if (r.data.success) self.pools = r.data.data;
            }).finally(function () { self.loading = false; });
        },

        // ── CRUD ностро-банков ───────────────────────────
        showCreateModal: function () {
            var self = this;
            self.formPool = { id: null, name: '', description: '' };
            self.createSelectedLedger = [];
            self.createSelectedStatement = [];
            self.createSelectedCategoryId = '';
            self._modal('poolModal', 'show');

            // Загружаем свободные счета и инициализируем select2
            self.loadingCreateAccounts = true;
            axios.get('<?= Url::to(['/account-pool/available-accounts']) ?>').then(function (r) {
                var accounts = r.data.success ? r.data.data : [];
                self.createLedgerAccounts    = accounts.filter(function (a) { return a.account_type === 'L'; });
                self.createStatementAccounts = accounts.filter(function (a) { return a.account_type === 'S'; });
            }).finally(function () {
                self.loadingCreateAccounts = false;
                self.$nextTick(function () { self._initCreateSelects(); });
            });
        },

        _initCreateSelects: function () {
            var self = this;

            // Ledger
            var $l = $('#create-ledger-select2');
            if ($l.length) {
                if ($l.data('select2')) $l.off('change.ledger').select2('destroy');
                $l.empty().select2({
                    theme: 'bootstrap-5',
                    placeholder: '— Выберите Ledger счета —',
                    allowClear: true,
                    multiple: true,
                    data: self.createLedgerAccounts.map(function (a) {
                        return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
                    }),
                    dropdownParent: $('#poolModal'),
                }).val(self.createSelectedLedger.length ? self.createSelectedLedger : null).trigger('change');
                $l.on('change.ledger', function () {
                    self.createSelectedLedger = $l.val() || [];
                });
            }

            // Statement
            var $s = $('#create-statement-select2');
            if ($s.length) {
                if ($s.data('select2')) $s.off('change.stmt').select2('destroy');
                $s.empty().select2({
                    theme: 'bootstrap-5',
                    placeholder: '— Выберите Statement счета —',
                    allowClear: true,
                    multiple: true,
                    data: self.createStatementAccounts.map(function (a) {
                        return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
                    }),
                    dropdownParent: $('#poolModal'),
                }).val(self.createSelectedStatement.length ? self.createSelectedStatement : null).trigger('change');
                $s.on('change.stmt', function () {
                    self.createSelectedStatement = $s.val() || [];
                });
            }

            // Category
            var $c = $('#create-category-select2');
            if ($c.length) {
                if ($c.data('select2')) $c.off('change.cat').select2('destroy');
                $c.empty().select2({
                    theme: 'bootstrap-5',
                    placeholder: '— Не привязывать к категории —',
                    allowClear: true,
                    data: self.categories.map(function (c) {
                        return { id: String(c.id), text: c.name };
                    }),
                    dropdownParent: $('#poolModal'),
                }).val(self.createSelectedCategoryId || null).trigger('change');
                $c.on('change.cat', function () {
                    self.createSelectedCategoryId = $c.val() || '';
                });
            }
        },

        editPool: function (pool) {
            var self = this;
            self.formPool = { id: pool.id, name: pool.name, description: pool.description || '' };
            self.createSelectedLedger = [];
            self.createSelectedStatement = [];
            self.createSelectedCategoryId = pool.category_id ? String(pool.category_id) : '';
            self._modal('poolModal', 'show');

            self.loadingCreateAccounts = true;
            axios.get('<?= Url::to(['/account-pool/available-accounts']) ?>', { params: { pool_id: pool.id } }).then(function (r) {
                var accounts = r.data.success ? r.data.data : [];
                self.createLedgerAccounts    = accounts.filter(function (a) { return a.account_type === 'L'; });
                self.createStatementAccounts = accounts.filter(function (a) { return a.account_type === 'S'; });
                // Предвыбрать уже привязанные
                self.createSelectedLedger    = accounts.filter(function (a) { return a.account_type === 'L' && a.assigned; }).map(function (a) { return String(a.id); });
                self.createSelectedStatement = accounts.filter(function (a) { return a.account_type === 'S' && a.assigned; }).map(function (a) { return String(a.id); });
            }).finally(function () {
                self.loadingCreateAccounts = false;
                self.$nextTick(function () { self._initCreateSelects(); });
            });
        },

        savePool: function () {
            var self = this;
            self.saving = true;
            var url, data;

            if (self.formPool.id) {
                url  = '<?= Url::to(['/account-pool/update']) ?>';
                data = {
                    id:                 self.formPool.id,
                    name:               self.formPool.name,
                    description:        self.formPool.description,
                    ledger_accounts:    self.createSelectedLedger,
                    statement_accounts: self.createSelectedStatement,
                    category_id:        self.createSelectedCategoryId || '',
                };
            } else {
                url  = '<?= Url::to(['/account-pool/create']) ?>';
                data = {
                    name:               self.formPool.name,
                    description:        self.formPool.description,
                    ledger_accounts:    self.createSelectedLedger,
                    statement_accounts: self.createSelectedStatement,
                    category_id:        self.createSelectedCategoryId || '',
                };
            }

            axios.post(url, data).then(function (r) {
                if (r.data.success) {
                    self._modal('poolModal', 'hide');
                    self.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.data.message });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Сетевая ошибка' });
            }).finally(function () { self.saving = false; });
        },

        deletePool: function (pool) {
            var self = this;
            Swal.fire({
                title: 'Удалить «' + pool.name + '»?',
                html: pool.accounts.length > 0
                    ? 'У этого ностро-банка <b>' + pool.accounts.length + '</b> привязанных счетов. Они будут отвязаны.'
                    : 'Ностро-банк будет удалён безвозвратно.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Удалить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#ef4444',
            }).then(function (r) {
                if (!r.isConfirmed) return;
                axios.post('<?= Url::to(['/account-pool/delete']) ?>', { id: pool.id }).then(function (res) {
                    if (res.data.success) self.reload();
                    else Swal.fire({ icon: 'error', title: 'Ошибка', text: res.data.message });
                });
            });
        },

        // ── Привязка/отвязка счетов ─────────────────────
        showAssignModal: function (pool) {
            var self = this;
            self.assignTarget = pool;
            self.selectedAccountId = '';
            self.loadingAvailable = true;
            self._modal('assignModal', 'show');

            axios.get('<?= Url::to(['/account-pool/available-accounts']) ?>').then(function (r) {
                self.availableAccounts = r.data.success ? r.data.data : [];
            }).finally(function () {
                self.loadingAvailable = false;
                self.$nextTick(function () { self._initAssignSelect2(); });
            });
        },

        _initAssignSelect2: function () {
            var self = this;
            var $el = $('#assign-account-select2');
            if (!$el.length) return;
            if ($el.data('select2')) $el.off('change.assign').select2('destroy');
            $el.empty();

            var accData = self.availableAccounts.map(function (a) {
                return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
            });

            $el.select2({
                theme:          'bootstrap-5',
                placeholder:    '— Выберите счёт —',
                allowClear:     true,
                data:           accData,
                dropdownParent: $('#assignModal'),
            });

            $el.val(null).trigger('change');

            $el.on('change.assign', function () {
                self.selectedAccountId = $el.val() || '';
            });
        },

        assignAccount: function () {
            var self = this;
            if (!self.selectedAccountId || !self.assignTarget) return;
            self.saving = true;

            axios.post('<?= Url::to(['/account-pool/assign-account']) ?>', {
                pool_id: self.assignTarget.id,
                account_id: self.selectedAccountId
            }).then(function (r) {
                if (r.data.success) {
                    self._modal('assignModal', 'hide');
                    self.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.data.message });
                }
            }).finally(function () { self.saving = false; });
        },

        unassignAccount: function (pool, acc) {
            var self = this;
            Swal.fire({
                title: 'Отвязать счёт?',
                html: 'Счёт <b>' + acc.name + '</b> будет отвязан от «' + pool.name + '».',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Отвязать',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#ef4444',
            }).then(function (r) {
                if (!r.isConfirmed) return;
                axios.post('<?= Url::to(['/account-pool/unassign-account']) ?>', {
                    account_id: acc.id
                }).then(function (res) {
                    if (res.data.success) self.reload();
                    else Swal.fire({ icon: 'error', title: 'Ошибка', text: res.data.message });
                });
            });
        },
    }
});
});
</script>
