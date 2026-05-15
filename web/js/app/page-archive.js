/**
 * Стартер Vue-страницы "Архив" (`#archive-app`).
 *
 * Подключает модалки и `ArchiveMixin`, настраивает axios для JSON API и
 * запускает загрузку архивных записей, статистики, счетов и ностро-банков.
 * Инстанс создаётся только при наличии корневого элемента страницы.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('archive-app');
        if (!root) return;

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && window.axios) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
        axios.defaults.transformRequest = [function (data) { return JSON.stringify(data); }];
        axios.defaults.headers.post['Content-Type'] = 'application/json';

        StateStorage.init((window.AppConfig && window.AppConfig.userId) || 'guest');

        /**
         * Vue-инстанс страницы архива сквитованных записей.
         *
         * Управляет списком `nostro_entries_archive`, восстановлением группы
         * по `match_id`, ручным архивированием, очисткой просроченных строк и
         * настройками хранения. Все операции выполняются через серверные API.
         */
        new Vue({
            el: '#archive-app',
            mixins: [ModalsMixin, ArchiveMixin],

            /**
             * Начальное состояние оболочки страницы архива.
             *
             * @type {Object}
             * @property {string} activeSection Раздел, используемый общими partial'ами.
             */
            data: {
                activeSection: 'archive'
            },

            /**
             * Загружает начальные данные архива и подключает защиту форм.
             *
             * Побочные эффекты: HTTP-запросы к archive endpoints, подписки на
             * управление колонками и запрет нативной отправки вложенных форм.
             *
             * @returns {void}
             */
            mounted: function () {
                this.bindArchiveSubmitGuard();
                this._initArchiveColManagement();
                this.loadArchiveTableColumnsPrefs();
                this.loadArchiveAccountPools();
                this.loadArchiveAccounts();
                this.loadArchive(true);
                this.loadArchiveStats();
            },

            methods: {
                /**
                 * Проверяет, истёк ли срок хранения архивной записи.
                 *
                 * @param {string|null|undefined} expiresAt Дата `expires_at` из API.
                 * @returns {boolean} `true`, если дата хранения уже прошла.
                 */
                isExpired: function (expiresAt) {
                    if (!expiresAt) return false;
                    return new Date(expiresAt) < new Date();
                },

                /**
                 * Проверяет, приблизился ли срок удаления архивной записи.
                 *
                 * Используется для визуального предупреждения за 90 дней до
                 * `expires_at`, не меняет серверные настройки retention.
                 *
                 * @param {string|null|undefined} expiresAt Дата `expires_at` из API.
                 * @returns {boolean} `true`, если запись истекает в ближайшие 90 дней.
                 */
                isExpiringSoon: function (expiresAt) {
                    if (!expiresAt) return false;
                    var d    = new Date(expiresAt);
                    var soon = new Date();
                    soon.setDate(soon.getDate() + 90);
                    return d < soon && d >= new Date();
                }
            }
        });
    });
})();
