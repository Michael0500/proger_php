/**
 * MatchingMixin — квитование, автоквитование, правила.
 * Работает с плоским массивом this.entries (из EntriesMixin).
 */
var MatchingMixin = {
    data: function () {
        return {
            selectedIds:      [],
            selectionSummary: null,
            matchingRules:    [],
            loadingRules:     false,
            editingRule: {
                id: null, name: '', section: 'NRE', pair_type: 'LS',
                match_dc: true, match_amount: true, match_value_date: true,
                match_instruction_id: false, match_end_to_end_id: false,
                match_transaction_id: false, match_message_id: false,
                cross_id_search: false, is_active: true, priority: 100, description: ''
            },
            autoMatchRunning: false
        };
    },

    computed: {
        hasSelection:    function () { return this.selectedIds.length >= 2; },
        summaryDiff:     function () { return this.selectionSummary ? this.selectionSummary.diff : null; },
        summaryBalanced: function () { return this.selectionSummary && this.selectionSummary.diff === 0; }
    },

    methods: {

        // ─── Выбор ─────────────────────────────────────────────────
        toggleEntrySelection: function (id) {
            var i = this.selectedIds.indexOf(id);
            if (i === -1) this.selectedIds.push(id);
            else          this.selectedIds.splice(i, 1);
            this.updateSummary();
        },

        isSelected: function (id) { return this.selectedIds.indexOf(id) !== -1; },

        clearSelection: function () {
            this.selectedIds      = [];
            this.selectionSummary = null;
        },

        // ─── Пересчёт суммы ────────────────────────────────────────
        updateSummary: function () {
            var self = this;
            if (!self.selectedIds.length) { self.selectionSummary = null; return; }
            var sL = 0, sS = 0, cL = 0, cS = 0;
            (self.entries || []).forEach(function (e) {
                if (self.selectedIds.indexOf(e.id) === -1) return;
                var a = parseFloat(e.amount || 0);
                if (e.ls === 'L') { sL += a; cL++; } else { sS += a; cS++; }
            });
            self.selectionSummary = {
                sum_ledger:    Math.round(sL * 100) / 100,
                sum_statement: Math.round(sS * 100) / 100,
                diff:          Math.round((sL - sS) * 100) / 100,
                cnt_ledger:    cL,
                cnt_statement: cS
            };
        },

        // ─── Ручное квитование ─────────────────────────────────────
        matchSelected: function () {
            var self = this;
            if (!self.hasSelection) {
                Swal.fire({ icon: 'warning', title: 'Выберите минимум 2 записи', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            if (self.summaryDiff !== 0) {
                Swal.fire({
                    icon: 'warning', title: 'Дисбаланс сумм',
                    html: 'Разница: <b>' + self.formatAmount(Math.abs(self.summaryDiff)) + '</b>.<br>Сквитовать всё равно?',
                    showCancelButton: true, confirmButtonText: 'Да, сквитовать',
                    cancelButtonText: 'Отмена', confirmButtonColor: '#f59e0b'
                }).then(function (r) { if (r.isConfirmed) self._doMatch(); });
                return;
            }
            self._doMatch();
        },

        _doMatch: function () {
            var self = this;
            var body = self.selectedIds.map(function (id) { return 'ids[]=' + id; }).join('&');
            // ИСПРАВЛЕНО: было window.window.AppRoutes
            SmartMatchApi.postRaw(window.AppRoutes.matchManual, body).then(function (r) {
                if (r.success) {
                    Swal.fire({ icon: 'success', title: r.message, toast: true,
                        position: 'top-end', timer: 2500, showConfirmButton: false });
                    self.clearSelection();
                    self.loadEntries(true);
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message });
                }
            });
        },

        // ─── Расквитование ─────────────────────────────────────────
        unmatchEntry: function (matchId) {
            if (!matchId) return;
            var self = this;
            Swal.fire({
                title: 'Расквитовать?',
                html: 'Match ID: <code>' + matchId + '</code><br>Все записи вернутся в статус «Ожидает».',
                icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#ef4444', confirmButtonText: 'Расквитовать', cancelButtonText: 'Отмена'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                // ИСПРАВЛЕНО: было window.window.AppRoutes
                SmartMatchApi.postRaw(window.AppRoutes.unmatch, 'match_id=' + encodeURIComponent(matchId))
                    .then(function (res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', title: res.message, toast: true,
                                position: 'top-end', timer: 2000, showConfirmButton: false });
                            self.loadEntries(true);
                        } else {
                            Swal.fire({ icon: 'error', title: res.message });
                        }
                    });
            });
        },

        // ─── Автоквитование ────────────────────────────────────────
        runAutoMatch: function () {
            var self = this;
            if (!self.selectedPool) return;

            Swal.fire({
                title: 'Автоквитование',
                html: 'Запустить по всем записям пула <b>' + self.selectedPool.name + '</b>?',
                icon: 'question', showCancelButton: true,
                confirmButtonText: '<i class="fas fa-magic me-1"></i>Запустить',
                cancelButtonText: 'Отмена', confirmButtonColor: '#6366f1'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                self.autoMatchRunning = true;
                var body = 'pool_id=' + self.selectedPool.id;
                // ИСПРАВЛЕНО: было window.window.AppRoutes
                SmartMatchApi.postRaw(window.AppRoutes.autoMatch, body)
                    .then(function (res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', title: 'Готово!', text: res.message });
                            self.loadEntries(true);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: res.message });
                        }
                    })
                    .finally(function () { self.autoMatchRunning = false; });
            });
        },

        // ─── Правила ───────────────────────────────────────────────
        loadMatchingRules: function () {
            var self = this;
            self.loadingRules = true;
            // ИСПРАВЛЕНО: было window.window.AppRoutes
            SmartMatchApi.get(window.AppRoutes.getRules, {})
                .then(function (r) {
                    // SmartMatchApi.get возвращает axios response, данные в r.data
                    if (r.data && r.data.success) self.matchingRules = r.data.data;
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

        editRule:       function (rule) { this.editingRule = Object.assign({}, rule); this._showModal('ruleModal'); },
        closeRuleModal: function ()     { this._hideModal('ruleModal'); },

        saveRule: function () {
            var self = this;
            if (!self.editingRule.name) {
                Swal.fire({ icon: 'warning', title: 'Введите название', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            // ИСПРАВЛЕНО: было window.window.AppRoutes
            // SmartMatchApi.post возвращает r.data уже распакованным (см. api.js)
            SmartMatchApi.post(window.AppRoutes.saveRule, self.editingRule).then(function (r) {
                if (r.success) {
                    Swal.fire({ icon: 'success', title: r.message, toast: true,
                        position: 'top-end', timer: 2000, showConfirmButton: false });
                    self.closeRuleModal();
                    self.loadMatchingRules();
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message || JSON.stringify(r.errors) });
                }
            });
        },

        deleteRule: function (rule) {
            var self = this;
            Swal.fire({
                title: 'Удалить правило?', text: rule.name, icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#ef4444',
                confirmButtonText: 'Удалить', cancelButtonText: 'Отмена'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                // ИСПРАВЛЕНО: было window.window.AppRoutes
                SmartMatchApi.post(window.AppRoutes.deleteRule, { id: rule.id }).then(function (res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Удалено', toast: true,
                            position: 'top-end', timer: 1500, showConfirmButton: false });
                        self.loadMatchingRules();
                    } else {
                        Swal.fire({ icon: 'error', title: res.message });
                    }
                });
            });
        }
    }
};