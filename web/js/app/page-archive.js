/**
 * SmartMatch — Vue-инстанс страницы "Архив" (#archive-app).
 * Подключает mixins: Modals, Archive.
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

        new Vue({
            el: '#archive-app',
            mixins: [ModalsMixin, ArchiveMixin],

            data: {
                activeSection: 'archive'
            },

            mounted: function () {
                this.bindArchiveSubmitGuard();
                this.loadArchiveAccountPools();
                this.loadArchiveAccounts();
                this.loadArchive(true);
                this.loadArchiveStats();
            },

            methods: {
                isExpired: function (expiresAt) {
                    if (!expiresAt) return false;
                    return new Date(expiresAt) < new Date();
                },

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
