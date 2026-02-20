<?php /** @var yii\web\View $this */ ?>

    <style>
        /* ═══════════════════════════════════════════════════
           MODAL SHARED STYLES
           ═══════════════════════════════════════════════════ */
        .modal-content {
            border: none !important;
            border-radius: 16px !important;
            box-shadow: 0 24px 60px rgba(0,0,0,.18) !important;
            overflow: hidden;
        }
        .modal-header {
            background: #fafbfd;
            border-bottom: 1px solid #f1f3f7 !important;
            padding: 18px 24px !important;
        }
        .modal-header .modal-title {
            font-size: 15px !important;
            font-weight: 700 !important;
            color: #1a202c !important;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-header .modal-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .modal-icon.blue   { background: #eff6ff; color: #2563eb; }
        .modal-icon.green  { background: #f0fdf4; color: #16a34a; }
        .modal-icon.indigo { background: #eef2ff; color: #6366f1; }
        .modal-icon.gray   { background: #f9fafb; color: #6b7280; }

        .modal-body { padding: 24px !important; }
        .modal-footer {
            background: #fafbfd;
            border-top: 1px solid #f1f3f7 !important;
            padding: 14px 24px !important;
            gap: 8px;
        }

        /* Поля форм */
        .modal .form-label {
            font-size: 12px !important;
            font-weight: 600 !important;
            color: #374151 !important;
            margin-bottom: 5px !important;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .modal .form-control,
        .modal .form-select {
            font-size: 13.5px !important;
            border-radius: 8px !important;
            border: 1px solid #e5e7eb !important;
            padding: 8px 12px !important;
            transition: border-color .15s, box-shadow .15s !important;
            background: #fff !important;
            color: #1a202c !important;
        }
        .modal .form-control:focus,
        .modal .form-select:focus {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 3px rgba(99,102,241,.12) !important;
            outline: none !important;
        }
        .modal .form-control::placeholder { color: #c4c9d6 !important; }

        /* Кнопки в модалах */
        .modal-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
        }
        .modal-btn.cancel {
            background: #fff;
            border-color: #e5e7eb;
            color: #6b7280;
        }
        .modal-btn.cancel:hover { border-color: #d1d5db; color: #374151; background: #f9fafb; }

        .modal-btn.save {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: #fff;
            box-shadow: 0 2px 8px rgba(99,102,241,.3);
        }
        .modal-btn.save:hover { background: linear-gradient(135deg, #4f46e5, #4338ca); }

        .modal-btn.save-green {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            box-shadow: 0 2px 8px rgba(16,185,129,.3);
        }
        .modal-btn.save-green:hover { background: linear-gradient(135deg, #059669, #047857); }

        /* Разделитель секций */
        .form-section {
            background: #f8f9fc;
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 4px;
        }
        .form-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #9ca3af;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Чекбоксы в форме */
        .modal .form-check-input {
            width: 15px !important;
            height: 15px !important;
            border-radius: 4px !important;
            border: 1.5px solid #d1d5db !important;
            cursor: pointer;
            accent-color: #6366f1;
        }
        .modal .form-check-input:checked {
            background-color: #6366f1 !important;
            border-color: #6366f1 !important;
        }
        .modal .form-check-label {
            font-size: 13px !important;
            color: #374151 !important;
            cursor: pointer;
            font-weight: 400 !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
        }
        .modal .form-switch .form-check-input {
            width: 32px !important;
            height: 18px !important;
            border-radius: 20px !important;
        }
    </style>

    <!-- ═══════════════════════════════════════════════════
         Группа — Создать
         ═══════════════════════════════════════════════════ -->
    <div class="modal fade" id="addGroupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="modal-icon indigo"><i class="fas fa-folder-plus"></i></span>
                        Новая группа
                    </h5>
                    <button type="button" class="btn-close" @click="closeAddGroupModal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                        <input type="text" class="form-control" v-model="newGroup.name"
                               placeholder="Например: NRE банки Европы">
                    </div>
                    <div>
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="newGroup.description"
                                  rows="2" placeholder="Необязательно..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn cancel" @click="closeAddGroupModal">
                        <i class="fas fa-times"></i>Отмена
                    </button>
                    <button class="modal-btn save" @click="createGroup">
                        <i class="fas fa-plus"></i>Создать группу
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Группа — Редактировать -->
    <div class="modal fade" id="editGroupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="modal-icon blue"><i class="fas fa-pen"></i></span>
                        Редактировать группу
                    </h5>
                    <button type="button" class="btn-close" @click="closeEditGroupModal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                        <input type="text" class="form-control" v-model="editingGroup.name">
                    </div>
                    <div>
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="editingGroup.description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn cancel" @click="closeEditGroupModal">
                        <i class="fas fa-times"></i>Отмена
                    </button>
                    <button class="modal-btn save" @click="updateGroup">
                        <i class="fas fa-save"></i>Сохранить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         Пул — Создать
         ═══════════════════════════════════════════════════ -->
    <div class="modal fade" id="addPoolModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="modal-icon green"><i class="fas fa-plus-circle"></i></span>
                        Новый пул
                    </h5>
                    <button type="button" class="btn-close" @click="closeAddPoolModal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                        <input type="text" class="form-control" v-model="newPool.name"
                               placeholder="Название пула">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="newPool.description"
                                  rows="2" placeholder="Необязательно..."></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="poolActiveNew" v-model="newPool.is_active">
                        <label class="form-check-label" for="poolActiveNew">Активен</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn cancel" @click="closeAddPoolModal">
                        <i class="fas fa-times"></i>Отмена
                    </button>
                    <button class="modal-btn save-green" @click="createPool">
                        <i class="fas fa-plus"></i>Создать пул
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Пул — Редактировать -->
    <div class="modal fade" id="editPoolModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="modal-icon blue"><i class="fas fa-pen"></i></span>
                        Редактировать пул
                    </h5>
                    <button type="button" class="btn-close" @click="closeEditPoolModal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                        <input type="text" class="form-control" v-model="editingPool.name">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="editingPool.description" rows="2"></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="editPoolActiveSwitch"
                               v-model="editingPool.is_active">
                        <label class="form-check-label" for="editPoolActiveSwitch">Активен</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn cancel" @click="closeEditPoolModal">
                        <i class="fas fa-times"></i>Отмена
                    </button>
                    <button class="modal-btn save" @click="updatePool">
                        <i class="fas fa-save"></i>Сохранить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Пул — Настройки фильтрации -->
    <div class="modal fade" id="configurePoolModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="modal-icon gray"><i class="fas fa-sliders-h"></i></span>
                        Фильтры пула
                        <span style="font-weight:400;color:#9ca3af;font-size:13px;margin-left:4px">
                        — {{ editingPool.name }}
                    </span>
                    </h5>
                    <button type="button" class="btn-close" @click="closeConfigurePoolModal"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:12.5px;color:#6b7280;margin-bottom:18px;
                           background:#f8f9fc;border-radius:8px;padding:10px 14px;
                           border-left:3px solid #6366f1">
                        <i class="fas fa-info-circle me-1" style="color:#6366f1"></i>
                        Счета автоматически включаются в пул, если соответствуют заданным критериям.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Валюта</label>
                            <input type="text" class="form-control"
                                   v-model="editingPool.filter_criteria.currency"
                                   placeholder="USD, EUR, RUB...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Тип счёта</label>
                            <input type="text" class="form-control"
                                   v-model="editingPool.filter_criteria.account_type"
                                   placeholder="NRE, INV...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Код банка</label>
                            <input type="text" class="form-control"
                                   v-model="editingPool.filter_criteria.bank_code"
                                   placeholder="SWIFT/BIC...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Страна</label>
                            <input type="text" class="form-control"
                                   v-model="editingPool.filter_criteria.country"
                                   placeholder="US, DE, RU...">
                        </div>
                        <div class="col-md-8 d-flex align-items-end pb-1">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="filterSuspenseSwitch"
                                       v-model="editingPool.filter_criteria.is_suspense">
                                <label class="form-check-label" for="filterSuspenseSwitch">
                                    Только Suspense счета (INV)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn cancel" @click="closeConfigurePoolModal">
                        <i class="fas fa-times"></i>Отмена
                    </button>
                    <button class="modal-btn save" @click="updatePool">
                        <i class="fas fa-save"></i>Сохранить фильтры
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         Запись NostroEntry — Добавить / Редактировать
         ═══════════════════════════════════════════════════ -->
    <div class="modal fade" id="entryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                    <span class="modal-icon green">
                        <i :class="editingEntry.id ? 'fas fa-pen' : 'fas fa-plus'"></i>
                    </span>
                        {{ editingEntry.id ? 'Редактировать запись' : 'Добавить запись' }}
                    </h5>
                    <button type="button" class="btn-close" @click="closeEntryModal"></button>
                </div>
                <div class="modal-body">

                    <!-- Основные поля -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Тип записи</label>
                            <select class="form-select" v-model="editingEntry.ls">
                                <option value="L">L — Ledger</option>
                                <option value="S">S — Statement</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Дебет / Кредит</label>
                            <select class="form-select" v-model="editingEntry.dc">
                                <option value="Debit">Debit (D)</option>
                                <option value="Credit">Credit (C)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Сумма <span style="color:#ef4444">*</span></label>
                            <input type="number" step="0.01" class="form-control"
                                   style="font-family:monospace;font-weight:600"
                                   v-model="editingEntry.amount" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Валюта <span style="color:#ef4444">*</span></label>
                            <input type="text" class="form-control"
                                   style="text-transform:uppercase;font-weight:700;letter-spacing:.05em"
                                   v-model="editingEntry.currency"
                                   placeholder="USD" maxlength="3">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Value Date</label>
                            <input type="date" class="form-control" v-model="editingEntry.value_date">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Post Date</label>
                            <input type="date" class="form-control" v-model="editingEntry.post_date">
                        </div>
                    </div>

                    <!-- Идентификаторы -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-fingerprint" style="color:#6366f1"></i>
                            Идентификаторы транзакции
                            <span style="font-weight:400;color:#c4c9d6;text-transform:none;letter-spacing:0">
                            — необязательно
                        </span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Instruction ID</label>
                                <input type="text" class="form-control"
                                       style="font-family:monospace;font-size:12.5px"
                                       v-model="editingEntry.instruction_id" placeholder="INSTR-...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">EndToEnd ID</label>
                                <input type="text" class="form-control"
                                       style="font-family:monospace;font-size:12.5px"
                                       v-model="editingEntry.end_to_end_id" placeholder="E2E-...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Transaction ID</label>
                                <input type="text" class="form-control"
                                       style="font-family:monospace;font-size:12.5px"
                                       v-model="editingEntry.transaction_id" placeholder="TXN-...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Message ID</label>
                                <input type="text" class="form-control"
                                       style="font-family:monospace;font-size:12.5px"
                                       v-model="editingEntry.message_id" placeholder="MSG-...">
                            </div>
                        </div>
                    </div>

                    <!-- Комментарий -->
                    <div class="mt-3">
                        <label class="form-label">Комментарий</label>
                        <input type="text" class="form-control"
                               v-model="editingEntry.comment"
                               maxlength="40" placeholder="До 40 символов...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn cancel" @click="closeEntryModal">
                        <i class="fas fa-times"></i>Отмена
                    </button>
                    <button class="modal-btn save-green" @click="saveEntry">
                        <i class="fas fa-save"></i>
                        {{ editingEntry.id ? 'Сохранить изменения' : 'Добавить запись' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         Правило квитования
         ═══════════════════════════════════════════════════ -->
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
                            <input type="text" class="form-control" v-model="editingRule.name"
                                   placeholder="Например: NRE — стандартное сопоставление">
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
                            <input type="number" class="form-control" v-model.number="editingRule.priority"
                                   min="1" max="999">
                            <div style="font-size:11px;color:#9ca3af;margin-top:3px">Меньше = выше</div>
                        </div>
                    </div>

                    <!-- Обязательные условия -->
                    <div class="form-section mb-3">
                        <div class="form-section-title">
                            <i class="fas fa-check-double" style="color:#10b981"></i>
                            Обязательные условия
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.match_dc" id="r_dc">
                                    <label class="form-check-label" for="r_dc">Противоположный D/C</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.match_amount" id="r_amount">
                                    <label class="form-check-label" for="r_amount">Совпадение суммы</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.match_value_date" id="r_vdate">
                                    <label class="form-check-label" for="r_vdate">Дата валютирования</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Идентификаторы -->
                    <div class="form-section mb-3">
                        <div class="form-section-title">
                            <i class="fas fa-fingerprint" style="color:#6366f1"></i>
                            Идентификаторы транзакции
                            <span style="font-weight:400;color:#c4c9d6;text-transform:none;letter-spacing:0">
                            — хотя бы один
                        </span>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.match_instruction_id" id="r_instr">
                                    <label class="form-check-label" for="r_instr">Instruction ID</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.match_end_to_end_id" id="r_e2e">
                                    <label class="form-check-label" for="r_e2e">EndToEnd ID</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.match_transaction_id" id="r_txn">
                                    <label class="form-check-label" for="r_txn">Transaction ID</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           v-model="editingRule.match_message_id" id="r_msg">
                                    <label class="form-check-label" for="r_msg">Message ID</label>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:12px;padding-top:10px;border-top:1px solid #e8eaf0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       v-model="editingRule.cross_id_search" id="r_cross">
                                <label class="form-check-label" for="r_cross"
                                       style="font-weight:600 !important">
                                    Перекрёстный поиск ID
                                </label>
                            </div>
                            <div style="font-size:11.5px;color:#9ca3af;margin-top:4px;margin-left:24px">
                                ID из любого поля записи A ищется в любом поле записи B
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       v-model="editingRule.is_active" id="r_active">
                                <label class="form-check-label" for="r_active"
                                       style="font-weight:600 !important">Активно</label>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Описание</label>
                            <input type="text" class="form-control"
                                   v-model="editingRule.description"
                                   placeholder="Комментарий...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-btn cancel" @click="closeRuleModal">
                        <i class="fas fa-times"></i>Отмена
                    </button>
                    <button class="modal-btn save" @click="saveRule">
                        <i class="fas fa-save"></i>Сохранить правило
                    </button>
                </div>
            </div>
        </div>
    </div>