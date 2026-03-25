/**
 * Mixin: Groups
 * Всё что касается Group — выбор, загрузка данных, CRUD, фильтры
 */
var GroupsMixin = {
    methods: {

        selectGroup: function (group, category) {
            this.selectedGroup    = group;
            this.selectedCategory = category;

            StateStorage.set('selectedGroupId',    group    ? group.id    : null);
            StateStorage.set('selectedCategoryId', category ? category.id : null);

            this.loadEntries(true);
        },

        refreshAccounts: function () {
            if (this.selectedGroup) this.loadEntries(true);
        },

        // Добавление
        showAddGroupModal: function (category) {
            this.newGroup = { category_id: category.id, name: '', description: '', is_active: true };
            this._showModal('addGroupModal');
        },

        createGroup: function () {
            var self = this;
            if (!self.newGroup.name || !self.newGroup.name.trim()) {
                Swal.fire('Ошибка', 'Название группы обязательно', 'error');
                return;
            }

            SmartMatchApi.groups.create(self.newGroup)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self.closeAddGroupModal();
                        self.loadCategories();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось создать группу', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось создать группу', 'error'); });
        },

        // Редактирование
        editGroup: function (group) {
            var formData = this._groupFormData(group);
            Object.keys(formData).forEach(function (key) {
                this.$set(this.editingGroup, key, formData[key]);
            }, this);
            this._showModal('editGroupModal');
        },

        updateGroup: function () {
            var self = this;
            if (!self.editingGroup.name || !self.editingGroup.name.trim()) {
                Swal.fire('Ошибка', 'Название группы обязательно', 'error');
                return;
            }

            SmartMatchApi.groups.update(self.editingGroup)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self.closeEditGroupModal();
                        self.closeConfigureGroupModal();
                        self.loadCategories();
                        if (self.selectedGroup && self.selectedGroup.id === self.editingGroup.id) {
                            self.loadEntries(true);
                        }
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось обновить группу', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось обновить группу', 'error'); });
        },

        // Удаление
        deleteGroup: function (group) {
            var self = this;
            Swal.fire({
                title: 'Вы уверены?',
                text: 'Удалить группу "' + group.name + '"?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Да, удалить',
                cancelButtonText: 'Отмена'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                SmartMatchApi.groups.delete(group.id)
                    .then(function (response) {
                        if (response.data.success) {
                            Swal.fire('Удалено', response.data.message, 'success');
                            self.loadCategories();
                            if (self.selectedGroup && self.selectedGroup.id === group.id) {
                                self.selectedGroup = null;
                                self.entries       = [];
                                self.entriesTotal  = 0;
                            }
                        } else {
                            Swal.fire('Ошибка', response.data.message, 'error');
                        }
                    })
                    .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить группу', 'error'); });
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

        configureGroup: function (group) {
            var self = this;

            self.$set(self.editingGroup, 'id',          group.id);
            self.$set(self.editingGroup, 'name',        group.name);
            self.$set(self.editingGroup, 'description', group.description || '');
            self.$set(self.editingGroup, 'is_active',   group.is_active !== false);

            self.groupFilters        = [];
            self.groupFilterMeta     = {};
            self.groupFiltersLoading = true;

            self._showModal('configureGroupModal');

            SmartMatchApi.groups.getFilters(group.id)
                .then(function (response) {
                    self.groupFiltersLoading = false;
                    if (!response.data.success) return;

                    self.groupFilterMeta = {
                        fieldGroups:  response.data.field_groups  || [],
                        operatorsMap: response.data.operators_map || {},
                        fieldOptions: response.data.field_options || {},
                        accounts:     response.data.accounts      || [],
                        accountPools: response.data.account_pools || [],
                        dateFields:   response.data.date_fields   || [],
                        selectFields: response.data.select_fields || [],
                    };

                    if (response.data.data && response.data.data.length > 0) {
                        self.groupFilters = response.data.data.map(function (f, i) {
                            return self._hydrateFilter(f, i);
                        });
                    } else {
                        self.groupFilters = [self._emptyFilter(true)];
                    }

                    self.$nextTick(function () { self._initFilterAccountSelects(); });
                })
                .catch(function () {
                    self.groupFiltersLoading = false;
                    self.groupFilters = [self._emptyFilter(true)];
                });
        },

        addGroupFilter: function (logic) {
            this.groupFilters.push(this._emptyFilter(false, logic || 'AND'));
            this.$nextTick(function () { this._initFilterAccountSelects(); }.bind(this));
        },

        removeGroupFilter: function (index) {
            this.groupFilters.splice(index, 1);
        },

        onGroupFilterFieldChange: function (index) {
            var self   = this;
            var filter = self.groupFilters[index];
            var ops    = (self.groupFilterMeta.operatorsMap || {})[filter.field] || { eq: 'равно' };
            self.$set(filter, 'operator', Object.keys(ops)[0]);
            self.$set(filter, 'value',    '');
            self.$set(filter, 'value2',   '');
            self.$nextTick(function () { self._initFilterAccountSelects(); });
        },

        onGroupFilterOperatorChange: function (index) {
            var filter = this.groupFilters[index];
            this.$set(filter, 'value',  '');
            this.$set(filter, 'value2', '');
        },

        groupFilterOperators: function (filter) {
            if (!this.groupFilterMeta || !this.groupFilterMeta.operatorsMap) {
                return { eq: 'равно', neq: 'не равно' };
            }
            return this.groupFilterMeta.operatorsMap[filter.field] || { eq: 'равно', neq: 'не равно' };
        },

        isDateFilterField: function (filter) {
            return (this.groupFilterMeta.dateFields || []).indexOf(filter.field) !== -1;
        },

        isSelectFilterField: function (filter) {
            return (this.groupFilterMeta.selectFields || []).indexOf(filter.field) !== -1;
        },

        groupFilterFieldOptions: function (filter) {
            return (this.groupFilterMeta.fieldOptions || {})[filter.field] || {};
        },

        saveGroupFilters: function () {
            var self = this;

            for (var i = 0; i < self.groupFilters.length; i++) {
                var f = self.groupFilters[i];
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

            var filtersToSend = self.groupFilters.map(function (f) {
                return {
                    field:    f.field,
                    operator: f.operator,
                    value:    f.operator === 'between' ? (f.value + '|' + f.value2) : f.value,
                    logic:    f.logic,
                };
            });

            SmartMatchApi.groups.saveFilters({
                group_id: self.editingGroup.id,
                filters: JSON.stringify(filtersToSend),
            })
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Сохранено', response.data.message, 'success');
                        self.closeConfigureGroupModal();
                        self.loadCategories();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Ошибка сохранения', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось сохранить фильтры', 'error'); });
        },

        _initFilterAccountSelects: function () {
            var self = this;
            self.groupFilters.forEach(function (filter, index) {
                if (filter.field === 'account_id') {
                    var $el = $('#group-filter-account-' + index);
                    if (!$el.length || $el.data('select2')) return;

                    var accountData = (self.groupFilterMeta.accounts || []).map(function (a) {
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

                    $el.on('change.groupfilter', function () {
                        self.$set(self.groupFilters[index], 'value', $el.val() || '');
                    });
                }

                if (filter.field === 'account_pool_id') {
                    var $el2 = $('#group-filter-pool-' + index);
                    if (!$el2.length || $el2.data('select2')) return;

                    var poolData = (self.groupFilterMeta.accountPools || []).map(function (p) {
                        return { id: String(p.id), text: p.name };
                    });

                    $el2.select2({
                        theme:       'bootstrap-5',
                        placeholder: 'Выберите пул...',
                        allowClear:  true,
                        data:        poolData,
                    });

                    if (filter.value) {
                        $el2.val(String(filter.value)).trigger('change');
                    }

                    $el2.on('change.groupfilter', function () {
                        self.$set(self.groupFilters[index], 'value', $el2.val() || '');
                    });
                }
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

        _groupFormData: function (group) {
            return { id: group.id, name: group.name, description: group.description || '', is_active: group.is_active !== false };
        },
    }
};
