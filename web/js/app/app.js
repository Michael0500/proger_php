/**
 * SmartMatch Vue Application — точка входа.
 * Порядок загрузки: api.js → mixins/*.js → app.js
 */
(function () {
    'use strict';

    // ── Настройка axios ───────────────────────────────────────────────
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
    }
    axios.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded';
    axios.defaults.transformRequest = [function (data) {
        if (data && typeof data === 'object') {
            return Object.keys(data).map(function (key) {
                var val = (data[key] === null || data[key] === undefined) ? '' : data[key];
                if (val === true)  val = 1;
                if (val === false) val = 0;
                return encodeURIComponent(key) + '=' + encodeURIComponent(val);
            }).join('&');
        }
        return data;
    }];

    // ── Инициализация ─────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('app')) return;

        new Vue({
            el: '#app',
            mixins: [
                ModalsMixin,
                GroupsMixin,
                PoolsMixin,
                EntriesMixin,
                MatchingMixin   // <- квитование
            ],

            data: {
                isSidebarCollapsed: false,
                loadingGroups:      false,
                loadingAccounts:    false,
                groups:             [],
                accounts:           [],
                selectedGroup:      null,
                selectedPool:       null,

                // Группы
                newGroup:     { name: '', description: '' },
                editingGroup: { id: null, name: '', description: '' },

                // Пулы
                newPool: { group_id: null, name: '', description: '', is_active: true },
                editingPool: {
                    id: null, name: '', description: '', is_active: true,
                    filter_criteria: { currency: '', account_type: '', bank_code: '', country: '', is_suspense: false }
                },

                // Записи
                editingEntry: {
                    id: null, account_id: null,
                    ls: 'L', dc: 'Debit',
                    amount: '', currency: '',
                    value_date: '', post_date: '',
                    instruction_id: '', end_to_end_id: '',
                    transaction_id: '', message_id: '',
                    comment: ''
                },
                editingCommentId:    null,
                editingCommentValue: ''
            },

            computed: {
                isAccountPage: function () { return this.selectedPool !== null; }
            },

            mounted: function () {
                this.loadGroups();
            },

            methods: {
                toggleSidebar: function () {
                    this.isSidebarCollapsed = !this.isSidebarCollapsed;
                },
                formatDate: function (dateString) {
                    if (!dateString) return '—';
                    var d = new Date(dateString);
                    return d.toLocaleDateString('ru-RU', {
                        year: 'numeric', month: '2-digit', day: '2-digit'
                    });
                }
            }
        });
    });
})();