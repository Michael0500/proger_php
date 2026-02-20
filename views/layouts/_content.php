<?php
/** @var yii\web\View $this */
use yii\helpers\Html;
use yii\helpers\Url;
?>

<div class="table-container">

    <div v-if="!selectedPool" class="text-center py-5">
        <i class="fas fa-arrow-left fa-3x text-muted mb-3"></i>
        <p class="text-muted">Выберите пул в меню слева, чтобы увидеть записи выверки</p>
    </div>

    <div v-else>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>
                <i class="fas fa-table me-2"></i>
                Записи выверки — пул «{{ selectedPool.name }}»
            </h5>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success" @click="showAddEntryModal(null)">
                    <i class="fas fa-plus me-1"></i>Добавить запись
                </button>
                <button class="btn btn-sm btn-outline-secondary" @click="refreshAccounts">
                    <i class="fas fa-sync"></i>
                </button>
            </div>
        </div>

        <div v-if="loadingAccounts" class="text-center py-5">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Загрузка...</span>
            </div>
        </div>

        <div v-else-if="accounts.length === 0" class="text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <p class="text-muted">В этом пуле нет Ностро банков</p>
        </div>

        <div v-else>
            <div v-for="account in accounts" :key="account.id" class="mb-4">

                <div class="d-flex align-items-center justify-content-between bg-light px-3 py-2 rounded mb-1 border">
                    <div>
                        <i class="fas fa-university me-2 text-primary"></i>
                        <strong>{{ account.name }}</strong>
                        <span v-if="account.currency" class="badge bg-secondary ms-2">{{ account.currency }}</span>
                        <span v-if="account.is_suspense" class="badge bg-warning text-dark ms-1">INV / suspense</span>
                        <span v-else class="badge bg-info text-dark ms-1">NRE</span>
                        <span class="ms-2 text-muted small">{{ account.entries.length }} записей</span>
                    </div>
                    <button class="btn btn-sm btn-outline-success" @click="showAddEntryModal(account)">
                        <i class="fas fa-plus me-1"></i>Добавить запись
                    </button>
                </div>

                <div v-if="account.entries.length === 0" class="text-muted small ps-4 py-2">
                    Нет записей для этого Ностро банка
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover table-striped mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width:120px">Match ID</th>
                            <th style="width:40px">L/S</th>
                            <th style="width:60px">D/C</th>
                            <th style="width:150px" class="text-end">Сумма</th>
                            <th style="width:60px">Валюта</th>
                            <th style="width:100px">Value Date</th>
                            <th style="width:100px">Post Date</th>
                            <th style="width:160px">Instruction ID</th>
                            <th style="width:160px">EndToEnd ID</th>
                            <th style="width:180px">Transaction ID</th>
                            <th style="width:140px">Message ID</th>
                            <th>Комментарий</th>
                            <th style="width:110px">Статус</th>
                            <th style="width:80px"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="entry in account.entries" :key="entry.id">
                            <td class="font-monospace small">{{ entry.match_id || '—' }}</td>
                            <td>
                                <span :class="entry.ls === 'L' ? 'badge bg-primary' : 'badge bg-dark'">
                                    {{ entry.ls }}
                                </span>
                            </td>
                            <td>
                                <span :class="entry.dc === 'Debit' ? 'text-danger fw-bold' : 'text-success fw-bold'">
                                    {{ entry.dc === 'Debit' ? 'D' : 'C' }}
                                </span>
                            </td>
                            <td class="text-end font-monospace">{{ entry.amount }}</td>
                            <td>{{ entry.currency }}</td>
                            <td>{{ entry.value_date || '—' }}</td>
                            <td>{{ entry.post_date || '—' }}</td>
                            <td class="small" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="entry.instruction_id">{{ entry.instruction_id || '—' }}</td>
                            <td class="small" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="entry.end_to_end_id">{{ entry.end_to_end_id || '—' }}</td>
                            <td class="small" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="entry.transaction_id">{{ entry.transaction_id || '—' }}</td>
                            <td class="small" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="entry.message_id">{{ entry.message_id || '—' }}</td>
                            <td>
                                <span v-if="editingCommentId !== entry.id"
                                      style="cursor:pointer;border-bottom:1px dashed #aaa"
                                      @dblclick="startEditComment(entry)"
                                      title="Двойной клик для редактирования">
                                    {{ entry.comment || '—' }}
                                </span>
                                <div v-else class="input-group input-group-sm" style="min-width:150px">
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           v-model="editingCommentValue"
                                           maxlength="40"
                                           @keyup.enter="saveComment(entry)"
                                           @keyup.esc="cancelEditComment">
                                    <button class="btn btn-success btn-sm" @click="saveComment(entry)">&#10003;</button>
                                    <button class="btn btn-secondary btn-sm" @click="cancelEditComment">&#10005;</button>
                                </div>
                            </td>
                            <td>
                                <span :class="'badge bg-' + entry.match_status_badge">
                                    {{ entry.match_status === 'M' ? 'Квит.' : (entry.match_status === 'I' ? 'Игн.' : 'Нет') }}
                                </span>
                            </td>
                            <td>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="#" @click.prevent="editEntry(entry, account)">
                                                <i class="fas fa-edit me-2"></i>Редактировать
                                            </a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" @click.prevent="deleteEntry(entry, account)">
                                                <i class="fas fa-trash me-2"></i>Удалить
                                            </a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Модальное окно добавления/редактирования записи -->
<div class="modal fade" id="entryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ editingEntry.id ? 'Редактировать запись' : 'Добавить запись' }}</h5>
                <button type="button" class="btn-close" @click="closeEntryModal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Ностро банк <span class="text-danger">*</span></label>
                        <select class="form-select" v-model="editingEntry.account_id" :disabled="!!editingEntry.id">
                            <option value="">— выберите —</option>
                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">L/S <span class="text-danger">*</span></label>
                        <select class="form-select" v-model="editingEntry.ls">
                            <option value="L">L — Ledger</option>
                            <option value="S">S — Statement</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">D/C <span class="text-danger">*</span></label>
                        <select class="form-select" v-model="editingEntry.dc">
                            <option value="Debit">Debit</option>
                            <option value="Credit">Credit</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Сумма <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" v-model="editingEntry.amount">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Валюта <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" v-model="editingEntry.currency" maxlength="3" placeholder="EUR">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Value Date</label>
                        <input type="date" class="form-control" v-model="editingEntry.value_date">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Post Date</label>
                        <input type="date" class="form-control" v-model="editingEntry.post_date">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Instruction ID</label>
                        <input type="text" class="form-control" v-model="editingEntry.instruction_id" maxlength="40">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">EndToEnd ID</label>
                        <input type="text" class="form-control" v-model="editingEntry.end_to_end_id" maxlength="40">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Transaction ID</label>
                        <input type="text" class="form-control" v-model="editingEntry.transaction_id" maxlength="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Message ID</label>
                        <input type="text" class="form-control" v-model="editingEntry.message_id" maxlength="40">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Комментарий</label>
                        <input type="text" class="form-control" v-model="editingEntry.comment" maxlength="40"
                               placeholder="до 40 символов">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="closeEntryModal">Отмена</button>
                <button type="button" class="btn btn-primary" @click="saveEntry">
                    {{ editingEntry.id ? 'Сохранить' : 'Добавить' }}
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Original content for other pages -->
<div v-if="!isAccountPage">
    <?= $content ?>
</div>