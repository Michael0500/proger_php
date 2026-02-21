<?php /** @var yii\web\View $this */ ?>

<div>

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
             :class="summaryBalanced ? 'balanced' : 'unbalanced'">
            <span style="font-weight:700;color:#374151">Выбрано: {{ selectedIds.length }}</span>
            <span style="color:#e5e7eb">|</span>
            <span>
                <i class="fas fa-database" style="font-size:11px;color:#6366f1;margin-right:4px"></i>
                <span style="color:#6366f1;font-weight:600">Ledger ({{ selectionSummary.cnt_ledger }}):</span>
                <strong style="font-family:monospace;margin-left:4px">{{ formatAmount(selectionSummary.sum_ledger) }}</strong>
            </span>
            <span>
                <i class="fas fa-file-alt" style="font-size:11px;color:#0284c7;margin-right:4px"></i>
                <span style="color:#0284c7;font-weight:600">Statement ({{ selectionSummary.cnt_statement }}):</span>
                <strong style="font-family:monospace;margin-left:4px">{{ formatAmount(selectionSummary.sum_statement) }}</strong>
            </span>
            <span :style="summaryBalanced ? 'color:#059669;font-weight:700' : 'color:#d97706;font-weight:700'">
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
                        <td>
                            <span style="background:#1e2532;color:#fff;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700">
                                {{ rule.section }}
                            </span>
                        </td>
                        <td style="font-size:12px">{{ rule.pair_type_label }}</td>
                        <td style="font-size:11px;color:#6b7280">{{ rule.conditions_summary }}</td>
                        <td style="text-align:center;font-size:12px">{{ rule.priority }}</td>
                        <td>
                            <span :class="rule.is_active?'status-badge status-matched':'status-badge status-ignored'">
                                {{ rule.is_active?'Активно':'Откл.' }}
                            </span>
                        </td>
                        <td style="text-align:right;padding-right:16px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button class="row-btn edit" @click="editRule(rule)" title="Редактировать">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button class="row-btn delete" @click="deleteRule(rule)" title="Удалить">
                                    <i class="fas fa-trash"></i>
                                </button>
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