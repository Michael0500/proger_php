/**
 * entries.js — единая таблица NostroEntry
 * Infinite scroll, сортировка, колоночная фильтрация, Select2, debounce
 */
var EntriesMixin = {
    data: function () {
        return {
            // ── Таблица ───────────────────────────────────
            entries:            [],
            entriesTotal:       0,
            entriesPage:        1,
            entriesLimit:       50,
            entriesLoading:     false,
            entriesLoadingMore: false,

            // Сортировка
            sortCol: 'id',
            sortDir: 'desc',

            // Фильтры
            filters:      {},
            filtersOpen:  false,

            // Select2 флаги
            _filterSelect2Inited: false,
            _entrySelect2Inited:  false,

            // ── Форма записи ──────────────────────────────
            editingEntry: {
                id: null, account_id: null, account_name: '',
                ls: 'L', dc: 'Debit', amount: '', currency: 'USD',
                value_date: '', post_date: '',
                instruction_id: '', end_to_end_id: '',
                transaction_id: '', message_id: '', comment: ''
            },

            // ── Выделение ─────────────────────────────────
            selectedIds: [],
            selectionSummary: null,
            summaryBalanced: false,

            // ── inline-комментарий ────────────────────────
            editingCommentId:    null,
            editingCommentValue: '',

            // debounce timer
            _filterDebounceTimer: null,

            // ── История ────────────────────────────────────
            historyLoading: false,
            historyItems:   [],
            historyEntry:   null,
        };
    },

    computed: {
        hasMoreEntries: function () {
            return this.entries.length < this.entriesTotal;
        },
        unmatchedEntries: function () {
            return this.entries.filter(function (e) { return e.match_status === 'U'; });
        },
        unmatchedIds: function () {
            return this.unmatchedEntries.map(function (e) { return e.id; });
        },
        allUnmatchedSelected: function () {
            var uids = this.unmatchedIds;
            if (!uids.length) return false;
            var sel  = this.selectedIds;
            return uids.every(function (id) { return sel.indexOf(id) !== -1; });
        },
        someSelected: function () {
            return this.selectedIds.length > 0 && !this.allUnmatchedSelected;
        },
        hasSelection: function () {
            return this.selectedIds.length >= 2;
        }
    },

    methods: {
        /**
         * Склонение слова "запись" в зависимости от числа
         * @param {number} count - количество записей
         * @returns {string} - "запись", "записи" или "записей"
         */
        recordText: function (count) {
            var n = Math.abs(count) % 100;
            var n1 = n % 10;

            if (n > 10 && n < 20) return 'записей';
            if (n1 > 1 && n1 < 5) return 'записи';
            if (n1 === 1) return 'запись';
            return 'записей';
        },
        // ══════════════════════════════════════════════════
        // ЗАГРУЗКА
        // ══════════════════════════════════════════════════

        loadEntries: function (reset) {
            if (!this.selectedGroup) return;
            if (reset) {
                this.entries          = [];
                this.entriesPage      = 1;
                this.selectedIds      = [];
                this.selectionSummary = null;
            }
            var self    = this;
            var isFirst = this.entriesPage === 1;
            if (isFirst) self.entriesLoading     = true;
            else         self.entriesLoadingMore = true;

            SmartMatchApi.get(window.AppRoutes.entryList, {
                pool_id: self.selectedGroup.id,
                page:    self.entriesPage,
                limit:   self.entriesLimit,
                sort:    self.sortCol,
                dir:     self.sortDir,
                filters: JSON.stringify(self.filters)
            }).then(function (response) {
                var r = response.data !== undefined ? response.data : response;
                if (r.success) {
                    self.entries      = reset ? r.data : self.entries.concat(r.data);
                    self.entriesTotal = r.total;
                }
            }).finally(function () {
                self.entriesLoading     = false;
                self.entriesLoadingMore = false;
            });
        },

        loadMoreEntries: function () {
            if (this.entriesLoadingMore || !this.hasMoreEntries) return;
            this.entriesPage++;
            this.loadEntries(false);
        },

        // ══════════════════════════════════════════════════
        // СОРТИРОВКА
        // ══════════════════════════════════════════════════

        sortBy: function (col) {
            if (this.sortCol === col) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortCol = col;
                this.sortDir = 'asc';
            }
            this.loadEntries(true);
        },

        sortIcon: function (col) {
            if (this.sortCol !== col) return 'fas fa-sort';
            return this.sortDir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        },

        // ══════════════════════════════════════════════════
        // ФИЛЬТРЫ
        // ══════════════════════════════════════════════════

        applyFilter: function (field, value) {
            var v = (value === null || value === undefined) ? '' : String(value).trim();
            if (v === '') {
                this.$delete(this.filters, field);
            } else {
                this.$set(this.filters, field, v);
            }
            this.loadEntries(true);
        },

        /** debounce для текстовых полей — задержка 400мс */
        debouncedFilter: function (field, value) {
            var self = this;
            if (self._filterDebounceTimer) clearTimeout(self._filterDebounceTimer);
            self._filterDebounceTimer = setTimeout(function () {
                self.applyFilter(field, value);
            }, 400);
        },

        clearFilter: function (field) {
            this.$delete(this.filters, field);
            this.loadEntries(true);
        },

        clearAllFilters: function () {
            this.filters = {};
            var $fs = $('#filter-account-select2');
            if ($fs.length && $fs.data('select2')) {
                $fs.val(null).trigger('change');
            }
            this.loadEntries(true);
        },

        hasFilter: function (field) {
            return this.filters[field] !== undefined && this.filters[field] !== '';
        },

        activeFilterCount: function () {
            var self = this, cnt = 0;
            Object.keys(self.filters).forEach(function (k) {
                if (self.filters[k] !== undefined && self.filters[k] !== '') cnt++;
            });
            return cnt;
        },

        toggleFiltersPanel: function () {
            var self = this;
            self.filtersOpen = !self.filtersOpen;
            if (self.filtersOpen) {
                setTimeout(function () { self.initFilterAccountSelect2(); }, 120);
            }
        },

        // ══════════════════════════════════════════════════
        // SELECT2 — ФИЛЬТР
        // ══════════════════════════════════════════════════

        initFilterAccountSelect2: function () {
            var self = this;
            var $el  = $('#filter-account-select2');
            if (!$el.length || self._filterSelect2Inited) return;
            self._filterSelect2Inited = true;

            $el.select2({
                theme:              'bootstrap-5',
                placeholder:        'Все счета...',
                allowClear:         true,
                minimumInputLength: 0,
                ajax: {
                    url: function () {
                        return window.AppRoutes.entrySearchAccounts +
                            '?group_id=' + (self.selectedGroup ? self.selectedGroup.id : 0);
                    },
                    dataType: 'json', delay: 200,
                    data:     function (p) { return { q: p.term || '' }; },
                    processResults: function (d) { return d; },
                    cache: true
                },
                templateResult: function (item) {
                    if (item.loading) return item.text;
                    var tag = item.currency
                        ? '<span style="background:#e0e7ff;color:#4338ca;border-radius:4px;' +
                        'padding:1px 6px;font-size:10px;font-weight:700;margin-left:5px">' +
                        item.currency + '</span>'
                        : '';
                    return $('<span>' + item.text + tag + '</span>');
                }
            });

            $el.on('select2:select', function (e) {
                self.applyFilter('account_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearFilter('account_id');
            });
        },

        // ══════════════════════════════════════════════════
        // SELECT2 — ФОРМА ЗАПИСИ
        // ══════════════════════════════════════════════════

        initEntryAccountSelect2: function () {
            var self = this;
            var $el  = $('#entry-account-select2');
            if (!$el.length || self._entrySelect2Inited) return;
            self._entrySelect2Inited = true;

            if ($el.data('select2')) {
                $el.off('select2:select select2:clear');
                $el.select2('destroy');
            }

            $el.select2({
                dropdownParent:     $('#entryModal'),
                theme:              'bootstrap-5',
                placeholder:        'Начните вводить название счёта...',
                allowClear:         true,
                minimumInputLength: 0,
                ajax: {
                    url: function () {
                        return window.AppRoutes.entrySearchAccounts +
                            '?group_id=' + (self.selectedGroup ? self.selectedGroup.id : 0);
                    },
                    dataType: 'json', delay: 200,
                    data:     function (p) { return { q: p.term || '' }; },
                    processResults: function (d) { return d; },
                    cache: true
                },
                templateResult: function (item) {
                    if (item.loading) return item.text;
                    var badges = '';
                    if (item.currency) {
                        badges += '<span style="background:#e0e7ff;color:#4338ca;border-radius:4px;' +
                            'padding:1px 6px;font-size:10px;font-weight:700;margin-left:6px">' +
                            item.currency + '</span>';
                    }
                    if (item.is_suspense) {
                        badges += '<span style="background:#fde68a;color:#92400e;border-radius:4px;' +
                            'padding:1px 6px;font-size:10px;font-weight:700;margin-left:3px">Suspense</span>';
                    }
                    return $('<span style="display:flex;align-items:center">' + item.text + badges + '</span>');
                },
                templateSelection: function (item) { return item.text || item.id; }
            });

            $el.on('select2:select', function (e) {
                self.editingEntry.account_id   = e.params.data.id;
                self.editingEntry.account_name = e.params.data.text;
                if (e.params.data.currency && !self.editingEntry.currency) {
                    self.editingEntry.currency = e.params.data.currency;
                }
            });
            $el.on('select2:clear', function () {
                self.editingEntry.account_id   = null;
                self.editingEntry.account_name = '';
            });

            // Сбрасываем выбранное значение после инициализации
            if (!self.editingEntry.id) {
                $el.val(null).trigger('change');
            }
        },

        // ══════════════════════════════════════════════════
        // CRUD
        // ══════════════════════════════════════════════════

        showAddEntryModal: function () {
            var self = this;
            self._entrySelect2Inited = false;
            self.editingEntry = {
                id: null, account_id: null, account_name: '',
                ls: 'L', dc: 'Debit', amount: '', currency: 'USD',
                value_date: '', post_date: '',
                instruction_id: '', end_to_end_id: '',
                transaction_id: '', message_id: '', comment: ''
            };
            var $el = $('#entry-account-select2');
            if ($el.length && $el.data('select2')) $el.val(null).trigger('change');
            self._showModal('entryModal');
            setTimeout(function () { self.initEntryAccountSelect2(); }, 300);
        },

        editEntry: function (entry) {
            var self = this;
            self._entrySelect2Inited = false;
            self.editingEntry = JSON.parse(JSON.stringify(entry));
            self._showModal('entryModal');
            setTimeout(function () {
                self.initEntryAccountSelect2();
                if (entry.account_id && entry.account_name) {
                    var $el = $('#entry-account-select2');
                    if ($el.length) {
                        var opt = new Option(entry.account_name, entry.account_id, true, true);
                        $el.append(opt).trigger('change');
                    }
                }
            }, 300);
        },

        closeEntryModal: function () {
            this._hideModal('entryModal');
            this._entrySelect2Inited = false;
        },

        saveEntry: function () {
            var self = this;
            if (!self.editingEntry.account_id) {
                Swal.fire({ icon: 'warning', title: 'Выберите счёт', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            self.editingEntry.amount = self.normalizeAmount(self.editingEntry.amount);
            if (!self.editingEntry.amount || parseFloat(self.editingEntry.amount) <= 0) {
                Swal.fire({ icon: 'warning', title: 'Укажите сумму > 0', toast: true,
                    position: 'top-end', timer: 2000, showConfirmButton: false });
                return;
            }
            var isNew = !self.editingEntry.id;
            var url   = isNew ? window.AppRoutes.entryCreate : window.AppRoutes.entryUpdate;

            SmartMatchApi.post(url, self.editingEntry).then(function (r) {
                if (r.success) {
                    self.closeEntryModal();
                    self.loadEntries(true);
                    Swal.fire({ icon: 'success',
                        title: isNew ? 'Запись добавлена' : 'Запись обновлена',
                        toast: true, position: 'top-end', timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: r.message || JSON.stringify(r.errors) });
                }
            });
        },

        deleteEntry: function (entry) {
            var self = this;
            Swal.fire({
                title: 'Удалить запись?',
                html: '<span style="font-family:monospace;font-size:13px">ID ' +
                    entry.id + ' · ' + entry.ls + ' · ' + entry.dc.charAt(0) +
                    ' · ' + entry.amount + ' ' + entry.currency + '</span>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: '<i class="fas fa-trash me-1"></i>Удалить',
                cancelButtonText: 'Отмена'
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(window.AppRoutes.entryDelete, { id: entry.id })
                    .then(function (r) {
                        if (r.success) {
                            var idx = self.entries.findIndex(function (e) { return e.id === entry.id; });
                            if (idx !== -1) self.entries.splice(idx, 1);
                            self.entriesTotal = Math.max(0, self.entriesTotal - 1);
                            Swal.fire({ icon: 'success', title: 'Удалено', toast: true,
                                position: 'top-end', timer: 1500, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: r.message });
                        }
                    });
            });
        },

        // ══════════════════════════════════════════════════
        // ВЫДЕЛЕНИЕ
        // ══════════════════════════════════════════════════

        isSelected: function (id) {
            return this.selectedIds.indexOf(id) !== -1;
        },

        toggleEntrySelection: function (id) {
            var idx = this.selectedIds.indexOf(id);
            if (idx === -1) {
                this.selectedIds.push(id);
            } else {
                this.selectedIds.splice(idx, 1);
            }
            this.updateSummary();
        },

        toggleSelectAll: function (checked) {
            if (checked) {
                this.selectedIds = this.unmatchedIds.slice();
            } else {
                this.selectedIds = [];
            }
            this.updateSummary();
        },

        clearSelection: function () {
            this.selectedIds      = [];
            this.selectionSummary = null;
            this.summaryBalanced  = false;
        },

        updateSummary: function () {
            if (!this.selectedIds.length) {
                this.selectionSummary = null;
                this.summaryBalanced  = false;
                return;
            }
            var self   = this;
            var sumL   = 0, cntL = 0, sumS = 0, cntS = 0;
            this.entries.forEach(function (e) {
                if (self.selectedIds.indexOf(e.id) !== -1) {
                    var amt = parseFloat(e.amount) || 0;
                    var sign = e.dc === 'Debit' ? 1 : -1;
                    if (e.ls === 'L') { sumL += amt * sign; cntL++; }
                    else              { sumS += amt * sign; cntS++; }
                }
            });
            var diff = Math.abs(sumL + sumS);
            this.selectionSummary = {
                sum_ledger:    sumL, cnt_ledger:    cntL,
                sum_statement: sumS, cnt_statement: cntS,
                diff: diff
            };
            this.summaryBalanced = diff < 0.005 && cntL > 0 && cntS > 0;
        },

        // ══════════════════════════════════════════════════
        // INLINE КОММЕНТАРИЙ
        // ══════════════════════════════════════════════════

        startEditComment: function (entry) {
            this.editingCommentId    = entry.id;
            this.editingCommentValue = entry.comment || '';
        },

        saveComment: function (entry) {
            var self = this;
            SmartMatchApi.post(window.AppRoutes.entryUpdateComment, {
                id: entry.id, comment: self.editingCommentValue
            }).then(function (r) {
                if (r.success) {
                    entry.comment        = self.editingCommentValue || null;
                    self.editingCommentId = null;
                }
            });
        },

        cancelEditComment: function () {
            this.editingCommentId = null;
        },

        // ══════════════════════════════════════════════════
        // INFINITE SCROLL
        // ══════════════════════════════════════════════════

        onTableScroll: function (e) {
            var el = e.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 160) {
                this.loadMoreEntries();
            }
        },

        // ══════════════════════════════════════════════════
        // УТИЛИТЫ
        // ══════════════════════════════════════════════════

        formatAmount: function (val) {
            if (val === null || val === undefined || val === '') return '—';
            return parseFloat(val).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },

        /** Нормализует ввод суммы: убирает запятые-разделители тысяч, оставляет точку десятичную */
        normalizeAmount: function (val) {
            if (val === null || val === undefined || val === '') return '';
            var s = String(val).trim();
            // Убираем пробелы (разделитель тысяч)
            s = s.replace(/\s/g, '');
            // Убираем запятые (разделитель тысяч), точка остаётся десятичным
            s = s.replace(/,/g, '');
            var n = parseFloat(s);
            if (isNaN(n)) return val;
            return n.toFixed(2);
        },

        // ══════════════════════════════════════════════════
        // ИСТОРИЯ ИЗМЕНЕНИЙ
        // ══════════════════════════════════════════════════

        /**
         * Открыть модальное окно истории для записи
         */
        showHistory: function (entry) {
            var self = this;
            self.historyEntry = entry;
            self.historyItems = [];
            self.historyLoading = true;
            self._showModal('entryHistoryModal');

            SmartMatchApi.get(window.AppRoutes.entryHistory, { id: entry.id })
                .then(function (r) {
                    let response = r.data
                    if (response.success) {
                        self.historyItems = response.data || [];
                    } else {
                        self.historyItems = [];
                    }
                })
                .catch(function () {
                    self.historyItems = [];
                })
                .finally(function () {
                    self.historyLoading = false;
                });
        }
    }
};