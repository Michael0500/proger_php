/**
 * Mixin квитования, автоквитования и правил matching.
 *
 * Работает с массивом `entries` из EntriesMixin и управляет выбранными
 * NostroEntry, ручным квитованием, просмотром групп по `match_id`,
 * расквитованием, пошаговым автоквитованием и CRUD правил. Сервер проверяет
 * баланс выбранных записей, уникальность `match_id`, область компании и
 * допустимость операций с архивом.
 */
var MatchingMixin = {
    /**
     * Начальное состояние подсистемы квитования.
     *
     * @returns {Object} Vue data для выбора строк, модалок, правил и прогресса автоквитования.
     */
    data: function () {
        return {
            selectedIds:      [],
            selectionSummary: null,
            matchGroupEntries: [],
            matchGroupId:      null,
            matchGroupLoading: false,
            matchingRules:    [],
            loadingRules:     false,
            /**
             * Форма создания/редактирования правила автоквитования.
             *
             * @type {Object}
             * @property {?number} id ID правила; `null` означает создание.
             * @property {string} pair_type Тип пары: `LS`, `LL` или `SS`.
             * @property {boolean} cross_id_search Включён ли поиск совпадений между любыми ID-полями.
             * @property {number} priority Приоритет выполнения правила.
             */
            editingRule: {
                id: null, name: '', section: 'NRE', pair_type: 'LS',
                match_dc: true, match_amount: true, match_value_date: true,
                match_instruction_id: false, match_end_to_end_id: false,
                match_transaction_id: false, match_message_id: false,
                cross_id_search: false, is_active: true, priority: 100, description: ''
            },
            autoMatchRunning: false,
            /**
             * Состояние пошагового автоквитования.
             *
             * @type {?Object}
             * @property {string} job_id ID задачи в серверном FileCache.
             * @property {number} total_steps Количество правил/шагов.
             * @property {number} current_step Номер выполненного шага.
             * @property {number} total_matched Общее количество найденных пар.
             */
            autoMatchProgress: null,  // { job_id, total_steps, current_step, total_matched, rules, step_results, unmatched_count }
            autoMatchScope: {
                type: 'all',   // 'all' | 'pool' | 'group' | 'category'
                poolId: null,
                poolName: ''
            }
        };
    },

    computed: {
        /**
         * Возвращает раздел компании пользователя, влияющий на проверку баланса.
         *
         * @returns {string} Раздел компании, например `NRE` или `INV`.
         */
        userSection: function () {
            return (window.AppConfig && window.AppConfig.companySection) || '';
        },
        /**
         * Проверяет, достаточно ли выбранных записей для ручного квитования.
         *
         * В INV допускается одна запись с нулевой разницей, в остальных случаях
         * требуется минимум две записи.
         *
         * @returns {boolean} `true`, если кнопка квитования может быть активна.
         */
        hasSelection: function () {
            // Разрешаем 1 запись если diff = 0 (например, сумма записи = 0)
            if (this.selectedIds.length === 1 && this.selectionSummary && this.summaryDiff === 0) {
                return true;
            }
            return this.selectedIds.length >= 2;
        },
        /**
         * Возвращает актуальную разницу выбранных записей.
         *
         * Для INV и записей одного счёта сравнение идёт по Debit/Credit, для
         * остальных NRE-сценариев — по Ledger/Statement.
         *
         * @returns {number|null} Разница сумм или `null`, если нет summary.
         */
        summaryDiff: function () {
            if (!this.selectionSummary) return null;
            // В INV всегда сравниваем Debit-Credit. В NRE — если все выбранные
            // записи на одном счёте, тоже сравниваем по D/C; иначе по L/S.
            if (this.userSection === 'INV' || this.selectionSummary.same_account) {
                return this.selectionSummary.diff_dc;
            }
            return this.selectionSummary.diff;
        },
        /**
         * Проверяет, сбалансирована ли текущая выборка для квитования.
         *
         * @returns {boolean|null} `true`, если разница равна нулю.
         */
        summaryBalanced: function () { return this.selectionSummary && this.summaryDiff === 0; }
    },

    methods: {

        /**
         * Переключает выбор записи выверки.
         *
         * Изменяет `selectedIds` и пересчитывает summary для панели ручного
         * квитования.
         *
         * @param {number|string} id Идентификатор NostroEntry.
         * @returns {void}
         */
        toggleEntrySelection: function (id) {
            var i = this.selectedIds.indexOf(id);
            if (i === -1) this.selectedIds.push(id);
            else          this.selectedIds.splice(i, 1);
            this.updateSummary();
        },

        /**
         * Проверяет, выбрана ли запись.
         *
         * @param {number|string} id Идентификатор NostroEntry.
         * @returns {boolean} `true`, если ID присутствует в `selectedIds`.
         */
        isSelected: function (id) { return this.selectedIds.indexOf(id) !== -1; },

        /**
         * Очищает текущий выбор и summary ручного квитования.
         *
         * @returns {void}
         */
        clearSelection: function () {
            this.selectedIds      = [];
            this.selectionSummary = null;
        },

        /**
         * Пересчитывает суммы выбранных записей для проверки баланса.
         *
         * Читает `entries` и `selectedIds`, заполняет `selectionSummary` суммами
         * Ledger/Statement и Debit/Credit. Значения округляются до копеек для
         * отображения UI; окончательная проверка выполняется на сервере.
         *
         * @returns {void}
         */
        updateSummary: function () {
            var self = this;
            if (!self.selectedIds.length) { self.selectionSummary = null; return; }
            var sL = 0, sS = 0, cL = 0, cS = 0;
            var sD = 0, sCr = 0, cD = 0, cCr = 0;
            var accountIds = {};
            (self.entries || []).forEach(function (e) {
                if (self.selectedIds.indexOf(e.id) === -1) return;
                var a = parseFloat(e.amount || 0);
                if (e.ls === 'L') { sL += a; cL++; } else { sS += a; cS++; }
                if (e.dc === 'Debit') { sD += a; cD++; } else { sCr += a; cCr++; }
                accountIds[e.account_id] = true;
            });
            self.selectionSummary = {
                sum_ledger:    Math.round(sL * 100) / 100,
                sum_statement: Math.round(sS * 100) / 100,
                diff:          Math.round((sL - sS) * 100) / 100,
                cnt_ledger:    cL,
                cnt_statement: cS,
                sum_debit:     Math.round(sD * 100) / 100,
                cnt_debit:     cD,
                sum_credit:    Math.round(sCr * 100) / 100,
                cnt_credit:    cCr,
                diff_dc:       Math.round((sD - sCr) * 100) / 100,
                same_account:  Object.keys(accountIds).length === 1,
            };
        },

        /**
         * Запускает сценарий ручного квитования выбранных записей.
         *
         * Проверяет минимальное количество выбранных записей и, если есть
         * дисбаланс, запрашивает подтверждение пользователя перед вызовом
         * `_doMatch()`.
         *
         * @returns {void}
         */
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

        /**
         * Отправляет выбранные записи на серверное ручное квитование.
         *
         * Вызывает POST `matchManual` с `ids` и section, затем перезагружает
         * таблицу записей при успехе. Сервер присваивает `match_id`, `matched_at`
         * и пишет аудит.
         *
         * @returns {void}
         */
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

        /**
         * Открывает модалку состава сквитованной группы.
         *
         * Загружает все активные записи с указанным `match_id` через GET
         * `matchGroup`; при ошибке закрывает модалку и показывает SweetAlert.
         *
         * @param {string} matchId Идентификатор группы квитования.
         * @returns {void}
         */
        showMatchGroup: function (matchId) {
            if (!matchId) return;
            var self = this;
            self.matchGroupId = matchId;
            self.matchGroupEntries = [];
            self.matchGroupLoading = true;
            self._showModal('matchGroupModal');

            SmartMatchApi.get(window.AppRoutes.matchGroup, { match_id: matchId })
                .then(function (r) {
                    var data = r.data || r;
                    if (data.success) {
                        self.matchGroupEntries = data.data;
                    } else {
                        Swal.fire({ icon: 'error', title: data.message, toast: true,
                            position: 'top-end', timer: 2500, showConfirmButton: false });
                        self._hideModal('matchGroupModal');
                    }
                })
                .catch(function () {
                    Swal.fire({ icon: 'error', title: 'Ошибка загрузки', toast: true,
                        position: 'top-end', timer: 2500, showConfirmButton: false });
                    self._hideModal('matchGroupModal');
                })
                .finally(function () { self.matchGroupLoading = false; });
        },

        /**
         * Закрывает модалку группы квитования и очищает её состояние.
         *
         * @returns {void}
         */
        closeMatchGroupModal: function () {
            this._hideModal('matchGroupModal');
            this.matchGroupEntries = [];
            this.matchGroupId = null;
        },

        /**
         * Запускает расквитование из модалки группы.
         *
         * Использует текущий `matchGroupId`, закрывает модалку и передаёт ID в
         * `unmatchEntry()`.
         *
         * @returns {void}
         */
        unmatchFromGroup: function () {
            var self = this;
            var matchId = self.matchGroupId;
            if (!matchId) return;
            self._hideModal('matchGroupModal');
            self.unmatchEntry(matchId);
        },

        /**
         * Расквитовывает все активные записи с указанным `match_id`.
         *
         * После подтверждения вызывает POST `unmatch`; сервер очищает `match_id`,
         * `matched_at`, возвращает `match_status = U` и пишет аудит.
         *
         * @param {string} matchId Идентификатор группы квитования.
         * @returns {void}
         */
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

        /**
         * Открывает модалку выбора области автоквитования.
         *
         * По умолчанию область ограничивается выбранным ностро-банком, чтобы
         * пользователь явно подтвердил запуск правил.
         *
         * @returns {void}
         */
        runAutoMatch: function () {
            var self = this;
            if (!self.selectedPool) return;

            var poolId   = self.selectedPool.id;
            var poolName = self.selectedPool.name;

            self.autoMatchScope = {
                type:     'pool',
                poolId:   poolId,
                poolName: poolName,
            };

            self._showModal('autoMatchScopeModal');
        },

        /**
         * Создаёт серверную задачу пошагового автоквитования.
         *
         * Вызывает POST `autoMatchStart`, сохраняет `job_id`, список правил и
         * счётчики прогресса, затем запускает `_runAutoMatchNextStep()`.
         *
         * @returns {void}
         */
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

        /**
         * Выполняет следующий шаг серверного автоквитования.
         *
         * Рекурсивно вызывает POST `autoMatchStep` до `is_finished`, обновляет
         * прогресс UI, перезагружает таблицу записей и показывает итог по
         * правилам. Ошибки отдельного шага останавливают процесс.
         *
         * @returns {void}
         */
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

        /**
         * Загружает правила автоквитования.
         *
         * Вызывает GET `getRules` и заполняет `matchingRules` для таблицы правил.
         *
         * @returns {void}
         */
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

        /**
         * Открывает форму создания правила автоквитования.
         *
         * Сбрасывает `editingRule` к дефолтному LS/NRE правилу и показывает
         * `ruleModal`.
         *
         * @returns {void}
         */
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

        /**
         * Открывает форму редактирования правила автоквитования.
         *
         * @param {Object} rule Правило из `matchingRules`.
         * @returns {void}
         */
        editRule:       function (rule) { this.editingRule = Object.assign({}, rule); this._showModal('ruleModal'); },
        /**
         * Закрывает модалку правила без сохранения.
         *
         * @returns {void}
         */
        closeRuleModal: function ()     { this._hideModal('ruleModal'); },

        /**
         * Сохраняет правило автоквитования.
         *
         * Валидирует имя, вызывает POST `saveRule`, закрывает модалку и
         * перезагружает список правил. Сервер хранит приоритет, pair_type,
         * набор критериев и флаг `cross_id_search`.
         *
         * @returns {void}
         */
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

        /**
         * Удаляет правило автоквитования после подтверждения.
         *
         * @param {Object} rule Правило из `matchingRules`.
         * @returns {void}
         */
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
