/**
 * SmartMatch — Vue-инстанс страницы "Выверка" (#entries-app).
 * Подключает mixins: Modals, Categories, Groups, Entries, Matching, StatePersistence.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('entries-app');
        if (!root) return;

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && window.axios) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
        axios.defaults.transformRequest = [function (data) { return JSON.stringify(data); }];
        axios.defaults.headers.post['Content-Type'] = 'application/json';

        StateStorage.init((window.AppConfig && window.AppConfig.userId) || 'guest');

        new Vue({
            el: '#entries-app',

            mixins: [ModalsMixin, CategoriesMixin, GroupsMixin, EntriesMixin, MatchingMixin, StatePersistenceMixin],

            data: {
                // Сайдбар
                isSidebarCollapsed: false,
                sidebarWidth:       240,
                isResizingSidebar:  false,

                // Flyout при свёрнутом сайдбаре
                flyoutCategory: null,
                flyoutStyle:    {},
                flyoutTimer:    null,

                // Категории / группы
                loadingCategories:   false,
                categories:          [],
                selectedCategory:    null,
                selectedGroup:       null,
                groupFilters:        [],
                groupFilterFields:   {},
                groupFilterMeta:     {},
                groupFiltersLoading: false,

                // Секция фиксирована на выверке; поле нужно mixin'ам
                activeSection: 'entries',

                newCategory:     { name: '', description: '' },
                editingCategory: { id: null, name: '', description: '' },

                newGroup:     { category_id: null, name: '', description: '', is_active: true },
                editingGroup: { id: null, name: '', description: '', is_active: true },

                collapsedCategories: {},
                _pendingCategoryId:  null,
                _pendingGroupId:     null,

                // Контекстное меню строки
                openRowMenu:  null,
                rowMenuStyle: {}
            },

            computed: {
                sidebarStyle: function () {
                    if (this.isSidebarCollapsed) return {};
                    return {
                        width:    this.sidebarWidth + 'px',
                        minWidth: this.sidebarWidth + 'px'
                    };
                }
            },

            mounted: function () {
                var self = this;
                document.addEventListener('click', function () { self.openRowMenu = null; });

                this._initColManagement();
                this.loadTableColumnsPrefs();
                this.loadCategories();
                this.loadAccountPools();
            },

            methods: {
                toggleSidebar: function () {
                    this.isSidebarCollapsed = !this.isSidebarCollapsed;
                },

                toggleRowMenu: function (type, id, event) {
                    var key = type + '-' + id;
                    if (this.openRowMenu === key) {
                        this.openRowMenu = null;
                        return;
                    }
                    var rect = event.currentTarget.getBoundingClientRect();
                    this.rowMenuStyle = {
                        top:  (rect.bottom + 4) + 'px',
                        left: (rect.right - 150) + 'px'
                    };
                    this.openRowMenu = key;
                },

                startSidebarResize: function (e) {
                    this.isResizingSidebar = true;
                    var self = this;
                    var startX = e.clientX;
                    var startW = this.sidebarWidth;

                    function onMove(ev) {
                        var newW = startW + (ev.clientX - startX);
                        if (newW < 180) newW = 180;
                        if (newW > 500) newW = 500;
                        self.sidebarWidth = newW;
                    }
                    function onUp() {
                        self.isResizingSidebar = false;
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                    }
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                },

                onCategoryHover: function (category, event) {
                    if (!this.isSidebarCollapsed) return;
                    clearTimeout(this.flyoutTimer);
                    var rect = event.currentTarget.getBoundingClientRect();
                    this.flyoutStyle = {
                        position: 'fixed',
                        left:     (rect.right + 8) + 'px',
                        top:      rect.top + 'px'
                    };
                    this.flyoutCategory = category;
                },
                onCategoryLeave: function () {
                    if (!this.isSidebarCollapsed) return;
                    var self = this;
                    this.flyoutTimer = setTimeout(function () {
                        self.flyoutCategory = null;
                    }, 120);
                },
                onFlyoutEnter: function () { clearTimeout(this.flyoutTimer); },
                onFlyoutLeave: function () {
                    var self = this;
                    this.flyoutTimer = setTimeout(function () {
                        self.flyoutCategory = null;
                    }, 80);
                },
                closeFlyout: function () {
                    clearTimeout(this.flyoutTimer);
                    this.flyoutCategory = null;
                }
            }
        });
    });
})();
