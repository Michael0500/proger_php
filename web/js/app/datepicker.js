/**
 * Интеграция flatpickr с Vue-формами SmartMatch.
 *
 * Модуль локализует календарь на русский язык, добавляет общий форматтер дат
 * для таблиц и регистрирует директиву `v-datepicker`. Директива синхронизирует
 * flatpickr с `v-model` через события `input` и `change`, поэтому формы
 * продолжают отправлять серверный формат `YYYY-MM-DD`.
 */
(function() {
    flatpickr.localize(flatpickr.l10ns.ru);

    Vue.mixin({
        methods: {
            /**
             * Форматирует дату из API в пользовательский вид.
             *
             * @param {string|null|undefined} dateStr Дата в формате `YYYY-MM-DD` или ISO-строка.
             * @returns {string} Дата в формате `DD.MM.YYYY`, исходное значение при нестандартном формате или `—`.
             */
            fmtDate: function(dateStr) {
                if (!dateStr) return '—';
                var parts = String(dateStr).substring(0, 10).split('-');
                if (parts.length !== 3) return dateStr;
                return parts[2] + '.' + parts[1] + '.' + parts[0];
            }
        }
    });

    Vue.directive('datepicker', {
        /**
         * Инициализирует flatpickr на input-элементе и подключает синхронизацию с Vue.
         *
         * Читает ограничения `min` и `max` из DOM, создаёт альтернативное поле
         * отображения `d.m.Y`, а реальное значение оставляет в серверном формате.
         *
         * @param {HTMLInputElement} el DOM-элемент, к которому применена директива.
         * @returns {void}
         */
        inserted: function(el) {
            var config = {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'd.m.Y',
                allowInput: true,
                locale: 'ru',
                onChange: function(selectedDates, dateStr) {
                    el.value = dateStr;
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                },
                onClose: function(selectedDates, dateStr) {
                    var newVal = selectedDates.length ? dateStr : '';
                    if (el.value !== newVal) {
                        el.value = newVal;
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            };

            if (el.getAttribute('min')) config.minDate = el.getAttribute('min');
            if (el.getAttribute('max')) config.maxDate = el.getAttribute('max');

            flatpickr(el, config);

            if (el.value) {
                el._flatpickr.setDate(el.value, false);
            }
        },

        /**
         * Синхронизирует flatpickr после изменения Vue-состояния или атрибутов.
         *
         * Используется при программном изменении дат, очистке формы и обновлении
         * динамических ограничений `min`/`max`.
         *
         * @param {HTMLInputElement} el DOM-элемент с экземпляром flatpickr.
         * @returns {void}
         */
        componentUpdated: function(el) {
            var fp = el._flatpickr;
            if (!fp) return;

            var current = fp.selectedDates.length
                ? fp.formatDate(fp.selectedDates[0], 'Y-m-d')
                : '';
            var wanted = el.value || '';

            if (current !== wanted) {
                fp.setDate(wanted, false);
            }

            fp.set('minDate', el.getAttribute('min') || undefined);
            fp.set('maxDate', el.getAttribute('max') || undefined);
        },

        /**
         * Уничтожает экземпляр flatpickr при удалении input из DOM.
         *
         * @param {HTMLInputElement} el DOM-элемент директивы.
         * @returns {void}
         */
        unbind: function(el) {
            if (el._flatpickr) el._flatpickr.destroy();
        }
    });
})();
