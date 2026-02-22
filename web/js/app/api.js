/**
 * SmartMatch API layer
 * Все URL берутся из глобального объекта window.AppRoutes,
 * который инициализируется в _vue-scripts.php через Yii2 Url::to()
 */
var SmartMatchApi = (function () {

    function post(url, data) {
        return axios.post(url, data);
    }

    function get(url, params) {
        return axios.get(url, { params: params || {} });
    }


    return {
        // ── Прямые методы (используются в entries.js, matching.js) ──
        get:     get,
        post:    function (url, data) {
            return post(url, data).then(function (r) { return r.data; });
        },

        // ── Группы ──────────────────────────────────────────────────
        groups: {
            list:   function ()       { return get(AppRoutes.groupGetGroups); },
            create: function (data)   { return post(AppRoutes.groupCreate, data); },
            update: function (data)   { return post(AppRoutes.groupUpdate, data); },
            delete: function (id)     { return post(AppRoutes.groupDelete, { id: id }); }
        },

        // ── Пулы ────────────────────────────────────────────────────
        pools: {
            create:   function (data) { return post(AppRoutes.poolCreate, data); },
            update:   function (data) { return post(AppRoutes.poolUpdate, data); },
            delete:   function (id)   { return post(AppRoutes.poolDelete, { id: id }); },
            accounts: function (id)   { return get(AppRoutes.poolGetAccounts, { id: id }); }
        },

        // ── Записи выверки (NostroEntry) ────────────────────────────
        entries: {
            create:        function (data)         { return post(AppRoutes.entryCreate, data); },
            update:        function (data)         { return post(AppRoutes.entryUpdate, data); },
            delete:        function (id)           { return post(AppRoutes.entryDelete, { id: id }); },
            updateComment: function (id, comment)  {
                return post(AppRoutes.entryUpdateComment, { id: id, comment: comment });
            }
        }
    };
})();