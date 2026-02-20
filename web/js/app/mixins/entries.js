/**
 * Mixin: Entries (NostroEntry)
 * Всё что касается записей выверки — CRUD, inline-редактирование комментария
 */
var EntriesMixin = {
    methods: {

        // Открыть форму добавления
        showAddEntryModal: function (account) {
            this.editingEntry = {
                id:             null,
                account_id:     account ? account.id : null,
                ls:             'L',
                dc:             'Debit',
                amount:         '',
                currency:       account && account.currency ? account.currency : '',
                value_date:     '',
                post_date:      '',
                instruction_id: '',
                end_to_end_id:  '',
                transaction_id: '',
                message_id:     '',
                comment:        ''
            };
            this._showModal('entryModal');
        },

        // Открыть форму редактирования
        editEntry: function (entry, account) {
            this.editingEntry = {
                id:             entry.id,
                account_id:     account.id,
                ls:             entry.ls,
                dc:             entry.dc,
                amount:         entry.amount_raw,
                currency:       entry.currency,
                value_date:     entry.value_date     || '',
                post_date:      entry.post_date      || '',
                instruction_id: entry.instruction_id || '',
                end_to_end_id:  entry.end_to_end_id  || '',
                transaction_id: entry.transaction_id || '',
                message_id:     entry.message_id     || '',
                comment:        entry.comment        || ''
            };
            this._showModal('entryModal');
        },

        // Сохранить (создать или обновить)
        saveEntry: function () {
            var self = this;

            if (!self.editingEntry.account_id) {
                Swal.fire('Ошибка', 'Выберите Ностро банк', 'error'); return;
            }
            if (!self.editingEntry.amount || isNaN(self.editingEntry.amount)) {
                Swal.fire('Ошибка', 'Укажите корректную сумму', 'error'); return;
            }
            if (!self.editingEntry.currency) {
                Swal.fire('Ошибка', 'Укажите валюту', 'error'); return;
            }

            var apiCall = self.editingEntry.id
                ? SmartMatchApi.entries.update(self.editingEntry)
                : SmartMatchApi.entries.create(self.editingEntry);

            apiCall
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self.closeEntryModal();
                        self.refreshAccounts();
                    } else {
                        var errText = response.data.errors
                            ? Object.values(response.data.errors).join('\n')
                            : (response.data.message || 'Ошибка');
                        Swal.fire('Ошибка', errText, 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось сохранить запись', 'error'); });
        },

        // Удаление
        deleteEntry: function (entry) {
            var self = this;
            Swal.fire({
                title: 'Удалить запись?',
                text: (entry.match_id || '(нет Match ID)') + ' | ' + entry.amount + ' ' + entry.currency,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Удалить',
                cancelButtonText: 'Отмена'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                SmartMatchApi.entries.delete(entry.id)
                    .then(function (response) {
                        if (response.data.success) {
                            Swal.fire('Удалено', response.data.message, 'success');
                            self.refreshAccounts();
                        } else {
                            Swal.fire('Ошибка', response.data.message, 'error');
                        }
                    })
                    .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить запись', 'error'); });
            });
        },

        // Inline-редактирование комментария
        startEditComment: function (entry) {
            this.editingCommentId    = entry.id;
            this.editingCommentValue = entry.comment || '';
        },

        cancelEditComment: function () {
            this.editingCommentId    = null;
            this.editingCommentValue = '';
        },

        saveComment: function (entry) {
            var self = this;
            SmartMatchApi.entries.updateComment(entry.id, self.editingCommentValue)
                .then(function (response) {
                    if (response.data.success) {
                        entry.comment = response.data.comment;
                        self.cancelEditComment();
                    } else {
                        Swal.fire('Ошибка', 'Не удалось сохранить комментарий', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Ошибка при сохранении', 'error'); });
        }
    }
};