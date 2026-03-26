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

        // Категории
        closeAddCategoryModal:  function () { this._hideModal('addCategoryModal'); },
        closeEditCategoryModal: function () { this._hideModal('editCategoryModal'); },

        // Группы
        closeAddGroupModal:       function () { this._hideModal('addGroupModal'); },
        closeEditGroupModal:      function () { this._hideModal('editGroupModal'); },
        closeConfigureGroupModal: function () {
            var self = this;
            (self.groupFilters || []).forEach(function (f, i) { self._destroyFilterSelect2(i); });
            this._hideModal('configureGroupModal');
        },

        // Записи
        closeEntryModal: function () { this._hideModal('entryModal'); },

        // История
        closeEntryHistoryModal: function (e) {
            if (e && e.stopPropagation) e.stopPropagation();
            this._hideModal('entryHistoryModal');
            this.historyEntry = null;
            this.historyItems = [];
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
        },
        /**
         * Получить значение поля из снапшота записи аудита
         */
        getSnapVal: function (item, field) {
            if (!item || !item.snapshot) return null;
            var v = item.snapshot[field];
            return (v === undefined || v === null) ? null : v;
        },

        /**
         * Получить старое значение поля (до изменения)
         */
        getOldVal: function (item, field) {
            if (!item || !item.changes || !item.changes[field]) return null;
            var v = item.changes[field]['old'];
            return (v === undefined || v === null) ? null : v;
        },

        /**
         * Проверить, было ли данное поле изменено в этой записи аудита
         */
        isChanged: function (item, field) {
            if (!item) return false;
            if (!item.changed_fields || !item.changed_fields.length) return false;
            return item.changed_fields.indexOf(field) !== -1;
        },

        /**
         * CSS-класс ячейки: подсветка изменённых и созданных полей
         */
        histCellClass: function (item, field) {
            var base = 'hist-td';
            if (this.isChanged(item, field)) {
                return base + ' hist-td-changed';
            }
            if (item && item.action === 'create') {
                var snap = item.snapshot || {};
                if (snap[field] !== undefined && snap[field] !== null && snap[field] !== '') {
                    return base + ' hist-td-created';
                }
            }
            return base;
        },

        /**
         * Читаемый статус квитования
         */
        histStatusLabel: function (code) {
            if (!code) return '—';
            var map = { 'U': 'Ожидает', 'M': 'Сквит.', 'I': 'Игнор', 'A': 'Архив' };
            return map[code] || code;
        },
    }
};