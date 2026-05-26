/**
 * Стартер Vue-страницы "Архив балансов" (`#balance-archive-app`).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('balance-archive-app');
        if (!root) return;

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && window.axios) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
        axios.defaults.transformRequest = [function (data) { return JSON.stringify(data); }];
        axios.defaults.headers.post['Content-Type'] = 'application/json';

        StateStorage.init((window.AppConfig && window.AppConfig.userId) || 'guest');

        new Vue({
            el: '#balance-archive-app',
            mixins: [ModalsMixin, BalanceArchiveMixin],

            data: {
                activeSection: 'balance-archive'
            },

            mounted: function () {
                this.bindBalanceArchiveSubmitGuard();
                this._initBalanceArchiveColManagement();
                this.loadBalanceArchiveTableColumnsPrefs();
                this.loadBalanceArchiveAccounts();
                this.loadBalanceArchive(true);
                this.loadBalanceArchiveStats();
            },

            methods: {
                isBalanceArchiveExpired: function (expiresAt) {
                    if (!expiresAt) return false;
                    return new Date(expiresAt) < new Date();
                },

                isBalanceArchiveExpiringSoon: function (expiresAt) {
                    if (!expiresAt) return false;
                    var d = new Date(expiresAt);
                    var soon = new Date();
                    soon.setDate(soon.getDate() + 90);
                    return d < soon && d >= new Date();
                }
            }
        });
    });
})();
