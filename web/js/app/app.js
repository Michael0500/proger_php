/**
 * SmartMatch — Vue App entry point
 */
(function () {
    'use strict';

    // ── axios config ──────────────────────────────────────────────
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
    }
    //axios.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded';
    axios.defaults.transformRequest = [(data) => JSON.stringify(data)];
    axios.defaults.headers.post['Content-Type'] = 'application/json';

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('app')) return;

        new Vue({
            el: '#app',
            mixins: [ModalsMixin, GroupsMixin, PoolsMixin, EntriesMixin, MatchingMixin,BalanceMixin],

            data: {
                isSidebarCollapsed: false,
                loadingGroups:      false,
                groups:             [],
                selectedGroup:      null,
                selectedPool:       null,

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
            },

            methods: {
                toggleSidebar: function () {
                    this.isSidebarCollapsed = !this.isSidebarCollapsed;
                }
            }
        });
    });
})();