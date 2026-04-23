<?php /** @var yii\web\View $this */ ?>

    <!-- ══ СЕКЦИЯ: БАЛАНС ════════════════════════════════════════ -->
    <div>

        <!-- ТУЛБАР -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:8px">
                <span class="pool-title">Баланс Ностро</span>
                <span v-if="userSection"
                      style="background:#4f46e5;color:#fff;border-radius:6px;padding:1px 10px;font-size:11px;font-weight:700">
                    {{ userSection }}
                </span>
                <span v-if="balancesTotal>0" style="font-size:11px;color:#9ca3af">
                    {{ balancesTotal.toLocaleString() }} {{ recordText(balancesTotal) }}
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
                <button class="toolbar-btn outline" @click="balanceFilters={};if(userSection){balanceFilters.section=userSection;}onBalanceFilterChange()">
                    <i class="fas fa-times"></i>Сбросить
                </button>
                <button class="toolbar-btn success" @click="openCreateBalanceModal">
                    <i class="fas fa-plus"></i>Добавить
                </button>
            </div>
        </div>

        <!-- ФИЛЬТР ПО НОСТРО-БАНКУ (Select2) -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
            <label style="font-size:12px;font-weight:600;color:#6b7280;white-space:nowrap">
                <i class="fas fa-landmark me-1" style="color:#4f46e5"></i>Ностро-банк:
            </label>
            <select id="balancePoolSelect" class="form-select" style="width:300px">
                <option value="">— Все ностро-банки —</option>
            </select>
            <span v-if="_getGroupPoolId() && !balancePoolId"
                  style="font-size:11px;color:#6366f1;background:#ede9fe;padding:2px 10px;border-radius:10px;white-space:nowrap">
                <i class="fas fa-filter me-1"></i>авто из группы
            </span>
        </div>

        <!-- ФИЛЬТРЫ -->
        <div v-show="balanceFiltersOpen" class="filters-panel" style="display:none;margin-bottom:14px">
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
                    <input type="text" v-datepicker class="filter-input" v-model="balanceFilters.value_date_from"
                           @change="onBalanceFilterChange()" style="width:140px">
                </div>
                <div class="filter-field">
                    <label class="filter-label">по</label>
                    <input type="text" v-datepicker class="filter-input" v-model="balanceFilters.value_date_to"
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
                <table class="entries-table" style="width:100%">
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
                                <button class="row-btn history" title="История изменений" @click="openHistoryModal(row)">
                                    <i class="fas fa-history"></i>
                                </button>
                                <button class="row-btn edit" title="Редактировать" @click="openEditBalanceModal(row)">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <div class="row-actions-dropdown">
                                    <button class="row-btn more" @click.stop="toggleRowMenu('balance', row.id, $event)" title="Ещё">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div v-if="openRowMenu==='balance-'+row.id" class="row-actions-menu" :style="rowMenuStyle">
                                        <button class="row-actions-menu-item danger" @click.stop="deleteBalance(row); openRowMenu=null">
                                            <i class="fas fa-trash"></i> Удалить
                                        </button>
                                    </div>
                                </div>
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
                            <i class="fas fa-check-circle me-1"></i>Все {{ balancesTotal.toLocaleString() }} {{ recordText(balancesTotal) }} загружены
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- МОДАЛ: Создать/Редактировать -->
        <div v-show="balanceModalOpen" class="modal-backdrop-custom"  @click.self="closeBalanceModal">
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
                                <option value="" disabled>— выберите —</option>
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
                            <label class="filter-label">Ностро банк</label>
                            <select id="balance-form-pool-select2" style="width:100%"></select>
                        </div>
                        <div style="grid-column:span 2">
                            <label class="filter-label">Счёт *</label>
                            <select id="balance-form-account-select2" style="width:100%"></select>
                        </div>
                        <div>
                            <label class="filter-label">Валюта *</label>
                            <select class="filter-input" v-model="editingBalance.currency" style="width:100%">
                                <option>RUB</option><option>USD</option><option>EUR</option><option>RUR</option>
                            </select>
                        </div>
                        <div>
                            <label class="filter-label">Дата вал. *</label>
                            <input type="text" v-datepicker class="filter-input" v-model="editingBalance.value_date" style="width:100%">
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
        <div v-show="confirmModalOpen" class="modal-backdrop-custom"  @click.self="closeConfirmModal">
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

        <!-- МОДАЛ: История баланса -->
        <div v-show="historyModalOpen" class="modal-backdrop-custom"  @click.self="closeHistoryModal">
            <div class="modal-card" style="max-width:900px">
                <div class="modal-card-header">
                    <span>
                        <i class="fas fa-history me-2" style="color:#6366f1"></i>
                        История изменений баланса
                        <span v-if="historyBalance"
                              style="font-weight:400;color:#9ca3af;font-size:12px;margin-left:8px">
                            {{ historyBalance.account_name }} · {{ historyBalance.ls_type }} · {{ historyBalance.currency }} · {{ historyBalance.value_date_fmt || historyBalance.value_date }}
                        </span>
                    </span>
                    <button class="btn-close" @click="closeHistoryModal"></button>
                </div>

                <div class="modal-card-body" style="padding:0">
                    <!-- Загрузка -->
                    <div v-if="historyLoading" style="text-align:center;padding:40px">
                        <div class="spinner-border spinner-border-sm" style="color:#6366f1"></div>
                        <div style="margin-top:8px;color:#9ca3af;font-size:13px">Загрузка истории...</div>
                    </div>

                    <!-- Пусто -->
                    <div v-else-if="!historyLogs.length"
                         style="text-align:center;padding:48px;color:#9ca3af;font-size:13px">
                        <i class="fas fa-clock" style="font-size:36px;color:#d1d5db;display:block;margin-bottom:12px"></i>
                        История изменений пуста
                    </div>

                    <!-- Таблица -->
                    <div v-else style="overflow-x:auto;max-height:500px;overflow-y:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:12px">
                            <thead>
                            <tr style="background:#f8f9fb;position:sticky;top:0;z-index:2">
                                <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap;background:#f0f1f5">Дата / Действие</th>
                                <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap;background:#f0f1f5">Пользователь</th>
                                <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap">Причина / Комментарий</th>
                                <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap">Было</th>
                                <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap">Стало</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr v-for="log in historyLogs" :key="log.id"
                                style="border-bottom:1px solid #f3f4f6"
                                :style="log.action==='confirm'?'background:#f0fdf4':log.action==='edit'?'background:#fefce8':''">

                                <!-- Дата + действие -->
                                <td style="padding:8px 12px;vertical-align:top;white-space:nowrap;background:#fafafa;border-right:1px solid #e5e7eb">
                                    <div style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px"
                                         :style="log.action==='confirm'?'background:#d1fae5;color:#059669':log.action==='edit'?'background:#dbeafe;color:#2563eb':'background:#ede9fe;color:#7c3aed'">
                                        <i :class="log.action==='confirm'?'fas fa-check-circle':log.action==='edit'?'fas fa-pen':'fas fa-file-import'"></i>
                                        {{ log.action==='confirm'?'Подтверждено':log.action==='edit'?'Изменено':'Импорт' }}
                                    </div>
                                    <div style="font-size:11px;font-weight:600;color:#374151">{{ formatDate(log.created_at) }}</div>
                                </td>

                                <!-- Пользователь -->
                                <td style="padding:8px 12px;vertical-align:top;white-space:nowrap;background:#fafafa;border-right:1px solid #e5e7eb">
                                    <div style="display:flex;align-items:center;gap:5px;font-size:12px;color:#4b5563">
                                        <i class="fas fa-user-circle" style="color:#9ca3af;font-size:14px"></i>
                                        {{ log.username || ('User #' + log.user_id) }}
                                    </div>
                                </td>

                                <!-- Причина -->
                                <td style="padding:8px 12px;vertical-align:top;max-width:200px">
                                        <span style="font-size:12px;color:#6b7280;font-style:italic">
                                            {{ log.reason || '—' }}
                                        </span>
                                </td>

                                <!-- Было (old_values) -->
                                <td style="padding:8px 12px;vertical-align:top;max-width:220px">
                                    <div v-if="log.old_values" style="font-size:11px;line-height:1.6">
                                        <div v-if="log.old_values.opening_balance !== undefined"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Open:</span>
                                            <span style="font-family:monospace;text-decoration:line-through;color:#b91c1c">
                                                    {{ formatAmount(log.old_values.opening_balance) }}
                                                    {{ log.old_values.opening_dc }}
                                                </span>
                                        </div>
                                        <div v-if="log.old_values.closing_balance !== undefined"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Close:</span>
                                            <span style="font-family:monospace;text-decoration:line-through;color:#b91c1c">
                                                    {{ formatAmount(log.old_values.closing_balance) }}
                                                    {{ log.old_values.closing_dc }}
                                                </span>
                                        </div>
                                        <div v-if="log.old_values.status"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Статус:</span>
                                            <span style="text-decoration:line-through;color:#b91c1c">
                                                    {{ log.old_values.status }}
                                                </span>
                                        </div>
                                        <div v-if="log.old_values.comment"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Коммент:</span>
                                            <span style="text-decoration:line-through;color:#b91c1c;font-style:italic">
                                                    {{ log.old_values.comment }}
                                                </span>
                                        </div>
                                    </div>
                                    <span v-else style="color:#d1d5db;font-size:11px">—</span>
                                </td>

                                <!-- Стало (new_values) -->
                                <td style="padding:8px 12px;vertical-align:top;max-width:220px">
                                    <div v-if="log.new_values" style="font-size:11px;line-height:1.6">
                                        <div v-if="log.new_values.opening_balance !== undefined"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Open:</span>
                                            <span style="font-family:monospace;font-weight:600;color:#059669">
                                                    {{ formatAmount(log.new_values.opening_balance) }}
                                                    {{ log.new_values.opening_dc }}
                                                </span>
                                        </div>
                                        <div v-if="log.new_values.closing_balance !== undefined"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Close:</span>
                                            <span style="font-family:monospace;font-weight:600;color:#059669">
                                                    {{ formatAmount(log.new_values.closing_balance) }}
                                                    {{ log.new_values.closing_dc }}
                                                </span>
                                        </div>
                                        <div v-if="log.new_values.status"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Статус:</span>
                                            <span style="font-weight:600;color:#059669">
                                                    {{ log.new_values.status }}
                                                </span>
                                        </div>
                                        <div v-if="log.new_values.comment"
                                             style="display:flex;gap:4px;align-items:center">
                                            <span style="color:#9ca3af;font-size:10px;text-transform:uppercase">Коммент:</span>
                                            <span style="font-weight:600;color:#059669;font-style:italic">
                                                    {{ log.new_values.comment }}
                                                </span>
                                        </div>
                                    </div>
                                    <span v-else style="color:#d1d5db;font-size:11px">—</span>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-card-footer" style="display:flex;justify-content:flex-end">
                    <button class="toolbar-btn outline" @click="closeHistoryModal">
                        <i class="fas fa-times me-1"></i>Закрыть
                    </button>
                </div>
            </div>
        </div>

        <!-- МОДАЛ: Импорт -->
        <div v-show="importModalOpen" class="modal-backdrop-custom"  @click.self="closeImportModal">
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

    </div>
