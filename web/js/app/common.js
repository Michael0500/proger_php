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
                var n = parseFloat(val);
                if (isNaN(n)) return '—';
                return n.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
        }
    });
})();
