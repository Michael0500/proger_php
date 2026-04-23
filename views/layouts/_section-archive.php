<?php /** @var yii\web\View $this */ ?>

    <div v-show="activeSection === 'archive'">

        <!-- Тулбар -->
        <div class="section-toolbar">
            <div class="section-title">
                <i class="fas fa-archive" style="color:#7c3aed;margin-right:6px"></i>
                Архив сквитованных записей
                <span v-if="archiveTotal > 0" style="font-size:11px;color:#9ca3af;margin-left:4px">
                        {{ archiveTotal.toLocaleString() }} {{ recordText(archiveTotal) }}
                    </span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <button class="toolbar-btn outline" @click="toggleArchiveFilters"
                        :style="(archiveFiltersOpen || activeArchiveFilterCount()>0) ? 'border-color:#7c3aed;color:#7c3aed' : ''">
                    <i class="fas fa-filter"></i>Фильтры
                    <span v-if="activeArchiveFilterCount()>0"
                          style="background:#7c3aed;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px">
                            {{ activeArchiveFilterCount() }}
                        </span>
                </button>
                <button class="toolbar-btn outline" @click="archiveSettingsOpen=true">
                    <i class="fas fa-cog"></i>Настройки
                </button>
                <button class="toolbar-btn outline" @click="purgeExpired" :disabled="archivePurging">
                    <i :class="archivePurging?'fas fa-spinner fa-spin':'fas fa-trash-alt'" style="color:#ef4444"></i>
                    Очистить просроченные
                </button>
                <button class="toolbar-btn primary" @click="runArchive" :disabled="archiveRunning">
                    <i :class="archiveRunning?'fas fa-spinner fa-spin':'fas fa-archive'"></i>
                    {{ archiveRunning ? 'Архивирование...' : 'Архивировать сейчас' }}
                </button>
            </div>
        </div>

        <!-- МОДАЛКА ПРОГРЕССА АРХИВИРОВАНИЯ -->
        <div v-show="archiveProgressOpen" class="modal-backdrop-custom" style="display:none;z-index:9999">
            <div class="modal-card" style="max-width:420px;text-align:center">
                <div class="modal-card-header" style="justify-content:center;border-bottom:0">
                        <span style="font-size:15px;font-weight:700">
                            <i class="fas fa-archive me-2" style="color:#4f46e5"></i>
                            Архивирование записей
                        </span>
                </div>
                <div class="modal-card-body" style="padding:24px 32px 28px">

                    <!-- Прогресс-бар -->
                    <div style="background:#f3f4f6;border-radius:99px;height:18px;overflow:hidden;margin-bottom:14px">
                        <div :style="{
                                    width: archiveProgressPct + '%',
                                    height: '100%',
                                    background: 'linear-gradient(90deg,#4f46e5,#7c3aed)',
                                    borderRadius: '99px',
                                    transition: 'width .3s ease'
                                }"></div>
                    </div>

                    <!-- Цифры -->
                    <div style="font-size:28px;font-weight:700;color:#1a1f36;line-height:1">
                        {{ archiveProgressPct }}%
                    </div>
                    <div style="margin-top:8px;font-size:13px;color:#6b7280">
                        Заархивировано:
                        <strong style="color:#4f46e5">{{ archiveProgressDone.toLocaleString() }}</strong>
                        из
                        <strong>{{ archiveProgressAll.toLocaleString() }}</strong>
                    </div>
                    <div style="margin-top:18px;font-size:12px;color:#9ca3af">
                        <i class="fas fa-info-circle me-1"></i>
                        Не закрывайте страницу до завершения
                    </div>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div v-if="archiveStats" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
            <div class="stat-chip stat-chip-purple">
                <i class="fas fa-archive"></i>
                <span>В архиве: <strong>{{ archiveStats.total_archived.toLocaleString() }}</strong></span>
            </div>
            <div class="stat-chip" :class="archiveStats.pending_archive>0?'stat-chip-orange':'stat-chip-gray'">
                <i class="fas fa-clock"></i>
                <span>Ожидают архивирования: <strong>{{ archiveStats.pending_archive.toLocaleString() }}</strong></span>
            </div>
            <div class="stat-chip" :class="archiveStats.expired_records>0?'stat-chip-red':'stat-chip-gray'">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Просрочено (к удалению): <strong>{{ archiveStats.expired_records.toLocaleString() }}</strong></span>
            </div>
            <div class="stat-chip stat-chip-gray">
                <i class="fas fa-sliders-h"></i>
                <span>Архивировать через: <strong>{{ archiveStats.settings.archive_after_days }} дн.</strong></span>
            </div>
        </div>

        <!-- ФИЛЬТРЫ -->
        <div v-show="archiveFiltersOpen" class="filters-panel" style="display:none;margin-bottom:14px">
            <div style="display:flex;flex-wrap:wrap;gap:10px 16px;align-items:flex-end">

                <!-- Ностро банк Select2 -->
                <div class="filter-field" style="min-width:200px">
                    <label class="filter-label">Ностро банк</label>
                    <select id="archive-pool-select2" style="width:100%"></select>
                </div>

                <!-- Ностро счёт Select2 -->
                <div class="filter-field" style="min-width:200px">
                    <label class="filter-label">Ностро счёт</label>
                    <select id="archive-account-select2" style="width:100%"></select>
                </div>

                <!-- L/S -->
                <div class="filter-field">
                    <label class="filter-label">L/S</label>
                    <div class="filter-toggle-group">
                        <button class="ftg-btn" :class="{active:!archiveFilters.ls}"
                                @click="applyArchiveFilter('ls','')">Все</button>
                        <button class="ftg-btn" :class="{active:archiveFilters.ls==='L'}"
                                @click="applyArchiveFilter('ls','L')">L</button>
                        <button class="ftg-btn" :class="{active:archiveFilters.ls==='S'}"
                                @click="applyArchiveFilter('ls','S')">S</button>
                    </div>
                </div>

                <!-- D/C -->
                <div class="filter-field">
                    <label class="filter-label">D/C</label>
                    <div class="filter-toggle-group">
                        <button class="ftg-btn" :class="{active:!archiveFilters.dc}"
                                @click="applyArchiveFilter('dc','')">Все</button>
                        <button class="ftg-btn" :class="{active:archiveFilters.dc==='Debit'}"
                                @click="applyArchiveFilter('dc','Debit')">D</button>
                        <button class="ftg-btn" :class="{active:archiveFilters.dc==='Credit'}"
                                @click="applyArchiveFilter('dc','Credit')">C</button>
                    </div>
                </div>

                <!-- Валюта -->
                <div class="filter-field">
                    <label class="filter-label">Валюта</label>
                    <div class="filter-input-wrap">
                        <select class="filter-input"
                                :value="archiveFilters.currency||''"
                                @change="applyArchiveFilter('currency',$event.target.value)">
                            <option value="">Все</option>
                            <option v-for="c in ['RUB','USD','EUR','GBP','CHF','CNY','JPY','KZT','BYR']" :key="c">{{ c }}</option>
                        </select>
                    </div>
                </div>

                <!-- Сумма -->
                <div class="filter-field">
                    <label class="filter-label">Сумма от</label>
                    <div class="filter-input-wrap">
                        <input type="number" class="filter-input" placeholder="0"
                               :value="archiveFilters.amount_min||''"
                               @change="applyArchiveFilter('amount_min',$event.target.value)"
                               @input="if(!archiveFilters.amount_max&&$event.target.value) applyArchiveFilter('amount_max',$event.target.value)">
                        <button v-if="archiveFilters.amount_min" class="filter-clear-btn"
                                @click="clearArchiveFilter('amount_min')">×</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Сумма до</label>
                    <div class="filter-input-wrap">
                        <input type="number" class="filter-input" placeholder="∞"
                               :value="archiveFilters.amount_max||''"
                               @change="applyArchiveFilter('amount_max',$event.target.value)">
                        <button v-if="archiveFilters.amount_max" class="filter-clear-btn"
                                @click="clearArchiveFilter('amount_max')">×</button>
                    </div>
                </div>

                <!-- Value Date -->
                <div class="filter-field">
                    <label class="filter-label">Value Date от</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.value_date_from||''"
                               @change="applyArchiveFilter('value_date_from',$event.target.value)">
                        <button v-if="archiveFilters.value_date_from" class="filter-clear-btn"
                                @click="clearArchiveFilter('value_date_from')">×</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Value Date до</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.value_date_to||''"
                               @change="applyArchiveFilter('value_date_to',$event.target.value)">
                        <button v-if="archiveFilters.value_date_to" class="filter-clear-btn"
                                @click="clearArchiveFilter('value_date_to')">×</button>
                    </div>
                </div>

                <!-- Дата архивирования -->
                <div class="filter-field">
                    <label class="filter-label">Заархивирован от</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.archived_at_from||''"
                               @change="applyArchiveFilter('archived_at_from',$event.target.value)">
                        <button v-if="archiveFilters.archived_at_from" class="filter-clear-btn"
                                @click="clearArchiveFilter('archived_at_from')">×</button>
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Заархивирован до</label>
                    <div class="filter-input-wrap">
                        <input type="text" v-datepicker class="filter-input"
                               :value="archiveFilters.archived_at_to||''"
                               @change="applyArchiveFilter('archived_at_to',$event.target.value)">
                        <button v-if="archiveFilters.archived_at_to" class="filter-clear-btn"
                                @click="clearArchiveFilter('archived_at_to')">×</button>
                    </div>
                </div>

                <!-- Поиск по полю -->
                <div class="filter-field">
                    <label class="filter-label">Поле поиска</label>
                    <select class="filter-input"
                            :value="archiveFilters.search_field||''"
                            @change="applyArchiveFilter('search_field',$event.target.value)">
                        <option value="">— Все поля —</option>
                        <option value="match_id">Match ID</option>
                        <option value="instruction_id">Instruction ID</option>
                        <option value="end_to_end_id">EndToEnd ID</option>
                        <option value="transaction_id">Transaction ID</option>
                        <option value="message_id">Message ID</option>
                        <option value="other_id">Other ID</option>
                        <option value="comment">Комментарий</option>
                    </select>
                </div>
                <div class="filter-field" style="min-width:180px">
                    <label class="filter-label">Значение</label>
                    <div class="filter-input-wrap">
                        <input type="text" class="filter-input" placeholder="Поиск..."
                               :value="archiveFilters.search_value||''"
                               @input="debouncedArchiveFilter('search_value',$event.target.value)">
                        <button v-if="archiveFilters.search_value" class="filter-clear-btn"
                                @click="clearArchiveFilter('search_value')">×</button>
                    </div>
                </div>

                <!-- Сброс фильтров -->
                <div class="filter-field" style="align-self:flex-end">
                    <button class="toolbar-btn outline" style="font-size:11px;padding:4px 10px"
                            @click="clearAllArchiveFilters">
                        <i class="fas fa-times"></i>Сбросить
                    </button>
                </div>
            </div>
        </div>

        <!-- ТАБЛИЦА АРХИВА -->
        <div class="table-card">
            <div v-if="archiveLoading" style="display:flex;justify-content:center;align-items:center;height:200px">
                <div class="spinner-border" style="color:#7c3aed"></div>
            </div>
            <div v-else-if="archiveRows.length===0" class="empty-pool" style="padding:60px">
                <i class="fas fa-archive" style="opacity:.3"></i>
                <p>Архив пуст</p>
                <p style="font-size:12px;color:#9ca3af;margin-top:4px">
                    Сквитованные записи старше {{ archiveSettings.archive_after_days }} дней будут перенесены сюда
                </p>
            </div>
            <div v-else class="table-scroll-wrap" @scroll="onArchiveScroll">
                <table class="entries-table">
                    <thead><tr>
                        <th class="th-sort" @click="sortArchive('id')" style="width:55px">
                            ID <i :class="archiveSortIcon('id')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('account_id')">
                            Счёт <i :class="archiveSortIcon('account_id')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('match_id')">
                            Match ID <i :class="archiveSortIcon('match_id')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('ls')" style="width:48px">
                            L/S <i :class="archiveSortIcon('ls')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('dc')" style="width:48px">
                            D/C <i :class="archiveSortIcon('dc')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('amount')" style="text-align:right">
                            Сумма <i :class="archiveSortIcon('amount')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('currency')" style="width:52px">
                            Вал. <i :class="archiveSortIcon('currency')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('value_date')">
                            Value Date <i :class="archiveSortIcon('value_date')"></i>
                        </th>
                        <th>Instr. ID</th>
                        <th>E2E ID</th>
                        <th>Txn ID</th>
                        <th>Msg ID</th>
                        <th class="th-sort" @click="sortArchive('archived_at')">
                            Архивирован <i :class="archiveSortIcon('archived_at')"></i>
                        </th>
                        <th class="th-sort" @click="sortArchive('expires_at')" style="color:#ef4444">
                            Хранить до <i :class="archiveSortIcon('expires_at')"></i>
                        </th>
                        <th style="width:50px;text-align:right;padding-right:12px"></th>
                    </tr></thead>
                    <tbody>
                    <tr v-for="row in archiveRows" :key="row.id"
                        :style="isExpired(row.expires_at)?'background:#fff5f5':''">
                        <td style="color:#9ca3af;font-size:11px">{{ row.original_id }}</td>
                        <td style="font-size:12px;font-weight:600;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                            :title="row.account_name">{{ row.account_name }}</td>
                        <td style="font-family:monospace;font-size:11px;color:#4f46e5;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                            :title="row.match_id">{{ row.match_id }}</td>
                        <td>
                                    <span :style="row.ls==='L'
                                        ?'background:#dbeafe;color:#1e40af;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'
                                        :'background:#fef3c7;color:#92400e;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:700'">
                                        {{ row.ls }}
                                    </span>
                        </td>
                        <td>
                                    <span :style="row.dc==='Debit'
                                        ?'color:#dc2626;font-weight:700;font-size:11px'
                                        :'color:#059669;font-weight:700;font-size:11px'">
                                        {{ row.dc==='Debit'?'D':'C' }}
                                    </span>
                        </td>
                        <td style="text-align:right;font-family:monospace;font-weight:600;font-size:12px">
                            {{ formatAmount(row.amount) }}
                        </td>
                        <td style="font-weight:600;font-size:12px">{{ row.currency }}</td>
                        <td style="white-space:nowrap;font-size:12px">{{ row.value_date_fmt||row.value_date||'—' }}</td>
                        <td style="font-family:monospace;font-size:11px;color:#6b7280;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            {{ row.instruction_id||'—' }}
                        </td>
                        <td style="font-family:monospace;font-size:11px;color:#6b7280;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            {{ row.end_to_end_id||'—' }}
                        </td>
                        <td style="font-family:monospace;font-size:11px;color:#6b7280;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            {{ row.transaction_id||'—' }}
                        </td>
                        <td style="font-family:monospace;font-size:11px;color:#6b7280;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            {{ row.message_id||'—' }}
                        </td>
                        <td style="white-space:nowrap;font-size:11px;color:#6b7280">
                            {{ row.archived_at_fmt||'—' }}
                        </td>
                        <td style="white-space:nowrap;font-size:11px"
                            :style="isExpired(row.expires_at)?'color:#dc2626;font-weight:700':isExpiringSoon(row.expires_at)?'color:#d97706;font-weight:600':'color:#6b7280'">
                            <i v-if="isExpired(row.expires_at)" class="fas fa-exclamation-triangle me-1"></i>
                            {{ row.expires_at_fmt||'—' }}
                        </td>
                        <td style="text-align:right;padding-right:12px">
                            <button class="row-btn history" @click="showArchiveHistory(row)"
                                    title="История изменений">
                                <i class="fas fa-history"></i>
                            </button>
                            <button class="row-btn edit" @click="restoreFromArchive(row)"
                                    title="Восстановить в активные записи">
                                <i class="fas fa-undo"></i>
                            </button>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <div v-if="archiveLoadingMore" style="text-align:center;padding:12px">
                    <div class="spinner-border spinner-border-sm" style="color:#7c3aed"></div>
                </div>
            </div>
        </div>

        <!-- МОДАЛ: Настройки архивирования -->
        <div v-show="archiveSettingsOpen" class="modal-backdrop-custom"  @click.self="archiveSettingsOpen=false">
            <div class="modal-card" style="max-width:440px">
                <div class="modal-card-header">
                    <span><i class="fas fa-cog me-2"></i>Настройки архивирования</span>
                    <button class="btn-close" @click="archiveSettingsOpen=false"></button>
                </div>
                <div class="modal-card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Дней до архивирования
                                <span style="font-weight:400;color:#9ca3af;font-size:11px">(мин 1, макс 3650)</span>
                            </label>
                            <input type="number" class="form-control"
                                   v-model.number="archiveSettings.archive_after_days"
                                   min="1" max="3650">
                            <div class="form-text">
                                Сквитованные записи старше указанного числа дней будут архивированы.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Срок хранения в архиве (лет)
                            </label>
                            <input type="number" class="form-control"
                                   v-model.number="archiveSettings.retention_years"
                                   min="1" max="20">
                            <div class="form-text">По умолчанию 5 лет согласно требованиям.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       v-model="archiveSettings.auto_archive_enabled" id="autoArchiveEnabled">
                                <label class="form-check-label fw-semibold" for="autoArchiveEnabled">
                                    Автоматическое архивирование
                                </label>
                            </div>
                            <div class="form-text">
                                Если включено — архивирование запускается автоматически по расписанию (cron).
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-card-footer" style="display:flex;justify-content:flex-end;gap:8px">
                    <button class="toolbar-btn outline" @click="archiveSettingsOpen=false">Отмена</button>
                    <button class="toolbar-btn primary" @click="saveArchiveSettings" :disabled="archiveSettingsSaving">
                        <i :class="archiveSettingsSaving?'fas fa-spinner fa-spin':'fas fa-save'"></i>
                        {{ archiveSettingsSaving ? 'Сохранение...' : 'Сохранить' }}
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /archive section -->

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
                                <div class="row-actions-dropdown">
                                    <button class="row-btn more" @click.stop="toggleRowMenu('rule', rule.id, $event)"><i class="fas fa-ellipsis-v"></i></button>
                                    <div v-if="openRowMenu==='rule-'+rule.id" class="row-actions-menu" :style="rowMenuStyle">
                                        <button class="row-actions-menu-item danger" @click.stop="deleteRule(rule); openRowMenu=null">
                                            <i class="fas fa-trash"></i> Удалить
                                        </button>
                                    </div>
                                </div>
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
