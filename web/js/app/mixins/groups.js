/**
 * Mixin: Groups
 * Всё что касается AccountGroup — загрузка, создание, редактирование, удаление
 */
var GroupsMixin = {
    methods: {

        loadGroups: function () {
            var self = this;
            self.loadingGroups = true;
            SmartMatchApi.groups.list()
                .then(function (response) {
                    if (response.data.success) {
                        // ── ДОБАВИТЬ: читаем сохранённые expanded ──────────
                        var savedExpanded  = StateStorage.get('groupsExpanded', {});
                        var savedGroupId   = StateStorage.get('selectedGroupId', null);
                        var savedPoolId    = StateStorage.get('selectedPoolId', null);
                        // ───────────────────────────────────────────────────

                        self.groups = response.data.data.map(function (group) {
                            // ── ДОБАВИТЬ: восстанавливаем expanded из сохранения ──
                            var isExpanded = !!savedExpanded[group.id];
                            return Object.assign({}, group, { expanded: isExpanded });
                            // ─────────────────────────────────────────────────────
                        });

                        // ── ДОБАВИТЬ: восстанавливаем выбор группы и пула ──
                        if (savedGroupId) {
                            var foundGroup = self.groups.find(function (g) {
                                return g.id === parseInt(savedGroupId, 10);
                            });
                            if (foundGroup) {
                                self.selectedGroup = foundGroup;

                                if (savedPoolId && foundGroup.pools) {
                                    var foundPool = foundGroup.pools.find(function (p) {
                                        return p.id === parseInt(savedPoolId, 10);
                                    });
                                    if (foundPool) {
                                        self.selectedPool = foundPool;
                                        self.loadEntries && self.loadEntries(true);
                                    }
                                }
                            }
                        }
                        // ───────────────────────────────────────────────────

                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить группы', 'error');
                    }
                })
                .catch(function () {
                    Swal.fire('Ошибка', 'Не удалось загрузить группы', 'error');
                })
                .finally(function () {
                    self.loadingGroups = false;
                });
        },

        toggleGroup: function (group) {
            var self = this;
            var index = self.groups.findIndex(function (g) { return g.id === group.id; });
            if (self.selectedGroup && self.selectedGroup.id === group.id) {
                if (index !== -1) self.groups[index].expanded = !self.groups[index].expanded;
            } else {
                self.selectedGroup = group;
                self.selectedPool = null;
                self.accounts = [];
                if (index !== -1) self.groups[index].expanded = true;
            }

            // ── ДОБАВИТЬ: сохраняем состояние expanded всех групп ──
            var expandedMap = {};
            self.groups.forEach(function (g) { expandedMap[g.id] = !!g.expanded; });
            StateStorage.set('groupsExpanded', expandedMap);
            StateStorage.set('selectedGroupId', self.selectedGroup ? self.selectedGroup.id : null);
            if (!self.selectedPool) StateStorage.set('selectedPoolId', null);
            // ───────────────────────────────────────────────────────
        },

        // Добавление
        showAddGroupModal: function () {
            this.newGroup = { name: '', description: '' };
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
                        self.loadGroups();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось создать группу', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось создать группу', 'error'); });
        },

        // Редактирование
        editGroup: function (group) {
            this.editingGroup = { id: group.id, name: group.name, description: group.description || '' };
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
                        self.loadGroups();
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
                text: 'Удалить группу "' + group.name + '"? Все связанные пулы будут отвязаны.',
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
                            self.loadGroups();
                            if (self.selectedGroup && self.selectedGroup.id === group.id) {
                                self.selectedGroup = null;
                                self.selectedPool  = null;
                                self.accounts      = [];
                            }
                        } else {
                            Swal.fire('Ошибка', response.data.message, 'error');
                        }
                    })
                    .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить группу', 'error'); });
            });
        }
    }
};