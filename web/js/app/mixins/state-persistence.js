/**
 * StatePersistenceMixin
 * Сохраняет/восстанавливает: активную вкладку и состояние сайдбара.
 * Категории/группы сохраняются напрямую в CategoriesMixin и GroupsMixin.
 */
var StatePersistenceMixin = {

    created: function () {
        // Восстанавливаем вкладку
        var section = StateStorage.get('activeSection', 'entries');
        if (['entries', 'balance', 'archive'].indexOf(section) !== -1) {
            this.activeSection = section;
        }
        // Восстанавливаем сайдбар
        var collapsed = StateStorage.get('sidebarCollapsed', false);
        this.isSidebarCollapsed = !!collapsed;
    },

    watch: {
        activeSection: function (val) {
            StateStorage.set('activeSection', val);
        },
        isSidebarCollapsed: function (val) {
            StateStorage.set('sidebarCollapsed', val);
        }
    }
};