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
            autoMatchRunning: false,
            autoMatchProgress: null,  // { job_id, total_steps, current_step, total_matched, rules, step_results, unmatched_count }
            autoMatchScope: {
                type: 'all',   // 'all' | 'pool' | 'group' | 'category'
                poolId: null,
                poolName: ''
            }
        };
    },

    computed: {
        hasSelection: function () {
            // Разрешаем 1 запись если diff = 0 (например, сумма записи = 0)
            if (this.selectedIds.length === 1 && this.selectionSummary && this.summaryDiff === 0) {
                return true;
            }
            return this.selectedIds.length >= 2;
        },
        summaryDiff: function () {
            if (!this.selectionSummary) return null;
            // В INV сравниваем по Debit/Credit, а не по L/S
            if (this.userSection === 'INV') return this.selectionSummary.diff_dc;
            return this.selectionSummary.diff;
        },
        summaryBalanced: function () { return this.selectionSummary && this.summaryDiff === 0; }
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
            var sD = 0, sCr = 0; // Debit/Credit для INV
            (self.entries || []).forEach(function (e) {
                if (self.selectedIds.indexOf(e.id) === -1) return;
                var a = parseFloat(e.amount || 0);
                if (e.ls === 'L') { sL += a; cL++; } else { sS += a; cS++; }
                if (e.dc === 'Debit') { sD += a; } else { sCr += a; }
            });
            self.selectionSummary = {
                sum_ledger:    Math.round(sL * 100) / 100,
                sum_statement: Math.round(sS * 100) / 100,
                diff:          Math.round((sL - sS) * 100) / 100,
                cnt_ledger:    cL,
                cnt_statement: cS,
                sum_debit:     Math.round(sD * 100) / 100,
                sum_credit:    Math.round(sCr * 100) / 100,
                diff_dc:       Math.round((sD - sCr) * 100) / 100,
            };
        },

        // ─── Ручное квитование ─────────────────────────────────────
        matchSelected: function () {
            var self = this;
            if (!self.hasSelection) {
                var minMsg = (self.userSection === 'INV')
                    ? 'Выберите минимум 1 запись (или 2+ для квитования с дисбалансом)'
                    : 'Выберите минимум 2 записи';
                Swal.fire({ icon: 'warning', title: minMsg, toast: true,
                    position: 'top-end', timer: 2500, showConfirmButton: false });
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
            var payload = { ids: self.selectedIds };
            if (self.userSection) payload.section = self.userSection;

            SmartMatchApi.post(window.AppRoutes.matchManual, payload).then(function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: res.message, toast: true,
                        position: 'top-end', timer: 2000, showConfirmButton: false });
                    self.loadEntries(true);
                } else {
                    Swal.fire({ icon: 'error', title: res.message });
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
                SmartMatchApi.post(window.AppRoutes.unmatch, { match_id: encodeURIComponent(matchId) }).then(function (res) {
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

        // ─── Автоквитование — открыть модалку выбора области ─────
        runAutoMatch: function () {
            var self = this;
            if (!self.selectedGroup) return;

            // Определяем ностробанк из фильтров текущей группы
            var poolId = null;
            var poolName = '';
            if (self.selectedGroup && Array.isArray(self.selectedGroup.filters)) {
                for (var i = 0; i < self.selectedGroup.filters.length; i++) {
                    var f = self.selectedGroup.filters[i];
                    if (f.field === 'account_pool_id' && f.operator === 'eq' && f.value) {
                        poolId = parseInt(f.value, 10);
                        var foundPool = (self.accountPools || []).find(function (p) { return p.id === poolId; });
                        poolName = foundPool ? foundPool.name : ('Ностробанк #' + poolId);
                        break;
                    }
                }
            }

            self.autoMatchScope = { type: 'all', poolId: poolId, poolName: poolName };

            // Дефолтная опция: если есть ностробанк — по ностробанку, иначе — по категории
            if (poolId) {
                self.autoMatchScope.type = 'pool';
            } else {
                self.autoMatchScope.type = 'category';
            }

            self._showModal('autoMatchScopeModal');
        },

        // ─── Автоквитование — подтвердить и запустить ─────────────
        confirmAutoMatch: function () {
            var self = this;
            self._hideModal('autoMatchScopeModal');
            self.autoMatchRunning = true;
            self.autoMatchProgress = null;

            var payload = { scope_type: self.autoMatchScope.type };
            if (self.autoMatchScope.type === 'pool' && self.autoMatchScope.poolId) {
                payload.scope_id = self.autoMatchScope.poolId;
            } else if (self.autoMatchScope.type === 'category' && self.selectedCategory) {
                payload.scope_id = self.selectedCategory.id;
            }

            SmartMatchApi.post(window.AppRoutes.autoMatchStart, payload).then(function (res) {
                if (!res.success) {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: res.message });
                    self.autoMatchRunning = false;
                    return;
                }

                self.autoMatchProgress = {
                    job_id:          res.job_id,
                    total_steps:     res.total_steps,
                    current_step:    0,
                    total_matched:   0,
                    rules:           res.rules,
                    step_results:    [],
                    unmatched_count: res.unmatched_count
                };

                self._runAutoMatchNextStep();
            }).catch(function () {
                self.autoMatchRunning = false;
                Swal.fire({ icon: 'error', title: 'Ошибка сети' });
            });
        },

        _runAutoMatchNextStep: function () {
            var self = this;
            if (!self.autoMatchProgress) return;

            SmartMatchApi.post(window.AppRoutes.autoMatchStep, {
                job_id: self.autoMatchProgress.job_id
            }).then(function (res) {
                if (!res.success) {
                    self.autoMatchRunning = false;
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: res.message });
                    return;
                }

                // Обновляем прогресс
                self.autoMatchProgress.current_step  = res.current_step;
                self.autoMatchProgress.total_matched  = res.total_matched;
                self.autoMatchProgress.step_results   = res.step_results;

                if (res.is_finished) {
                    // Готово
                    self.autoMatchRunning = false;
                    self.loadEntries(true);

                    // Формируем итоговое сообщение
                    var html = '<div class="text-start">';
                    html += '<p><b>Сквитовано пар: ' + res.total_matched + '</b></p>';
                    if (res.step_results && res.step_results.length) {
                        html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th>Правило</th><th class="text-end">Пар</th></tr></thead><tbody>';
                        res.step_results.forEach(function (sr) {
                            var badge = sr.matched > 0
                                ? '<span class="badge bg-success">' + sr.matched + '</span>'
                                : '<span class="badge bg-secondary">0</span>';
                            var err = sr.error ? ' <span class="text-danger">⚠ ' + sr.error + '</span>' : '';
                            html += '<tr><td>' + sr.rule_name + err + '</td><td class="text-end">' + badge + '</td></tr>';
                        });
                        html += '</tbody></table>';
                    }
                    html += '</div>';

                    Swal.fire({
                        icon: res.total_matched > 0 ? 'success' : 'info',
                        title: 'Автоквитование завершено',
                        html: html,
                        width: 500
                    });
                    self.autoMatchProgress = null;
                } else {
                    // Следующий шаг
                    self._runAutoMatchNextStep();
                }
            }).catch(function () {
                self.autoMatchRunning = false;
                self.autoMatchProgress = null;
                Swal.fire({ icon: 'error', title: 'Ошибка сети' });
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