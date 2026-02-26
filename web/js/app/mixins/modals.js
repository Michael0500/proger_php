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
        closeEntryModal: function () { this._hideModal('entryModal'); },

        // История
        closeHistoryModal: function () {
            this._hideModal('entryHistoryModal');
            if (this.historyEntry) this.historyEntry = null;
            if (this.historyItems) this.historyItems = [];
        },

        // ── Методы для отображения истории ───────────────────

        /**
         * Иконка для действия истории
         */
        getHistoryIcon: function (action) {
            var icons = {
                'create':  'fas fa-plus-circle',
                'update':  'fas fa-edit',
                'delete':  'fas fa-trash-alt',
                'archive': 'fas fa-archive'
            };
            return icons[action] || 'fas fa-clock';
        },

        /**
         * Текстовая метка действия
         */
        getHistoryActionLabel: function (action) {
            var labels = {
                'create':  'Создано',
                'update':  'Изменено',
                'delete':  'Удалено',
                'archive': 'Заархивировано'
            };
            return labels[action] || action;
        },

        /**
         * Форматирование даты
         */
        formatDate: function (dateStr) {
            if (!dateStr) return '—';
            var d = new Date(dateStr);
            var day = String(d.getDate()).padStart(2, '0');
            var month = String(d.getMonth() + 1).padStart(2, '0');
            var year = d.getFullYear();
            var hours = String(d.getHours()).padStart(2, '0');
            var mins = String(d.getMinutes()).padStart(2, '0');
            return day + '.' + month + '.' + year + ' ' + hours + ':' + mins;
        },

        /**
         * Человекочитаемое имя поля
         */
        getFieldLabel: function (field) {
            var labels = {
                'account_id':     'Счёт',
                'match_id':       'Match ID',
                'ls':             'L/S',
                'dc':             'D/C',
                'amount':         'Сумма',
                'currency':       'Валюта',
                'value_date':     'Дата валютирования',
                'post_date':      'Дата проводки',
                'instruction_id': 'Instruction ID',
                'end_to_end_id':  'EndToEnd ID',
                'transaction_id': 'Transaction ID',
                'message_id':     'Message ID',
                'comment':        'Комментарий',
                'match_status':   'Статус',
                'source':         'Источник'
            };
            return labels[field] || field;
        },

        /**
         * Форматирование значения для отображения
         */
        formatValue: function (valuesObj, changedField) {
            if (!valuesObj) return '—';
            var lines = [];
            var self = this;
            Object.keys(valuesObj).forEach(function (key) {
                var val = valuesObj[key];
                var label = self.getFieldLabel(key);
                var formatted = val;
                if (key === 'amount') {
                    formatted = parseFloat(val).toLocaleString('ru-RU', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + ' ' + (valuesObj.currency || '');
                } else if (key === 'dc') {
                    formatted = val === 'Debit' ? 'Debit (D)' : 'Credit (C)';
                } else if (key === 'ls') {
                    formatted = val === 'L' ? 'Ledger (L)' : 'Statement (S)';
                } else if (key === 'match_status') {
                    var statusLabels = { 'U': 'Unmatched', 'M': 'Matched', 'I': 'Ignored', 'A': 'Archived' };
                    formatted = statusLabels[val] || val;
                }
                lines.push(label + ': ' + (formatted === null || formatted === '' ? '—' : formatted));
            });
            return lines.join('\n');
        }
    }
};