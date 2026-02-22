/**
 * balance.js — Mixin для раздела баланса Ностро счетов
 * Работает по тем же паттернам что entries.js (infinite scroll, Select2, debounce)
 */
var BalanceMixin = {
    data: function () {
        return {
            // ── Таблица ───────────────────────────────────────────
            balances:            [],
            balancesTotal:       0,
            balancesPage:        1,
            balancesLimit:       50,
            balancesLoading:     false,
            balancesLoadingMore: false,

            // Сортировка
            balanceSortCol: 'value_date',
            balanceSortDir: 'desc',

            // Фильтры
            balanceFilters:     {},
            balanceFiltersOpen: false,

            // Аккаунты для dropdown
            balanceAccounts: [],

            // ── Форма записи ──────────────────────────────────────
            editingBalance: {
                id:               null,
                account_id:       null,
                account_name:     '',
                ls_type:          'S',
                statement_number: '',
                currency:         'RUB',
                value_date:       '',
                opening_balance:  '',
                opening_dc:       'C',
                closing_balance:  '',
                closing_dc:       'C',
                section:          'NRE',
                source:           'MANUAL',
                comment:          '',
                status:           'normal',
            },
            balanceModalOpen:   false,
            balanceSaving:      false,

            // ── Подтверждение ошибки ──────────────────────────────
            confirmingBalance:  null,
            confirmReason:      '',
            confirmModalOpen:   false,
            confirmSaving:      false,

            // ── История изменений ─────────────────────────────────
            historyBalance:     null,
            historyLogs:        [],
            historyModalOpen:   false,
            historyLoading:     false,

            // ── Импорт ────────────────────────────────────────────
            importModalOpen:    false,
            importType:         'bnd', // bnd | asb
            importAccountId:    null,
            importSection:      'NRE',
            importFile:         null,
            importLoading:      false,
            importResult:       null,

            // debounce
            _balanceDebounceTimer: null,
        };
    },

    computed: {
        hasMoreBalances: function () {
            return this.balances.length < this.balancesTotal;
        },
    },

    methods: {

        // ── Загрузка данных ───────────────────────────────────────

        loadBalances: function (reset) {
            if (reset) {
                this.balancesPage = 1;
                this.balances     = [];
            }
            if (this.balancesLoading || this.balancesLoadingMore) return;

            var isFirstPage = this.balancesPage === 1;
            if (isFirstPage) this.balancesLoading     = true;
            else             this.balancesLoadingMore  = true;

            var self = this;
            SmartMatchApi.get(AppRoutes.balanceList, {
                page:    self.balancesPage,
                limit:   self.balancesLimit,
                sort:    self.balanceSortCol,
                dir:     self.balanceSortDir,
                filters: JSON.stringify(self.balanceFilters),
            }).then(function (r) {
                var d = r.data;
                if (!d.success) { self.showToast(d.message || 'Ошибка', 'error'); return; }

                self.balancesTotal = d.total;
                if (isFirstPage) self.balances = d.data;
                else             self.balances = self.balances.concat(d.data);
            }).finally(function () {
                self.balancesLoading     = false;
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
                if (r.data.success) self.balanceAccounts = r.data.data;
            });
        },

        onBalanceFilterChange: function () {
            clearTimeout(this._balanceDebounceTimer);
            var self = this;
            this._balanceDebounceTimer = setTimeout(function () {
                self.loadBalances(true);
            }, 350);
        },

        // ── Сортировка ────────────────────────────────────────────

        sortBalance: function (col) {
            if (this.balanceSortCol === col) {
                this.balanceSortDir = this.balanceSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.balanceSortCol = col;
                this.balanceSortDir = 'desc';
            }
            this.loadBalances(true);
        },

        // ── Форма создания / редактирования ───────────────────────

        openCreateBalanceModal: function () {
            this.editingBalance = {
                id: null, account_id: null, account_name: '',
                ls_type: 'S', statement_number: '', currency: 'RUB',
                value_date: '', opening_balance: '', opening_dc: 'C',
                closing_balance: '', closing_dc: 'C',
                section: 'NRE', source: 'MANUAL', comment: '', status: 'normal',
            };
            this.balanceModalOpen = true;
        },

        openEditBalanceModal: function (row) {
            this.editingBalance = Object.assign({}, row, {
                value_date:      row.value_date ? row.value_date.substring(0, 10) : '',
                opening_balance: row.opening_balance,
                closing_balance: row.closing_balance,
            });
            this.balanceModalOpen = true;
        },

        closeBalanceModal: function () {
            this.balanceModalOpen = false;
        },

        saveBalance: function () {
            var self = this;
            if (self.balanceSaving) return;
            self.balanceSaving = true;

            var isEdit = !!self.editingBalance.id;
            var url    = isEdit ? AppRoutes.balanceUpdate : AppRoutes.balanceCreate;

            SmartMatchApi.post(url, self.editingBalance).then(function (d) {
                if (!d.success) {
                    self.showToast(d.message || 'Ошибка', 'error');
                    return;
                }
                self.showToast(d.message || 'Сохранено', 'success');
                self.balanceModalOpen = false;
                self.loadBalances(true);
            }).finally(function () { self.balanceSaving = false; });
        },

        deleteBalance: function (row) {
            if (!confirm('Удалить запись баланса?')) return;
            var self = this;
            SmartMatchApi.post(AppRoutes.balanceDelete, { id: row.id }).then(function (d) {
                if (d.success) {
                    self.showToast('Удалено', 'success');
                    self.loadBalances(true);
                } else {
                    self.showToast(d.message || 'Ошибка', 'error');
                }
            });
        },

        // ── Подтверждение ошибки ──────────────────────────────────

        openConfirmModal: function (row) {
            this.confirmingBalance = row;
            this.confirmReason     = '';
            this.confirmModalOpen  = true;
        },

        closeConfirmModal: function () {
            this.confirmModalOpen = false;
            this.confirmingBalance = null;
        },

        submitConfirm: function () {
            var self = this;
            if (!self.confirmReason.trim()) {
                self.showToast('Укажите причину корректировки', 'warning');
                return;
            }
            if (self.confirmSaving) return;
            self.confirmSaving = true;

            SmartMatchApi.post(AppRoutes.balanceConfirm, {
                id:     self.confirmingBalance.id,
                reason: self.confirmReason,
            }).then(function (d) {
                if (!d.success) { self.showToast(d.message || 'Ошибка', 'error'); return; }
                self.showToast('Запись подтверждена ⚫', 'success');
                self.confirmModalOpen = false;
                self.loadBalances(true);
            }).finally(function () { self.confirmSaving = false; });
        },

        // ── История изменений ─────────────────────────────────────

        openHistoryModal: function (row) {
            this.historyBalance  = row;
            this.historyLogs     = [];
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
                if (r.data.success) self.historyLogs = r.data.data;
            }).finally(function () { self.historyLoading = false; });
        },

        // ── Импорт файлов ─────────────────────────────────────────

        openImportModal: function (type) {
            this.importType      = type || 'bnd';
            this.importAccountId = null;
            this.importSection   = 'NRE';
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
            if (!self.importFile)      { self.showToast('Выберите файл',  'warning'); return; }
            if (!self.importAccountId) { self.showToast('Выберите счёт', 'warning'); return; }
            if (self.importLoading) return;

            self.importLoading = true;
            self.importResult  = null;

            var fd = new FormData();
            fd.append('file',       self.importFile);
            fd.append('account_id', self.importAccountId);
            fd.append('section',    self.importSection);

            var url = self.importType === 'asb'
                ? AppRoutes.balanceImportAsb
                : AppRoutes.balanceImportBnd;

            axios.post(url, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            }).then(function (r) {
                self.importResult = r.data;
                if (r.data.success) {
                    self.showToast(r.data.message, r.data.errors > 0 ? 'warning' : 'success');
                    self.loadBalances(true);
                } else {
                    self.showToast(r.data.message || 'Ошибка импорта', 'error');
                }
            }).catch(function () {
                self.showToast('Ошибка при загрузке файла', 'error');
            }).finally(function () {
                self.importLoading = false;
            });
        },

        // ── Хелперы ───────────────────────────────────────────────

        balanceStatusClass: function (status) {
            return {
                'status-normal':    status === 'normal',
                'status-error':     status === 'error',
                'status-confirmed': status === 'confirmed',
            };
        },

        balanceStatusIcon: function (status) {
            if (status === 'error')     return '🔴';
            if (status === 'confirmed') return '⚫';
            return '⚪';
        },

        formatBalanceAmount: function (amount, dc) {
            if (amount === null || amount === undefined) return '—';
            var sign = dc === 'D' ? '-' : '';
            return sign + parseFloat(amount).toLocaleString('ru-RU', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
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