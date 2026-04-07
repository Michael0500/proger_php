/**
 * StatePersistenceMixin
 * Сохраняет/восстанавливает: активную вкладку и состояние сайдбара.
 * Категории/группы сохраняются напрямую в CategoriesMixin и GroupsMixin.
 */
var StatePersistenceMixin = {

    created: function () {
        var forcedSection = window.AppConfig && window.AppConfig.initialSection &&
                            window.AppConfig.initialSection !== 'entries';

        // На отдельных страницах (архив/баланс) секция зафиксирована — не восстанавливаем из storage
        if (!forcedSection) {
            var section = StateStorage.get('activeSection', 'entries');
            if (['entries', 'balance', 'archive'].indexOf(section) !== -1) {
                this.activeSection = section;
            }
        }

        // Восстанавливаем сайдбар
        var collapsed = StateStorage.get('sidebarCollapsed', false);
        this.isSidebarCollapsed = !!collapsed;

        // Восстанавливаем ширину сайдбара
        var width = StateStorage.get('sidebarWidth', 240);
        if (typeof width === 'number' && width >= 180 && width <= 500) {
            this.sidebarWidth = width;
        }
    },

    watch: {
        activeSection: function (val) {
            // На отдельных страницах не пишем в storage
            var forcedSection = window.AppConfig && window.AppConfig.initialSection &&
                                window.AppConfig.initialSection !== 'entries';
            if (!forcedSection) StateStorage.set('activeSection', val);
        },
        isSidebarCollapsed: function (val) {
            StateStorage.set('sidebarCollapsed', val);
        },
        sidebarWidth: function (val) {
            StateStorage.set('sidebarWidth', val);
        }
    }
};