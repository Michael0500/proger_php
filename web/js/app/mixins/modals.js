/**
 * Mixin: Modal utilities
 * Методы открытия/закрытия Bootstrap 5 модальных окон
 */
var ModalsMixin = {
    methods: {
        _showModal: function (id) {
            var el = document.getElementById(id);
            if (el) {
                var existing = bootstrap.Modal.getInstance(el);
                if (existing) {
                    existing.show();
                } else {
                    new bootstrap.Modal(el).show();
                }
            }
        },
        _hideModal: function (id) {
            var el = document.getElementById(id);
            if (el) {
                var m = bootstrap.Modal.getInstance(el);
                if (m) m.hide();
            }
        },

        // Группы
        closeAddGroupModal:  function () { this._hideModal('addGroupModal'); },
        closeEditGroupModal: function () { this._hideModal('editGroupModal'); },

        // Пулы
        closeAddPoolModal:      function () { this._hideModal('addPoolModal'); },
        closeEditPoolModal:     function () { this._hideModal('editPoolModal'); },
        closeConfigurePoolModal: function () { this._hideModal('configurePoolModal'); },

        // Записи
        closeEntryModal: function () { this._hideModal('entryModal'); }
    }
};