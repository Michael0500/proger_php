/**
 * Стартер Vue-страницы "Выверка" (`#entries-app`).
 *
 * Подключает mixins модалок, категорий, ностро-банков, таблицы записей,
 * ручного/автоматического квитования и сохранения состояния. Инстанс
 * запускается только если корневой элемент есть в DOM, поэтому файл может
 * безопасно грузиться на всех страницах через общий asset bundle.
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

        /**
         * Vue-инстанс рабочей страницы выверки.
         *
         * Управляет сайдбаром категорий и ностро-банков, таблицей активных
         * NostroEntry, фильтрами, настройками колонок, ручным квитованием и
         * пошаговым автоквитованием. Данные всегда загружаются через API,
         * где применяются ограничения текущей компании.
         */
        new Vue({
            el: '#entries-app',

            mixins: [ModalsMixin, CategoriesMixin, PoolsMixin, EntriesMixin, MatchingMixin, StatePersistenceMixin],

            /**
             * Начальное состояние страницы выверки.
             *
             * @type {Object}
             * @property {boolean} isSidebarCollapsed Признак свёрнутого сайдбара.
             * @property {number} sidebarWidth Ширина сайдбара, сохраняемая в StateStorage.
             * @property {Array<Object>} categories Категории и связанные ностро-банки.
             * @property {?Object} selectedCategory Выбранная категория.
             * @property {?Object} selectedPool Выбранный ностро-банк для загрузки записей.
             * @property {string} activeSection Текущий раздел UI.
             */
            data: {
                // Сайдбар
                isSidebarCollapsed: false,
                sidebarWidth:       240,
                isResizingSidebar:  false,

                // Flyout при свёрнутом сайдбаре
                flyoutCategory: null,
                flyoutStyle:    {},
                flyoutTimer:    null,

                // Категории / ностро-банки
                loadingCategories:   false,
                categories:          [],
                selectedCategory:    null,
                selectedPool:        null,

                // Секция фиксирована на выверке; поле нужно mixin'ам
                activeSection: 'entries',

                newCategory:     { name: '', description: '' },
                editingCategory: { id: null, name: '', description: '' },

                collapsedCategories: {},

                // Контекстное меню строки
                openRowMenu:  null,
                rowMenuStyle: {}
            },

            computed: {
                /**
                 * Возвращает inline-стили ширины сайдбара.
                 *
                 * @returns {Object} CSS-свойства для раскрытого сайдбара или пустой объект.
                 */
                sidebarStyle: function () {
                    if (this.isSidebarCollapsed) return {};
                    return {
                        width:    this.sidebarWidth + 'px',
                        minWidth: this.sidebarWidth + 'px'
                    };
                }
            },

            /**
             * Загружает стартовые данные страницы и подключает глобальные UI-обработчики.
             *
             * Побочные эффекты: подписка на клик документа для закрытия меню,
             * загрузка категорий, списка ностро-банков и настроек колонок.
             *
             * @returns {void}
             */
            mounted: function () {
                var self = this;
                document.addEventListener('click', function () { self.openRowMenu = null; });

                this._initColManagement();
                this.loadTableColumnsPrefs();
                this.loadCategories();
                this.loadAccountPools();
            },

            methods: {
                /**
                 * Переключает состояние сайдбара категорий.
                 *
                 * Значение сохраняется watcher'ом из `StatePersistenceMixin`.
                 *
                 * @returns {void}
                 */
                toggleSidebar: function () {
                    this.isSidebarCollapsed = !this.isSidebarCollapsed;
                },

                /**
                 * Открывает или закрывает контекстное меню строки.
                 *
                 * @param {string} type Тип сущности меню.
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
                },

                /**
                 * Запускает интерактивное изменение ширины сайдбара.
                 *
                 * Читает начальную позицию мыши, меняет `sidebarWidth` в пределах
                 * 180-500px и сбрасывает временные стили body после mouseup.
                 *
                 * @param {MouseEvent} e Событие начала перетаскивания разделителя.
                 * @returns {void}
                 */
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

                /**
                 * Показывает flyout категории при наведении на свёрнутый сайдбар.
                 *
                 * @param {Object} category Категория с дочерними ностро-банками.
                 * @param {MouseEvent} event Событие hover на элементе категории.
                 * @returns {void}
                 */
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
                /**
                 * Запускает отложенное закрытие flyout категории.
                 *
                 * @returns {void}
                 */
                onCategoryLeave: function () {
                    if (!this.isSidebarCollapsed) return;
                    var self = this;
                    this.flyoutTimer = setTimeout(function () {
                        self.flyoutCategory = null;
                    }, 120);
                },
                /**
                 * Отменяет таймер закрытия flyout при наведении на сам flyout.
                 *
                 * @returns {void}
                 */
                onFlyoutEnter: function () { clearTimeout(this.flyoutTimer); },
                /**
                 * Закрывает flyout после короткой задержки при уходе курсора.
                 *
                 * @returns {void}
                 */
                onFlyoutLeave: function () {
                    var self = this;
                    this.flyoutTimer = setTimeout(function () {
                        self.flyoutCategory = null;
                    }, 80);
                },
                /**
                 * Немедленно закрывает flyout категории.
                 *
                 * @returns {void}
                 */
                closeFlyout: function () {
                    clearTimeout(this.flyoutTimer);
                    this.flyoutCategory = null;
                }
            }
        });
    });
})();
