<?php /** @var yii\web\View $this */ ?>

<div>
    <!-- Пул не выбран -->
    <div v-if="!selectedPool" class="empty-pool">
        <i class="fas fa-hand-point-left"></i>
        <p>Выберите пул в панели слева</p>
    </div>

    <div v-else>

        <!-- ── Тулбар ──────────────────────────────────────── -->
        <div style="display:flex; align-items:center; justify-content:space-between;
                    flex-wrap:wrap; gap:12px; margin-bottom:20px">
            <div style="display:flex; align-items:center; gap:10px">
                <span class="pool-title">{{ selectedPool.name }}</span>
                <span class="pool-title pool-tag">{{ selectedGroup ? selectedGroup.name : '' }}</span>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap">
                <button class="toolbar-btn outline"
                        @click="loadMatchingRules(); _showModal('rulesListModal')">
                    <i class="fas fa-sliders-h"></i>Правила
                </button>
                <button class="toolbar-btn primary"
                        :disabled="autoMatchRunning"
                        @click="runAutoMatch(null)">
                    <i :class="autoMatchRunning ? 'fas fa-spinner fa-spin' : 'fas fa-magic'"></i>
                    {{ autoMatchRunning ? 'Выполняется...' : 'Автоквитование' }}
                </button>
                <button class="toolbar-btn success"
                        :disabled="!hasSelection"
                        @click="matchSelected">
                    <i class="fas fa-link"></i>
                    Сквитовать{{ selectedIds.length > 0 ? ' (' + selectedIds.length + ')' : '' }}
                </button>
                <button class="toolbar-btn danger-soft"
                        v-if="selectedIds.length > 0"
                        @click="clearSelection">
                    <i class="fas fa-times"></i>Сбросить
                </button>
            </div>
        </div>

        <!-- ── Панель итогов ───────────────────────────────── -->
        <div v-if="selectionSummary"
             class="summary-bar"
             :class="summaryBalanced ? 'balanced' : 'unbalanced'">
            <span style="font-weight:700; color:#374151">
                Выбрано: {{ selectedIds.length }}
            </span>
            <span style="color:#d1d5db">|</span>
            <span>
                <span style="display:inline-flex;align-items:center;gap:4px;color:#6366f1;font-weight:600">
                    <i class="fas fa-database" style="font-size:11px"></i>
                    Ledger ({{ selectionSummary.cnt_ledger }}):
                </span>
                <strong style="font-family:monospace;margin-left:3px">
                    {{ formatAmount(selectionSummary.sum_ledger) }}
                </strong>
            </span>
            <span>
                <span style="display:inline-flex;align-items:center;gap:4px;color:#0284c7;font-weight:600">
                    <i class="fas fa-file-alt" style="font-size:11px"></i>
                    Statement ({{ selectionSummary.cnt_statement }}):
                </span>
                <strong style="font-family:monospace;margin-left:3px">
                    {{ formatAmount(selectionSummary.sum_statement) }}
                </strong>
            </span>
            <span :style="summaryBalanced
                ? 'color:#059669;font-weight:700'
                : 'color:#d97706;font-weight:700'">
                <i :class="summaryBalanced ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'"></i>
                Разница: <span style="font-family:monospace">{{ formatAmount(selectionSummary.diff) }}</span>
                <span v-if="summaryBalanced" style="font-weight:500;margin-left:4px">— готово к квитованию</span>
            </span>
        </div>

        <!-- Загрузка -->
        <div v-if="loadingAccounts" style="display:flex;justify-content:center;padding:60px 0">
            <div class="spinner-border" style="color:#6366f1"></div>
        </div>

        <div v-else-if="accounts.length === 0" class="empty-pool">
            <i class="fas fa-inbox"></i>
            <p>Нет Ностро банков в этом пуле</p>
        </div>

        <!-- ── Карточки счетов ─────────────────────────────── -->
        <div v-for="account in accounts" :key="account.id" class="account-card">

            <!-- Заголовок -->
            <div class="account-card-header">
                <div class="acc-meta">
                    <i class="fas fa-university" style="color:#6366f1;font-size:14px;margin-right:8px"></i>
                    <span class="acc-name">{{ account.name }}</span>
                    <span v-if="account.currency" class="badge-currency">{{ account.currency }}</span>
                    <span v-if="account.is_suspense" class="badge-suspense">
                        <i class="fas fa-exclamation-circle" style="font-size:9px"></i> Suspense
                    </span>
                    <span class="acc-count">{{ account.entries ? account.entries.length : 0 }} записей</span>
                </div>
                <div style="display:flex;gap:4px">
                    <button class="card-hdr-btn green"
                            @click="selectAllInAccount(account)"
                            title="Выбрать все незаквитованные">
                        <i class="fas fa-check-square"></i>
                    </button>
                    <button class="card-hdr-btn blue"
                            @click="runAutoMatch(account.id)"
                            title="Автоквитование по счёту">
                        <i class="fas fa-magic"></i>
                    </button>
                    <button class="card-hdr-btn green"
                            @click="showAddEntryModal(account)"
                            title="Добавить запись">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>

            <!-- Таблица -->
            <div style="overflow-x:auto" v-if="account.entries && account.entries.length > 0">
                <table class="entries-table">
                    <thead>
                    <tr>
                        <th style="width:32px;padding-left:16px"></th>
                        <th>Match ID</th>
                        <th>L/S</th>
                        <th>D/C</th>
                        <th style="text-align:right">Сумма</th>
                        <th>Вал.</th>
                        <th>Value Date</th>
                        <th>Post Date</th>
                        <th>Instr.ID</th>
                        <th>E2E ID</th>
                        <th>Txn ID</th>
                        <th>Msg ID</th>
                        <th>Комментарий</th>
                        <th>Статус</th>
                        <th style="width:80px; text-align:right; padding-right:16px">
                            <i class="fas fa-cog" style="opacity:.4"></i>
                        </th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="entry in account.entries" :key="entry.id"
                        :class="{
                            'entry-selected': isSelected(entry.id),
                            'entry-matched':  !isSelected(entry.id) && entry.match_status === 'M'
                        }">

                        <!-- Чекбокс -->
                        <td style="padding-left:16px">
                            <input type="checkbox"
                                   style="width:14px;height:14px;cursor:pointer;accent-color:#6366f1"
                                   v-if="entry.match_status === 'U'"
                                   :checked="isSelected(entry.id)"
                                   @change="toggleEntrySelection(entry.id)">
                            <i v-else class="fas fa-lock"
                               style="font-size:10px;color:#d1d5db" title="Сквитовано"></i>
                        </td>

                        <!-- Match ID -->
                        <td>
                            <span v-if="entry.match_id"
                                  class="match-id-badge"
                                  @click="unmatchEntry(entry.match_id)"
                                  title="Нажмите для расквитования">
                                <i class="fas fa-link" style="font-size:9px"></i>
                                {{ entry.match_id }}
                            </span>
                            <span v-else style="color:#d1d5db;font-size:11px">—</span>
                        </td>

                        <!-- L/S -->
                        <td>
                            <span :class="entry.ls === 'L' ? 'badge-ls-l' : 'badge-ls-s'">
                                {{ entry.ls }}
                            </span>
                        </td>

                        <!-- D/C -->
                        <td>
                            <span :class="entry.dc === 'Debit' ? 'badge-debit' : 'badge-credit'">
                                {{ entry.dc === 'Debit' ? 'D' : 'C' }}
                            </span>
                        </td>

                        <td style="text-align:right;font-family:monospace;font-weight:600;color:#1a202c">
                            {{ entry.amount }}
                        </td>

                        <td>
                            <span style="font-size:11px;color:#6b7280;font-weight:600">{{ entry.currency }}</span>
                        </td>

                        <td style="white-space:nowrap;font-size:12px">{{ entry.value_date || '—' }}</td>
                        <td style="white-space:nowrap;font-size:12px">{{ entry.post_date  || '—' }}</td>

                        <!-- ID поля -->
                        <td style="max-width:80px;overflow:hidden;text-overflow:ellipsis;
                                   white-space:nowrap;font-family:monospace;font-size:11px;color:#6b7280"
                            :title="entry.instruction_id">{{ entry.instruction_id || '—' }}</td>
                        <td style="max-width:80px;overflow:hidden;text-overflow:ellipsis;
                                   white-space:nowrap;font-family:monospace;font-size:11px;color:#6b7280"
                            :title="entry.end_to_end_id">{{ entry.end_to_end_id || '—' }}</td>
                        <td style="max-width:80px;overflow:hidden;text-overflow:ellipsis;
                                   white-space:nowrap;font-family:monospace;font-size:11px;color:#6b7280"
                            :title="entry.transaction_id">{{ entry.transaction_id || '—' }}</td>
                        <td style="max-width:80px;overflow:hidden;text-overflow:ellipsis;
                                   white-space:nowrap;font-family:monospace;font-size:11px;color:#6b7280"
                            :title="entry.message_id">{{ entry.message_id || '—' }}</td>

                        <!-- Комментарий -->
                        <td style="min-width:100px">
                            <span v-if="editingCommentId !== entry.id"
                                  @dblclick="startEditComment(entry)"
                                  class="comment-inline"
                                  :class="{ 'has-value': entry.comment }"
                                  :title="entry.comment ? 'Двойной клик — редактировать' : 'Нет комментария'">
                                {{ entry.comment || '—' }}
                            </span>
                            <div v-else style="display:flex;gap:3px;min-width:140px">
                                <input type="text"
                                       style="flex:1;font-size:11px;padding:3px 6px;border:1px solid #c7d2fe;
                                              border-radius:5px;font-family:monospace;outline:none;
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

                        <!-- Статус -->
                        <td>
                            <span :class="entry.match_status === 'M' ? 'status-badge status-matched' :
                                          entry.match_status === 'I' ? 'status-badge status-ignored' :
                                          'status-badge status-waiting'">
                                {{ entry.match_status === 'M' ? 'Сквит.' :
                                   entry.match_status === 'I' ? 'Игнор'  : 'Ожидает' }}
                            </span>
                        </td>

                        <!-- Кнопки -->
                        <td style="text-align:right;padding-right:16px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button class="row-btn edit"
                                        @click="editEntry(entry, account)"
                                        title="Редактировать">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button class="row-btn unlink"
                                        v-if="entry.match_status === 'M'"
                                        @click="unmatchEntry(entry.match_id)"
                                        title="Расквитовать">
                                    <i class="fas fa-unlink"></i>
                                </button>
                                <button class="row-btn delete"
                                        @click="deleteEntry(entry)"
                                        title="Удалить">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- Нет записей -->
            <div v-else class="empty-row">
                <i class="fas fa-inbox" style="font-size:14px;opacity:.3"></i>
                Нет записей
                <button @click="showAddEntryModal(account)"
                        style="background:none;border:none;color:#6366f1;font-weight:600;
                               font-size:12.5px;cursor:pointer;padding:0;margin-left:4px">
                    <i class="fas fa-plus" style="font-size:10px;margin-right:2px"></i>Добавить первую
                </button>
            </div>
        </div>
        <!-- /v-for -->

    </div>
</div>

<!-- ── Список правил (модал) ───────────────────────── -->
<div class="modal fade" id="rulesListModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius:14px;border:none;
                    box-shadow:0 20px 60px rgba(0,0,0,.15)">
            <div class="modal-header" style="border-bottom:1px solid #f1f3f7;padding:16px 20px">
                <h5 class="modal-title" style="font-weight:700;font-size:15px">
                    <i class="fas fa-sliders-h me-2" style="color:#6366f1"></i>Правила автоквитования
                </h5>
                <button type="button" class="btn-close" @click="_hideModal('rulesListModal')"></button>
            </div>
            <div class="modal-body" style="padding:0">
                <div style="padding:12px 16px;border-bottom:1px solid #f1f3f7">
                    <button class="toolbar-btn success" @click="showAddRuleModal">
                        <i class="fas fa-plus"></i>Добавить правило
                    </button>
                </div>
                <div v-if="loadingRules" style="text-align:center;padding:40px">
                    <div class="spinner-border" style="color:#6366f1"></div>
                </div>
                <div v-else-if="matchingRules.length === 0" class="empty-pool" style="padding:50px">
                    <i class="fas fa-inbox"></i>
                    <p>Нет правил. Создайте первое.</p>
                </div>
                <table v-else class="entries-table">
                    <thead>
                    <tr>
                        <th style="padding-left:16px">Название</th>
                        <th>Раздел</th>
                        <th>Тип пары</th>
                        <th>Условия</th>
                        <th style="width:55px">Приор.</th>
                        <th style="width:80px">Статус</th>
                        <th style="width:72px;text-align:right;padding-right:16px"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="rule in matchingRules" :key="rule.id">
                        <td style="padding-left:16px">
                            <span style="font-weight:600">{{ rule.name }}</span>
                            <div v-if="rule.description"
                                 style="font-size:11px;color:#9ca3af">{{ rule.description }}</div>
                        </td>
                        <td>
                            <span style="background:#1e2532;color:#fff;border-radius:6px;
                                         padding:2px 8px;font-size:11px;font-weight:700">
                                {{ rule.section }}
                            </span>
                        </td>
                        <td style="font-size:12px">{{ rule.pair_type_label }}</td>
                        <td style="font-size:11px;color:#6b7280">{{ rule.conditions_summary }}</td>
                        <td style="text-align:center;font-size:12px">{{ rule.priority }}</td>
                        <td>
                            <span :class="rule.is_active
                                ? 'status-badge status-matched'
                                : 'status-badge status-ignored'">
                                {{ rule.is_active ? 'Активно' : 'Откл.' }}
                            </span>
                        </td>
                        <td style="text-align:right;padding-right:16px">
                            <div style="display:flex;gap:3px;justify-content:flex-end">
                                <button class="row-btn edit" @click="editRule(rule)"
                                        title="Редактировать"><i class="fas fa-pen"></i></button>
                                <button class="row-btn delete" @click="deleteRule(rule)"
                                        title="Удалить"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer" style="border-top:1px solid #f1f3f7;padding:12px 16px">
                <button class="toolbar-btn outline" @click="_hideModal('rulesListModal')">
                    <i class="fas fa-times"></i>Закрыть
                </button>
            </div>
        </div>
    </div>
</div>