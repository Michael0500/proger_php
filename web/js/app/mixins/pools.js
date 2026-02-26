/**
 * Mixin: Pools
 * Всё что касается AccountPool — выбор, загрузка данных, CRUD
 */
var PoolsMixin = {
    methods: {

        selectPool: function (pool, group) {
            this.selectedPool  = pool;
            this.selectedGroup = group;

            // ── ДОБАВИТЬ ──
            StateStorage.set('selectedPoolId',  pool  ? pool.id  : null);
            StateStorage.set('selectedGroupId', group ? group.id : null);
            // ─────────────

            this.loadEntries(true);
        },

        refreshAccounts: function () {
            if (this.selectedPool) this.loadEntries(true);
        },

        // Добавление
        showAddPoolModal: function (group) {
            this.newPool = { group_id: group.id, name: '', description: '', is_active: true };
            this._showModal('addPoolModal');
        },

        createPool: function () {
            var self = this;
            if (!self.newPool.name || !self.newPool.name.trim()) {
                Swal.fire('Ошибка', 'Название пула обязательно', 'error');
                return;
            }

            // ✅ Копируем данные и сериализуем filter_criteria
            var payload = Object.assign({}, self.newPool);
            payload.filter_criteria = JSON.stringify(payload.filter_criteria);

            SmartMatchApi.pools.create(self.newPool)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self.closeAddPoolModal();
                        self.loadGroups();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось создать пул', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось создать пул', 'error'); });
        },

        // Редактирование
        editPool: function (pool) {
            var formData = this._poolFormData(pool);
            // ✅ Обновляем через $set для сохранения реактивности
            Object.keys(formData).forEach(function (key) {
                this.$set(this.editingPool, key, formData[key]);
            }, this);
            this._showModal('editPoolModal');
        },

        updatePool: function () {
            var self = this;
            if (!self.editingPool.name || !self.editingPool.name.trim()) {
                Swal.fire('Ошибка', 'Название пула обязательно', 'error');
                return;
            }

            var payload = Object.assign({}, self.editingPool);
            payload.filter_criteria = JSON.stringify(payload.filter_criteria);

            SmartMatchApi.pools.update(self.editingPool)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self.closeEditPoolModal();
                        self.closeConfigurePoolModal();
                        self.loadGroups();
                        if (self.selectedPool && self.selectedPool.id === self.editingPool.id) {
                            self.loadEntries(true);
                        }
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось обновить пул', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось обновить пул', 'error'); });
        },

        // Удаление
        deletePool: function (pool) {
            var self = this;
            Swal.fire({
                title: 'Вы уверены?',
                text: 'Удалить пул "' + pool.name + '"?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Да, удалить',
                cancelButtonText: 'Отмена'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                SmartMatchApi.pools.delete(pool.id)
                    .then(function (response) {
                        if (response.data.success) {
                            Swal.fire('Удалено', response.data.message, 'success');
                            self.loadGroups();
                            if (self.selectedPool && self.selectedPool.id === pool.id) {
                                self.selectedPool = null;
                                self.entries      = [];
                                self.entriesTotal = 0;
                            }
                        } else {
                            Swal.fire('Ошибка', response.data.message, 'error');
                        }
                    })
                    .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить пул', 'error'); });
            });
        },
        
        filterValueHint: function (field) {
            var hints = {
                'currency':       'USD, EUR, RUB…',
                'account_type':   'NRE, INV…',
                'country':        'US, DE, RU…',
                'entry_currency': 'USD, EUR, RUB…',
            };
            return hints[field] || 'Значение…';
        },

        configurePool: function (pool) {
            var self = this;

            self.$set(self.editingPool, 'id',          pool.id);
            self.$set(self.editingPool, 'name',        pool.name);
            self.$set(self.editingPool, 'description', pool.description || '');
            self.$set(self.editingPool, 'is_active',   pool.is_active !== false);

            self.poolFilters        = [];
            self.poolFilterMeta     = {};
            self.poolFiltersLoading = true;

            self._showModal('configurePoolModal');

            SmartMatchApi.pools.getFilters(pool.id)
                .then(function (response) {
                    self.poolFiltersLoading = false;
                    if (!response.data.success) return;

                    self.poolFilterMeta = {
                        fieldGroups:  response.data.field_groups  || [],
                        operatorsMap: response.data.operators_map || {},
                        fieldOptions: response.data.field_options || {},
                        accounts:     response.data.accounts      || [],
                        dateFields:   response.data.date_fields   || [],
                        selectFields: response.data.select_fields || [],
                    };

                    if (response.data.data && response.data.data.length > 0) {
                        self.poolFilters = response.data.data.map(function (f, i) {
                            return self._hydrateFilter(f, i);
                        });
                    } else {
                        self.poolFilters = [self._emptyFilter(true)];
                    }

                    self.$nextTick(function () { self._initFilterAccountSelects(); });
                })
                .catch(function () {
                    self.poolFiltersLoading = false;
                    self.poolFilters = [self._emptyFilter(true)];
                });
        },

        addPoolFilter: function (logic) {
            this.poolFilters.push(this._emptyFilter(false, logic || 'AND'));
            this.$nextTick(function () { this._initFilterAccountSelects(); }.bind(this));
        },

        removePoolFilter: function (index) {
            this.poolFilters.splice(index, 1);
        },

        onPoolFilterFieldChange: function (index) {
            var self   = this;
            var filter = self.poolFilters[index];
            var ops    = (self.poolFilterMeta.operatorsMap || {})[filter.field] || { eq: 'равно' };
            self.$set(filter, 'operator', Object.keys(ops)[0]);
            self.$set(filter, 'value',    '');
            self.$set(filter, 'value2',   '');
            self.$nextTick(function () { self._initFilterAccountSelects(); });
        },

        onPoolFilterOperatorChange: function (index) {
            var filter = this.poolFilters[index];
            this.$set(filter, 'value',  '');
            this.$set(filter, 'value2', '');
        },

        poolFilterOperators: function (filter) {
            if (!this.poolFilterMeta || !this.poolFilterMeta.operatorsMap) {
                return { eq: 'равно', neq: 'не равно' };
            }
            return this.poolFilterMeta.operatorsMap[filter.field] || { eq: 'равно', neq: 'не равно' };
        },

        isDateFilterField: function (filter) {
            return (this.poolFilterMeta.dateFields || []).indexOf(filter.field) !== -1;
        },

        isSelectFilterField: function (filter) {
            return (this.poolFilterMeta.selectFields || []).indexOf(filter.field) !== -1;
        },

        poolFilterFieldOptions: function (filter) {
            return (this.poolFilterMeta.fieldOptions || {})[filter.field] || {};
        },

        savePoolFilters: function () {
            var self = this;

            for (var i = 0; i < self.poolFilters.length; i++) {
                var f = self.poolFilters[i];
                if (!f.field) {
                    Swal.fire('Ошибка', 'Выберите поле для условия #' + (i + 1), 'error');
                    return;
                }
                if (f.operator === 'between') {
                    if (!f.value || !f.value2) {
                        Swal.fire('Ошибка', 'Укажите обе даты диапазона для условия #' + (i + 1), 'error');
                        return;
                    }
                } else if (f.value === '' || f.value === null || f.value === undefined) {
                    Swal.fire('Ошибка', 'Введите значение для условия #' + (i + 1), 'error');
                    return;
                }
            }

            var filtersToSend = self.poolFilters.map(function (f) {
                return {
                    field:    f.field,
                    operator: f.operator,
                    value:    f.operator === 'between' ? (f.value + '|' + f.value2) : f.value,
                    logic:    f.logic,
                };
            });

            SmartMatchApi.pools.saveFilters({
                pool_id: self.editingPool.id,
                filters: JSON.stringify(filtersToSend),
            })
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Сохранено', response.data.message, 'success');
                        self.closeConfigurePoolModal();
                        self.loadGroups();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Ошибка сохранения', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось сохранить фильтры', 'error'); });
        },

        _initFilterAccountSelects: function () {
            var self = this;
            self.poolFilters.forEach(function (filter, index) {
                if (filter.field !== 'account_id') return;
                var $el = $('#pool-filter-account-' + index);
                if (!$el.length || $el.data('select2')) return;

                var accountData = (self.poolFilterMeta.accounts || []).map(function (a) {
                    return {
                        id:   String(a.id),
                        text: a.name + (a.currency ? ' (' + a.currency + ')' : ''),
                    };
                });

                $el.select2({
                    theme:       'bootstrap-5',
                    placeholder: 'Выберите счёт...',
                    allowClear:  true,
                    data:        accountData,
                });

                if (filter.value) {
                    $el.val(String(filter.value)).trigger('change');
                }

                $el.on('change.poolfilter', function () {
                    self.$set(self.poolFilters[index], 'value', $el.val() || '');
                });
            });
        },

        _hydrateFilter: function (f) {
            var value  = f.value || '';
            var value2 = '';
            if (f.operator === 'between') {
                var parts = value.split('|');
                value  = parts[0] || '';
                value2 = parts[1] || '';
            }
            return {
                id: f.id || null, field: f.field, operator: f.operator,
                value: value, value2: value2, logic: f.logic || 'AND',
            };
        },

        _emptyFilter: function (isFirst, logic) {
            return { id: null, field: '', operator: 'eq', value: '', value2: '', logic: isFirst ? 'AND' : (logic || 'AND') };
        },

        _poolFormData: function (pool) {
            return { id: pool.id, name: pool.name, description: pool.description || '', is_active: pool.is_active !== false };
        },
    }
};