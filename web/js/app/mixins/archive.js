/**
 * ArchiveMixin — раздел архива сквитованных записей.
 * Стиль — как BalanceMixin / EntriesMixin в проекте.
 */
var ArchiveMixin = {
    data: function () {
        return {
            // ── Таблица архива ────────────────────────────────────
            archiveRows:            [],
            archiveTotal:           0,
            archivePage:            1,
            archiveLimit:           50,
            archiveLoading:         false,
            archiveLoadingMore:     false,

            archiveSortCol: 'archived_at',
            archiveSortDir: 'desc',

            archiveFilters:     {},
            archiveFiltersOpen: false,

            // Список счетов для фильтра
            archiveAccounts: [],

            // ── Статистика ────────────────────────────────────────
            archiveStats:        null,
            archiveStatsLoading: false,

            // ── Настройки ─────────────────────────────────────────
            archiveSettings: {
                archive_after_days:   90,
                retention_years:       5,
                auto_archive_enabled:  true,
            },
            archiveSettingsOpen:   false,
            archiveSettingsSaving: false,

            // ── Архивирование вручную ─────────────────────────────
            archiveRunning:       false,
            archivePurging:       false,
            archiveProgressOpen:  false,
            archiveProgressDone:  0,
            archiveProgressAll:   0,
            archiveProgressPct:   0,

            _archiveDebounceTimer:        null,
            _archivePoolSelect2Inited:    false,
            _archiveAccountSelect2Inited: false,
        };
    },

    computed: {
        hasMoreArchive: function () {
            return this.archiveRows.length < this.archiveTotal;
        },
    },

    methods: {

        // ══════════════════════════════════════════════════
        // ЗАГРУЗКА АРХИВА
        // ══════════════════════════════════════════════════

        loadArchive: function (reset) {
            if (reset) {
                this.archiveRows  = [];
                this.archivePage  = 1;
            }
            var self    = this;
            var isFirst = this.archivePage === 1;
            if (isFirst) self.archiveLoading     = true;
            else         self.archiveLoadingMore  = true;

            SmartMatchApi.get(AppRoutes.archiveList, {
                page:    self.archivePage,
                limit:   self.archiveLimit,
                sort:    self.archiveSortCol,
                dir:     self.archiveSortDir,
                filters: JSON.stringify(self.archiveFilters),
            }).then(function (r) {
                var d = r.data;
                if (d && d.success) {
                    self.archiveRows  = reset ? d.data : self.archiveRows.concat(d.data);
                    self.archiveTotal = d.total;
                }
            }).finally(function () {
                self.archiveLoading     = false;
                self.archiveLoadingMore = false;
            });
        },

        loadMoreArchive: function () {
            if (this.archiveLoadingMore || !this.hasMoreArchive) return;
            this.archivePage++;
            this.loadArchive(false);
        },

        onArchiveScroll: function (e) {
            var el = e.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 160) {
                this.loadMoreArchive();
            }
        },

        // ══════════════════════════════════════════════════
        // СОРТИРОВКА
        // ══════════════════════════════════════════════════

        sortArchive: function (col) {
            if (this.archiveSortCol === col) {
                this.archiveSortDir = this.archiveSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.archiveSortCol = col;
                this.archiveSortDir = 'asc';
            }
            this.loadArchive(true);
        },

        archiveSortIcon: function (col) {
            if (this.archiveSortCol !== col) return 'fas fa-sort';
            return this.archiveSortDir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        },

        // ══════════════════════════════════════════════════
        // ФИЛЬТРЫ
        // ══════════════════════════════════════════════════

        applyArchiveFilter: function (field, value) {
            var v = (value === null || value === undefined) ? '' : String(value).trim();
            if (v === '') {
                this.$delete(this.archiveFilters, field);
            } else {
                this.$set(this.archiveFilters, field, v);
            }
            this.loadArchive(true);
        },

        clearArchiveFilter: function (field) {
            this.$delete(this.archiveFilters, field);
            this.loadArchive(true);
        },

        clearAllArchiveFilters: function () {
            this.archiveFilters = {};
            var $fp = $('#archive-pool-select2');
            if ($fp.length && $fp.data('select2')) $fp.val(null).trigger('change');
            var $fa = $('#archive-account-select2');
            if ($fa.length && $fa.data('select2')) $fa.val(null).trigger('change');
            this.loadArchive(true);
        },

        debouncedArchiveFilter: function (field, value) {
            var self = this;
            clearTimeout(self._archiveDebounceTimer);
            self._archiveDebounceTimer = setTimeout(function () {
                self.applyArchiveFilter(field, value);
            }, 400);
        },

        activeArchiveFilterCount: function () {
            return Object.keys(this.archiveFilters).filter(function (k) {
                return !!this.archiveFilters[k];
            }, this).length;
        },

        toggleArchiveFilters: function () {
            this.archiveFiltersOpen = !this.archiveFiltersOpen;
            if (this.archiveFiltersOpen) {
                var self = this;
                setTimeout(function () {
                    self.initArchivePoolSelect2();
                    self.initArchiveAccountSelect2();
                }, 120);
            }
        },

        initArchivePoolSelect2: function () {
            var self = this;
            var $el  = $('#archive-pool-select2');
            if (!$el.length || self._archivePoolSelect2Inited) return;
            self._archivePoolSelect2Inited = true;

            var poolData = (self.accountPools || []).map(function (p) {
                return { id: String(p.id), text: p.name };
            });

            $el.select2({
                theme:       'bootstrap-5',
                placeholder: 'Все банки...',
                allowClear:  true,
                data:        poolData,
                language: { noResults: function () { return 'Нет ностробанков'; } }
            });
            $el.val(null).trigger('change.select2');

            $el.on('select2:select', function (e) {
                self.applyArchiveFilter('account_pool_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearArchiveFilter('account_pool_id');
            });
        },

        initArchiveAccountSelect2: function () {
            var self = this;
            var $el  = $('#archive-account-select2');
            if (!$el.length || self._archiveAccountSelect2Inited) return;
            self._archiveAccountSelect2Inited = true;

            var accData = (self.archiveAccounts || []).map(function (a) {
                return { id: String(a.id), text: a.name + (a.currency ? ' (' + a.currency + ')' : '') };
            });

            $el.select2({
                theme:       'bootstrap-5',
                placeholder: 'Все счета...',
                allowClear:  true,
                data:        accData,
                language: { noResults: function () { return 'Нет счетов'; } }
            });
            $el.val(null).trigger('change.select2');

            $el.on('select2:select', function (e) {
                self.applyArchiveFilter('account_id', e.params.data.id);
            });
            $el.on('select2:clear', function () {
                self.clearArchiveFilter('account_id');
            });
        },

        // ══════════════════════════════════════════════════
        // ОПЕРАЦИИ
        // ══════════════════════════════════════════════════

        // Запустить архивирование (прогрессивно, порциями)
        runArchive: function () {
            var self = this;

            Swal.fire({
                title: 'Запустить архивирование?',
                html:  'Все сквитованные записи старше <strong>' +
                    self.archiveSettings.archive_after_days +
                    ' дней</strong> будут перенесены в архив.',
                icon:  'question',
                showCancelButton: true,
                confirmButtonText: 'Да, архивировать',
                cancelButtonText:  'Отмена',
                confirmButtonColor: '#4f46e5',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                self._startProgressArchive();
            });
        },

        // Внутренний метод — запускает цикл AJAX-порций
        _startProgressArchive: function () {
            var self = this;
            self.archiveRunning      = true;
            self.archiveProgressDone = 0;
            self.archiveProgressAll  = 0;
            self.archiveProgressPct  = 0;
            self.archiveProgressOpen = true;

            // Сначала узнаём общее кол-во — для прогресс-бара
            SmartMatchApi.post(AppRoutes.archiveCount, {}).then(function (d) {
                if (!d.success) {
                    self._closeProgressArchive();
                    Swal.fire('Ошибка', d.message || 'Не удалось начать', 'error');
                    return;
                }
                if (d.total === 0) {
                    self._closeProgressArchive();
                    Swal.fire('Готово', 'Нет записей для архивирования', 'info');
                    return;
                }
                self.archiveProgressAll = d.total;
                // Запускаем первую порцию
                self._runNextBatch(0, d.total);
            }).catch(function () {
                self._closeProgressArchive();
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            });
        },

        _runNextBatch: function (totalDone, totalAll) {
            var self = this;
            SmartMatchApi.post(AppRoutes.archiveRunBatch, {
                total_done: totalDone,
                total_all:  totalAll,
            }).then(function (d) {
                if (!d.success) {
                    self._closeProgressArchive();
                    Swal.fire('Ошибка', d.message, 'error');
                    return;
                }

                self.archiveProgressDone = d.total_done;
                self.archiveProgressPct  = d.percent || 0;

                if (d.is_finished) {
                    self._closeProgressArchive();
                    self.loadArchive(true);
                    self.loadArchiveStats();
                    Swal.fire({
                        title: 'Архивирование завершено!',
                        html:  'Заархивировано записей: <strong>' + d.total_done + '</strong>',
                        icon:  'success',
                        confirmButtonColor: '#4f46e5',
                    });
                } else {
                    // Небольшая пауза чтобы браузер не подвисал
                    setTimeout(function () {
                        self._runNextBatch(d.total_done, d.total_all);
                    }, 50);
                }
            }).catch(function (err) {
                self._closeProgressArchive();
                Swal.fire('Ошибка', 'Ошибка соединения: ' + (err.message || ''), 'error');
            });
        },

        _closeProgressArchive: function () {
            this.archiveRunning      = false;
            this.archiveProgressOpen = false;
        },

        // Удалить просроченные записи
        purgeExpired: function () {
            var self = this;
            Swal.fire({
                title: 'Удалить просроченные?',
                text:  'Архивные записи с истёкшим сроком хранения будут безвозвратно удалены.',
                icon:  'warning',
                showCancelButton: true,
                confirmButtonText: 'Удалить',
                cancelButtonText:  'Отмена',
                confirmButtonColor: '#ef4444',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                self.archivePurging = true;
                SmartMatchApi.post(AppRoutes.archivePurgeExpired, {}).then(function (d) {
                    if (d.success) {
                        Swal.fire('Готово', d.message, 'success');
                        self.loadArchive(true);
                        self.loadArchiveStats();
                    } else {
                        Swal.fire('Ошибка', d.message, 'error');
                    }
                }).finally(function () {
                    self.archivePurging = false;
                });
            });
        },

        archiveRestoreRowText: function (row, index) {
            var parts = [
                (index + 1) + '. ID=' + (row.original_id || row.id || '—'),
                row.ls || '—',
                row.dc === 'Debit' ? 'D' : (row.dc === 'Credit' ? 'C' : (row.dc || '—')),
                this.formatAmount(row.amount) + ' ' + (row.currency || ''),
            ];
            if (row.value_date) parts.push(row.value_date);
            return parts.join(' | ');
        },

        archiveRestoreRowsHtml: function (rows) {
            var self = this;
            return '<div style="max-height:220px;overflow:auto;text-align:left;margin-top:10px;' +
                'border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#f9fafb">' +
                rows.map(function (item, idx) {
                    return '<div style="font-family:monospace;font-size:12px;line-height:1.5">' +
                        self.escapeArchiveHtml(self.archiveRestoreRowText(item, idx)) +
                        '</div>';
                }).join('') +
                '</div>';
        },

        escapeArchiveHtml: function (value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        // Восстановить группу записей из архива по match_id
        restoreFromArchive: function (row) {
            var self = this;
            SmartMatchApi.get(AppRoutes.archiveRestorePreview, { id: row.id }).then(function (r) {
                var preview = r.data;
                if (!preview || !preview.success) {
                    Swal.fire('Ошибка', (preview && preview.message) || 'Не удалось получить связанные строки', 'error');
                    return;
                }

                var rows = preview.data || [];
                var matchId = self.escapeArchiveHtml(preview.match_id || row.match_id || '—');
                Swal.fire({
                    title: rows.length === 1 ? 'Восстановить запись?' : 'Восстановить связанные записи?',
                    html:  'В активные записи будут возвращены строки по Match ID: <strong>' + matchId + '</strong>.' +
                        self.archiveRestoreRowsHtml(rows),
                    icon:  'question',
                    showCancelButton: true,
                    confirmButtonText: rows.length === 1 ? 'Восстановить' : 'Восстановить все',
                    cancelButtonText:  'Отмена',
                    confirmButtonColor: '#059669',
                    width: 620,
                }).then(function (res) {
                    if (!res.isConfirmed) return;
                    SmartMatchApi.post(AppRoutes.archiveRestore, { id: row.id }).then(function (d) {
                        if (d.success) {
                            var restoredRows = d.data || rows;
                            Swal.fire({
                                title: 'Восстановлено',
                                html: self.escapeArchiveHtml(d.message || 'Записи восстановлены из архива') +
                                    self.archiveRestoreRowsHtml(restoredRows),
                                icon: 'success',
                                confirmButtonColor: '#4f46e5',
                                width: 620,
                            });
                            self.loadArchive(true);
                            self.loadArchiveStats();
                        } else {
                            Swal.fire('Ошибка', d.message, 'error');
                        }
                    }).catch(function () {
                        Swal.fire('Ошибка', 'Ошибка соединения', 'error');
                    });
                });
            }).catch(function () {
                Swal.fire('Ошибка', 'Ошибка соединения', 'error');
            });
        },

        // ══════════════════════════════════════════════════
        // СТАТИСТИКА
        // ══════════════════════════════════════════════════

        loadArchiveStats: function () {
            var self = this;
            self.archiveStatsLoading = true;
            SmartMatchApi.get(AppRoutes.archiveStats, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.archiveStats    = r.data.data;
                    self.archiveSettings = r.data.data.settings;
                }
            }).finally(function () {
                self.archiveStatsLoading = false;
            });
        },

        // ══════════════════════════════════════════════════
        // НАСТРОЙКИ
        // ══════════════════════════════════════════════════

        loadArchiveSettings: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.archiveSettings, {}).then(function (r) {
                if (r.data && r.data.success) {
                    self.archiveSettings = r.data.data;
                }
            });
        },

        saveArchiveSettings: function () {
            var self = this;
            self.archiveSettingsSaving = true;
            SmartMatchApi.post(AppRoutes.archiveSaveSettings, self.archiveSettings).then(function (d) {
                if (d.success) {
                    Swal.fire({ title: 'Сохранено', icon: 'success', timer: 1400, showConfirmButton: false });
                    self.archiveSettingsOpen = false;
                    self.loadArchiveStats();
                } else {
                    Swal.fire('Ошибка', d.message, 'error');
                }
            }).finally(function () {
                self.archiveSettingsSaving = false;
            });
        },

        // ══════════════════════════════════════════════════
        // СЧЕТА ДЛЯ ФИЛЬТРА
        // ══════════════════════════════════════════════════

        loadArchiveAccounts: function () {
            var self = this;
            SmartMatchApi.get(AppRoutes.archiveAccounts, {}).then(function (r) {
                if (r.data && r.data.success) self.archiveAccounts = r.data.data;
            });
        },

        // Переключиться на раздел архива
        switchToArchive: function () {
            this.activeSection = 'archive';
            if (!this.archiveRows.length && !this.archiveLoading) {
                this.loadArchive(true);
            }
            if (!this.archiveStats) {
                this.loadArchiveStats();
            }
        },

        // ══════════════════════════════════════════════════
        // ИСТОРИЯ ИЗМЕНЕНИЙ
        // ══════════════════════════════════════════════════

        /**
         * Открыть модальное окно истории для архивной записи
         */
        showArchiveHistory: function (row) {
            var self = this;
            // Создаём объект entry для отображения в заголовке
            self.historyEntry = {
                id: row.original_id,
                ls: row.ls,
                dc: row.dc,
                amount: row.amount,
                currency: row.currency,
            };
            self.historyItems = [];
            self.historyLoading = true;
            self._showModal('entryHistoryModal');

            SmartMatchApi.get(AppRoutes.archiveHistory, { id: row.id })
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
    },
};
