/**
 * SmartMatch — Vue App entry point
 * ИЗМЕНЕНИЯ: добавлены activeSection, BalanceMixin, switchToBalance, onBalanceScroll
 */
(function () {
    'use strict';

    // ── axios config ──────────────────────────────────────────────
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
    }
    axios.defaults.transformRequest = [(data) => JSON.stringify(data)];
    axios.defaults.headers.post['Content-Type'] = 'application/json';

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('app')) return;

        new Vue({
            el: '#app',

            // BalanceMixin добавлен к существующим миксинам
            mixins: [ModalsMixin, GroupsMixin, PoolsMixin, EntriesMixin, MatchingMixin, BalanceMixin],

            data: {
                isSidebarCollapsed: false,
                loadingGroups:      false,
                groups:             [],
                selectedGroup:      null,
                selectedPool:       null,

                // ── Активная секция: 'entries' | 'balance' ────────
                activeSection: 'entries',

                // Группы
                newGroup:     { name: '', description: '' },
                editingGroup: { id: null, name: '', description: '' },

                // Пулы
                newPool: { group_id: null, name: '', description: '', is_active: true },
                editingPool: {
                    id: null, name: '', description: '', is_active: true,
                    filter_criteria: {
                        currency: '', account_type: '', bank_code: '',
                        country: '', is_suspense: false
                    }
                },

                // inline-комментарий (также в EntriesMixin)
                editingCommentId:    null,
                editingCommentValue: ''
            },

            mounted: function () {
                this.loadGroups();

                // Загружаем счета для баланса при старте
                this.loadBalanceAccounts();
            },

            methods: {
                toggleSidebar: function () {
                    this.isSidebarCollapsed = !this.isSidebarCollapsed;
                },

                /**
                 * Переключиться на вкладку Баланс и загрузить данные если ещё нет
                 */
                switchToBalance: function () {
                    this.activeSection = 'balance';
                    if (!this.balances.length && !this.balancesLoading) {
                        this.loadBalances(true);
                    }
                },

                /**
                 * Infinite scroll для таблицы баланса
                 */
                onBalanceScroll: function (e) {
                    var el = e.target;
                    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 120) {
                        this.loadMoreBalances();
                    }
                },
            }
        });
    });
})();