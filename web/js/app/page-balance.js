/**
 * Стартер Vue-страницы "Баланс" (`#balance-app`).
 *
 * Подключает модалки и `BalanceMixin`, настраивает axios для JSON API и
 * запускает загрузку балансов, счетов и пользовательских настроек колонок.
 * Инстанс создаётся только на странице с соответствующим корневым элементом.
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

        /**
         * Vue-инстанс страницы балансов Nostro.
         *
         * Управляет таблицей closing/opening balances, импортом файлов,
         * подтверждением ошибок, историей и настройками колонок. Серверные
         * endpoints применяют `company_id` и раздел компании (`NRE`/`INV`).
         */
        new Vue({
            el: '#balance-app',
            mixins: [ModalsMixin, BalanceMixin],

            /**
             * Начальное состояние оболочки страницы баланса.
             *
             * @type {Object}
             * @property {string} activeSection Раздел, ожидаемый mixin'ами страницы.
             * @property {?string} openRowMenu Ключ открытого контекстного меню строки.
             * @property {Object} rowMenuStyle Абсолютная позиция меню строки.
             */
            data: {
                // Поле activeSection используется внутренними watch'ами BalanceMixin.
                activeSection: 'balance',

                // Контекстное меню строки таблицы
                openRowMenu:  null,
                rowMenuStyle: {}
            },

            /**
             * Подключает UI-обработчики и загружает стартовые данные балансов.
             *
             * Побочные эффекты: подписка на клик документа, инициализация
             * управления колонками, HTTP-запросы к balance endpoints.
             *
             * @returns {void}
             */
            mounted: function () {
                var self = this;
                document.addEventListener('click', function () { self.openRowMenu = null; });

                // Предустановленный фильтр по номеру пакета (переход со страницы отката).
                var pageInit = (typeof window.BalancePageInit === 'object' && window.BalancePageInit) || {};
                if (pageInit.batchId) {
                    this.$set(this.balanceFilters, 'batch_id', parseInt(pageInit.batchId, 10));
                    this.balanceFiltersOpen = true;
                }

                this._initBalanceColManagement();
                this.loadBalanceTableColumnsPrefs();
                this.loadBalanceAccounts();
                this.loadBalances(true);
            },

            methods: {
                /**
                 * Обрабатывает прокрутку таблицы балансов для догрузки следующей страницы.
                 *
                 * @param {Event} e Событие scroll от контейнера таблицы.
                 * @returns {void}
                 */
                onBalanceScroll: function (e) {
                    var el = e.target;
                    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 120) {
                        this.loadMoreBalances();
                    }
                },

                /**
                 * Открывает или закрывает контекстное меню строки баланса.
                 *
                 * @param {string} type Тип строки или действия.
                 * @param {number|string} id Идентификатор строки.
                 * @param {MouseEvent} event Событие клика по кнопке меню.
                 * @returns {void}
                 */
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
