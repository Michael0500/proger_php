/**
 * Стартер Vue-страницы "Баланс" с сайдбаром категорий и ностро-банков
 * (`#bank-balance-app`).
 *
 * Поведение: пользователь выбирает ностро-банк в сайдбаре — таблица балансов
 * `nostro_balance` фильтруется по `accounts.pool_id` этого банка. Без выбора
 * пула отображается пустое состояние. Категория только раскрывает/сворачивает
 * список пулов под собой и не фильтрует данные.
 *
 * Подключённые mixins: ModalsMixin, CategoriesMixin, PoolsMixin, BalanceMixin,
 * StatePersistenceMixin. Инстанс создаётся только при наличии корневого
 * элемента в DOM, чтобы файл мог грузиться через общий asset bundle.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('bank-balance-app');
        if (!root) return;

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && window.axios) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
        axios.defaults.transformRequest = [function (data) { return JSON.stringify(data); }];
        axios.defaults.headers.post['Content-Type'] = 'application/json';

        StateStorage.init((window.AppConfig && window.AppConfig.userId) || 'guest');

        new Vue({
            el: '#bank-balance-app',

            mixins: [ModalsMixin, CategoriesMixin, PoolsMixin, BalanceMixin, StatePersistenceMixin],

            data: {
                // Сайдбар (как в выверке)
                isSidebarCollapsed: false,
                sidebarWidth:       240,
                isResizingSidebar:  false,

                // Flyout при свёрнутом сайдбаре
                flyoutCategory: null,
                flyoutStyle:    {},
                flyoutTimer:    null,

                // Категории / ностро-банки
                loadingCategories: false,
                categories:        [],
                selectedCategory:  null,
                selectedPool:      null,

                // Раздел: страница работает только с балансами
                activeSection: 'balance',

                newCategory:     { name: '', description: '' },
                editingCategory: { id: null, name: '', description: '' },

                collapsedCategories: {},

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

            watch: {
                /**
                 * При смене выбранного ностро-банка перезагружаем балансы.
                 * BalanceMixin.loadBalances использует `selectedPool.id`,
                 * когда `balancePoolId` не задан. При сбросе выбора (например,
                 * клик по другой категории) очищаем таблицу без API-запроса —
                 * view покажет пустое состояние.
                 */
                selectedPool: function (newPool) {
                    if (newPool && newPool.id) {
                        this.loadBalances(true);
                    } else {
                        this.balances      = [];
                        this.balancesTotal = 0;
                    }
                }
            },

            mounted: function () {
                var self = this;
                document.addEventListener('click', function () { self.openRowMenu = null; });

                this._initBalanceColManagement();
                this.loadBalanceTableColumnsPrefs();
                this.loadBalanceAccounts();
                this.loadCategories();
            },

            methods: {
                /**
                 * Стартер не использует loadEntries из EntriesMixin (его здесь нет).
                 * PoolsMixin.selectPool и CategoriesMixin.loadCategories вызывают
                 * `this.loadEntries && ...` — этот no-op делает selectPool
                 * совместимым со страницей балансов: фактическая загрузка
                 * происходит через watcher selectedPool → loadBalances.
                 *
                 * @returns {void}
                 */
                loadEntries: function () { /* no-op for balance page */ },

                /**
                 * Догружает следующую страницу балансов при прокрутке таблицы.
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
