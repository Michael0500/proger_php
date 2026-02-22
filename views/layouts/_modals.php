<?php /** @var yii\web\View $this */ ?>

<!-- ══════════════════════════ Группа — Создать ══════════════════════════ -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-folder-plus"></i></span>
                    Новая группа
                </h5>
                <button type="button" class="btn-close" @click="closeAddGroupModal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" class="form-control" v-model="newGroup.name" placeholder="Например: NRE банки Европы">
                </div>
                <div>
                    <label class="form-label">Описание</label>
                    <textarea class="form-control" v-model="newGroup.description" rows="2" placeholder="Необязательно..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeAddGroupModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="createGroup"><i class="fas fa-plus"></i>Создать</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Группа — Редактировать ══════════════════════════ -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon blue"><i class="fas fa-pen"></i></span>
                    Редактировать группу
                </h5>
                <button type="button" class="btn-close" @click="closeEditGroupModal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" class="form-control" v-model="editingGroup.name">
                </div>
                <div>
                    <label class="form-label">Описание</label>
                    <textarea class="form-control" v-model="editingGroup.description" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeEditGroupModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="updateGroup"><i class="fas fa-save"></i>Сохранить</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Пул — Создать ══════════════════════════ -->
<div class="modal fade" id="addPoolModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon green"><i class="fas fa-plus-circle"></i></span>
                    Новый пул
                </h5>
                <button type="button" class="btn-close" @click="closeAddPoolModal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" class="form-control" v-model="newPool.name" placeholder="Название пула">
                </div>
                <div class="mb-3">
                    <label class="form-label">Описание</label>
                    <textarea class="form-control" v-model="newPool.description" rows="2" placeholder="Необязательно..."></textarea>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="poolActiveNew" v-model="newPool.is_active">
                    <label class="form-check-label" for="poolActiveNew">Активен</label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel"     @click="closeAddPoolModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save-green" @click="createPool"><i class="fas fa-plus"></i>Создать</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Пул — Редактировать ══════════════════════════ -->
<div class="modal fade" id="editPoolModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon blue"><i class="fas fa-pen"></i></span>
                    Редактировать пул
                </h5>
                <button type="button" class="btn-close" @click="closeEditPoolModal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" class="form-control" v-model="editingPool.name">
                </div>
                <div class="mb-3">
                    <label class="form-label">Описание</label>
                    <textarea class="form-control" v-model="editingPool.description" rows="2"></textarea>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="editPoolActiveSwitch" v-model="editingPool.is_active">
                    <label class="form-check-label" for="editPoolActiveSwitch">Активен</label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeEditPoolModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="updatePool"><i class="fas fa-save"></i>Сохранить</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Пул — Фильтры ══════════════════════════ -->
<div class="modal fade" id="configurePoolModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon gray"><i class="fas fa-sliders-h"></i></span>
                    Фильтры пула
                    <span style="font-weight:400;color:#9ca3af;font-size:13px">— {{ editingPool.name }}</span>
                </h5>
                <button type="button" class="btn-close" @click="closeConfigurePoolModal"></button>
            </div>
            <div class="modal-body">
                <div style="font-size:12.5px;color:#6b7280;margin-bottom:16px;
                            background:#f5f3ff;border-radius:8px;padding:10px 14px;
                            border-left:3px solid #6366f1">
                    <i class="fas fa-info-circle me-1" style="color:#6366f1"></i>
                    Счета автоматически включаются в пул, если соответствуют критериям.
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Валюта</label>
                        <input type="text" class="form-control" v-model="editingPool.filter_criteria.currency" placeholder="USD, EUR...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Тип счёта</label>
                        <input type="text" class="form-control" v-model="editingPool.filter_criteria.account_type" placeholder="NRE, INV...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Код банка</label>
                        <input type="text" class="form-control" v-model="editingPool.filter_criteria.bank_code" placeholder="SWIFT/BIC...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Страна</label>
                        <input type="text" class="form-control" v-model="editingPool.filter_criteria.country" placeholder="US, DE...">
                    </div>
                    <div class="col-md-8 d-flex align-items-end" style="padding-bottom:2px">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="filterSuspenseSwitch" v-model="editingPool.filter_criteria.is_suspense">
                            <label class="form-check-label" for="filterSuspenseSwitch">Только Suspense счета (INV)</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeConfigurePoolModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="updatePool"><i class="fas fa-save"></i>Сохранить фильтры</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════ Правило квитования ══════════════════════════ -->
<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-icon indigo"><i class="fas fa-sliders-h"></i></span>
                    {{ editingRule.id ? 'Редактировать правило' : 'Новое правило квитования' }}
                </h5>
                <button type="button" class="btn-close" @click="closeRuleModal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                        <input type="text" class="form-control" v-model="editingRule.name" placeholder="Например: NRE — стандартное сопоставление">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Раздел</label>
                        <select class="form-select" v-model="editingRule.section">
                            <option value="NRE">NRE</option>
                            <option value="INV">INV</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Тип пары</label>
                        <select class="form-select" v-model="editingRule.pair_type">
                            <option value="LS">Ledger ↔ Statement</option>
                            <option value="LL">Ledger ↔ Ledger</option>
                            <option value="SS">Statement ↔ Statement</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Приоритет</label>
                        <input type="number" class="form-control" v-model.number="editingRule.priority" min="1" max="999">
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px">Меньше = выше</div>
                    </div>
                </div>
                <div class="form-section mb-3">
                    <div class="form-section-title">
                        <i class="fas fa-check-double" style="color:#10b981"></i>Обязательные условия
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_dc" id="r_dc">
                                <label class="form-check-label" for="r_dc">Противоположный D/C</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_amount" id="r_amount">
                                <label class="form-check-label" for="r_amount">Совпадение суммы</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_value_date" id="r_vdate">
                                <label class="form-check-label" for="r_vdate">Дата валютирования</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-section mb-3">
                    <div class="form-section-title">
                        <i class="fas fa-fingerprint" style="color:#6366f1"></i>
                        Идентификаторы
                        <span style="font-weight:400;color:#c4c9d6;text-transform:none;letter-spacing:0">— хотя бы один</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_instruction_id" id="r_instr">
                                <label class="form-check-label" for="r_instr">Instruction ID</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_end_to_end_id" id="r_e2e">
                                <label class="form-check-label" for="r_e2e">EndToEnd ID</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_transaction_id" id="r_txn">
                                <label class="form-check-label" for="r_txn">Transaction ID</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" v-model="editingRule.match_message_id" id="r_msg">
                                <label class="form-check-label" for="r_msg">Message ID</label>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8eaf0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" v-model="editingRule.cross_id_search" id="r_cross">
                            <label class="form-check-label" for="r_cross" style="font-weight:600 !important">
                                Перекрёстный поиск ID
                            </label>
                        </div>
                        <div style="font-size:11.5px;color:#9ca3af;margin-top:3px;margin-left:42px">
                            ID из любого поля записи A ищется во всех полях записи B
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" v-model="editingRule.is_active" id="r_active">
                            <label class="form-check-label" for="r_active" style="font-weight:600 !important">Активно</label>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Описание</label>
                        <input type="text" class="form-control" v-model="editingRule.description" placeholder="Комментарий...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeRuleModal"><i class="fas fa-times"></i>Отмена</button>
                <button class="modal-btn save"   @click="saveRule"><i class="fas fa-save"></i>Сохранить правило</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════ МОДАЛ ДОБАВЛЕНИЯ/РЕДАКТИРОВАНИЯ ЗАПИСИ ════════════════ -->
<div class="modal fade" id="entryModal" tabindex="-1" aria-labelledby="entryModalLabel">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="entryModalLabel">
                    <span class="modal-icon" :class="editingEntry.id ? 'amber' : 'green'">
                        <i :class="editingEntry.id ? 'fas fa-pen' : 'fas fa-plus'"></i>
                    </span>
                    {{ editingEntry.id ? 'Редактировать запись #' + editingEntry.id : 'Добавить запись' }}
                </h5>
                <button type="button" class="btn-close" @click="closeEntryModal"></button>
            </div>
            <div class="modal-body">

                <!-- ── Счёт (Select2) ── -->
                <div class="form-section" style="margin-bottom:16px">
                    <label class="form-section-label">
                        <i class="fas fa-university"></i> Счёт
                        <span class="required-star">*</span>
                    </label>
                    <select id="entry-account-select2" style="width:100%">
                        <option v-if="editingEntry.account_id" :value="editingEntry.account_id">
                            {{ editingEntry.account_name }}
                        </option>
                    </select>
                    <div class="field-hint">Начните вводить название счёта для поиска</div>
                </div>

                <!-- ── L/S · D/C · Валюта ── -->
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label-sm">L/S <span class="required-star">*</span></label>
                        <div class="entry-radio-group">
                            <label class="entry-radio" :class="{ active: editingEntry.ls === 'L' }">
                                <input type="radio" v-model="editingEntry.ls" value="L">
                                <span class="badge-ls-l">L</span> Ledger
                            </label>
                            <label class="entry-radio" :class="{ active: editingEntry.ls === 'S' }">
                                <input type="radio" v-model="editingEntry.ls" value="S">
                                <span class="badge-ls-s">S</span> Statement
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">D/C <span class="required-star">*</span></label>
                        <div class="entry-radio-group">
                            <label class="entry-radio" :class="{ active: editingEntry.dc === 'Debit' }">
                                <input type="radio" v-model="editingEntry.dc" value="Debit">
                                <span class="badge-debit">D</span> Debit
                            </label>
                            <label class="entry-radio" :class="{ active: editingEntry.dc === 'Credit' }">
                                <input type="radio" v-model="editingEntry.dc" value="Credit">
                                <span class="badge-credit">C</span> Credit
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">Сумма <span class="required-star">*</span></label>
                        <input type="number" class="form-control-sm-custom" v-model="editingEntry.amount"
                               step="0.01" min="0.01" placeholder="0.00">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">Валюта <span class="required-star">*</span></label>
                        <select class="form-control-sm-custom" v-model="editingEntry.currency">
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="RUB">RUB</option>
                            <option value="GBP">GBP</option>
                            <option value="CHF">CHF</option>
                            <option value="CNY">CNY</option>
                            <option value="JPY">JPY</option>
                        </select>
                    </div>
                </div>

                <!-- ── Даты ── -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-sm">Value Date</label>
                        <input type="date" class="form-control-sm-custom" v-model="editingEntry.value_date">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-sm">Post Date</label>
                        <input type="date" class="form-control-sm-custom" v-model="editingEntry.post_date">
                    </div>
                </div>

                <!-- ── ID поля ── -->
                <div class="form-section">
                    <div class="form-section-label">
                        <i class="fas fa-fingerprint"></i> Идентификаторы
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-sm">Instruction ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.instruction_id" maxlength="40" placeholder="INSTR...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">EndToEnd ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.end_to_end_id" maxlength="40" placeholder="E2E...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Transaction ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.transaction_id" maxlength="60" placeholder="TXN...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Message ID</label>
                            <input type="text" class="form-control-sm-custom"
                                   v-model="editingEntry.message_id" maxlength="40" placeholder="MSG...">
                        </div>
                    </div>
                </div>

                <!-- ── Комментарий ── -->
                <div class="mt-3">
                    <label class="form-label-sm">Комментарий</label>
                    <input type="text" class="form-control-sm-custom"
                           v-model="editingEntry.comment" maxlength="40"
                           placeholder="До 40 символов...">
                </div>

            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" @click="closeEntryModal">
                    <i class="fas fa-times"></i>Отмена
                </button>
                <button class="modal-btn save-green" @click="saveEntry">
                    <i class="fas fa-save"></i>
                    {{ editingEntry.id ? 'Сохранить' : 'Добавить запись' }}
                </button>
            </div>
        </div>
    </div>
</div>