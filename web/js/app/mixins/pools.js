/**
 * Mixin: Pools
 * Выбор ностро-банка в сайдбаре выверки + быстрый CRUD из сайдбара:
 * добавление в категорию, перемещение между категориями, удаление, открепление.
 */
var PoolsMixin = {
    data: function () {
        return {
            // Форма быстрого создания ностро-банка из сайдбара
            newPool: {
                name:          '',
                description:   '',
                category_id:   null,
                category_name: '',
            },

            // Привязка счетов в модалке быстрого создания
            loadingPoolAccounts:        false,
            availableLedgerAccounts:    [],
            availableStatementAccounts: [],
            selectedLedgerAccounts:     [],
            selectedStatementAccounts:  [],

            // Перемещение ностро-банка
            movingPool: {
                id:                  null,
                name:                '',
                from_category_id:    null,
                from_category_name:  '',
                target_category_id:  '',
            },
        };
    },

    methods: {

        selectPool: function (pool, category) {
            this.selectedPool     = pool;
            this.selectedCategory = category;

            StateStorage.set('selectedPoolId',     pool     ? pool.id     : null);
            StateStorage.set('selectedCategoryId', category ? category.id : null);

            this.loadEntries(true);
        },

        // ── Быстрое создание из сайдбара ─────────────────────────
        showAddPoolModal: function (category) {
            var self = this;
            self.newPool = {
                name:          '',
                description:   '',
                category_id:   category ? category.id   : null,
                category_name: category ? category.name : '',
            };
            self.selectedLedgerAccounts    = [];
            self.selectedStatementAccounts = [];
            self.availableLedgerAccounts    = [];
            self.availableStatementAccounts = [];
            self._showModal('addPoolModal');
            self.$nextTick(function () {
                if (self.$refs.addPoolNameInput) self.$refs.addPoolNameInput.focus();
            });

            // Загружаем свободные счета и инициализируем select2
            self.loadingPoolAccounts = true;
            SmartMatchApi.get(window.AppRoutes.accountPoolAvailableAccounts).then(function (r) {
                var payload  = (r && r.data) ? r.data : {};
                var accounts = payload.success ? (payload.data || []) : [];
                self.availableLedgerAccounts    = accounts.filter(function (a) { return a.account_type === 'L'; });
                self.availableStatementAccounts = accounts.filter(function (a) { return a.account_type === 'S'; });
            }).catch(function () {
                self.availableLedgerAccounts    = [];
                self.availableStatementAccounts = [];
            }).then(function () {
                self.loadingPoolAccounts = false;
                self.$nextTick(function () { self._initAddPoolAccountSelects(); });
            });
        },

        _initAddPoolAccountSelects: function () {
            var self = this;
            if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) return;

            var $l = $('#add-pool-ledger-select2');
            if ($l.length) {
                if ($l.data('select2')) $l.off('change.addPoolL').select2('destroy');
                $l.empty().select2({
                    theme: 'bootstrap-5',
                    placeholder: '— Выберите Ledger счета —',
                    allowClear: true,
                    multiple: true,
                    data: self.availableLedgerAccounts.map(function (a) {
                        return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
                    }),
                    dropdownParent: $('#addPoolModal'),
                }).val(null).trigger('change');
                $l.on('change.addPoolL', function () {
                    self.selectedLedgerAccounts = $l.val() || [];
                });
            }

            var $s = $('#add-pool-statement-select2');
            if ($s.length) {
                if ($s.data('select2')) $s.off('change.addPoolS').select2('destroy');
                $s.empty().select2({
                    theme: 'bootstrap-5',
                    placeholder: '— Выберите Statement счета —',
                    allowClear: true,
                    multiple: true,
                    data: self.availableStatementAccounts.map(function (a) {
                        return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
                    }),
                    dropdownParent: $('#addPoolModal'),
                }).val(null).trigger('change');
                $s.on('change.addPoolS', function () {
                    self.selectedStatementAccounts = $s.val() || [];
                });
            }
        },

        createPoolFromSidebar: function () {
            var self = this;
            var name = (self.newPool.name || '').trim();
            if (!name) {
                Swal.fire({ icon: 'warning', title: 'Введите название', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            SmartMatchApi.post(window.AppRoutes.accountPoolQuickCreate, {
                name:               name,
                description:        self.newPool.description || '',
                category_id:        self.newPool.category_id || '',
                ledger_accounts:    self.selectedLedgerAccounts,
                statement_accounts: self.selectedStatementAccounts,
            }).then(function (r) {
                if (r.success) {
                    self._hideModal('addPoolModal');
                    Swal.fire({ icon: 'success', title: r.message, toast: true,
                        position: 'top-end', timer: 1800, showConfirmButton: false });
                    self.loadCategories();
                    self.loadAccountPools && self.loadAccountPools();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message || 'Не удалось создать' });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Сетевая ошибка' });
            });
        },

        // ── Перемещение между категориями ────────────────────────
        showMovePoolModal: function (pool, fromCategory) {
            this.movingPool = {
                id:                 pool.id,
                name:               pool.name,
                from_category_id:   fromCategory ? fromCategory.id   : null,
                from_category_name: fromCategory ? fromCategory.name : '',
                target_category_id: fromCategory ? fromCategory.id   : '',
            };
            this._showModal('movePoolModal');
        },

        confirmMovePool: function () {
            var self     = this;
            var poolId   = self.movingPool.id;
            var targetId = self.movingPool.target_category_id;

            // Если категория не изменилась — просто закрываем
            if (String(targetId || '') === String(self.movingPool.from_category_id || '')) {
                self._hideModal('movePoolModal');
                return;
            }

            SmartMatchApi.post(window.AppRoutes.accountPoolMoveToCategory, {
                id:          poolId,
                category_id: targetId === '' || targetId === null ? '' : targetId,
            }).then(function (r) {
                if (r.success) {
                    self._hideModal('movePoolModal');
                    Swal.fire({ icon: 'success', title: r.message, toast: true,
                        position: 'top-end', timer: 1800, showConfirmButton: false });
                    self.loadCategories();
                    self.loadAccountPools && self.loadAccountPools();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Сетевая ошибка' });
            });
        },

        // ── Открепление от категории (без удаления) ──────────────
        detachPoolFromCategory: function (pool, fromCategory) {
            var self = this;
            Swal.fire({
                title: 'Открепить ностро-банк?',
                html: '<b>' + pool.name + '</b> будет откреплён от категории «' +
                    (fromCategory ? fromCategory.name : '—') +
                    '». Сам банк и его счета останутся, но он исчезнет из сайдбара.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Открепить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#6366f1',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(window.AppRoutes.accountPoolMoveToCategory, {
                    id:          pool.id,
                    category_id: '',
                }).then(function (r) {
                    if (r.success) {
                        Swal.fire({ icon: 'success', title: r.message, toast: true,
                            position: 'top-end', timer: 1800, showConfirmButton: false });
                        if (self.selectedPool && self.selectedPool.id === pool.id) {
                            self.selectedPool = null;
                        }
                        self.loadCategories();
                    } else {
                        Swal.fire({ icon: 'error', title: r.message });
                    }
                });
            });
        },

        // ── Удаление ностро-банка ────────────────────────────────
        deletePool: function (pool) {
            var self = this;
            Swal.fire({
                title: 'Удалить ностро-банк?',
                html: '<b>' + pool.name + '</b> и все его записи выверки будут удалены безвозвратно. Привязанные счета будут отвязаны.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Да, удалить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#ef4444',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(window.AppRoutes.accountPoolDelete, { id: pool.id })
                    .then(function (r) {
                        if (r.success) {
                            Swal.fire({ icon: 'success', title: r.message, toast: true,
                                position: 'top-end', timer: 1800, showConfirmButton: false });
                            if (self.selectedPool && self.selectedPool.id === pool.id) {
                                self.selectedPool = null;
                                self.entries     = [];
                                self.entriesTotal = 0;
                            }
                            self.loadCategories();
                            self.loadAccountPools && self.loadAccountPools();
                        } else {
                            Swal.fire({ icon: 'error', title: r.message });
                        }
                    });
            });
        },
    }
};
