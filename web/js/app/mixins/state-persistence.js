/**
 * StatePersistenceMixin
 * Сохраняет/восстанавливает состояние сайдбара (ширина + свернут ли).
 * Используется только стартером страницы выверки (#entries-app).
 */
var StatePersistenceMixin = {

    created: function () {
        var collapsed = StateStorage.get('sidebarCollapsed', false);
        this.isSidebarCollapsed = !!collapsed;

        var width = StateStorage.get('sidebarWidth', 240);
        if (typeof width === 'number' && width >= 180 && width <= 500) {
            this.sidebarWidth = width;
        }
    },

    watch: {
        isSidebarCollapsed: function (val) {
            StateStorage.set('sidebarCollapsed', val);
        },
        sidebarWidth: function (val) {
            StateStorage.set('sidebarWidth', val);
        }
    }
};
