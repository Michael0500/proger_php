/**
 * balance.js — Mixin для раздела баланса Ностро счетов
 * Уведомления через Swal.fire (SweetAlert2) — как во всём проекте
 * Секция (NRE/INV) автоматически берётся из компании пользователя (AppConfig.companySection)
 */
var BalanceMixin = {
    data: function () {
        // Секция текущего пользователя — NRE или INV
        var section = (window.AppConfig && window.AppConfig.companySection) || 'NRE';

        return {
            balances:            [],
            balancesTotal:       0,
            balancesPage:        1,
            balancesLimit:       50,
            balancesLoading:     false,
            balancesLoadingMore: false,

            balanceSortCol: 'value_date',
            balanceSortDir: 'desc',

            // Фильтр section предустановлен по компании пользователя
            balanceFilters:     section ? { section: section } : {},
            balanceFiltersOpen: false,

            balanceAccounts: [],
            balancePools:    [],
            balancePoolId:   '',  // фильтр по ностро-банку (Select2)

            editingBalance: {
                id: null, account_id: null, account_name: '',
                ls_type: '', statement_number: '', currency: 'RUB',
                value_date: '', opening_balance: '', opening_dc: 'C',
                closing_balance: '', closing_dc: 'C',
                section:  section,   // ← дефолт из компании
                source:  'MANUAL', comment: '', status: 'normal',
            },
            editingBalancePoolId: null, // выбранный банк в форме (не сохраняется, только для фильтра счетов)
            balanceModalOpen: false,
            balanceSaving:    false,

            confirmingBalance: null,
            confirmReason:     '',
            confirmModalOpen:  false,
            confirmSaving:     false,

            historyBalance:     null,
            historyLogs:        [],
            historyModalOpen:   false,
            historyLoading:     false,

            importModalOpen:    false,
            importType:         'bnd',
            importAccountId:    null,
            importSection:      section,  // ← дефолт из компании
            importFile:         null,
            importLoading:      false,
            importResult:       null,

            _balanceDebounceTimer: null,
        };
    },

    watch: {},

    computed: {
        hasMoreBalances: function () {
            return this.balances.length < this.balancesTotal;
        },

        // Секция пользователя для удобного доступа из шаблона
        userSection: function () {
            return (window.AppConfig && window.AppConfig.companySection) || '';
        },
    },

    methods: {

        // ── Уведомление ───────────────────────────────────────────
        _balanceNotify: function (message, type) {
            Swal.fire({
                toast:             true,
                position:          'top-end',
                icon:              type || 'info',
                title:             message,
                showConfirmButton: false,
                timer:             3000,
                timerProgressBar:  true,
            });
        },

        // ── Загрузка ──────────────────────────────────────────────
        loadBalances: function (reset) {
            if (reset) {
                this.balancesPage = 1;
                this.balances     = [];
            }
            if (this.balancesLoading || this.balancesLoadingMore) return;

            var isFirstPage = (this.balancesPage === 1);
            if (isFirstPage) this.balancesLoading    = true;
            else             this.balancesLoadingMore = true;

            var self = this;

            // Собираем итоговые фильтры
            var filters = Object.assign({}, self.balanceFilters);

            // Фильтр по ностро-банку: из Select2 (если задан вручную)
            if (self.balancePoolId) filters.pool_id = self.balancePoolId;

            SmartMatchApi.get(AppRoutes.balanceList, {
                page:    self.balancesPage,
                limit:   self.balancesLimit,
                sort:    self.balanceSortCol,
                dir:     self.balanceSortDir,
                filters: JSON.stringify(filters),
            }).then(function (r) {
                var d = r.data;
                if (!d.success) {
                    self._balanceNotify(d.message || 'Ошибка загрузки', 'error');
                    return;
                }
                self.balancesTotal = d.total;
                if (isFirstPage) self.balances = d.data;
                else             self.balances = self.balances.concat(d.data);
            }).finally(function () {
                self.balancesLoading    = false;
                self.balancesLoadingMore = false;
            });
        },

        loadMoreBalances: function () {
            if (!this.hasMoreBalances) return;
            this.balancesPage++;
            this.loadBalances(false);
        },

        loadBalanceAccounts: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.balanceAccounts).then(function (r) {
                if (r.data && r.data.success) {
                    self.balanceAccounts = r.data.data;
                    if (r.data.pools) {
                        self.balancePools = r.data.pools;
                        self.$nextTick(function () { self.initBalancePoolSelect(); });
                    }
                }
            });
        },

        onBalanceFilterChange: function () {
            clearTimeout(this._balanceDebounceTimer);
            var self = this;
            this._balanceDebounceTimer = setTimeout(function () {
                self.loadBalances(true);
            }, 350);
        },

        onBalancePoolChange: function () {
            this.loadBalances(true);
        },

        // ── Сортировка ────────────────────────────────────────────
        sortBalance: function (col) {
            if (this.balanceSortCol === col) {
                this.balanceSortDir = (this.balanceSortDir === 'asc') ? 'desc' : 'asc';
            } else {
                this.balanceSortCol = col;
                this.balanceSortDir = 'desc';
            }
            this.loadBalances(true);
        },

        // ── CRUD форма ────────────────────────────────────────────
        openCreateBalanceModal: function () {
            var section = (window.AppConfig && window.AppConfig.companySection) || 'NRE';
            this.editingBalance = {
                id: null, account_id: null, account_name: '',
                ls_type: '', statement_number: '', currency: 'RUB',
                value_date: '', opening_balance: '', opening_dc: 'C',
                closing_balance: '', closing_dc: 'C',
                section:  section,
                source:  'MANUAL', comment: '', status: 'normal',
            };
            this.editingBalancePoolId = null;
            this.balanceModalOpen = true;
            this.$nextTick(function () { this.initBalanceFormSelects(); }.bind(this));
        },

        openEditBalanceModal: function (row) {
            this.editingBalance = Object.assign({}, row, {
                value_date:      row.value_date ? row.value_date.substring(0, 10) : '',
                opening_balance: row.opening_balance,
                closing_balance: row.closing_balance,
            });
            // Определяем банк по pool_id счёта
            var acc = (this.balanceAccounts || []).find(function (a) {
                return String(a.id) === String(row.account_id);
            });
            this.editingBalancePoolId = acc && acc.pool_id ? String(acc.pool_id) : null;
            this.balanceModalOpen = true;
            this.$nextTick(function () { this.initBalanceFormSelects(); }.bind(this));
        },

        closeBalanceModal: function () {
            var self = this;
            Swal.fire({
                title: 'Отменить изменения?',
                text: 'Введённые данные будут потеряны.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Да, отменить',
                cancelButtonText: 'Нет, продолжить',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) self.balanceModalOpen = false;
            });
        },

        /** Инициализирует оба Select2 в форме баланса */
        initBalanceFormSelects: function () {
            this.initBalanceFormPoolSelect2();
            this.initBalanceFormAccountSelect2(this.editingBalancePoolId);
        },

        initBalanceFormPoolSelect2: function () {
            var self = this;
            var $el  = $('#balance-form-pool-select2');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
            }

            var poolData = (self.balancePools || []).map(function (p) {
                return { id: String(p.id), text: p.name };
            });

            $el.select2({
                dropdownParent: $(document.body),
                theme:          'bootstrap-5',
                placeholder:    'Выберите банк...',
                allowClear:     true,
                data:           poolData,
                language: { noResults: function () { return 'Нет ностробанков'; } }
            });

            // Явно сбрасываем / устанавливаем значение после инита
            if (self.editingBalancePoolId) {
                $el.val(String(self.editingBalancePoolId)).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.on('select2:select', function (e) {
                self.editingBalancePoolId = e.params.data.id;
                self.editingBalance.account_id   = null;
                self.editingBalance.account_name = '';
                self.initBalanceFormAccountSelect2(self.editingBalancePoolId);
            });
            $el.on('select2:clear', function () {
                self.editingBalancePoolId = null;
                self.editingBalance.account_id   = null;
                self.editingBalance.account_name = '';
                self.initBalanceFormAccountSelect2(null);
            });
        },

        initBalanceFormAccountSelect2: function (poolId) {
            var self = this;
            var $el  = $('#balance-form-account-select2');
            if (!$el.length) return;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
                $el.empty();
            }

            var accounts = (self.balanceAccounts || []).filter(function (a) {
                return !poolId || String(a.pool_id) === String(poolId);
            });

            var accData = accounts.map(function (a) {
                return { id: String(a.id), text: a.name };
            });

            $el.select2({
                dropdownParent: $(document.body),
                theme:          'bootstrap-5',
                placeholder:    poolId ? 'Выберите счёт...' : 'Сначала выберите банк...',
                allowClear:     true,
                data:           accData,
                language: { noResults: function () { return 'Нет счетов'; } }
            });

            // Явно сбрасываем / устанавливаем значение после инита
            if (self.editingBalance.account_id) {
                $el.val(String(self.editingBalance.account_id)).trigger('change.select2');
            } else {
                $el.val(null).trigger('change.select2');
            }

            $el.on('select2:select', function (e) {
                var found = (self.balanceAccounts || []).find(function (a) {
                    return String(a.id) === String(e.params.data.id);
                });
                self.editingBalance.account_id   = parseInt(e.params.data.id);
                self.editingBalance.account_name = found ? found.name : e.params.data.text;
            });
            $el.on('select2:clear', function () {
                self.editingBalance.account_id   = null;
                self.editingBalance.account_name = '';
            });
        },

        saveBalance: function () {
            var self = this;
            if (self.balanceSaving) return;
            if (!self.editingBalance.ls_type) {
                self._balanceNotify('Выберите тип (L или S)', 'warning'); return;
            }
            if (!self.editingBalance.account_id) {
                self._balanceNotify('Выберите счёт', 'warning'); return;
            }
            if (!self.editingBalance.value_date) {
                self._balanceNotify('Укажите дату валютирования', 'warning'); return;
            }
            if (self.editingBalance.ls_type === 'S' && !self.editingBalance.statement_number) {
                self._balanceNotify('Укажите номер выписки', 'warning'); return;
            }
            self.balanceSaving = true;

            var url = self.editingBalance.id ? AppRoutes.balanceUpdate : AppRoutes.balanceCreate;
            SmartMatchApi.post(url, self.editingBalance).then(function (d) {
                if (!d.success) {
                    Swal.fire('Ошибка', d.message || 'Не удалось сохранить', 'error');
                    return;
                }
                self._balanceNotify(d.message || 'Сохранено', 'success');
                self.balanceModalOpen = false;
                self.loadBalances(true);
            }).catch(function () {
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            }).finally(function () {
                self.balanceSaving = false;
            });
        },

        deleteBalance: function (row) {
            var self = this;
            Swal.fire({
                title: 'Удалить запись?',
                text:  (row.account_name || '') + ' · ' + (row.currency || '') + ' · ' + (row.value_date_fmt || row.value_date || ''),
                icon:  'warning',
                showCancelButton:   true,
                confirmButtonColor: '#d33',
                cancelButtonColor:  '#6b7280',
                confirmButtonText:  'Да, удалить',
                cancelButtonText:   'Отмена',
            }).then(function (result) {
                if (!result.isConfirmed) return;
                SmartMatchApi.post(AppRoutes.balanceDelete, { id: row.id }).then(function (d) {
                    if (d.success) {
                        self._balanceNotify('Запись удалена', 'success');
                        self.loadBalances(true);
                    } else {
                        Swal.fire('Ошибка', d.message || 'Не удалось удалить', 'error');
                    }
                });
            });
        },

        // ── Подтверждение ошибки ──────────────────────────────────
        openConfirmModal: function (row) {
            this.confirmingBalance = row;
            this.confirmReason     = '';
            this.confirmModalOpen  = true;
        },

        closeConfirmModal: function () {
            this.confirmModalOpen  = false;
            this.confirmingBalance = null;
        },

        submitConfirm: function () {
            var self = this;
            if (!self.confirmReason.trim()) {
                self._balanceNotify('Укажите причину корректировки', 'warning'); return;
            }
            if (self.confirmSaving) return;
            self.confirmSaving = true;

            SmartMatchApi.post(AppRoutes.balanceConfirm, {
                id:     self.confirmingBalance.id,
                reason: self.confirmReason,
            }).then(function (d) {
                if (!d.success) {
                    Swal.fire('Ошибка', d.message || 'Не удалось подтвердить', 'error');
                    return;
                }
                self._balanceNotify('Запись подтверждена ⚫', 'success');
                self.confirmModalOpen = false;
                self.loadBalances(true);
            }).catch(function () {
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            }).finally(function () {
                self.confirmSaving = false;
            });
        },

        // ── История изменений ─────────────────────────────────────
        openHistoryModal: function (row) {
            this.historyBalance   = row;
            this.historyLogs      = [];
            this.historyModalOpen = true;
            this.loadHistory(row.id);
        },

        closeHistoryModal: function () {
            this.historyModalOpen = false;
        },

        loadHistory: function (id) {
            var self = this;
            self.historyLoading = true;
            SmartMatchApi.get(AppRoutes.balanceHistory, { id: id }).then(function (r) {
                if (r.data && r.data.success) self.historyLogs = r.data.data;
            }).finally(function () {
                self.historyLoading = false;
            });
        },

        // ── Импорт ────────────────────────────────────────────────
        openImportModal: function (type) {
            var section = (window.AppConfig && window.AppConfig.companySection) || 'NRE';
            this.importType      = type || 'bnd';
            this.importAccountId = null;
            this.importSection   = section;   // ← дефолт из компании
            this.importFile      = null;
            this.importResult    = null;
            this.importModalOpen = true;
        },

        closeImportModal: function () {
            this.importModalOpen = false;
        },

        onImportFileChange: function (e) {
            this.importFile = e.target.files[0] || null;
        },

        submitImport: function () {
            var self = this;

            var accountId = parseInt(self.importAccountId, 10);
            if (!accountId || isNaN(accountId)) {
                self._balanceNotify('Выберите счёт', 'warning');
                return;
            }
            if (!self.importFile) {
                self._balanceNotify('Выберите файл', 'warning');
                return;
            }
            if (self.importLoading) return;

            self.importLoading = true;
            self.importResult  = null;

            var fd = new FormData();
            fd.append('file',       self.importFile);
            fd.append('account_id', accountId);
            fd.append('section',    self.importSection);

            var url = (self.importType === 'asb')
                ? AppRoutes.balanceImportAsb
                : AppRoutes.balanceImportBnd;

            axios.post(url, fd, {
                transformRequest: [function (data) { return data; }],
                headers: { 'Content-Type': undefined },
            }).then(function (r) {
                self.importResult = r.data;
                if (r.data.success) {
                    self._balanceNotify(r.data.message, r.data.errors > 0 ? 'warning' : 'success');
                    self.loadBalances(true);
                } else {
                    Swal.fire('Ошибка импорта', r.data.message || 'Неизвестная ошибка', 'error');
                }
            }).catch(function (err) {
                console.error('Import error:', err);
                Swal.fire('Ошибка', 'Не удалось загрузить файл', 'error');
            }).finally(function () {
                self.importLoading = false;
            });
        },

        // ── Хелперы ───────────────────────────────────────────────
        balanceStatusIcon: function (status) {
            if (status === 'error')     return '🔴';
            if (status === 'confirmed') return '⚫';
            return '⚪';
        },

        formatBalanceAmount: function (amount) {
            if (amount === null || amount === undefined || amount === '') return '—';
            return parseFloat(amount).toLocaleString('ru-RU', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        initBalancePoolSelect: function () {
            var self = this;
            var $el = jQuery('#balancePoolSelect');
            if (!$el.length) return;

            // Заполняем options
            $el.find('option:gt(0)').remove();
            self.balancePools.forEach(function (p) {
                $el.append(new Option(p.name, p.id, false, false));
            });

            $el.select2({
                theme: 'bootstrap-5',
                allowClear: true,
                placeholder: '— Все ностро-банки —',
                width: '300px',
            });

            // Установить текущее значение
            if (self.balancePoolId) {
                $el.val(self.balancePoolId).trigger('change.select2');
            }

            $el.off('change.balancePool').on('change.balancePool', function () {
                self.balancePoolId = jQuery(this).val() || '';
                self.onBalancePoolChange();
            });
        },

        initBalanceInfiniteScroll: function () {
            var self  = this;
            var table = document.querySelector('.balance-table-wrap');
            if (!table) return;
            table.addEventListener('scroll', function () {
                if (table.scrollTop + table.clientHeight >= table.scrollHeight - 100) {
                    self.loadMoreBalances();
                }
            });
        },
    },
};