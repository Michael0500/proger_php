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

            _archiveDebounceTimer: null,
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

        // Восстановить запись из архива
        restoreFromArchive: function (row) {
            var self = this;
            Swal.fire({
                title: 'Восстановить запись?',
                html:  'Запись <strong>ID=' + row.original_id + '</strong> (Match ID: ' +
                    row.match_id + ') будет возвращена в активные записи.',
                icon:  'question',
                showCancelButton: true,
                confirmButtonText: 'Восстановить',
                cancelButtonText:  'Отмена',
                confirmButtonColor: '#059669',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                SmartMatchApi.post(AppRoutes.archiveRestore, { id: row.id }).then(function (d) {
                    if (d.success) {
                        Swal.fire('Восстановлено', d.message, 'success');
                        self.loadArchive(true);
                        self.loadArchiveStats();
                    } else {
                        Swal.fire('Ошибка', d.message, 'error');
                    }
                }).catch(function () {
                    Swal.fire('Ошибка', 'Ошибка соединения', 'error');
                });
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
    },
};