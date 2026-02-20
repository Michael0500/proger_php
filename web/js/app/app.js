/**
 * SmartMatch Vue Application
 *
 * Точка входа. Собирает все миксины и инициализирует Vue.
 * Порядок загрузки файлов (через AppAsset):
 *   1. api.js
 *   2. mixins/modals.js
 *   3. mixins/groups.js
 *   4. mixins/pools.js
 *   5. mixins/entries.js
 *   6. app.js  ← этот файл
 */
(function () {
    'use strict';

    // ── Настройка axios ──────────────────────────────────────────────────
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
    }
    // Yii2 request->post() читает form-urlencoded, а не JSON
    axios.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded';
    axios.defaults.transformRequest = [function (data) {
        if (data && typeof data === 'object') {
            return Object.keys(data).map(function (key) {
                var val = (data[key] === null || data[key] === undefined) ? '' : data[key];
                return encodeURIComponent(key) + '=' + encodeURIComponent(val);
            }).join('&');
        }
        return data;
    }];

    // ── Инициализация после загрузки DOM ─────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {

        // Элемент #app присутствует только для авторизованных пользователей с компанией
        if (!document.getElementById('app')) return;

        new Vue({
            el: '#app',

            // Подключаем все миксины
            mixins: [
                ModalsMixin,
                GroupsMixin,
                PoolsMixin,
                EntriesMixin
            ],

            data: {
                // ── UI ───────────────────────────────────────────────────
                isSidebarCollapsed: false,
                loadingGroups:      false,
                loadingAccounts:    false,

                // ── Данные ───────────────────────────────────────────────
                groups:        [],
                accounts:      [],   // массив Ностро банков с вложенными entries[]
                selectedGroup: null,
                selectedPool:  null,

                // ── Формы: группы ────────────────────────────────────────
                newGroup:     { name: '', description: '' },
                editingGroup: { id: null, name: '', description: '' },

                // ── Формы: пулы ──────────────────────────────────────────
                newPool: { group_id: null, name: '', description: '', is_active: true },
                editingPool: {
                    id: null, name: '', description: '', is_active: true,
                    filter_criteria: {
                        currency: '', account_type: '', bank_code: '', country: '', is_suspense: false
                    }
                },

                // ── Формы: записи выверки ────────────────────────────────
                editingEntry: {
                    id: null, account_id: null,
                    ls: 'L', dc: 'Debit',
                    amount: '', currency: '',
                    value_date: '', post_date: '',
                    instruction_id: '', end_to_end_id: '',
                    transaction_id: '', message_id: '',
                    comment: ''
                },

                // ── Inline-редактирование комментария ────────────────────
                editingCommentId:    null,
                editingCommentValue: ''
            },

            computed: {
                isAccountPage: function () {
                    return this.selectedPool !== null;
                }
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
                    var date = new Date(dateString);
                    return date.toLocaleDateString('ru-RU', {
                        year: 'numeric', month: 'short', day: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
                }
            }
        });
    });

})();