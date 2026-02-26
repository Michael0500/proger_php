/**
 * StateStorage — сохранение/восстановление состояния UI в localStorage.
 * Ключ хранилища привязан к userId, чтобы у каждого пользователя было своё состояние.
 */
var StateStorage = (function () {
    'use strict';

    var _userId = null;
    var _prefix = 'smartmatch_ui_';

    function _key(name) {
        return _prefix + (_userId || 'guest') + '_' + name;
    }

    return {
        /**
         * Инициализация — передаём ID текущего пользователя.
         * Вызывать до первого использования.
         */
        init: function (userId) {
            _userId = userId ? String(userId) : 'guest';
        },

        get: function (name, defaultValue) {
            try {
                var raw = localStorage.getItem(_key(name));
                if (raw === null) return (defaultValue !== undefined ? defaultValue : null);
                return JSON.parse(raw);
            } catch (e) {
                return (defaultValue !== undefined ? defaultValue : null);
            }
        },

        set: function (name, value) {
            try {
                localStorage.setItem(_key(name), JSON.stringify(value));
            } catch (e) {
                // localStorage недоступен (приватный режим и т.д.) — молча игнорируем
            }
        },

        remove: function (name) {
            try {
                localStorage.removeItem(_key(name));
            } catch (e) {}
        }
    };
})();