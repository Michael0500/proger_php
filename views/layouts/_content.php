<?php /** @var yii\web\View $this */ ?>

<div>

    <!-- ══ ТАБЫ ══════════════════════════════════════════════════ -->
    <div style="display:flex;gap:2px;margin-bottom:14px;border-bottom:2px solid #e8eaf0">
        <button class="section-tab" :class="{active: activeSection==='entries'}"
                @click="activeSection='entries'">
            <i class="fas fa-exchange-alt me-1"></i>Выверка
        </button>
        <button class="section-tab" :class="{active: activeSection==='balance'}"
                @click="switchToBalance">
            <i class="fas fa-balance-scale me-1"></i>Баланс
            <span v-if="balancesTotal>0"
                  style="background:#e0e7ff;color:#4338ca;border-radius:10px;padding:0 6px;font-size:10px;font-weight:700;margin-left:4px">
                {{ balancesTotal }}
            </span>
        </button>
    </div>

    <!-- ══ СЕКЦИЯ: ВЫВЕРКА ═══════════════════════════════════════ -->
    <div v-show="activeSection==='entries'">

        <!-- ─────────── Пул не выбран ─────────── -->
        <div v-if="!selectedPool" class="empty-pool">
            <i class="fas fa-hand-point-left"></i>
            <p>Выберите пул в панели слева</p>
        </div>

        <div v-else>

            <!-- ТУЛБАР -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span class="pool-title">{{ selectedPool.name }}</span>
                    <span class="pool-tag">{{ selectedGroup ? selectedGroup.name : '' }}</span>
                    <span v-if="entriesTotal > 0" style="font-size:11px;color:#9ca3af;margin-left:2px">
                    {{ entriesTotal.toLocaleString() }} записей
                </span>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <button class="toolbar-btn outline" @click="toggleFiltersPanel"
                            :style="(filtersOpen || activeFilterCount() > 0) ? 'border-color:#6366f1;color:#6366f1' : ''">
                        <i class="fas fa-filter"></i>Фильтры
                        <span v-if="activeFilterCount() > 0"
                              style="background:#6366f1;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px">
                        {{ activeFilterCount() }}
                    </span>
                    </button>
                    <button class="toolbar-btn outline" @click="showAddEntryModal()">
                        <i class="fas fa-plus"></i>Добавить запись
                    </button>
                    <button class="toolbar-btn outline" @click="loadMatchingRules(); _showModal('rulesListModal')">
                        <i class="fas fa-sliders-h"></i>Правила
                    </button>
                    <button class="toolbar-btn primary" :disabled="autoMatchRunning" @click="runAutoMatch(null)">
                        <i :class="autoMatchRunning ? 'fas fa-spinner fa-spin' : 'fas fa-magic'"></i>
                        {{ autoMatchRunning ? 'Выполняется...' : 'Автоквитование' }}
                    </button>
                    <button class="toolbar-btn success" :disabled="selectedIds.length < 2" @click="matchSelected">
                        <i class="fas fa-link"></i>
                        Сквитовать{{ selectedIds.length > 0 ? ' (' + selectedIds.length + ')' : '' }}
                    </button>
                    <button class="toolbar-btn danger-soft" v-if="selectedIds.length > 0" @click="clearSelection">
                        <i class="fas fa-times"></i>Сбросить
                    </button>
                </div>
            </div>

            <!-- ПАНЕЛЬ ФИЛЬТРОВ -->
            <div v-show="filtersOpen" class="filters-panel">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">
                    <i class="fas fa-filter me-1"></i>Фильтры
                </span>
                    <button class="row-btn delete" @click="clearAllFilters" title="Сбросить все">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">

                    <!-- Счёт Select2 -->
                    <div class="filter-field" style="grid-column:span 2">
                        <label class="filter-label">Ностро счёт</label>
                        <select id="filter-account-select2" style="width:100%"></select>
                    </div>

                    <!-- L/S -->
                    <div class="filter-field">
                        <label class="filter-label">L / S</label>
                        <div class="filter-toggle-group">
                            <button @click="applyFilter('ls','')" :class="['ftg-btn', !filters.ls?'active':'']">Все</button>
                            <button @click="applyFilter('ls','L')" :class="['ftg-btn',filters.ls==='L'?'active-l':'']" style="color:#6366f1">L</button>
                            <button @click="applyFilter('ls','S')" :class="['ftg-btn',filters.ls==='S'?'active-s':'']" style="color:#0284c7">S</button>
                        </div>
                    </div>

                    <!-- D/C -->
                    <div class="filter-field">
                        <label class="filter-label">D / C</label>
                        <div class="filter-toggle-group">
                            <button @click="applyFilter('dc','')" :class="['ftg-btn', !filters.dc?'active':'']">Все</button>
                            <button @click="applyFilter('dc','Debit')" :class="['ftg-btn',filters.dc==='Debit'?'active-d':'']" style="color:#ef4444">D</button>
                            <button @click="applyFilter('dc','Credit')" :class="['ftg-btn',filters.dc==='Credit'?'active-c':'']" style="color:#10b981">C</button>
                        </div>
                    </div>

                    <!-- Статус -->
                    <div class="filter-field">
                        <label class="filter-label">Статус</label>
                        <div class="filter-toggle-group">
                            <button @click="applyFilter('match_status','')" :class="['ftg-btn', !filters.match_status?'active':'']">Все</button>
                            <button @click="applyFilter('match_status','U')" :class="['ftg-btn',filters.match_status==='U'?'active':'']">Ожидает</button>
                            <button @click="applyFilter('match_status','M')" :class="['ftg-btn',filters.match_status==='M'?'active':'']">Сквит.</button>
                        </div>
                    </div>

                    <!-- Валюта -->
                    <div class="filter-field">
                        <label class="filter-label">Валюта</label>
                        <div class="filter-input-wrap">
                            <input type="text" class="filter-input" placeholder="USD, EUR..."
                                   :value="filters.currency||''"
                                   @input="debouncedFilter('currency',$event.target.value)">
                            <button v-if="filters.currency" class="filter-clear-btn" @click="clearFilter('currency')">×</button>
                        </div>
                    </div>

                    <!-- Сумма от/до -->
                    <div class="filter-field">
                        <label class="filter-label">Сумма от</label>
                        <div class="filter-input-wrap">
                            <input type="number" class="filter-input" placeholder="0"
                                   :value="filters.amount_min||''"
                                   @change="applyFilter('amount_min',$event.target.value)">
                            <button v-if="filters.amount_min" class="filter-clear-btn" @click="clearFilter('amount_min')">×</button>
                        </div>
                    </div>
                    <div class="filter-field">
                        <label class="filter-label">Сумма до</label>
                        <div class="filter-input-wrap">
                            <input type="number" class="filter-input" placeholder="∞"
                                   :value="filters.amount_max||''"
                                   @change="applyFilter('amount_max',$event.target.value)">
                            <button v-if="filters.amount_max" class="filter-clear-btn" @click="clearFilter('amount_max')">×</button>
                        </div>
                    </div>

                    <!-- Даты -->
                    <div class="filter-field">
                        <label class="filter-label">Value Date от</label>
                        <div class="filter-input-wrap">
                            <input type="date" class="filter-input" :value="filters.value_date_from||''"
                                   @change="applyFilter('value_date_from',$event.target.value)">
                            <button v-if="filters.value_date_from" class="filter-clear-btn" @click="clearFilter('value_date_from')">×</button>
                        </div>
                    </div>
                    <div class="filter-field">
                        <label class="filter-label">Value Date до</label>
                        <div class="filter-input-wrap">
                            <input type="date" class="filter-input" :value="filters.value_date_to||''"
                                   @change="applyFilter('value_date_to',$event.target.value)">
                            <button v-if="filters.value_date_to" class="filter-clear-btn" @click="clearFilter('value_date_to')">×</button>
                        </div>
                    </div>

                    <!-- ID поиск -->
                    <div class="filter-field">
                        <label class="filter-label">Instr.ID</label>
                        <div class="filter-input-wrap">
                            <input type="text" class="filter-input" placeholder="..."
                                   :value="filters.instruction_id||''"
                                   @input="debouncedFilter('instruction_id',$event.target.value)">
                            <button v-if="filters.instruction_id" class="filter-clear-btn" @click="clearFilter('instruction_id')">×</button>
                        </div>
                    </div>
                    <div class="filter-field">
                        <label class="filter-label">E2E ID</label>
                        <div class="filter-input-wrap">
                            <input type="text" class="filter-input" placeholder="..."
                                   :value="filters.end_to_end_id||''"
                                   @input="debouncedFilter('end_to_end_id',$event.target.value)">
                            <button v-if="filters.end_to_end_id" class="filter-clear-btn" @click="clearFilter('end_to_end_id')">×</button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ПАНЕЛЬ ИТОГОВ -->
            <div v-if="selectionSummary && selectedIds.length > 0" class="summary-bar"
                 :class="summaryBalanced ? 'balanced' : 'unbalanced'"
                 style="margin-bottom:14px">
            <span class="summary-item">
                <i class="fas fa-check-square" style="color:#6366f1"></i>
                <strong>{{ selectedIds.length }}</strong> выбрано
            </span>
                <span class="summary-sep">|</span>
                <span class="summary-item">
                <span class="mono" style="color:#6366f1">L:</span>
                <strong class="mono">{{ formatAmount(selectionSummary.sum_ledger) }}</strong>
                <span style="font-size:10px;color:#9ca3af">({{ selectionSummary.cnt_ledger }})</span>
            </span>
                <span class="summary-sep">|</span>
                <span class="summary-item">
                <span class="mono" style="color:#0284c7">S:</span>
                <strong class="mono">{{ formatAmount(selectionSummary.sum_statement) }}</strong>
                <span style="font-size:10px;color:#9ca3af">({{ selectionSummary.cnt_statement }})</span>
            </span>
                <span class="summary-sep">|</span>
                <span class="summary-item" :style="summaryBalanced ?
                   'color:#059669;font-weight:700' : 'color:#d97706;font-weight:700'">
                <i :class="summaryBalanced ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'"></i>
                Разница: <span style="font-family:monospace">{{ formatAmount(selectionSummary.diff) }}</span>
                <span v-if="summaryBalanced" style="font-weight:500;margin-left:4px">— готово!</span>
            </span>
            </div>

            <!-- ТАБЛИЦА -->
            <div class="table-card">
                <div v-if="entriesLoading" style="display:flex;justify-content:center;align-items:center;height:200px">
                    <div class="spinner-border" style="color:#6366f1"></div>
                </div>
                <div v-else-if="entries.length === 0" class="empty-pool" style="padding:60px">
                    <i class="fas fa-inbox"></i>
                    <p>Нет записей</p>
                    <button class="toolbar-btn success" @click="showAddEntryModal()" style="margin-top:12px">
                        <i class="fas fa-plus"></i>Добавить первую
                    </button>
                </div>
                <div v-else class="table-scroll-wrap" @scroll="onTableScroll">
                    <table class="entries-table">
                        <thead>
                        <tr>
                            <th style="width:36px;padding-left:14px">
                                <input type="checkbox"
                                       style="width:14px;height:14px;cursor:pointer;accent-color:#6366f1"
                                       :checked="allUnmatchedSelected"
                                       :indeterminate.prop="someSelected"
                                       @change="toggleSelectAll($event.target.checked)">
                            </th>
                            <th class="th-sort" @click="sortBy('id')">ID <i :class="sortIcon('id')"></i></th>
                            <th class="th-sort" @click="sortBy('account_id')">Счёт <i :class="sortIcon('account_id')"></i></th>
                            <th class="th-sort" @click="sortBy('match_id')">Match ID <i :class="sortIcon('match_id')"></i></th>
                            <th class="th-sort" @click="sortBy('ls')">L/S <i :class="sortIcon('ls')"></i></th>
                            <th class="th-sort" @click="sortBy('dc')">D/C <i :class="sortIcon('dc')"></i></th>
                            <th class="th-sort" @click="sortBy('amount')" style="text-align:right">Сумма <i :class="sortIcon('amount')"></i></th>
                            <th class="th-sort" @click="sortBy('currency')">Вал. <i :class="sortIcon('currency')"></i></th>
                            <th class="th-sort" @click="sortBy('value_date')">Value Date <i :class="sortIcon('value_date')"></i></th>
                            <th class="th-sort" @click="sortBy('post_date')">Post Date <i :class="sortIcon('post_date')"></i></th>
                            <th class="th-sort" @click="sortBy('instruction_id')">Instr.ID <i :class="sortIcon('instruction_id')"></i></th>
                            <th class="th-sort" @click="sortBy('end_to_end_id')">E2E ID <i :class="sortIcon('end_to_end_id')"></i></th>
                            <th class="th-sort" @click="sortBy('transaction_id')">Txn ID <i :class="sortIcon('transaction_id')"></i></th>
                            <th class="th-sort" @click="sortBy('message_id')">Msg ID <i :class="sortIcon('message_id')"></i></th>
                            <th class="th-sort" @click="sortBy('comment')">Комментарий <i :class="sortIcon('comment')"></i></th>
                            <th class="th-sort" @click="sortBy('match_status')">Статус <i :class="sortIcon('match_status')"></i></th>
                            <th style="width:68px;text-align:right;padding-right:14px">
                                <i class="fas fa-cog" style="opacity:.3"></i>
                            </th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="entry in entries" :key="entry.id"
                            :class="{'entry-selected':isSelected(entry.id),'entry-matched':!isSelected(entry.id)&&entry.match_status==='M'}">

                            <td style="padding-left:14px">
                                <input type="checkbox"
                                       style="width:14px;height:14px;cursor:pointer;accent-color:#6366f1"
                                       v-if="entry.match_status==='U'"
                                       :checked="isSelected(entry.id)"
                                       @change="toggleEntrySelection(entry.id)">
                                <i v-else class="fas fa-lock" style="font-size:10px;color:#d1d5db" title="Сквитовано"></i>
                            </td>

                            <td style="font-size:11px;color:#9ca3af;font-family:monospace">{{ entry.id }}</td>

                            <td style="min-width:110px">
                                <span style="font-size:12px;font-weight:600;color:#374151">{{ entry.account_name || '—' }}</span>
                                <span v-if="entry.account_is_suspense" class="badge-suspense"
                                      style="margin-left:4px;font-size:9px;padding:1px 5px">S</span>
                            </td>

                            <td>
                            <span v-if="entry.match_id" class="match-id-badge"
                                  @click="unmatchEntry(entry.match_id)" title="Нажмите для расквитования">
                                <i class="fas fa-link" style="font-size:8px"></i>{{ entry.match_id }}
                            </span>
                                <span v-else style="color:#d1d5db;font-size:11px">—</span>
                            </td>

                            <td>
                                <span :class="entry.ls==='L'?'badge-ls-l':'badge-ls-s'">{{ entry.ls }}</span>
                            </td>
                            <td>
                            <span :class="entry.dc==='Debit'?'badge-debit':'badge-credit'">
                                {{ entry.dc==='Debit'?'D':'C' }}
                            </span>
                            </td>
                            <td style="text-align:right;font-family:monospace;font-weight:600;color:#1a202c;white-space:nowrap">
                                {{ formatAmount(entry.amount) }}
                            </td>
                            <td><span style="font-size:11px;color:#6b7280;font-weight:700">{{ entry.currency }}</span></td>
                            <td style="white-space:nowrap;font-size:12px">{{ entry.value_date||'—' }}</td>
                            <td style="white-space:nowrap;font-size:12px">{{ entry.post_date||'—' }}</td>

                            <td class="td-mono-truncate" :title="entry.instruction_id">{{ entry.instruction_id||'—' }}</td>
                            <td class="td-mono-truncate" :title="entry.end_to_end_id">{{ entry.end_to_end_id||'—' }}</td>
                            <td class="td-mono-truncate" :title="entry.transaction_id">{{ entry.transaction_id||'—' }}</td>
                            <td class="td-mono-truncate" :title="entry.message_id">{{ entry.message_id||'—' }}</td>

                            <td style="min-width:110px">
                            <span v-if="editingCommentId!==entry.id"
                                  @dblclick="startEditComment(entry)"
                                  class="comment-inline" :class="{'has-value':entry.comment}"
                                  :title="entry.comment?'Двойной клик — редактировать':''">
                                {{ entry.comment||'—' }}
                            </span>
                                <div v-else style="display:flex;gap:3px;min-width:140px">
                                    <input type="text"
                                           style="flex:1;font-size:11px;padding:3px 7px;border:1.5px solid #6366f1;
                                              border-radius:6px;font-family:monospace;outline:none;
                                              box-shadow:0 0 0 3px rgba(99,102,241,.1)"
                                           v-model="editingCommentValue" maxlength="40"
                                           @keyup.enter="saveComment(entry)"
                                           @keyup.esc="cancelEditComment">
                                    <button class="row-btn edit" @click="saveComment(entry)" title="Сохранить">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="row-btn delete" @click="cancelEditComment" title="Отмена">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </td>

                            <td>
                            <span :class="entry.match_status==='M'?'status-badge status-matched':
                                          entry.match_status==='I'?'status-badge status-ignored':
                                          'status-badge status-waiting'">
                                {{ entry.match_status==='M'?'Сквит.':entry.match_status==='I'?'Игнор':'Ожидает' }}
                            </span>
                            </td>

                            <td style="text-align:right;padding-right:14px">
                                <div style="display:flex;gap:3px;justify-content:flex-end">
                                    <button class="row-btn edit" @click="editEntry(entry)" title="Редактировать">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="row-btn unlink" v-if="entry.match_status==='M'"
                                            @click="unmatchEntry(entry.match_id)" title="Расквитовать">
                                        <i class="fas fa-unlink"></i>
                                    </button>
                                    <button class="row-btn delete" @click="deleteEntry(entry)" title="Удалить">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="entriesLoadingMore">
                            <td colspan="17" style="text-align:center;padding:16px">
                                <div class="spinner-border spinner-border-sm"
                                     style="color:#6366f1;width:18px;height:18px;border-width:2px"></div>
                                <span style="margin-left:8px;font-size:12px;color:#9ca3af">Загрузка...</span>
                            </td>
                        </tr>
                        <tr v-if="!hasMoreEntries&&entries.length>0&&!entriesLoading">
                            <td colspan="17" style="text-align:center;padding:12px;font-size:11px;
                                               color:#c4c9d6;border-top:1px solid #f4f5f8">
                                <i class="fas fa-check-circle me-1"></i>
                                Все {{ entriesTotal.toLocaleString() }} записей загружены
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div><!-- /v-show entries -->


    <!-- ══ СЕКЦИЯ: БАЛАНС ════════════════════════════════════════ -->
    <div v-show="activeSection==='balance'">

        <!-- ТУЛБАР -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:8px">
                <span class="pool-title">Баланс Ностро</span>
                <span v-if="userSection"
                      style="background:#4f46e5;color:#fff;border-radius:6px;padding:1px 10px;font-size:11px;font-weight:700">
                    {{ userSection }}
                </span>
                <span v-if="balancesTotal>0" style="font-size:11px;color:#9ca3af">
                    {{ balancesTotal.toLocaleString() }} записей
                </span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <button class="toolbar-btn outline" @click="openImportModal('bnd')">
                    <i class="fas fa-file-code"></i>Импорт БНД
                </button>
                <button class="toolbar-btn outline" @click="openImportModal('asb')">
                    <i class="fas fa-file-alt"></i>Импорт АСБ
                </button>
                <button class="toolbar-btn outline" @click="balanceFiltersOpen=!balanceFiltersOpen"
                        :style="balanceFiltersOpen?'border-color:#6366f1;color:#6366f1':''">
                    <i class="fas fa-filter"></i>Фильтры
                </button>
                <button class="toolbar-btn success" @click="openCreateBalanceModal">
                    <i class="fas fa-plus"></i>Добавить
                </button>
            </div>
        </div>

        <!-- ФИЛЬТРЫ -->
        <div v-show="balanceFiltersOpen" class="filters-panel" style="margin-bottom:14px">
            <div style="display:flex;flex-wrap:wrap;gap:10px 16px;align-items:flex-end">
                <div class="filter-field">
                    <label class="filter-label">Тип</label>
                    <div class="filter-toggle-group">
                        <button class="ftg-btn" :class="{active:!balanceFilters.ls_type}"
                                @click="balanceFilters.ls_type='';onBalanceFilterChange()">Все</button>
                        <button class="ftg-btn" :class="{active:balanceFilters.ls_type==='L'}"
                                @click="balanceFilters.ls_type='L';onBalanceFilterChange()">L</button>
                        <button class="ftg-btn" :class="{active:balanceFilters.ls_type==='S'}"
                                @click="balanceFilters.ls_type='S';onBalanceFilterChange()">S</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Раздел</label>
                    <template v-if="userSection">
                        <span style="background:#4f46e5;color:#fff;border-radius:6px;padding:3px 14px;font-size:12px;font-weight:700;display:inline-block">{{ userSection }}</span>
                    </template>
                    <template v-else>
                        <div class="filter-toggle-group">
                            <button class="ftg-btn" :class="{active:!balanceFilters.section}"
                                    @click="balanceFilters.section='';onBalanceFilterChange()">Все</button>
                            <button class="ftg-btn" :class="{active:balanceFilters.section==='NRE'}"
                                    @click="balanceFilters.section='NRE';onBalanceFilterChange()">NRE</button>
                            <button class="ftg-btn" :class="{active:balanceFilters.section==='INV'}"
                                    @click="balanceFilters.section='INV';onBalanceFilterChange()">INV</button>
                        </div>
                    </template>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Статус</label>
                    <div class="filter-toggle-group">
                        <button class="ftg-btn" :class="{active:!balanceFilters.status}"
                                @click="balanceFilters.status='';onBalanceFilterChange()">Все</button>
                        <button class="ftg-btn" :class="{active:balanceFilters.status==='normal'}"
                                @click="balanceFilters.status='normal';onBalanceFilterChange()">⚪</button>
                        <button class="ftg-btn" :class="{active:balanceFilters.status==='error'}"
                                @click="balanceFilters.status='error';onBalanceFilterChange()">🔴</button>
                        <button class="ftg-btn" :class="{active:balanceFilters.status==='confirmed'}"
                                @click="balanceFilters.status='confirmed';onBalanceFilterChange()">⚫</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Валюта</label>
                    <select class="filter-input" v-model="balanceFilters.currency"
                            @change="onBalanceFilterChange()" style="width:90px">
                        <option value="">—</option>
                        <option>RUB</option><option>USD</option><option>EUR</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Дата с</label>
                    <input type="date" class="filter-input" v-model="balanceFilters.value_date_from"
                           @change="onBalanceFilterChange()" style="width:140px">
                </div>
                <div class="filter-field">
                    <label class="filter-label">по</label>
                    <input type="date" class="filter-input" v-model="balanceFilters.value_date_to"
                           @change="onBalanceFilterChange()" style="width:140px">
                </div>
                <div class="filter-field">
                    <label class="filter-label">Источник</label>
                    <select class="filter-input" v-model="balanceFilters.source"
                            @change="onBalanceFilterChange()" style="width:120px">
                        <option value="">Все</option>
                        <option value="BND">БНД</option><option value="ASB">АСБ</option>
                        <option value="MT950">MT950</option><option value="CAMT">camt</option>
                        <option value="ED211">ED211</option><option value="FCC12">FCC12</option>
                        <option value="BARS_GL">BARS GL</option><option value="MANUAL">Ручной</option>
                    </select>
                </div>
                <div class="filter-field" style="align-self:end">
                    <button class="toolbar-btn outline" @click="balanceFilters={};if(userSection){balanceFilters.section=userSection;}onBalanceFilterChange()">
                        <i class="fas fa-times"></i>Сброс
                    </button>
                </div>
            </div>
        </div>

        <!-- ТАБЛИЦА БАЛАНСА -->
        <div class="table-card">
            <div v-if="balancesLoading" style="display:flex;justify-content:center;align-items:center;height:200px">
                <div class="spinner-border" style="color:#6366f1"></div>
            </div>
            <div v-else-if="!balances.length" class="empty-pool" style="padding:60px">
                <i class="fas fa-inbox"></i>
                <p>Нет записей баланса</p>
                <button class="toolbar-btn success" @click="openCreateBalanceModal" style="margin-top:12px">
                    <i class="fas fa-plus"></i>Добавить первую
                </button>
            </div>
            <div v-else class="table-scroll-wrap" @scroll="onBalanceScroll">
                <table class="entries-table">
                    <thead>
                    <tr>
                        <th style="width:50px">ID</th>
                        <th class="th-sort" @click="sortBalance('ls_type')">L/S</th>
                        <th>Раздел</th>
                        <th class="th-sort" @click="sortBalance('account_id')">Счёт</th>
                        <th class="th-sort" @click="sortBalance('currency')">Валюта</th>
                        <th class="th-sort" @click="sortBalance('value_date')">Дата вал.</th>
                        <th>№ выписки</th>
                        <th style="text-align:right">Opening</th>
                        <th style="width:36px;text-align:center">D/C</th>
                        <th style="text-align:right">Closing</th>
                        <th style="width:36px;text-align:center">D/C</th>
                        <th>Источник</th>
                        <th style="width:36px;text-align:center">Ст.</th>
                        <th>Комментарий</th>
                        <th style="width:96px;text-align:right;padding-right:12px"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="row in balances" :key="row.id"
                        :style="row.status==='error'?'background:#fff5f5':row.status==='confirmed'?'background:#f8f9fa':''">
                        <td style="color:#9ca3af;font-size:11px">{{ row.id }}</td>
                        <td>
                            <span :style="row.ls_type==='L'
                                ?'background:#dbeafe;color:#1e40af;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'
                                :'background:#fef3c7;color:#92400e;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'">
                                {{ row.ls_type }}
                            </span>
                        </td>
                        <td><span style="background:#1e2532;color:#fff;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700">{{ row.section }}</span></td>
                        <td class="td-mono-truncate" :title="row.account_name" style="max-width:120px">{{ row.account_name }}</td>
                        <td style="font-weight:600;font-size:12px">{{ row.currency }}</td>
                        <td style="white-space:nowrap;font-size:12px">{{ row.value_date_fmt || row.value_date }}</td>
                        <td class="td-mono-truncate" :title="row.statement_number" style="max-width:100px">{{ row.statement_number||'—' }}</td>
                        <td style="text-align:right;font-family:monospace;font-size:12px">{{ formatBalanceAmount(row.opening_balance) }}</td>
                        <td style="text-align:center">
                            <span :style="row.opening_dc==='D'?'color:#ef4444;font-weight:700;font-size:11px':'color:#059669;font-weight:700;font-size:11px'">{{ row.opening_dc }}</span>
                        </td>
                        <td style="text-align:right;font-family:monospace;font-size:12px">{{ formatBalanceAmount(row.closing_balance) }}</td>
                        <td style="text-align:center">
                            <span :style="row.closing_dc==='D'?'color:#ef4444;font-weight:700;font-size:11px':'color:#059669;font-weight:700;font-size:11px'">{{ row.closing_dc }}</span>
                        </td>
                        <td style="font-size:11px;color:#6b7280">{{ row.source }}</td>
                        <td style="text-align:center" :title="row.comment">
                            {{ row.status==='error'?'🔴':row.status==='confirmed'?'⚫':'⚪' }}
                        </td>
                        <td class="td-mono-truncate" :title="row.comment" style="max-width:160px;font-size:11px;color:#6b7280">{{ row.comment||'' }}</td>
                        <td style="text-align:right;padding-right:12px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button v-if="row.status==='error'" class="row-btn"
                                        style="color:#d97706;background:#fffbeb;border-color:#fde68a"
                                        title="Подтвердить" @click="openConfirmModal(row)">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button v-if="row.status==='confirmed'" class="row-btn"
                                        style="color:#374151;background:#f3f4f6;border-color:#e5e7eb"
                                        title="История" @click="openHistoryModal(row)">
                                    <i class="fas fa-history"></i>
                                </button>
                                <button class="row-btn edit" title="Редактировать" @click="openEditBalanceModal(row)">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button class="row-btn delete" title="Удалить" @click="deleteBalance(row)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="balancesLoadingMore">
                        <td colspan="15" style="text-align:center;padding:16px">
                            <div class="spinner-border spinner-border-sm" style="color:#6366f1;width:18px;height:18px;border-width:2px"></div>
                        </td>
                    </tr>
                    <tr v-if="!hasMoreBalances&&balances.length>0&&!balancesLoading">
                        <td colspan="15" style="text-align:center;padding:12px;font-size:11px;color:#c4c9d6;border-top:1px solid #f4f5f8">
                            <i class="fas fa-check-circle me-1"></i>Все {{ balancesTotal.toLocaleString() }} записей загружены
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- МОДАЛ: Создать/Редактировать -->
        <div v-if="balanceModalOpen" class="modal-backdrop-custom" @click.self="closeBalanceModal">
            <div class="modal-card" style="max-width:600px">
                <div class="modal-card-header">
                    <span>{{ editingBalance.id ? 'Редактировать баланс' : 'Новая запись баланса' }}</span>
                    <button class="btn-close" @click="closeBalanceModal"></button>
                </div>
                <div class="modal-card-body">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div>
                            <label class="filter-label">Тип *</label>
                            <select class="filter-input" v-model="editingBalance.ls_type" style="width:100%">
                                <option value="L">L — Ledger</option>
                                <option value="S">S — Statement</option>
                            </select>
                        </div>
                        <div>
                            <label class="filter-label">Раздел *</label>
                            <div v-if="userSection" style="display:flex;align-items:center;height:34px">
                                <span style="background:#4f46e5;color:#fff;border-radius:6px;padding:3px 14px;font-size:12px;font-weight:700">{{ userSection }}</span>
                            </div>
                            <select v-else class="filter-input" v-model="editingBalance.section" style="width:100%">
                                <option value="NRE">NRE</option>
                                <option value="INV">INV</option>
                            </select>
                        </div>
                        <div style="grid-column:span 2">
                            <label class="filter-label">Счёт *</label>
                            <select class="filter-input" v-model.number="editingBalance.account_id" style="width:100%">
                                <option :value="null">— выберите —</option>
                                <option v-for="a in balanceAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="filter-label">Валюта *</label>
                            <select class="filter-input" v-model="editingBalance.currency" style="width:100%">
                                <option>RUB</option><option>USD</option><option>EUR</option><option>RUR</option>
                            </select>
                        </div>
                        <div>
                            <label class="filter-label">Дата вал. *</label>
                            <input type="date" class="filter-input" v-model="editingBalance.value_date" style="width:100%">
                        </div>
                        <div v-if="editingBalance.ls_type==='S'" style="grid-column:span 2">
                            <label class="filter-label">№ выписки *</label>
                            <input type="text" class="filter-input" v-model="editingBalance.statement_number" style="width:100%">
                        </div>
                        <div>
                            <label class="filter-label">Opening Balance *</label>
                            <input type="text" class="filter-input" v-model="editingBalance.opening_balance"
                                   placeholder="0.00" style="width:100%;font-family:monospace">
                        </div>
                        <div>
                            <label class="filter-label">D/C Opening</label>
                            <div class="filter-toggle-group">
                                <button class="ftg-btn" :class="{active:editingBalance.opening_dc==='D'}"
                                        @click="editingBalance.opening_dc='D'">D</button>
                                <button class="ftg-btn" :class="{active:editingBalance.opening_dc==='C'}"
                                        @click="editingBalance.opening_dc='C'">C</button>
                            </div>
                        </div>
                        <div>
                            <label class="filter-label">Closing Balance *</label>
                            <input type="text" class="filter-input" v-model="editingBalance.closing_balance"
                                   placeholder="0.00" style="width:100%;font-family:monospace">
                        </div>
                        <div>
                            <label class="filter-label">D/C Closing</label>
                            <div class="filter-toggle-group">
                                <button class="ftg-btn" :class="{active:editingBalance.closing_dc==='D'}"
                                        @click="editingBalance.closing_dc='D'">D</button>
                                <button class="ftg-btn" :class="{active:editingBalance.closing_dc==='C'}"
                                        @click="editingBalance.closing_dc='C'">C</button>
                            </div>
                        </div>
                        <div>
                            <label class="filter-label">Источник</label>
                            <select class="filter-input" v-model="editingBalance.source" style="width:100%">
                                <option value="MANUAL">Ручной</option>
                                <option value="BND">БНД</option><option value="ASB">АСБ</option>
                                <option value="MT950">MT950</option><option value="CAMT">camt</option>
                                <option value="FCC12">FCC12</option><option value="BARS_GL">BARS GL</option>
                            </select>
                        </div>
                        <div>
                            <label class="filter-label">Комментарий</label>
                            <input type="text" class="filter-input" v-model="editingBalance.comment" style="width:100%">
                        </div>
                    </div>
                </div>
                <div class="modal-card-footer" style="display:flex;justify-content:flex-end;gap:8px">
                    <button class="toolbar-btn outline" @click="closeBalanceModal">Отмена</button>
                    <button class="toolbar-btn primary" :disabled="balanceSaving" @click="saveBalance">
                        <i class="fas fa-save"></i>{{ balanceSaving?'Сохранение...':'Сохранить' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- МОДАЛ: Подтвердить ошибку -->
        <div v-if="confirmModalOpen" class="modal-backdrop-custom" @click.self="closeConfirmModal">
            <div class="modal-card" style="max-width:460px">
                <div class="modal-card-header">
                    <span>🔴 Подтверждение корректировки</span>
                    <button class="btn-close" @click="closeConfirmModal"></button>
                </div>
                <div class="modal-card-body">
                    <div v-if="confirmingBalance" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:12px;font-size:12px">
                        <strong>{{ confirmingBalance.account_name }}</strong>
                        &nbsp;·&nbsp;{{ confirmingBalance.currency }}
                        &nbsp;·&nbsp;{{ confirmingBalance.value_date_fmt||confirmingBalance.value_date }}
                        <div style="color:#ef4444;margin-top:4px">{{ confirmingBalance.comment }}</div>
                    </div>
                    <label class="filter-label">Причина корректировки *</label>
                    <textarea class="filter-input" rows="3" v-model="confirmReason"
                              placeholder="Введите причину..."
                              style="width:100%;resize:vertical"></textarea>
                </div>
                <div class="modal-card-footer" style="display:flex;justify-content:flex-end;gap:8px">
                    <button class="toolbar-btn outline" @click="closeConfirmModal">Отмена</button>
                    <button class="toolbar-btn primary" :disabled="confirmSaving" @click="submitConfirm">
                        <i class="fas fa-check"></i>{{ confirmSaving?'Сохранение...':'Подтвердить' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- МОДАЛ: История -->
        <div v-if="historyModalOpen" class="modal-backdrop-custom" @click.self="closeHistoryModal">
            <div class="modal-card" style="max-width:680px">
                <div class="modal-card-header">
                    <span>⚫ История изменений</span>
                    <button class="btn-close" @click="closeHistoryModal"></button>
                </div>
                <div class="modal-card-body">
                    <div v-if="historyLoading" style="text-align:center;padding:30px">
                        <div class="spinner-border spinner-border-sm" style="color:#6366f1"></div>
                    </div>
                    <div v-else-if="!historyLogs.length" style="text-align:center;padding:30px;color:#9ca3af;font-size:13px">Нет записей</div>
                    <table v-else class="entries-table" style="font-size:12px">
                        <thead><tr><th>Дата</th><th>Действие</th><th>Пользователь</th><th>Причина</th></tr></thead>
                        <tbody>
                        <tr v-for="log in historyLogs" :key="log.id">
                            <td style="white-space:nowrap">{{ log.created_at }}</td>
                            <td>{{ log.action }}</td>
                            <td>{{ log.user_id }}</td>
                            <td>{{ log.reason||'—' }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-card-footer" style="display:flex;justify-content:flex-end">
                    <button class="toolbar-btn outline" @click="closeHistoryModal">Закрыть</button>
                </div>
            </div>
        </div>

        <!-- МОДАЛ: Импорт -->
        <div v-if="importModalOpen" class="modal-backdrop-custom" @click.self="closeImportModal">
            <div class="modal-card" style="max-width:460px">
                <div class="modal-card-header">
                    <span><i class="fas fa-upload me-2"></i>Импорт {{ importType==='bnd'?'Банк-клиент БНД (XML)':'Банк-клиент АСБ (TXT)' }}</span>
                    <button class="btn-close" @click="closeImportModal"></button>
                </div>
                <div class="modal-card-body">
                    <div style="margin-bottom:12px">
                        <label class="filter-label">Счёт *</label>
                        <select class="filter-input" v-model.number="importAccountId" style="width:100%">
                            <option :value="null">— выберите счёт —</option>
                            <option v-for="a in balanceAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                    <div style="margin-bottom:12px">
                        <label class="filter-label">Раздел</label>
                        <div v-if="userSection" style="display:flex;align-items:center;gap:8px">
                            <span style="background:#4f46e5;color:#fff;border-radius:6px;padding:3px 14px;font-size:12px;font-weight:700">{{ userSection }}</span>
                            <span style="font-size:11px;color:#9ca3af">определяется компанией</span>
                        </div>
                        <div v-else class="filter-toggle-group" style="width:120px">
                            <button class="ftg-btn" :class="{active:importSection==='NRE'}" @click="importSection='NRE'">NRE</button>
                            <button class="ftg-btn" :class="{active:importSection==='INV'}" @click="importSection='INV'">INV</button>
                        </div>
                    </div>
                    <div style="margin-bottom:12px">
                        <label class="filter-label">Файл * <span style="color:#9ca3af;font-weight:400">{{ importType==='bnd'?'(.xml)':'(.txt, .csv)' }}</span></label>
                        <input type="file" class="filter-input"
                               :accept="importType==='bnd'?'.xml':'.txt,.csv'"
                               @change="onImportFileChange" style="width:100%;padding:5px">
                    </div>
                    <div v-if="importResult" style="margin-top:10px">
                        <div :style="'padding:8px 12px;border-radius:7px;font-size:12px;'+(importResult.success?'background:#f0fdf4;border:1px solid #bbf7d0;color:#065f46':'background:#fff5f5;border:1px solid #fecaca;color:#991b1b')">
                            {{ importResult.message }}
                        </div>
                        <div v-if="importResult.parse_errors&&importResult.parse_errors.length"
                             style="margin-top:8px;padding:8px 12px;border-radius:7px;background:#fffbeb;border:1px solid #fde68a;font-size:11px">
                            <strong>Ошибки парсинга:</strong>
                            <div v-for="e in importResult.parse_errors" :key="e" style="margin-top:2px">· {{ e }}</div>
                        </div>
                    </div>
                </div>
                <div class="modal-card-footer" style="display:flex;justify-content:flex-end;gap:8px">
                    <button class="toolbar-btn outline" @click="closeImportModal">Закрыть</button>
                    <button class="toolbar-btn primary" :disabled="importLoading" @click="submitImport">
                        <i class="fas fa-upload"></i>{{ importLoading?'Загрузка...':'Загрузить' }}
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /v-show balance -->

</div>

<!-- Модал: Список правил -->
<div class="modal fade" id="rulesListModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-sliders-h"></i></span>
                    Правила автоквитования
                </h5>
                <button type="button" class="btn-close" @click="_hideModal('rulesListModal')"></button>
            </div>
            <div class="modal-body" style="padding:0 !important">
                <div style="padding:12px 16px;border-bottom:1px solid #f1f3f7">
                    <button class="toolbar-btn success" @click="showAddRuleModal">
                        <i class="fas fa-plus"></i>Добавить правило
                    </button>
                </div>
                <div v-if="loadingRules" style="text-align:center;padding:40px">
                    <div class="spinner-border" style="color:#6366f1"></div>
                </div>
                <div v-else-if="!matchingRules||matchingRules.length===0" class="empty-pool" style="padding:48px">
                    <i class="fas fa-inbox"></i><p>Нет правил. Создайте первое.</p>
                </div>
                <table v-else class="entries-table">
                    <thead><tr>
                        <th style="padding-left:16px">Название</th>
                        <th>Раздел</th><th>Тип пары</th><th>Условия</th>
                        <th style="width:55px">Приор.</th>
                        <th style="width:80px">Статус</th>
                        <th style="width:72px;text-align:right;padding-right:16px"></th>
                    </tr></thead>
                    <tbody>
                    <tr v-for="rule in matchingRules" :key="rule.id">
                        <td style="padding-left:16px">
                            <span style="font-weight:600">{{ rule.name }}</span>
                            <div v-if="rule.description" style="font-size:11px;color:#9ca3af">{{ rule.description }}</div>
                        </td>
                        <td><span style="background:#1e2532;color:#fff;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700">{{ rule.section }}</span></td>
                        <td style="font-size:12px">{{ rule.pair_type_label }}</td>
                        <td style="font-size:11px;color:#6b7280">{{ rule.conditions_summary }}</td>
                        <td style="text-align:center;font-size:12px">{{ rule.priority }}</td>
                        <td><span :class="rule.is_active?'status-badge status-matched':'status-badge status-ignored'">{{ rule.is_active?'Активно':'Откл.' }}</span></td>
                        <td style="text-align:right;padding-right:16px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button class="row-btn edit" @click="editRule(rule)"><i class="fas fa-pen"></i></button>
                                <button class="row-btn delete" @click="deleteRule(rule)"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="_hideModal('rulesListModal')">
                    <i class="fas fa-times"></i>Закрыть
                </button>
            </div>
        </div>
    </div>
</div>