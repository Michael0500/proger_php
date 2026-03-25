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
                loadingCategories:  false,
                categories:         [],
                selectedCategory:   null,
                selectedGroup:      null,
                groupFilters: [],
                groupFilterFields: {},
                groupFilterMeta:     {},
                groupFiltersLoading: false,

                // Активная секция: 'entries' | 'balance' | 'archive'
                activeSection: 'entries',

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
            },

            mounted: function () {
                this.loadCategories();
                this.loadBalanceAccounts();
                this.loadArchiveAccounts();
                this.loadArchiveStats();

                if (this.activeSection === 'balance') {
                    this.loadBalances(true);
                } else if (this.activeSection === 'archive') {
                    this.loadArchive(true);
                }
            },

            methods: {
                toggleSidebar: function () {
                    this.isSidebarCollapsed = !this.isSidebarCollapsed;
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
