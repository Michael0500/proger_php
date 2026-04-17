<?php
/** @var yii\web\View $this */
/** @var array $initData */

use yii\helpers\Url;

$this->title = 'Счета — SmartMatch';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="accounts-app" v-cloak>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-university" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Счета</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">
                    Управление ностро-счетами
                    <span v-if="accounts.length" style="margin-left:6px">· <strong style="color:#4f46e5">{{ accounts.length }}</strong> {{ accountWord(accounts.length) }}</span>
                </div>
            </div>
        </div>
        <button class="btn-action btn-primary-violet" @click="showCreateModal">
            <i class="fas fa-plus me-1"></i> Добавить счёт
        </button>
    </div>

    <!-- ══ ФИЛЬТР ══════════════════════════════════════════════ -->
    <div class="sm-card" style="margin-bottom:14px;padding:12px 16px">
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <div style="display:flex;align-items:center;gap:6px">
                <i class="fas fa-filter" style="color:#9ca3af;font-size:12px"></i>
                <select id="filter-pool-select2" style="width:200px"></select>
            </div>
            <div style="display:flex;align-items:center;gap:6px">
                <input type="text" class="form-control form-control-sm" v-model="searchQuery"
                       placeholder="Поиск по названию..." style="width:220px">
            </div>
            <span v-if="filteredAccounts.length !== accounts.length" style="font-size:11px;color:#9ca3af">
                Показано {{ filteredAccounts.length }} из {{ accounts.length }}
            </span>
        </div>
    </div>

    <!-- ══ ТАБЛИЦА СЧЕТОВ ═════════════════════════════════════ -->
    <div v-if="loading" style="text-align:center;padding:60px">
        <i class="fas fa-spinner fa-spin" style="font-size:24px;color:#9ca3af"></i>
    </div>

    <div v-else-if="accounts.length === 0" class="sm-card" style="text-align:center;padding:60px">
        <i class="fas fa-university" style="font-size:48px;color:#d1d5db;margin-bottom:16px"></i>
        <p style="color:#9ca3af;font-size:14px">Счета ещё не созданы</p>
        <button class="btn-action btn-primary-violet" @click="showCreateModal" style="margin-top:8px">
            <i class="fas fa-plus me-1"></i> Создать первый
        </button>
    </div>

    <div v-else class="sm-card" style="padding:0;overflow:hidden">
        <div style="overflow-x:auto">
            <table class="acc-table">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Название</th>
                        <th style="width:80px">Валюта</th>
                        <th>Ностро-банк</th>
                        <th style="width:90px">Тип</th>
                        <th style="width:80px">Страна</th>
                        <th style="width:70px">Suspense</th>
                        <th style="width:70px">BARSGL</th>
                        <th style="width:100px">Дата откр.</th>
                        <th style="width:100px">Дата закр.</th>
                        <th style="width:90px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="acc in filteredAccounts" :key="acc.id">
                        <td style="color:#9ca3af;font-size:11px">{{ acc.id }}</td>
                        <td style="font-weight:600">{{ acc.name }}</td>
                        <td><span v-if="acc.currency" class="acc-badge acc-badge-currency">{{ acc.currency }}</span><span v-else style="color:#d1d5db">—</span></td>
                        <td><span v-if="acc.pool_name" style="color:#6b7280">{{ acc.pool_name }}</span><span v-else style="color:#d1d5db">— не привязан —</span></td>
                        <td><span v-if="acc.account_type" class="acc-badge acc-badge-type">{{ acc.account_type }}</span><span v-else style="color:#d1d5db">—</span></td>
                        <td>{{ acc.country || '—' }}</td>
                        <td style="text-align:center">
                            <i v-if="acc.is_suspense" class="fas fa-check-circle" style="color:#f59e0b"></i>
                            <span v-else style="color:#d1d5db">—</span>
                        </td>
                        <td style="text-align:center">
                            <i v-if="acc.load_barsgl" class="fas fa-check-circle" style="color:#059669"></i>
                            <span v-else style="color:#d1d5db">—</span>
                        </td>
                        <td style="font-size:12px">{{ fmtDate(acc.date_open) }}</td>
                        <td style="font-size:12px">{{ fmtDate(acc.date_close) }}</td>
                        <td style="text-align:right">
                            <button class="acc-btn-icon" @click="editAccount(acc)" title="Редактировать">
                                <i class="fas fa-pen" style="color:#6366f1"></i>
                            </button>
                            <button class="acc-btn-icon" @click="deleteAccount(acc)" title="Удалить">
                                <i class="fas fa-trash-alt" style="color:#ef4444"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ МОДАЛКА: Создать / Редактировать ═══════════════════ -->
    <div class="modal fade" id="accountModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-university me-2" style="color:#4f46e5"></i>
                        {{ form.id ? 'Редактировать счёт' : 'Новый счёт' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Название -->
                        <div class="col-md-8">
                            <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-control" v-model="form.name" placeholder="NOSTRO_USD_01" maxlength="55">
                        </div>

                        <!-- Валюта -->
                        <div class="col-md-4">
                            <label class="form-label">Валюта</label>
                            <select class="form-select" v-model="form.currency">
                                <option value="">— не указана —</option>
                                <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </div>

                        <!-- Ностро-банк -->
                        <div class="col-md-6">
                            <label class="form-label">Ностро-банк</label>
                            <select id="account-pool-select2" style="width:100%"></select>
                            <div class="form-text">Необязательно. Можно привязать позже.</div>
                        </div>

                        <!-- Тип счёта Ledger/Statement -->
                        <div class="col-md-3">
                            <label class="form-label">Тип счёта <span style="color:#ef4444">*</span></label>
                            <select class="form-select" v-model="form.account_type">
                                <option value="">— не указан —</option>
                                <option value="L">L — Ledger</option>
                                <option value="S">S — Statement</option>
                            </select>
                        </div>

                        <!-- Страна -->
                        <div class="col-md-3">
                            <label class="form-label">Страна</label>
                            <input type="text" class="form-control" v-model="form.country" placeholder="DE, US, RU...">
                        </div>

                        <!-- Дата открытия -->
                        <div class="col-md-4">
                            <label class="form-label">Дата открытия</label>
                            <input type="text" v-datepicker class="form-control" v-model="form.date_open">
                        </div>

                        <!-- Дата закрытия -->
                        <div class="col-md-4">
                            <label class="form-label">Дата закрытия</label>
                            <input type="text" v-datepicker class="form-control" v-model="form.date_close">
                        </div>

                        <!-- Статус загрузки -->
                        <div class="col-md-4">
                            <label class="form-label">Статус загрузки</label>
                            <select class="form-select" v-model="form.load_status">
                                <option value="L">L — Loaded</option>
                                <option value="P">P — Pending</option>
                                <option value="E">E — Error</option>
                            </select>
                        </div>

                        <!-- Чекбоксы -->
                        <div class="col-12">
                            <div style="display:flex;gap:24px;flex-wrap:wrap">
                                <label class="acc-check">
                                    <input type="checkbox" v-model="form.is_suspense">
                                    <span><i class="fas fa-exclamation-triangle me-1" style="color:#f59e0b"></i> Suspense-счёт (для INV)</span>
                                </label>
                                <label class="acc-check">
                                    <input type="checkbox" v-model="form.load_barsgl">
                                    <span><i class="fas fa-database me-1" style="color:#059669"></i> Load BAR/SGL</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="saveAccount" :disabled="!form.name.trim() || !form.account_type || saving">
                        <i v-if="saving" class="fas fa-spinner fa-spin me-1"></i>
                        {{ form.id ? 'Сохранить' : 'Создать' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
.acc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.acc-table th {
    padding: 10px 12px;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #6b7280;
    border-bottom: 2px solid #e5e7eb;
    background: #f9fafb;
    white-space: nowrap;
}
.acc-table td {
    padding: 9px 12px;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
    vertical-align: middle;
}
.acc-table tr:last-child td { border-bottom: none; }
.acc-table tr:hover td { background: #fafbff; }

.acc-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}
.acc-badge-currency { background: #d1fae5; color: #065f46; }
.acc-badge-type { background: #e0e7ff; color: #3730a3; }

.acc-btn-icon {
    border: none;
    background: none;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: background .15s;
}
.acc-btn-icon:hover { background: #f3f4f6; }

.acc-check {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    color: #374151;
    cursor: pointer;
}
.acc-check input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #4f46e5;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var _init = <?= $initJson ?>;

    new Vue({
        el: '#accounts-app',

        data: function () {
            return {
                accounts: _init.accounts || [],
                pools: _init.pools || [],
                loading: false,
                saving: false,

                // Фильтры
                filterPool: '',
                searchQuery: '',

                // Форма
                form: this._emptyForm(),

                currencies: ['USD', 'EUR', 'RUB', 'GBP', 'CHF', 'CNY', 'JPY', 'TRY', 'AED', 'KZT'],
            };
        },

        computed: {
            filteredAccounts: function () {
                var self = this;
                var list = self.accounts;

                if (self.filterPool === '__none__') {
                    list = list.filter(function (a) { return !a.pool_id; });
                } else if (self.filterPool) {
                    var pid = parseInt(self.filterPool);
                    list = list.filter(function (a) { return a.pool_id === pid; });
                }

                if (self.searchQuery.trim()) {
                    var q = self.searchQuery.trim().toLowerCase();
                    list = list.filter(function (a) {
                        return a.name.toLowerCase().indexOf(q) !== -1;
                    });
                }

                return list;
            }
        },

        mounted: function () {
            this._initFilterPoolSelect2();
        },

        methods: {
            _initFilterPoolSelect2: function () {
                var self = this;
                var $el = $('#filter-pool-select2');

                var data = [{ id: '__none__', text: 'Без ностро-банка' }];
                self.pools.forEach(function (p) {
                    data.push({ id: String(p.id), text: p.name });
                });

                $el.select2({
                    theme:       'bootstrap-5',
                    placeholder: 'Все ностро-банки',
                    allowClear:  true,
                    data:        data,
                });

                $el.on('change', function () {
                    self.filterPool = $el.val() || '';
                });
            },

            _emptyForm: function () {
                return {
                    id: null, name: '', currency: '', pool_id: '',
                    account_type: '', country: '', is_suspense: false,
                    load_barsgl: false, load_status: 'L',
                    date_open: '', date_close: ''
                };
            },

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
                axios.get('<?= Url::to(['/account/list']) ?>').then(function (r) {
                    if (r.data.success) self.accounts = r.data.data;
                }).finally(function () { self.loading = false; });
            },

            // ── CRUD ────────────────────────────────────────
            _initPoolSelect2: function () {
                var self = this;
                var $el = $('#account-pool-select2');
                if ($el.data('select2')) $el.off('change.pool').select2('destroy');
                $el.empty();

                var poolData = self.pools.map(function (p) {
                    return { id: String(p.id), text: p.name };
                });

                $el.select2({
                    theme:          'bootstrap-5',
                    placeholder:    '— не привязан —',
                    allowClear:     true,
                    data:           poolData,
                    dropdownParent: $('#accountModal'),
                });

                if (self.form.pool_id) {
                    $el.val(String(self.form.pool_id)).trigger('change');
                } else {
                    $el.val(null).trigger('change');
                }

                $el.on('change.pool', function () {
                    self.form.pool_id = $el.val() || '';
                });
            },

            showCreateModal: function () {
                this.form = this._emptyForm();
                this._modal('accountModal', 'show');
                this.$nextTick(this._initPoolSelect2);
            },

            editAccount: function (acc) {
                this.form = {
                    id:           acc.id,
                    name:         acc.name,
                    currency:     acc.currency || '',
                    pool_id:      acc.pool_id || '',
                    account_type: acc.account_type || '',
                    country:      acc.country || '',
                    is_suspense:  acc.is_suspense,
                    load_barsgl:  acc.load_barsgl,
                    load_status:  acc.load_status || 'L',
                    date_open:    acc.date_open || '',
                    date_close:   acc.date_close || '',
                };
                this._modal('accountModal', 'show');
                this.$nextTick(this._initPoolSelect2);
            },

            saveAccount: function () {
                var self = this;
                if (!self.form.name.trim()) return;
                self.saving = true;

                var url = self.form.id
                    ? '<?= Url::to(['/account/update']) ?>'
                    : '<?= Url::to(['/account/create']) ?>';

                var payload = {
                    name:         self.form.name.trim(),
                    currency:     self.form.currency,
                    pool_id:      self.form.pool_id,
                    account_type: self.form.account_type,
                    country:      self.form.country,
                    is_suspense:  self.form.is_suspense ? '1' : '0',
                    load_barsgl:  self.form.load_barsgl ? '1' : '0',
                    load_status:  self.form.load_status,
                    date_open:    self.form.date_open,
                    date_close:   self.form.date_close,
                };
                if (self.form.id) payload.id = self.form.id;

                axios.post(url, payload).then(function (r) {
                    if (r.data.success) {
                        self._modal('accountModal', 'hide');
                        if (self.form.id) {
                            // Обновляем в списке
                            var idx = self.accounts.findIndex(function (a) { return a.id === self.form.id; });
                            if (idx !== -1) self.$set(self.accounts, idx, r.data.data);
                        } else {
                            self.accounts.push(r.data.data);
                        }
                        Swal.fire({ icon: 'success', title: r.data.message, timer: 1500, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Ошибка', text: r.data.message });
                    }
                }).catch(function () {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Сетевая ошибка' });
                }).finally(function () { self.saving = false; });
            },

            deleteAccount: function (acc) {
                var self = this;
                Swal.fire({
                    title: 'Удалить счёт?',
                    html: 'Счёт <b>' + acc.name + '</b> будет удалён безвозвратно.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Удалить',
                    cancelButtonText: 'Отмена',
                    confirmButtonColor: '#ef4444',
                }).then(function (r) {
                    if (!r.isConfirmed) return;
                    axios.post('<?= Url::to(['/account/delete']) ?>', { id: acc.id }).then(function (res) {
                        if (res.data.success) {
                            self.accounts = self.accounts.filter(function (a) { return a.id !== acc.id; });
                            Swal.fire({ icon: 'success', title: res.data.message, timer: 1500, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: res.data.message });
                        }
                    });
                });
            },
        }
    });

});
</script>
