/**
 * Mixin: Categories
 * Всё что касается Category — загрузка, создание, редактирование, удаление
 */
var CategoriesMixin = {
    methods: {

        loadCategories: function () {
            var self = this;
            self.loadingCategories = true;
            SmartMatchApi.categories.list()
                .then(function (response) {
                    if (response.data.success) {
                        var savedExpanded    = StateStorage.get('categoriesExpanded', {});
                        var savedCategoryId  = StateStorage.get('selectedCategoryId', null);
                        var savedGroupId     = StateStorage.get('selectedGroupId', null);

                        self.categories = response.data.data.map(function (category) {
                            var isExpanded = !!savedExpanded[category.id];
                            return Object.assign({}, category, { expanded: isExpanded });
                        });

                        if (savedCategoryId) {
                            var foundCategory = self.categories.find(function (c) {
                                return c.id === parseInt(savedCategoryId, 10);
                            });
                            if (foundCategory) {
                                self.selectedCategory = foundCategory;

                                if (savedGroupId && foundCategory.groups) {
                                    var foundGroup = foundCategory.groups.find(function (g) {
                                        return g.id === parseInt(savedGroupId, 10);
                                    });
                                    if (foundGroup) {
                                        self.selectedGroup = foundGroup;
                                        self.loadEntries && self.loadEntries(true);
                                    }
                                }
                            }
                        }

                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить категории', 'error');
                    }
                })
                .catch(function () {
                    Swal.fire('Ошибка', 'Не удалось загрузить категории', 'error');
                })
                .finally(function () {
                    self.loadingCategories = false;
                });
        },

        toggleCategory: function (category) {
            var self = this;
            var index = self.categories.findIndex(function (c) { return c.id === category.id; });
            if (self.selectedCategory && self.selectedCategory.id === category.id) {
                if (index !== -1) self.categories[index].expanded = !self.categories[index].expanded;
            } else {
                self.selectedCategory = category;
                self.selectedGroup = null;
                self.accounts = [];
                if (index !== -1) self.categories[index].expanded = true;
            }

            var expandedMap = {};
            self.categories.forEach(function (c) { expandedMap[c.id] = !!c.expanded; });
            StateStorage.set('categoriesExpanded', expandedMap);
            StateStorage.set('selectedCategoryId', self.selectedCategory ? self.selectedCategory.id : null);
            if (!self.selectedGroup) StateStorage.set('selectedGroupId', null);
        },

        // Добавление
        showAddCategoryModal: function () {
            this.newCategory = { name: '', description: '' };
            this._showModal('addCategoryModal');
        },

        createCategory: function () {
            var self = this;
            if (!self.newCategory.name || !self.newCategory.name.trim()) {
                Swal.fire('Ошибка', 'Название категории обязательно', 'error');
                return;
            }
            SmartMatchApi.categories.create(self.newCategory)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self._forceCloseAddCategoryModal();
                        self.loadCategories();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось создать категорию', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось создать категорию', 'error'); });
        },

        // Редактирование
        editCategory: function (category) {
            this.editingCategory = { id: category.id, name: category.name, description: category.description || '' };
            this._showModal('editCategoryModal');
        },

        updateCategory: function () {
            var self = this;
            if (!self.editingCategory.name || !self.editingCategory.name.trim()) {
                Swal.fire('Ошибка', 'Название категории обязательно', 'error');
                return;
            }
            SmartMatchApi.categories.update(self.editingCategory)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self._forceCloseEditCategoryModal();
                        self.loadCategories();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось обновить категорию', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось обновить категорию', 'error'); });
        },

        // Удаление
        deleteCategory: function (category) {
            var self = this;
            Swal.fire({
                title: 'Вы уверены?',
                text: 'Удалить категорию "' + category.name + '"? Все связанные группы будут удалены.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Да, удалить',
                cancelButtonText: 'Отмена'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                SmartMatchApi.categories.delete(category.id)
                    .then(function (response) {
                        if (response.data.success) {
                            Swal.fire('Удалено', response.data.message, 'success');
                            self.loadCategories();
                            if (self.selectedCategory && self.selectedCategory.id === category.id) {
                                self.selectedCategory = null;
                                self.selectedGroup    = null;
                                self.accounts         = [];
                            }
                        } else {
                            Swal.fire('Ошибка', response.data.message, 'error');
                        }
                    })
                    .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить категорию', 'error'); });
            });
        }
    }
};
