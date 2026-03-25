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

        // ── Категории ─────────────────────────────────────────────
        categories: {
            list:   function ()       { return get(AppRoutes.categoryGetCategories); },
            create: function (data)   { return post(AppRoutes.categoryCreate, data); },
            update: function (data)   { return post(AppRoutes.categoryUpdate, data); },
            delete: function (id)     { return post(AppRoutes.categoryDelete, { id: id }); }
        },

        // ── Группы ────────────────────────────────────────────────
        groups: {
            create:      function (data)    { return post(AppRoutes.groupCreate, data); },
            update:      function (data)    { return post(AppRoutes.groupUpdate, data); },
            delete:      function (id)      { return post(AppRoutes.groupDelete, { id: id }); },
            accounts:    function (id)      { return get(AppRoutes.groupGetAccounts, { id: id }); },
            getFilters:  function(groupId)  { return get(AppRoutes.groupGetFilters, { group_id: groupId }); },
            saveFilters: function(data)     { return post(AppRoutes.groupSaveFilters, data); },
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
