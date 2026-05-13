/**
 * Глобальные Vue-хелперы, доступные во всех Vue-инстансах проекта.
 * Регистрируется через Vue.mixin() — подмешивается автоматически.
 */
(function () {
    if (!window.Vue) return;

    Vue.mixin({
        computed: {
            // Глобальные справочники, доступны во всех инстансах как this.dictCurrencies / this.dictCountries
            dictCurrencies: function () {
                return (window.AppDictionaries && window.AppDictionaries.currencies) || [];
            },
            dictCountries: function () {
                return (window.AppDictionaries && window.AppDictionaries.countries) || [];
            },
            // Просто список кодов для select-ов
            dictCurrencyCodes: function () {
                return ((window.AppDictionaries && window.AppDictionaries.currencies) || []).map(function (c) {
                    return c.code;
                });
            },
        },
        methods: {
            recordText: function (count) {
                var n  = Math.abs(count) % 100;
                var n1 = n % 10;
                if (n > 10 && n < 20) return 'записей';
                if (n1 > 1 && n1 < 5)  return 'записи';
                if (n1 === 1)          return 'запись';
                return 'записей';
            },

            formatAmount: function (val) {
                if (val === null || val === undefined || val === '') return '—';
                var s = String(val).trim();
                var sign = '';
                if (s.charAt(0) === '-') {
                    sign = '-';
                    s = s.slice(1);
                }
                s = s.replace(/\s/g, '').replace(/,/g, '');
                if (!/^\d+(\.\d+)?$/.test(s)) return '—';
                var parts = s.split('.');
                var intPart = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                var decPart = ((parts[1] || '') + '00').slice(0, 2);
                return sign + intPart + '.' + decPart;
            }
        }
    });
})();
