/**
 * Mixin: Pools
 * Всё что касается AccountPool — выбор, загрузка данных, CRUD
 */
var PoolsMixin = {
    methods: {

        selectPool: function (pool, group) {
            this.selectedPool  = pool;
            this.selectedGroup = group;
            this.loadAccounts(pool.id);
        },

        loadAccounts: function (poolId) {
            var self = this;
            self.loadingAccounts = true;
            self.accounts = [];
            SmartMatchApi.pools.accounts(poolId)
                .then(function (response) {
                    if (response.data.success) {
                        self.accounts = response.data.data;
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить данные', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось загрузить данные', 'error'); })
                .finally(function () { self.loadingAccounts = false; });
        },

        refreshAccounts: function () {
            if (this.selectedPool) this.loadAccounts(this.selectedPool.id);
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
            this.editingPool = this._poolFormData(pool);
            this._showModal('editPoolModal');
        },

        updatePool: function () {
            var self = this;
            if (!self.editingPool.name || !self.editingPool.name.trim()) {
                Swal.fire('Ошибка', 'Название пула обязательно', 'error');
                return;
            }
            SmartMatchApi.pools.update(self.editingPool)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self.closeEditPoolModal();
                        self.closeConfigurePoolModal();
                        self.loadGroups();
                        if (self.selectedPool && self.selectedPool.id === self.editingPool.id) {
                            self.loadAccounts(self.selectedPool.id);
                        }
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось обновить пул', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось обновить пул', 'error'); });
        },

        // Настройка фильтров
        configurePool: function (pool) {
            this.editingPool = this._poolFormData(pool);
            this._showModal('configurePoolModal');
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
                                self.accounts     = [];
                            }
                        } else {
                            Swal.fire('Ошибка', response.data.message, 'error');
                        }
                    })
                    .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить пул', 'error'); });
            });
        },

        // Вспомогательный метод: собирает объект формы пула
        _poolFormData: function (pool) {
            return {
                id: pool.id,
                name: pool.name,
                description: pool.description || '',
                is_active: pool.is_active,
                filter_criteria: pool.filter_criteria
                    ? JSON.parse(pool.filter_criteria)
                    : { currency: '', account_type: '', bank_code: '', country: '', is_suspense: false }
            };
        }
    }
};