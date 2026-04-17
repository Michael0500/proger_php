/**
 * SmartMatch — Vue App entry point
 */
(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
    }
    axios.defaults.transformRequest = [(data) => JSON.stringify(data)];
    axios.defaults.headers.post['Content-Type'] = 'application/json';

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('app')) return;

        StateStorage.init(window.AppConfig.userId || 'guest');

        new Vue({
            el: '#app',

            mixins: [ModalsMixin, CategoriesMixin, GroupsMixin, EntriesMixin, MatchingMixin, BalanceMixin, ArchiveMixin, StatePersistenceMixin],

            data: {
                isSidebarCollapsed: false,
                sidebarWidth: 240,
                isResizingSidebar: false,

                // Flyout
                flyoutCategory: null,
                flyoutStyle: {},
                loadingCategories:  false,
                categories:         [],
                selectedCategory:   null,
                selectedGroup:      null,
                groupFilters: [],
                groupFilterFields: {},
                groupFilterMeta:     {},
                groupFiltersLoading: false,

                // Активная секция: 'entries' | 'balance' | 'archive'
                activeSection: (window.AppConfig && window.AppConfig.initialSection) || 'entries',

                newCategory:     { name: '', description: '' },
                editingCategory: { id: null, name: '', description: '' },

                newGroup: { category_id: null, name: '', description: '', is_active: true },
                editingGroup: {
                    id: null, name: '', description: '', is_active: true,
                },

                editingCommentId:    null,
                editingCommentValue: '',
                collapsedCategories: {},
                _pendingCategoryId: null,
                _pendingGroupId:    null,

                openRowMenu: null,
                rowMenuStyle: {},
            },

            mounted: function () {
                var self = this;
                document.addEventListener('click', function () { self.openRowMenu = null; });

                this.flyoutTimer = null;
                this._initColManagement();
                this.loadTableColumnsPrefs();
                var forcedSection = window.AppConfig && window.AppConfig.initialSection &&
                                    window.AppConfig.initialSection !== 'entries';

                // Категории и группы нужны только в основном приложении с sidebar
                if (!forcedSection) {
                    this.loadCategories();
                }

                this.loadAccountPools();
                this.loadBalanceAccounts();
                this.loadArchiveAccounts();
                this.loadArchiveStats();

                if (this.activeSection === 'balance') {
                    this.loadBalances(true);
                } else if (this.activeSection === 'archive') {
                    this.loadArchive(true);
                }
            },

            computed: {
                sidebarStyle: function () {
                    if (this.isSidebarCollapsed) return {};
                    return {
                        width: this.sidebarWidth + 'px',
                        minWidth: this.sidebarWidth + 'px'
                    };
                }
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
                    var btn = event.currentTarget;
                    var rect = btn.getBoundingClientRect();
                    this.rowMenuStyle = {
                        top: (rect.bottom + 4) + 'px',
                        left: (rect.right - 150) + 'px'
                    };
                    this.openRowMenu = key;
                },

                /* ── Sidebar resize ─────────────────── */
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

                /* ── Flyout (collapsed hover) ───────── */
                onCategoryHover: function (category, event) {
                    if (!this.isSidebarCollapsed) return;
                    clearTimeout(this.flyoutTimer);
                    var row = event.currentTarget;
                    var rect = row.getBoundingClientRect();
                    this.flyoutStyle = {
                        position: 'fixed',
                        left: (rect.right + 8) + 'px',
                        top: rect.top + 'px'
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
                onFlyoutEnter: function () {
                    clearTimeout(this.flyoutTimer);
                },
                onFlyoutLeave: function () {
                    var self = this;
                    this.flyoutTimer = setTimeout(function () {
                        self.flyoutCategory = null;
                    }, 80);
                },
                closeFlyout: function () {
                    clearTimeout(this.flyoutTimer);
                    this.flyoutCategory = null;
                },

                switchToBalance: function () {
                    this.activeSection = 'balance';
                    if (!this.balances.length && !this.balancesLoading) {
                        this.loadBalances(true);
                    }
                },

                switchToArchive: function () {
                    this.activeSection = 'archive';
                    if (!this.archiveRows.length && !this.archiveLoading) {
                        this.loadArchive(true);
                    }
                    if (!this.archiveStats) {
                        this.loadArchiveStats();
                    }
                },

                onBalanceScroll: function (e) {
                    var el = e.target;
                    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 120) {
                        this.loadMoreBalances();
                    }
                },

                // ── Вспомогательные методы для архива ────────────

                isExpired: function (expiresAt) {
                    if (!expiresAt) return false;
                    return new Date(expiresAt) < new Date();
                },

                isExpiringSoon: function (expiresAt) {
                    if (!expiresAt) return false;
                    var d = new Date(expiresAt);
                    var soon = new Date();
                    soon.setDate(soon.getDate() + 90);
                    return d < soon && d >= new Date();
                },
            }
        });
    });
})();
