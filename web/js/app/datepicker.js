(function() {
    flatpickr.localize(flatpickr.l10ns.ru);

    Vue.mixin({
        methods: {
            fmtDate: function(dateStr) {
                if (!dateStr) return '—';
                var parts = String(dateStr).substring(0, 10).split('-');
                if (parts.length !== 3) return dateStr;
                return parts[2] + '.' + parts[1] + '.' + parts[0];
            }
        }
    });

    Vue.directive('datepicker', {
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

        unbind: function(el) {
            if (el._flatpickr) el._flatpickr.destroy();
        }
    });
})();
