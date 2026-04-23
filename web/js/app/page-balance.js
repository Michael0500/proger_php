/**
 * SmartMatch — Vue-инстанс страницы "Баланс" (#balance-app).
 * Подключает mixins: Modals, Balance.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('balance-app');
        if (!root) return;

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && window.axios) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
        axios.defaults.transformRequest = [function (data) { return JSON.stringify(data); }];
        axios.defaults.headers.post['Content-Type'] = 'application/json';

        StateStorage.init((window.AppConfig && window.AppConfig.userId) || 'guest');

        new Vue({
            el: '#balance-app',
            mixins: [ModalsMixin, BalanceMixin],

            data: {
                // Поле activeSection используется внутренними watch'ами BalanceMixin.
                activeSection: 'balance',

                // Контекстное меню строки таблицы
                openRowMenu:  null,
                rowMenuStyle: {}
            },

            mounted: function () {
                var self = this;
                document.addEventListener('click', function () { self.openRowMenu = null; });

                this.loadBalanceAccounts();
                this.loadBalances(true);
            },

            methods: {
                onBalanceScroll: function (e) {
                    var el = e.target;
                    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 120) {
                        this.loadMoreBalances();
                    }
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
                }
            }
        });
    });
})();
