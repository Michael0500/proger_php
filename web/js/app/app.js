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

            mixins: [ModalsMixin, GroupsMixin, PoolsMixin, EntriesMixin, MatchingMixin, BalanceMixin, ArchiveMixin, StatePersistenceMixin],

            data: {
                isSidebarCollapsed: false,
                loadingGroups:      false,
                groups:             [],
                selectedGroup:      null,
                selectedPool:       null,
                poolFilters: [],
                poolFilterFields: {},
                poolFilterMeta:     {},
                poolFiltersLoading: false,

                // Активная секция: 'entries' | 'balance' | 'archive'
                activeSection: 'entries',

                newGroup:     { name: '', description: '' },
                editingGroup: { id: null, name: '', description: '' },

                newPool: { group_id: null, name: '', description: '', is_active: true },
                editingPool: {
                    id: null, name: '', description: '', is_active: true,
                    filter_criteria: {
                        currency: '', account_type: '', bank_code: '',
                        country: '', is_suspense: false
                    }
                },

                editingCommentId:    null,
                editingCommentValue: '',
                collapsedGroups: {},
                _pendingGroupId: null,
                _pendingPoolId:  null,
            },

            mounted: function () {
                this.loadGroups();
                this.loadBalanceAccounts();
                this.loadArchiveAccounts();
                // Загружаем статистику архива при старте (для badge в сайдбаре)
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

                /**
                 * Истёк ли срок хранения записи
                 */
                isExpired: function (expiresAt) {
                    if (!expiresAt) return false;
                    return new Date(expiresAt) < new Date();
                },

                /**
                 * Истекает ли срок хранения в ближайшие 90 дней
                 */
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