/**
 * Mixin: Pools
 * Всё что касается AccountPool — выбор, загрузка данных, CRUD
 */
var PoolsMixin = {
    methods: {

        selectPool: function (pool, group) {
            this.selectedPool  = pool;
            this.selectedGroup = group;
            // Загружаем записи через EntriesMixin (таблица entries)
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
        // Открываем модалку конфигурации фильтров
        configurePool: function (pool) {
            var self = this;

            // Сохраняем базовую информацию о пуле
            var formData = self._poolFormData(pool);
            Object.keys(formData).forEach(function (key) {
                self.$set(self.editingPool, key, formData[key]);
            });

            // Загружаем фильтры с сервера
            self.poolFilters = [];
            SmartMatchApi.pools.getFilters(pool.id)
                .then(function (response) {
                    if (response.data.success) {
                        // Сохраняем доступные поля для выпадашки
                        self.poolFilterFields = response.data.available_fields || {};

                        if (response.data.data && response.data.data.length > 0) {
                            self.poolFilters = response.data.data.map(function (f) {
                                return {
                                    id:       f.id || null,
                                    field:    f.field,
                                    operator: f.operator,
                                    value:    f.value,
                                    logic:    f.logic || 'AND',
                                };
                            });
                        } else {
                            // Добавляем пустую строку, чтобы форма не была пустой
                            self.poolFilters = [self._emptyFilter(true)];
                        }
                    }
                })
                .catch(function () {
                    self.poolFilters = [self._emptyFilter(true)];
                });

            self._showModal('configurePoolModal');
        },

        // Добавить новую строку условия
        addPoolFilter: function (logic) {
            this.poolFilters.push(this._emptyFilter(false, logic || 'AND'));
        },

        // Удалить строку условия по индексу
        removePoolFilter: function (index) {
            this.poolFilters.splice(index, 1);
        },

        // Сохранить фильтры через API
        savePoolFilters: function () {
            var self = this;

            // Валидация: у каждой строки должно быть поле и значение
            for (var i = 0; i < self.poolFilters.length; i++) {
                var f = self.poolFilters[i];
                if (!f.field) {
                    Swal.fire('Ошибка', 'Выберите поле для условия #' + (i + 1), 'error');
                    return;
                }
                if (f.value === '' || f.value === null || f.value === undefined) {
                    Swal.fire('Ошибка', 'Введите значение для условия #' + (i + 1), 'error');
                    return;
                }
            }

            var payload = {
                pool_id: self.editingPool.id,
                filters: JSON.stringify(self.poolFilters),
            };

            SmartMatchApi.pools.saveFilters(payload)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Сохранено', response.data.message, 'success');
                        self.closeConfigurePoolModal();
                        self.loadGroups();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось сохранить фильтры', 'error');
                    }
                })
                .catch(function () {
                    Swal.fire('Ошибка', 'Не удалось сохранить фильтры', 'error');
                });
        },

        // Пустая строка фильтра
        _emptyFilter: function (isFirst, logic) {
            return {
                id:       null,
                field:    '',
                operator: 'eq',
                value:    '',
                logic:    isFirst ? 'AND' : (logic || 'AND'),
            };
        },

        // Обновлённый _poolFormData — больше не парсит filter_criteria
        _poolFormData: function (pool) {
            return {
                id:          pool.id,
                name:        pool.name,
                description: pool.description || '',
                is_active:   pool.is_active !== false,
            };
        },

        filterValuePlaceholder: function (field) {
            var map = {
                'currency':       'USD, EUR, RUB…',
                'account_type':   'NRE, INV…',
                'bank_code':      'SWIFT/BIC код…',
                'country':        'US, DE, RU…',
                'name':           'Название счёта…',
                'account_number': 'Номер счёта…',
                'is_suspense':    '',
            };
            return map[field] || 'Значение…';
        },
    }
};