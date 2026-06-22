/**
 * StateStorage — сохранение и восстановление клиентского UI-состояния.
 *
 * Модуль хранит в localStorage только долгоживущие настройки интерфейса:
 * раскрытые блоки, ширины колонок и похожие предпочтения.
 * Фильтры списков не сохраняются между переходами по страницам.
 * Бизнес-данные, `match_id`, записи выверки и права доступа не сохраняются на
 * клиенте. Ключи привязаны к userId, чтобы пользователи одной рабочей станции
 * не перезаписывали состояние друг друга.
 *
 * @type {{
 *   init: function(number|string|null|undefined): void,
 *   get: function(string, *=): *,
 *   set: function(string, *): void,
 *   remove: function(string): void
 * }}
 */
var StateStorage = (function () {
    'use strict';

    var _userId = null;
    var _prefix = 'smartmatch_ui_';

    /**
     * Формирует физический ключ localStorage для текущего пользователя.
     *
     * @param {string} name Логическое имя UI-настройки.
     * @returns {string} Ключ localStorage.
     */
    function _key(name) {
        return _prefix + (_userId || 'guest') + '_' + name;
    }

    return {
        /**
         * Инициализирует пространство ключей для текущего пользователя.
         *
         * Вызывается стартерами страниц до первого чтения/записи настроек.
         *
         * @param {number|string|null|undefined} userId ID пользователя из `AppConfig`.
         * @returns {void}
         */
        init: function (userId) {
            _userId = userId ? String(userId) : 'guest';
        },

        /**
         * Читает сохранённое значение UI-настройки.
         *
         * При недоступном localStorage или повреждённом JSON возвращает
         * `defaultValue` либо `null`, чтобы интерфейс продолжил работу.
         *
         * @param {string} name Логическое имя настройки.
         * @param {*=} defaultValue Значение по умолчанию.
         * @returns {*} Распарсенное значение, `defaultValue` или `null`.
         */
        get: function (name, defaultValue) {
            try {
                var raw = localStorage.getItem(_key(name));
                if (raw === null) return (defaultValue !== undefined ? defaultValue : null);
                return JSON.parse(raw);
            } catch (e) {
                return (defaultValue !== undefined ? defaultValue : null);
            }
        },

        /**
         * Сохраняет значение UI-настройки.
         *
         * Ошибки квоты, приватного режима или блокировки storage подавляются:
         * потеря предпочтений интерфейса не должна прерывать бизнес-сценарий.
         *
         * @param {string} name Логическое имя настройки.
         * @param {*} value JSON-сериализуемое значение.
         * @returns {void}
         */
        set: function (name, value) {
            try {
                localStorage.setItem(_key(name), JSON.stringify(value));
            } catch (e) {
                // localStorage недоступен (приватный режим и т.д.) — молча игнорируем
            }
        },

        /**
         * Удаляет сохранённую UI-настройку текущего пользователя.
         *
         * @param {string} name Логическое имя настройки.
         * @returns {void}
         */
        remove: function (name) {
            try {
                localStorage.removeItem(_key(name));
            } catch (e) {}
        }
    };
})();
