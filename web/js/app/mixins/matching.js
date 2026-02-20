/**
 * Mixin: Matching
 * Ручное квитование, автоквитование, управление правилами.
 *
 * Зависимости: SmartMatchApi (api.js должен иметь matching секцию)
 */
var MatchingMixin = {
    data: function () {
        return {
            // ── Выбранные для квитования ────────────────────────────
            selectedIds: [],          // ID выбранных записей (checkbox)
            selectionSummary: null,   // { sum_ledger, sum_statement, diff, cnt_ledger, cnt_statement }

            // ── Правила автоквитования ──────────────────────────────
            matchingRules: [],
            loadingRules:  false,
            editingRule: {
                id: null, name: '', section: 'NRE', pair_type: 'LS',
                match_dc: true, match_amount: true, match_value_date: true,
                match_instruction_id: false, match_end_to_end_id: false,
                match_transaction_id: false, match_message_id: false,
                cross_id_search: false, is_active: true, priority: 100, description: ''
            },

            // ── Автоквитование ──────────────────────────────────────
            autoMatchRunning: false,
        };
    },

    computed: {
        // Есть ли выбранные записи
        hasSelection: function () { return this.selectedIds.length >= 2; },

        // Разница сумм для UI
        summaryDiff: function () {
            if (!this.selectionSummary) return null;
            return this.selectionSummary.diff;
        },

        summaryBalanced: function () {
            return this.selectionSummary && this.selectionSummary.diff === 0;
        }
    },

    methods: {

        // ── Выбор записей ──────────────────────────────────────────

        toggleEntrySelection: function (entryId) {
            var idx = this.selectedIds.indexOf(entryId);
            if (idx === -1) {
                this.selectedIds.push(entryId);
            } else {
                this.selectedIds.splice(idx, 1);
            }
            this.updateSummary();
        },

        isSelected: function (entryId) {
            return this.selectedIds.indexOf(entryId) !== -1;
        },

        // Выбрать все записи счёта (только незаквитованные)
        selectAllInAccount: function (account) {
            var self = this;
            account.entries.forEach(function (e) {
                if (e.match_status === 'U' && self.selectedIds.indexOf(e.id) === -1) {
                    self.selectedIds.push(e.id);
                }
            });
            this.updateSummary();
        },

        clearSelection: function () {
            this.selectedIds = [];
            this.selectionSummary = null;
        },

        // Обновить панель подсчёта сумм
        updateSummary: function () {
            var self = this;
            if (self.selectedIds.length < 1) {
                self.selectionSummary = null;
                return;
            }
            // Считаем локально из уже загруженных данных
            var sumL = 0, sumS = 0, cntL = 0, cntS = 0;
            self.accounts.forEach(function (acc) {
                acc.entries.forEach(function (e) {
                    if (self.selectedIds.indexOf(e.id) !== -1) {
                        if (e.ls === 'L') { sumL += parseFloat(e.amount_raw || 0); cntL++; }
                        else              { sumS += parseFloat(e.amount_raw || 0); cntS++; }
                    }
                });
            });
            self.selectionSummary = {
                sum_ledger:    sumL,
                sum_statement: sumS,
                diff:          Math.round((sumL - sumS) * 100) / 100,
                cnt_ledger:    cntL,
                cnt_statement: cntS
            };
        },

        // ── Ручное квитование ──────────────────────────────────────

        matchSelected: function () {
            var self = this;
            if (!self.hasSelection) {
                Swal.fire('Внимание', 'Выберите минимум 2 записи', 'warning');
                return;
            }

            // Предупреждение если дисбаланс
            var diff = self.summaryDiff;
            if (diff !== null && diff !== 0) {
                Swal.fire({
                    title: 'Дисбаланс сумм',
                    html: 'Разница между Ledger и Statement: <strong>' +
                        self.formatAmount(Math.abs(diff)) + '</strong><br>' +
                        'Записи останутся на ручном разборе.',
                    icon: 'warning',
                    confirmButtonText: 'Понятно'
                });
                return;
            }

            Swal.fire({
                title: 'Подтвердите квитование',
                html: 'Выбрано записей: <strong>' + self.selectedIds.length + '</strong><br>' +
                    'Ledger: ' + self.selectionSummary.cnt_ledger +
                    ' | Statement: ' + self.selectionSummary.cnt_statement,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Сквитовать',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#198754'
            }).then(function (result) {
                if (!result.isConfirmed) return;

                // Отправляем ids как массив
                var formData = self.selectedIds.map(function (id) {
                    return 'ids[]=' + id;
                }).join('&');

                axios.post(AppRoutes.matchManual, formData, {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                }).then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Готово!', response.data.message, 'success');
                        self.clearSelection();
                        self.refreshAccounts();
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                }).catch(function () {
                    Swal.fire('Ошибка', 'Не удалось выполнить квитование', 'error');
                });
            });
        },

        // Расквитование по match_id
        unmatchEntry: function (matchId) {
            var self = this;
            if (!matchId) return;

            Swal.fire({
                title: 'Расквитовать?',
                text: 'Match ID: ' + matchId + '. Все записи вернутся в статус "Не сквитовано".',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Расквитовать',
                cancelButtonText: 'Отмена'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                axios.post(AppRoutes.unmatch, 'match_id=' + encodeURIComponent(matchId), {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                }).then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Готово', response.data.message, 'success');
                        self.refreshAccounts();
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                }).catch(function () {
                    Swal.fire('Ошибка', 'Не удалось расквитовать', 'error');
                });
            });
        },

        // ── Автоквитование ─────────────────────────────────────────

        runAutoMatch: function (accountId) {
            var self = this;
            self.autoMatchRunning = true;

            var body = accountId ? 'account_id=' + accountId : '';

            Swal.fire({
                title: 'Запустить автоквитование?',
                text: accountId ? 'По выбранному счёту' : 'По всем счетам пула',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Запустить',
                cancelButtonText: 'Отмена',
                confirmButtonColor: '#0d6efd'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    self.autoMatchRunning = false;
                    return;
                }

                axios.post(AppRoutes.autoMatch, body, {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                }).then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Готово!', response.data.message, 'success');
                        self.refreshAccounts();
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                }).catch(function () {
                    Swal.fire('Ошибка', 'Ошибка при автоквитовании', 'error');
                }).finally(function () {
                    self.autoMatchRunning = false;
                });
            });
        },

        // ── Правила квитования ─────────────────────────────────────

        loadMatchingRules: function () {
            var self = this;
            self.loadingRules = true;
            axios.get(AppRoutes.getRules)
                .then(function (r) {
                    if (r.data.success) self.matchingRules = r.data.data;
                })
                .finally(function () { self.loadingRules = false; });
        },

        showAddRuleModal: function () {
            this.editingRule = {
                id: null, name: '', section: 'NRE', pair_type: 'LS',
                match_dc: true, match_amount: true, match_value_date: true,
                match_instruction_id: false, match_end_to_end_id: false,
                match_transaction_id: false, match_message_id: false,
                cross_id_search: false, is_active: true, priority: 100, description: ''
            };
            this._showModal('ruleModal');
        },

        editRule: function (rule) {
            this.editingRule = Object.assign({}, rule);
            this._showModal('ruleModal');
        },

        closeRuleModal: function () { this._hideModal('ruleModal'); },

        saveRule: function () {
            var self = this;
            if (!self.editingRule.name) {
                Swal.fire('Ошибка', 'Введите название правила', 'error'); return;
            }

            // Собираем форм-дату вручную (булевы поля)
            var fields = ['id','name','section','pair_type','match_dc','match_amount',
                'match_value_date','match_instruction_id','match_end_to_end_id',
                'match_transaction_id','match_message_id','cross_id_search',
                'is_active','priority','description'];
            var parts = fields.map(function (k) {
                var v = self.editingRule[k];
                if (v === true)  v = 1;
                if (v === false) v = 0;
                if (v === null || v === undefined) v = '';
                return encodeURIComponent(k) + '=' + encodeURIComponent(v);
            });

            axios.post(AppRoutes.saveRule, parts.join('&'), {
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            }).then(function (response) {
                if (response.data.success) {
                    Swal.fire('Сохранено', response.data.message, 'success');
                    self.closeRuleModal();
                    self.loadMatchingRules();
                } else {
                    var err = response.data.errors
                        ? Object.values(response.data.errors).join('\n')
                        : response.data.message;
                    Swal.fire('Ошибка', err, 'error');
                }
            }).catch(function () { Swal.fire('Ошибка', 'Не удалось сохранить', 'error'); });
        },

        deleteRule: function (rule) {
            var self = this;
            Swal.fire({
                title: 'Удалить правило?',
                text: rule.name,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Удалить',
                cancelButtonText: 'Отмена'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                axios.post(AppRoutes.deleteRule, 'id=' + rule.id, {
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                }).then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Удалено', response.data.message, 'success');
                        self.loadMatchingRules();
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                });
            });
        },

        // ── Утилиты ────────────────────────────────────────────────
        formatAmount: function (val) {
            return parseFloat(val).toLocaleString('ru-RU', { minimumFractionDigits: 2 });
        }
    }
};