<?php
/** @var yii\web\View $this */
/** @var array $initData */

use yii\helpers\Url;

$this->title = 'Выверка по всем ностро-банкам — SmartMatch';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="all-nostro-app" v-cloak>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-globe" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Выверка по всем ностро-банкам</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">
                    <span v-if="entriesTotal > 0">{{ entriesTotal.toLocaleString() }} {{ recordText(entriesTotal) }}</span>
                    <span v-else>Записи со всех ностро-банков компании</span>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <button class="toolbar-btn outline" @click="filtersOpen = !filtersOpen"
                    :style="(filtersOpen || activeFilterCount() > 0) ? 'border-color:#6366f1;color:#6366f1' : ''">
                <i class="fas fa-filter"></i>Фильтры
                <span v-if="activeFilterCount() > 0"
                      style="background:#6366f1;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px">
                    {{ activeFilterCount() }}
                </span>
            </button>
            <button v-if="activeFilterCount() > 0" class="toolbar-btn outline" @click="clearAllFilters"
                    style="border-color:#ef4444;color:#ef4444">
                <i class="fas fa-times"></i>Сбросить фильтры
            </button>
            <div style="position:relative">
                <button class="toolbar-btn outline" @click="toggleColsDropdown" data-col-toggle
                        :style="showColsDropdown ? 'border-color:#6366f1;color:#6366f1' : ''">
                    <i class="fas fa-columns"></i>Столбцы
                </button>
                <div v-if="showColsDropdown" class="col-mgr-dropdown">
                    <div class="col-mgr-title">Видимые столбцы</div>
                    <label v-for="col in tableColumns" :key="col.key" class="col-mgr-item">
                        <input type="checkbox" v-model="col.visible">
                        {{ col.label }}
                    </label>
                </div>
            </div>
        </div>
    </div>

    <?= $this->render("//partials/_entries-filters", ["showMultiPoolFilter" => true, "showAccountFilter" => true, "poolSelectId" => "an-filter-pools", "accountSelectId" => "an-filter-account"]) ?>

    <!-- ══ ТАБЛИЦА ══════════════════════════════════════════════ -->
    <div class="table-card">
        <div v-if="entriesLoading" style="display:flex;justify-content:center;align-items:center;height:200px">
            <div class="spinner-border" style="color:#6366f1"></div>
        </div>
        <div v-else-if="entries.length === 0" class="empty-pool" style="padding:60px">
            <i class="fas fa-inbox"></i>
            <p>Нет записей</p>
        </div>
        <div v-else class="table-scroll-wrap" @scroll="onTableScroll">
            <table class="entries-table">
                <thead>
                    <tr>
                        <th v-show="tblColVisible('id')" class="th-sort th-resizable" @click="sortBy('id')"
                            :style="{width: colByKey('id').width+'px', minWidth: colByKey('id').width+'px', paddingLeft:'14px'}">
                            <span>ID</span> <i :class="sortIcon('id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('account_id')" class="th-sort th-resizable" @click="sortBy('account_id')"
                            :style="{width: colByKey('account_id').width+'px', minWidth: colByKey('account_id').width+'px'}">
                            <span>Банк / Счёт</span> <i :class="sortIcon('account_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('account_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('match_id')" class="th-sort th-resizable" @click="sortBy('match_id')"
                            :style="{width: colByKey('match_id').width+'px', minWidth: colByKey('match_id').width+'px'}">
                            <span>Match ID</span> <i :class="sortIcon('match_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('match_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('ls')" class="th-sort th-resizable" @click="sortBy('ls')"
                            :style="{width: colByKey('ls').width+'px', minWidth: colByKey('ls').width+'px'}">
                            <span>L/S</span> <i :class="sortIcon('ls')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('ls'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('dc')" class="th-sort th-resizable" @click="sortBy('dc')"
                            :style="{width: colByKey('dc').width+'px', minWidth: colByKey('dc').width+'px'}">
                            <span>D/C</span> <i :class="sortIcon('dc')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('dc'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('amount')" class="th-sort th-resizable" @click="sortBy('amount')"
                            :style="{width: colByKey('amount').width+'px', minWidth: colByKey('amount').width+'px', textAlign:'right'}">
                            <span>Сумма</span> <i :class="sortIcon('amount')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('amount'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('currency')" class="th-sort th-resizable" @click="sortBy('currency')"
                            :style="{width: colByKey('currency').width+'px', minWidth: colByKey('currency').width+'px'}">
                            <span>Вал.</span> <i :class="sortIcon('currency')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('currency'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('value_date')" class="th-sort th-resizable" @click="sortBy('value_date')"
                            :style="{width: colByKey('value_date').width+'px', minWidth: colByKey('value_date').width+'px'}">
                            <span>Value Date</span> <i :class="sortIcon('value_date')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('value_date'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('post_date')" class="th-sort th-resizable" @click="sortBy('post_date')"
                            :style="{width: colByKey('post_date').width+'px', minWidth: colByKey('post_date').width+'px'}">
                            <span>Post Date</span> <i :class="sortIcon('post_date')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('post_date'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('instruction_id')" class="th-sort th-resizable" @click="sortBy('instruction_id')"
                            :style="{width: colByKey('instruction_id').width+'px', minWidth: colByKey('instruction_id').width+'px'}">
                            <span>Instr.ID</span> <i :class="sortIcon('instruction_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('instruction_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('end_to_end_id')" class="th-sort th-resizable" @click="sortBy('end_to_end_id')"
                            :style="{width: colByKey('end_to_end_id').width+'px', minWidth: colByKey('end_to_end_id').width+'px'}">
                            <span>E2E ID</span> <i :class="sortIcon('end_to_end_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('end_to_end_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('transaction_id')" class="th-sort th-resizable" @click="sortBy('transaction_id')"
                            :style="{width: colByKey('transaction_id').width+'px', minWidth: colByKey('transaction_id').width+'px'}">
                            <span>Txn ID</span> <i :class="sortIcon('transaction_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('transaction_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('message_id')" class="th-sort th-resizable" @click="sortBy('message_id')"
                            :style="{width: colByKey('message_id').width+'px', minWidth: colByKey('message_id').width+'px'}">
                            <span>Msg ID</span> <i :class="sortIcon('message_id')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('message_id'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('comment')" class="th-sort th-resizable" @click="sortBy('comment')"
                            :style="{width: colByKey('comment').width+'px', minWidth: colByKey('comment').width+'px'}">
                            <span>Комментарий</span> <i :class="sortIcon('comment')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('comment'))" @click.stop></div>
                        </th>
                        <th v-show="tblColVisible('match_status')" class="th-sort th-resizable" @click="sortBy('match_status')"
                            :style="{width: colByKey('match_status').width+'px', minWidth: colByKey('match_status').width+'px'}">
                            <span>Статус</span> <i :class="sortIcon('match_status')"></i>
                            <div class="col-resize-handle" @mousedown.stop.prevent="startColResize($event, colByKey('match_status'))" @click.stop></div>
                        </th>
                        <th style="width:60px;min-width:60px;text-align:right;padding-right:14px">
                            <i class="fas fa-cog" style="opacity:.3"></i>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.id">
                        <td v-show="tblColVisible('id')" style="font-size:11px;color:#9ca3af;font-family:monospace;padding-left:14px">{{ entry.id }}</td>
                        <td v-show="tblColVisible('account_id')">
                            <div style="font-size:12px;font-weight:600;color:#374151">{{ entry.account_name || '—' }}</div>
                            <div v-if="entry.pool_name" style="font-size:10px;color:#9ca3af">
                                <i class="fas fa-landmark" style="font-size:9px;color:#4f46e5"></i>
                                {{ entry.pool_name }}
                            </div>
                        </td>
                        <td v-show="tblColVisible('match_id')">
                            <span v-if="entry.match_id" class="match-id-badge">
                                <i class="fas fa-link" style="font-size:8px"></i>{{ entry.match_id }}
                            </span>
                            <span v-else style="color:#d1d5db;font-size:11px">—</span>
                        </td>
                        <td v-show="tblColVisible('ls')">
                            <span :class="entry.ls==='L'?'badge-ls-l':'badge-ls-s'">{{ entry.ls }}</span>
                        </td>
                        <td v-show="tblColVisible('dc')">
                            <span :class="entry.dc==='Debit'?'badge-debit':'badge-credit'">
                                {{ entry.dc==='Debit'?'D':'C' }}
                            </span>
                        </td>
                        <td v-show="tblColVisible('amount')" style="text-align:right;font-family:monospace;font-weight:600;color:#1a202c;white-space:nowrap">
                            {{ formatAmount(entry.amount) }}
                        </td>
                        <td v-show="tblColVisible('currency')"><span style="font-size:11px;color:#6b7280;font-weight:700">{{ entry.currency }}</span></td>
                        <td v-show="tblColVisible('value_date')" style="white-space:nowrap;font-size:12px">{{ fmtDate(entry.value_date) }}</td>
                        <td v-show="tblColVisible('post_date')" style="white-space:nowrap;font-size:12px">{{ fmtDate(entry.post_date) }}</td>
                        <td v-show="tblColVisible('instruction_id')" class="td-mono-truncate" :title="entry.instruction_id">{{ entry.instruction_id||'—' }}</td>
                        <td v-show="tblColVisible('end_to_end_id')" class="td-mono-truncate" :title="entry.end_to_end_id">{{ entry.end_to_end_id||'—' }}</td>
                        <td v-show="tblColVisible('transaction_id')" class="td-mono-truncate" :title="entry.transaction_id">{{ entry.transaction_id||'—' }}</td>
                        <td v-show="tblColVisible('message_id')" class="td-mono-truncate" :title="entry.message_id">{{ entry.message_id||'—' }}</td>
                        <td v-show="tblColVisible('comment')" class="td-mono-truncate" :title="entry.comment">{{ entry.comment||'—' }}</td>
                        <td v-show="tblColVisible('match_status')">
                            <span :class="entry.match_status==='M'?'status-badge status-matched':
                                          entry.match_status==='I'?'status-badge status-ignored':
                                          'status-badge status-waiting'">
                                {{ entry.match_status==='M'?'Сквит.':entry.match_status==='I'?'Игнор':'Ожидает' }}
                            </span>
                        </td>
                        <td style="text-align:right;padding-right:8px">
                            <button class="row-btn info" @click="openEntryDetail(entry)" title="Подробнее">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <tr v-if="entriesLoadingMore">
                        <td colspan="16" style="text-align:center;padding:16px">
                            <div class="spinner-border spinner-border-sm"
                                 style="color:#6366f1;width:18px;height:18px;border-width:2px"></div>
                            <span style="margin-left:8px;font-size:12px;color:#9ca3af">Загрузка...</span>
                        </td>
                    </tr>
                    <tr v-if="!hasMoreEntries && entries.length>0 && !entriesLoading">
                        <td colspan="16" style="text-align:center;padding:12px;font-size:11px;color:#c4c9d6;border-top:1px solid #f4f5f8">
                            <i class="fas fa-check-circle me-1"></i>
                            Все {{ entriesTotal.toLocaleString() }} {{ recordText(entriesTotal) }} загружены
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?= $this->render("//partials/_entries-detail-modal") ?>

</div>

<script>
(function () {
    'use strict';

    var INIT = <?= $initJson ?>;

    var ROUTES = {
        list:           '<?= Url::to(['/all-nostro/list']) ?>',
        searchAccounts: '<?= Url::to(['/all-nostro/search-accounts']) ?>',
        prefGet:        '<?= Url::to(['/user-preference/get']) ?>',
        prefSave:       '<?= Url::to(['/user-preference/save']) ?>'
    };

    document.addEventListener('DOMContentLoaded', function () {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && window.axios) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }

        new Vue({
            el: '#all-nostro-app',

            data: {
                pools: INIT.pools || [],

                entries: [],
                entriesTotal: 0,
                entriesPage: 1,
                entriesLimit: 50,
                entriesLoading: false,
                entriesLoadingMore: false,

                sortCol: 'id',
                sortDir: 'desc',

                filters: {},
                filtersOpen: true,

                detailEntry: null,

                // ── Настройки колонок (синхронизируются со страницей выверки) ──
                tableColumns: [
                    { key: 'id',             label: 'ID',             visible: false, width: 60  },
                    { key: 'account_id',     label: 'Счёт',           visible: false, width: 120 },
                    { key: 'match_id',       label: 'Match ID',       visible: true,  width: 100 },
                    { key: 'ls',             label: 'L/S',            visible: true,  width: 55  },
                    { key: 'dc',             label: 'D/C',            visible: true,  width: 55  },
                    { key: 'amount',         label: 'Сумма',          visible: true,  width: 110 },
                    { key: 'currency',       label: 'Вал.',           visible: true,  width: 55  },
                    { key: 'value_date',     label: 'Value Date',     visible: true,  width: 100 },
                    { key: 'post_date',      label: 'Post Date',      visible: true,  width: 100 },
                    { key: 'instruction_id', label: 'Instr.ID',       visible: true,  width: 100 },
                    { key: 'end_to_end_id',  label: 'E2E ID',         visible: true,  width: 95  },
                    { key: 'transaction_id', label: 'Txn ID',         visible: true,  width: 95  },
                    { key: 'message_id',     label: 'Msg ID',         visible: true,  width: 95  },
                    { key: 'comment',        label: 'Комментарий',    visible: true,  width: 130 },
                    { key: 'match_status',   label: 'Статус',         visible: false, width: 95  }
                ],
                showColsDropdown: false,
                _tableColumnsLoaded: false,
                _colsSaveTimer: null,

                _filterDebounceTimer: null,
                _poolsSelect2Inited: false,
                _accountSelect2Inited: false
            },

            computed: {
                hasMoreEntries: function () {
                    return this.entries.length < this.entriesTotal;
                }
            },

            watch: {
                tableColumns: {
                    handler: function () { this.saveTableColumnsPrefs(); },
                    deep: true
                }
            },

            mounted: function () {
                var self = this;
                self.loadTableColumnsPrefs();
                self.loadEntries(true);
                self.$nextTick(function () {
                    self.initPoolsSelect2();
                    self.initAccountSelect2();
                });

                document.addEventListener('click', function (e) {
                    if (!self.showColsDropdown) return;
                    if (e.target.closest && (e.target.closest('.col-mgr-dropdown') || e.target.closest('[data-col-toggle]'))) return;
                    self.showColsDropdown = false;
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        self.showColsDropdown = false;
                        self.closeEntryDetail();
                    }
                });
            },

            methods: {
                recordText: function (count) {
                    var n = Math.abs(count) % 100;
                    var n1 = n % 10;
                    if (n > 10 && n < 20) return 'записей';
                    if (n1 > 1 && n1 < 5) return 'записи';
                    if (n1 === 1) return 'запись';
                    return 'записей';
                },

                loadEntries: function (reset) {
                    if (reset) {
                        this.entries = [];
                        this.entriesPage = 1;
                    }
                    var self = this;
                    var isFirst = self.entriesPage === 1;
                    if (isFirst) self.entriesLoading = true;
                    else self.entriesLoadingMore = true;

                    axios.get(ROUTES.list, {
                        params: {
                            page:    self.entriesPage,
                            limit:   self.entriesLimit,
                            sort:    self.sortCol,
                            dir:     self.sortDir,
                            filters: JSON.stringify(self.filters)
                        }
                    }).then(function (response) {
                        var r = response.data;
                        if (r && r.success) {
                            self.entries = reset ? r.data : self.entries.concat(r.data);
                            self.entriesTotal = r.total;
                        }
                    }).catch(function () { /* no-op */ })
                    .then(function () {
                        self.entriesLoading = false;
                        self.entriesLoadingMore = false;
                    });
                },

                loadMoreEntries: function () {
                    if (this.entriesLoadingMore || !this.hasMoreEntries) return;
                    this.entriesPage++;
                    this.loadEntries(false);
                },

                onTableScroll: function (e) {
                    var el = e.target;
                    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 160) {
                        this.loadMoreEntries();
                    }
                },

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

                applyFilter: function (field, value) {
                    var v = (value === null || value === undefined) ? '' : String(value).trim();
                    if (v === '') {
                        this.$delete(this.filters, field);
                    } else {
                        this.$set(this.filters, field, v);
                    }
                    this.loadEntries(true);
                },
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
                    var $p = $('#an-filter-pools');
                    if ($p.length && $p.data('select2')) $p.val(null).trigger('change');
                    var $a = $('#an-filter-account');
                    if ($a.length && $a.data('select2')) $a.val(null).trigger('change');
                    this.loadEntries(true);
                },
                activeFilterCount: function () {
                    var self = this, cnt = 0;
                    Object.keys(self.filters).forEach(function (k) {
                        var v = self.filters[k];
                        if (v === undefined || v === '' || v === null) return;
                        if (Array.isArray(v) && v.length === 0) return;
                        cnt++;
                    });
                    return cnt;
                },

                // ── Select2: мультивыбор ностро-банков ────────────────
                initPoolsSelect2: function () {
                    var self = this;
                    var $el = $('#an-filter-pools');
                    if (!$el.length || self._poolsSelect2Inited) return;
                    self._poolsSelect2Inited = true;

                    var data = self.pools.map(function (p) {
                        return { id: String(p.id), text: p.name };
                    });

                    $el.select2({
                        theme:       'bootstrap-5',
                        placeholder: 'Все ностро-банки...',
                        allowClear:  true,
                        data:        data,
                        language: {
                            noResults: function () { return 'Нет ностро-банков'; }
                        }
                    });

                    $el.on('change', function () {
                        var vals = $el.val() || [];
                        if (vals.length === 0) {
                            self.$delete(self.filters, 'pool_ids');
                        } else {
                            self.$set(self.filters, 'pool_ids', vals.map(function (v) { return parseInt(v, 10); }));
                        }
                        // Сбросим выбранный счёт — список мог стать невалидным
                        var $a = $('#an-filter-account');
                        if ($a.length && $a.data('select2')) $a.val(null).trigger('change');
                        self.$delete(self.filters, 'account_id');

                        self.loadEntries(true);
                    });
                },

                // ── Select2: счёт ─────────────────────────────────────
                initAccountSelect2: function () {
                    var self = this;
                    var $el = $('#an-filter-account');
                    if (!$el.length || self._accountSelect2Inited) return;
                    self._accountSelect2Inited = true;

                    $el.select2({
                        theme:              'bootstrap-5',
                        placeholder:        'Все счета...',
                        allowClear:         true,
                        minimumInputLength: 0,
                        ajax: {
                            url: ROUTES.searchAccounts,
                            dataType: 'json',
                            delay: 200,
                            data: function (p) {
                                var req = { q: p.term || '' };
                                var poolIds = (self.filters.pool_ids || []);
                                if (poolIds.length) {
                                    req['pool_ids'] = poolIds;
                                }
                                return req;
                            },
                            processResults: function (d) { return d; },
                            cache: false
                        },
                        templateResult: function (item) {
                            if (item.loading) return item.text;
                            var tag = item.currency
                                ? '<span style="background:#e0e7ff;color:#4338ca;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:700;margin-left:5px">' + item.currency + '</span>'
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

                openEntryDetail: function (entry) {
                    this.detailEntry = entry;
                    document.body.style.overflow = 'hidden';
                },
                closeEntryDetail: function () {
                    this.detailEntry = null;
                    document.body.style.overflow = '';
                },

                // ── Управление колонками (общий ключ с выверкой) ────────
                colByKey: function (key) {
                    return this.tableColumns.find(function (c) { return c.key === key; }) || { width: 100 };
                },
                tblColVisible: function (key) {
                    var col = this.tableColumns.find(function (c) { return c.key === key; });
                    return col ? col.visible : true;
                },
                toggleColsDropdown: function () {
                    this.showColsDropdown = !this.showColsDropdown;
                },
                startColResize: function (e, col) {
                    e.preventDefault();
                    e.stopPropagation();
                    var startX = e.clientX;
                    var startW = col.width || 100;

                    var onMove = function (ev) {
                        ev.preventDefault();
                        col.width = Math.max(50, startW + (ev.clientX - startX));
                    };
                    var onUp = function () {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        document.body.style.userSelect = '';
                        document.body.style.cursor = '';
                        document.body.classList.remove('resizing-col');
                    };
                    document.body.style.userSelect = 'none';
                    document.body.style.cursor = 'col-resize';
                    document.body.classList.add('resizing-col');
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                },

                loadTableColumnsPrefs: function () {
                    var self = this;
                    axios.get(ROUTES.prefGet, { params: { key: 'entries_table_columns' } })
                        .then(function (response) {
                            var r = response && response.data ? response.data : null;
                            if (r && r.success && Array.isArray(r.value)) {
                                var saved = {};
                                r.value.forEach(function (c) {
                                    if (c && typeof c.key === 'string') saved[c.key] = c;
                                });
                                self.tableColumns.forEach(function (col) {
                                    var s = saved[col.key];
                                    if (!s) return;
                                    if (typeof s.visible === 'boolean') col.visible = s.visible;
                                    if (typeof s.width === 'number' && s.width >= 40) col.width = s.width;
                                });
                            }
                        })
                        .catch(function () { /* no-op */ })
                        .then(function () {
                            self.$nextTick(function () { self._tableColumnsLoaded = true; });
                        });
                },

                saveTableColumnsPrefs: function () {
                    if (!this._tableColumnsLoaded) return;
                    var self = this;
                    if (self._colsSaveTimer) clearTimeout(self._colsSaveTimer);
                    self._colsSaveTimer = setTimeout(function () {
                        var payload = self.tableColumns.map(function (c) {
                            return { key: c.key, visible: !!c.visible, width: c.width };
                        });
                        axios.post(ROUTES.prefSave, {
                            key: 'entries_table_columns',
                            value: payload
                        });
                    }, 600);
                },

                formatAmount: function (val) {
                    if (val === null || val === undefined || val === '') return '—';
                    return parseFloat(val).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                },
                fmtDate: function (val) {
                    if (!val) return '—';
                    var parts = String(val).slice(0, 10).split('-');
                    if (parts.length === 3) return parts[2] + '.' + parts[1] + '.' + parts[0];
                    return val;
                }
            }
        });
    });
}());
</script>
