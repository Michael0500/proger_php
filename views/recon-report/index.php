<?php

use yii\helpers\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var array $initData */

$this->title = 'Раккорд — Reconciliation Report';

$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="recon-app">

    <!-- ══════════════════════════════════════
         TOOLBAR
    ══════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-file-alt" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Reconciliation Report</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">Раккорд — NRE</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
            <button v-if="report" class="btn-action btn-outline-secondary" @click="printReport" title="Печать / PDF">
                <i class="fas fa-print"></i> Печать
            </button>
            <button v-if="report" class="btn-action btn-primary-violet" @click="exportPdf" :disabled="exporting">
                <span v-if="exporting"><i class="fas fa-spinner fa-spin me-1"></i>Генерация...</span>
                <span v-else><i class="fas fa-file-pdf me-1"></i> Скачать PDF</span>
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         ПАНЕЛЬ ФИЛЬТРОВ
    ══════════════════════════════════════ -->
    <div class="sm-card" style="margin-bottom:18px">
        <div class="sm-card-header">
            <i class="fas fa-sliders-h me-2" style="color:#4f46e5"></i>
            Параметры формирования раккорда
        </div>
        <div class="sm-card-body">
            <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">

                <!-- Ностро-банк -->
                <div style="min-width:180px;flex:1">
                    <label class="form-label">Ностро-банк</label>
                    <select class="form-select" v-model="form.poolId" @change="onPoolChange">
                        <option value="">— Все счета —</option>
                        <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>

                <!-- Счёт -->
                <div style="min-width:220px;flex:2">
                    <label class="form-label">Счёт <span style="color:#ef4444">*</span></label>
                    <select class="form-select" v-model="form.accountId" :disabled="filteredAccounts.length===0">
                        <option value="">— Выберите счёт —</option>
                        <option v-for="a in filteredAccounts" :key="a.id" :value="a.id">
                            {{ a.name }} ({{ a.currency }})
                        </option>
                    </select>
                </div>

                <!-- Дата раккорда -->
                <div style="min-width:150px">
                    <label class="form-label">Дата раккорда <span style="color:#ef4444">*</span></label>
                    <input type="date" class="form-control" v-model="form.dateRecon" :max="todayIso">
                </div>

                <!-- Режим периода -->
                <div style="min-width:120px">
                    <label class="form-label">Режим</label>
                    <select class="form-select" v-model="form.periodMode">
                        <option value="auto">Авто (предыдущий + текущий)</option>
                        <option value="custom">Произвольный период</option>
                    </select>
                </div>
            </div>

            <!-- Произвольный период -->
            <div v-if="form.periodMode==='custom'" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-top:12px">
                <div style="min-width:150px">
                    <label class="form-label">Период с</label>
                    <input type="date" class="form-control" v-model="form.dateFrom" :max="form.dateTo||todayIso">
                </div>
                <div style="min-width:150px">
                    <label class="form-label">Период по</label>
                    <input type="date" class="form-control" v-model="form.dateTo" :min="form.dateFrom" :max="todayIso">
                </div>
            </div>

            <div style="margin-top:14px">
                <button class="btn-action btn-primary-violet"
                        @click="generateReport"
                        :disabled="loading || !form.accountId || !form.dateRecon"
                        style="height:38px;padding:0 20px">
                    <span v-if="loading"><i class="fas fa-spinner fa-spin me-1"></i>Формирование...</span>
                    <span v-else><i class="fas fa-play me-1"></i>Сформировать</span>
                </button>
            </div>

            <div v-if="error" style="margin-top:12px;padding:10px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#dc2626;font-size:13px">
                <i class="fas fa-exclamation-triangle me-1"></i>{{ error }}
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         ОТЧЁТ
    ══════════════════════════════════════ -->
    <div v-if="report" id="recon-report-printable">

        <!-- ШАПКА -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-body" style="padding:20px 24px">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
                    <div>
                        <div style="font-size:22px;font-weight:900;color:#1a1f36;letter-spacing:-.5px;margin-bottom:6px">
                            Reconciliation Report
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:16px;font-size:13px">
                            <div><span style="color:#9ca3af;font-weight:600">Company:</span>
                                <span style="font-weight:700;color:#4f46e5;margin-left:5px">{{ report.company }}</span>
                            </div>
                            <div><span style="color:#9ca3af;font-weight:600">Nosto Bank:</span>
                                <span style="font-weight:700;color:#1a1f36;margin-left:5px">{{ report.nostro_bank }}</span>
                            </div>
                            <div><span style="color:#9ca3af;font-weight:600">Account:</span>
                                <span style="font-weight:700;color:#1a1f36;margin-left:5px">{{ report.account_name }}</span>
                            </div>
                            <div><span style="color:#9ca3af;font-weight:600">Currency:</span>
                                <span style="font-weight:700;color:#1a1f36;margin-left:5px">{{ report.currency }}</span>
                            </div>
                        </div>
                    </div>
                    <div style="text-align:right;font-size:13px">
                        <div><span style="color:#9ca3af;font-weight:600">Date:</span>
                            <span style="font-weight:700;color:#1a1f36;margin-left:5px">{{ fmtDateTime(report.generated_at) }}</span>
                        </div>
                        <div style="margin-top:3px"><span style="color:#9ca3af;font-weight:600">Date Reconciliation:</span>
                            <span style="font-weight:700;color:#1a1f36;margin-left:5px">{{ fmtDate(report.date_recon) }}</span>
                        </div>
                        <div v-if="report.date_from && report.date_to" style="margin-top:3px">
                            <span style="color:#9ca3af;font-weight:600">Period:</span>
                            <span style="font-weight:700;color:#1a1f36;margin-left:5px">{{ fmtDate(report.date_from) }} — {{ fmtDate(report.date_to) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CLOSING BALANCE ── -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-header">
                <i class="fas fa-balance-scale me-2" style="color:#059669"></i>
                Closing Balance
            </div>
            <div class="sm-card-body" style="padding:0">
                <table class="recon-summary-table">
                    <thead>
                    <tr>
                        <th style="width:50%">Type</th>
                        <th style="text-align:right">Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><span class="badge-ls badge-l">L</span> Ledger</td>
                        <td style="text-align:right;font-family:monospace;font-weight:700">
                            {{ report.closing_balance.ledger !== null ? fmtAmountSigned(report.closing_balance.ledger) : '—' }}
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge-ls badge-s">S</span> Statement</td>
                        <td style="text-align:right;font-family:monospace;font-weight:700">
                            {{ report.closing_balance.statement !== null ? fmtAmountSigned(report.closing_balance.statement) : '—' }}
                        </td>
                    </tr>
                    <tr class="recon-diff-row" :class="diffClass(report.closing_balance.difference)">
                        <td><strong>Difference</strong></td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">
                            {{ report.closing_balance.difference !== null ? fmtAmountSigned(report.closing_balance.difference) : '—' }}
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── OUTSTANDING ITEMS summary ── -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-header">
                <i class="fas fa-list-alt me-2" style="color:#f59e0b"></i>
                Outstanding Items
                <span style="margin-left:10px;font-size:11px;font-weight:500;color:#9ca3af">
                    <template v-if="report.date_from && report.date_to">({{ fmtDate(report.date_from) }} — {{ fmtDate(report.date_to) }})</template>
                    <template v-else>({{ fmtDate(prevDay) }} и {{ fmtDate(report.date_recon) }})</template>
                </span>
            </div>
            <div class="sm-card-body" style="padding:0">
                <table class="recon-summary-table">
                    <thead><tr><th style="width:50%">Type</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                    <tr><td><span class="badge-ls badge-l">L</span> Ledger</td><td style="text-align:right;font-family:monospace;font-weight:700">{{ fmtAmountSigned(report.outstanding_items.ledger) }}</td></tr>
                    <tr><td><span class="badge-ls badge-s">S</span> Statement</td><td style="text-align:right;font-family:monospace;font-weight:700">{{ fmtAmountSigned(report.outstanding_items.statement) }}</td></tr>
                    <tr class="recon-diff-row" :class="diffClass(report.outstanding_items.difference)"><td><strong>Difference</strong></td><td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.outstanding_items.difference) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── TRIAL BALANCE ── -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-header">
                <i class="fas fa-calculator me-2" style="color:#4f46e5"></i>
                Trial Balance
            </div>
            <div class="sm-card-body" style="padding:0">
                <table class="recon-summary-table">
                    <thead>
                    <tr>
                        <th style="width:40%">Indicator</th>
                        <th style="text-align:right">Ledger</th>
                        <th style="text-align:right">Statement</th>
                        <th style="text-align:right">Difference</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr style="font-size:11px;color:#9ca3af">
                        <td>Closing Balance</td>
                        <td style="text-align:right;font-family:monospace">{{ report.closing_balance.ledger!==null ? fmtAmountSigned(report.closing_balance.ledger) : '—' }}</td>
                        <td style="text-align:right;font-family:monospace">{{ report.closing_balance.statement!==null ? fmtAmountSigned(report.closing_balance.statement) : '—' }}</td>
                        <td style="text-align:right;font-family:monospace">{{ report.closing_balance.difference!==null ? fmtAmountSigned(report.closing_balance.difference) : '—' }}</td>
                    </tr>
                    <tr style="font-size:11px;color:#9ca3af">
                        <td>+ Outstanding Items</td>
                        <td style="text-align:right;font-family:monospace">{{ fmtAmountSigned(report.outstanding_items.ledger) }}</td>
                        <td style="text-align:right;font-family:monospace">{{ fmtAmountSigned(report.outstanding_items.statement) }}</td>
                        <td style="text-align:right;font-family:monospace">{{ fmtAmountSigned(report.outstanding_items.difference) }}</td>
                    </tr>
                    <tr class="recon-diff-row" :class="diffClass(report.trial_balance.difference)">
                        <td><strong>Trial Balance</strong></td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.trial_balance.ledger) }}</td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.trial_balance.statement) }}</td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.trial_balance.difference) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── ДЕТАЛИЗАЦИЯ: Outstanding Items ── -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-header">
                <i class="fas fa-th-list me-2" style="color:#6366f1"></i>
                Outstanding Items — Detail
            </div>
            <div class="sm-card-body" style="padding:0">

                <!-- Ledger-Debit -->
                <div class="oi-section-header oi-ledger-debit">
                    <i class="fas fa-arrow-up me-1"></i>
                    Ledger-Debit (Outstanding Items)
                    <span class="oi-count">{{ report.outstanding_items.ledger_debit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.ledger_debit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value</th><th>Instruction_ID</th><th>EndToEnd_ID</th><th>Transaction_ID</th><th>Message_ID</th><th>D/C Mark</th><th style="text-align:right">Amount</th>
                        </tr></thead>
                        <tbody>
                        <tr v-for="(r,i) in report.outstanding_items.ledger_debit" :key="i">
                            <td style="white-space:nowrap">{{ fmtDate(r.value) }}</td>
                            <td class="td-mono-sm">{{ r.instruction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.end_to_end_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.transaction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.message_id||'—' }}</td>
                            <td><span class="dc-d">D</span></td>
                            <td style="text-align:right;font-family:monospace;font-weight:600">{{ fmtAmount(r.amount) }}</td>
                        </tr>
                        </tbody>
                        <tfoot><tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_ledger_debit) }}</td>
                        </tr></tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет записей</div>

                <!-- Ledger-Credit -->
                <div class="oi-section-header oi-ledger-credit">
                    <i class="fas fa-arrow-down me-1"></i>
                    Ledger-Credit (Outstanding Items)
                    <span class="oi-count">{{ report.outstanding_items.ledger_credit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.ledger_credit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value</th><th>Instruction_ID</th><th>EndToEnd_ID</th><th>Transaction_ID</th><th>Message_ID</th><th>D/C Mark</th><th style="text-align:right">Amount</th>
                        </tr></thead>
                        <tbody>
                        <tr v-for="(r,i) in report.outstanding_items.ledger_credit" :key="i">
                            <td style="white-space:nowrap">{{ fmtDate(r.value) }}</td>
                            <td class="td-mono-sm">{{ r.instruction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.end_to_end_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.transaction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.message_id||'—' }}</td>
                            <td><span class="dc-c">C</span></td>
                            <td style="text-align:right;font-family:monospace;font-weight:600">{{ fmtAmount(r.amount) }}</td>
                        </tr>
                        </tbody>
                        <tfoot><tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_ledger_credit) }}</td>
                        </tr></tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет записей</div>

                <!-- Ledger: Net Amount -->
                <div style="padding:10px 20px;border-top:2px solid #e5e9f2;background:#eef2ff">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <strong style="font-size:13px;color:#4338ca">Ledger: Net Amount</strong>
                        <span style="font-family:monospace;font-weight:800;font-size:14px;color:#4338ca">{{ fmtAmountSigned(report.outstanding_items.ledger_net_amount) }}</span>
                    </div>
                </div>

                <!-- Statement-Debit -->
                <div class="oi-section-header oi-stmt-debit">
                    <i class="fas fa-arrow-up me-1"></i>
                    Statement-Debit (Outstanding Items)
                    <span class="oi-count">{{ report.outstanding_items.stmt_debit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.stmt_debit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value</th><th>Instruction_ID</th><th>EndToEnd_ID</th><th>Transaction_ID</th><th>Message_ID</th><th>D/C Mark</th><th style="text-align:right">Amount</th>
                        </tr></thead>
                        <tbody>
                        <tr v-for="(r,i) in report.outstanding_items.stmt_debit" :key="i">
                            <td style="white-space:nowrap">{{ fmtDate(r.value) }}</td>
                            <td class="td-mono-sm">{{ r.instruction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.end_to_end_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.transaction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.message_id||'—' }}</td>
                            <td><span class="dc-d">D</span></td>
                            <td style="text-align:right;font-family:monospace;font-weight:600">{{ fmtAmount(r.amount) }}</td>
                        </tr>
                        </tbody>
                        <tfoot><tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_stmt_debit) }}</td>
                        </tr></tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет записей</div>

                <!-- Statement-Credit -->
                <div class="oi-section-header oi-stmt-credit">
                    <i class="fas fa-arrow-down me-1"></i>
                    Statement-Credit (Outstanding Items)
                    <span class="oi-count">{{ report.outstanding_items.stmt_credit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.stmt_credit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value</th><th>Instruction_ID</th><th>EndToEnd_ID</th><th>Transaction_ID</th><th>Message_ID</th><th>D/C Mark</th><th style="text-align:right">Amount</th>
                        </tr></thead>
                        <tbody>
                        <tr v-for="(r,i) in report.outstanding_items.stmt_credit" :key="i">
                            <td style="white-space:nowrap">{{ fmtDate(r.value) }}</td>
                            <td class="td-mono-sm">{{ r.instruction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.end_to_end_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.transaction_id||'—' }}</td>
                            <td class="td-mono-sm">{{ r.message_id||'—' }}</td>
                            <td><span class="dc-c">C</span></td>
                            <td style="text-align:right;font-family:monospace;font-weight:600">{{ fmtAmount(r.amount) }}</td>
                        </tr>
                        </tbody>
                        <tfoot><tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_stmt_credit) }}</td>
                        </tr></tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет записей</div>

                <!-- Statement: Net Amount -->
                <div style="padding:10px 20px;border-top:2px solid #e5e9f2;background:#eef2ff">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <strong style="font-size:13px;color:#4338ca">Statement: Net Amount</strong>
                        <span style="font-family:monospace;font-weight:800;font-size:14px;color:#4338ca">{{ fmtAmountSigned(report.outstanding_items.stmt_net_amount) }}</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── LEDGER/STATEMENT TOTAL AMOUNT ── -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-header">
                <i class="fas fa-sigma me-2" style="color:#7c3aed"></i>
                Ledger/Statement Total Amount
            </div>
            <div class="sm-card-body" style="padding:0">
                <table class="recon-summary-table">
                    <tbody>
                    <tr>
                        <td style="width:50%">Ledger: Net Amount</td>
                        <td style="text-align:right;font-family:monospace;font-weight:700">{{ fmtAmountSigned(report.totals.ledger_net_amount) }}</td>
                    </tr>
                    <tr>
                        <td>Statement: Net Amount</td>
                        <td style="text-align:right;font-family:monospace;font-weight:700">{{ fmtAmountSigned(report.totals.statement_net_amount) }}</td>
                    </tr>
                    <tr style="background:#f0fdf4;font-weight:800;font-size:15px">
                        <td><strong>Ledger/Statement Total Amount</strong></td>
                        <td style="text-align:right;font-family:monospace;font-weight:900;font-size:16px;color:#059669">
                            {{ fmtAmountSigned(report.totals.total_amount) }}
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Имя файла PDF -->
        <div style="padding:10px 16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e9f2;font-size:11px;color:#9ca3af;display:flex;align-items:center;gap:8px">
            <i class="fas fa-info-circle" style="color:#4f46e5"></i>
            <span>Имя файла PDF: <strong style="color:#1a1f36;font-family:monospace">{{ pdfFilename }}</strong></span>
        </div>

    </div><!-- /recon-report-printable -->

    <!-- Пустой стейт -->
    <div v-if="!report && !loading" style="text-align:center;padding:60px 20px;color:#9ca3af">
        <i class="fas fa-file-contract" style="font-size:48px;margin-bottom:16px;opacity:.3"></i>
        <div style="font-size:15px;font-weight:600;margin-bottom:6px">Раккорд не сформирован</div>
        <div style="font-size:13px">Выберите счёт и дату, затем нажмите «Сформировать»</div>
    </div>

</div><!-- /recon-app -->


<!-- jsPDF + autoTable для клиентской генерации PDF -->
<script src="<?= Yii::getAlias('@web') ?>/js/jspdf.umd.min.js"></script>
<script src="<?= Yii::getAlias('@web') ?>/js/jspdf.plugin.autotable.min.js"></script>

<!-- ══════════════════════════════════════
     Vue2 Script
══════════════════════════════════════ -->
<script>
    (function () {
        var _init = <?= $initJson ?>;

        document.addEventListener('DOMContentLoaded', function () {
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
            }
            axios.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded';
            axios.defaults.transformRequest = [function (data) {
                if (data && typeof data === 'object') {
                    return Object.keys(data).map(function (k) {
                        var v = data[k];
                        if (v === null || v === undefined) v = '';
                        return encodeURIComponent(k) + '=' + encodeURIComponent(v);
                    }).join('&');
                }
                return data;
            }];

            new Vue({
                el: '#recon-app',

                data: {
                    pools:    _init.pools    || [],
                    accounts: _init.accounts || [],
                    form: {
                        poolId:     '',
                        accountId:  '',
                        dateRecon:  (function () {
                            var d = new Date();
                            return d.toISOString().slice(0, 10);
                        }()),
                        periodMode: 'auto',
                        dateFrom:   '',
                        dateTo:     '',
                    },
                    report:    null,
                    loading:   false,
                    exporting: false,
                    error:     null,
                },

                computed: {
                    todayIso: function () {
                        return new Date().toISOString().slice(0, 10);
                    },
                    filteredAccounts: function () {
                        if (!this.form.poolId) return this.accounts;
                        var pid = parseInt(this.form.poolId);
                        return this.accounts.filter(function (a) { return a.pool_id === pid; });
                    },
                    prevDay: function () {
                        if (!this.report) return '';
                        var d = new Date(this.report.date_recon);
                        d.setDate(d.getDate() - 1);
                        return d.toISOString().slice(0, 10);
                    },
                    pdfFilename: function () {
                        if (!this.report) return '';
                        var dt = this.report.date_recon.split('-').reverse().join('.');
                        return 'ReconReport_' + this.report.nostro_bank + '_' + dt + '.pdf';
                    },
                },

                methods: {
                    onPoolChange: function () {
                        this.form.accountId = '';
                    },

                    _buildPayload: function () {
                        var payload = {
                            account_id: this.form.accountId,
                            date_recon: this.form.dateRecon,
                        };
                        if (this.form.periodMode === 'custom' && this.form.dateFrom && this.form.dateTo) {
                            payload.date_from = this.form.dateFrom;
                            payload.date_to   = this.form.dateTo;
                        }
                        return payload;
                    },

                    generateReport: function () {
                        var self = this;
                        if (!this.form.accountId || !this.form.dateRecon) return;
                        this.loading = true;
                        this.error   = null;
                        this.report  = null;

                        axios.post('<?= Url::to(['/recon-report/generate']) ?>', this._buildPayload())
                            .then(function (resp) {
                                if (resp.data.success) {
                                    self.report = resp.data.report;
                                    self.$nextTick(function () { self.scrollToReport(); });
                                } else {
                                    self.error = resp.data.message || 'Ошибка формирования отчёта';
                                }
                            })
                            .catch(function (err) {
                                self.error = 'Ошибка сети: ' + (err.message || 'неизвестная ошибка');
                            })
                            .finally(function () { self.loading = false; });
                    },

                    scrollToReport: function () {
                        var el = document.getElementById('recon-report-printable');
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    },

                    printReport: function () {
                        window.print();
                    },

                    exportPdf: function () {
                        var self = this;
                        var r = self.report;
                        if (!r) return;
                        self.exporting = true;

                        try {
                            var jsPDF = window.jspdf.jsPDF;
                            var doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
                            var pageW = doc.internal.pageSize.getWidth();
                            var y = 15;

                            // ── Заголовок ──
                            doc.setFontSize(18);
                            doc.setFont(undefined, 'bold');
                            doc.text('Reconciliation Report', 14, y);
                            y += 8;

                            doc.setFontSize(9);
                            doc.setFont(undefined, 'normal');
                            var meta = [
                                ['Company: ' + r.company, 'Date: ' + self.fmtDateTime(r.generated_at)],
                                ['Nosto Bank: ' + r.nostro_bank, 'Date Reconciliation: ' + self.fmtDate(r.date_recon)],
                                ['Account: ' + r.account_name + ' (' + (r.currency || '') + ')',
                                    (r.date_from && r.date_to) ? 'Period: ' + self.fmtDate(r.date_from) + ' - ' + self.fmtDate(r.date_to) : ''],
                            ];
                            meta.forEach(function (row) {
                                doc.text(row[0], 14, y);
                                doc.text(row[1], pageW / 2, y);
                                y += 4.5;
                            });
                            y += 4;

                            // ── Closing Balance ──
                            doc.setFontSize(11);
                            doc.setFont(undefined, 'bold');
                            doc.text('Closing Balance', 14, y);
                            y += 2;

                            doc.autoTable({
                                startY: y,
                                head: [['Type', 'Amount']],
                                body: [
                                    ['Ledger', self.fmtAmountSigned(r.closing_balance.ledger)],
                                    ['Statement', self.fmtAmountSigned(r.closing_balance.statement)],
                                    ['Difference', self.fmtAmountSigned(r.closing_balance.difference)],
                                ],
                                styles: { fontSize: 8, cellPadding: 2 },
                                headStyles: { fillColor: [79, 70, 229] },
                                columnStyles: { 1: { halign: 'right', fontStyle: 'bold' } },
                                margin: { left: 14, right: 14 },
                                theme: 'grid',
                                didParseCell: function (data) {
                                    if (data.row.index === 2) { data.cell.styles.fillColor = [240, 242, 245]; data.cell.styles.fontStyle = 'bold'; }
                                },
                            });
                            y = doc.lastAutoTable.finalY + 6;

                            // ── Outstanding Items Summary ──
                            doc.setFontSize(11);
                            doc.setFont(undefined, 'bold');
                            doc.text('Outstanding Items', 14, y);
                            y += 2;

                            doc.autoTable({
                                startY: y,
                                head: [['Type', 'Amount']],
                                body: [
                                    ['Ledger', self.fmtAmountSigned(r.outstanding_items.ledger)],
                                    ['Statement', self.fmtAmountSigned(r.outstanding_items.statement)],
                                    ['Difference', self.fmtAmountSigned(r.outstanding_items.difference)],
                                ],
                                styles: { fontSize: 8, cellPadding: 2 },
                                headStyles: { fillColor: [245, 158, 11] },
                                columnStyles: { 1: { halign: 'right', fontStyle: 'bold' } },
                                margin: { left: 14, right: 14 },
                                theme: 'grid',
                                didParseCell: function (data) {
                                    if (data.row.index === 2) { data.cell.styles.fillColor = [240, 242, 245]; data.cell.styles.fontStyle = 'bold'; }
                                },
                            });
                            y = doc.lastAutoTable.finalY + 6;

                            // ── Trial Balance ──
                            doc.setFontSize(11);
                            doc.setFont(undefined, 'bold');
                            doc.text('Trial Balance', 14, y);
                            y += 2;

                            doc.autoTable({
                                startY: y,
                                head: [['Indicator', 'Ledger', 'Statement', 'Difference']],
                                body: [
                                    ['Closing Balance', self.fmtAmountSigned(r.closing_balance.ledger), self.fmtAmountSigned(r.closing_balance.statement), self.fmtAmountSigned(r.closing_balance.difference)],
                                    ['+ Outstanding Items', self.fmtAmountSigned(r.outstanding_items.ledger), self.fmtAmountSigned(r.outstanding_items.statement), self.fmtAmountSigned(r.outstanding_items.difference)],
                                    ['Trial Balance', self.fmtAmountSigned(r.trial_balance.ledger), self.fmtAmountSigned(r.trial_balance.statement), self.fmtAmountSigned(r.trial_balance.difference)],
                                ],
                                styles: { fontSize: 8, cellPadding: 2 },
                                headStyles: { fillColor: [79, 70, 229] },
                                columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' }, 3: { halign: 'right' } },
                                margin: { left: 14, right: 14 },
                                theme: 'grid',
                                didParseCell: function (data) {
                                    if (data.row.index === 2) { data.cell.styles.fillColor = [240, 242, 245]; data.cell.styles.fontStyle = 'bold'; }
                                },
                            });
                            y = doc.lastAutoTable.finalY + 8;

                            // ── Функция для таблицы записей ──
                            var entryCols = ['Value', 'Instruction_ID', 'EndToEnd_ID', 'Transaction_ID', 'Message_ID', 'D/C Mark', 'Amount'];
                            var entryRow = function (e) {
                                return [
                                    self.fmtDate(e.value), e.instruction_id || '-', e.end_to_end_id || '-',
                                    e.transaction_id || '-', e.message_id || '-', e.dc || '-', self.fmtAmount(e.amount)
                                ];
                            };

                            var sections = [
                                { title: 'Ledger-Debit (Outstanding Items)', data: r.outstanding_items.ledger_debit, net: r.outstanding_items.net_ledger_debit, color: [220, 38, 38] },
                                { title: 'Ledger-Credit (Outstanding Items)', data: r.outstanding_items.ledger_credit, net: r.outstanding_items.net_ledger_credit, color: [5, 150, 105] },
                            ];

                            // Добавляем новую страницу для детализации
                            doc.addPage();
                            y = 15;

                            doc.setFontSize(13);
                            doc.setFont(undefined, 'bold');
                            doc.text('Outstanding Items - Detail', 14, y);
                            y += 8;

                            sections.forEach(function (sec) {
                                if (y > 170) { doc.addPage(); y = 15; }
                                doc.setFontSize(10);
                                doc.setFont(undefined, 'bold');
                                doc.text(sec.title, 14, y);
                                y += 2;

                                var body = sec.data.map(entryRow);
                                body.push([{ content: 'Net Amount:', colSpan: 6, styles: { halign: 'right', fontStyle: 'bold' } }, self.fmtAmount(sec.net)]);

                                doc.autoTable({
                                    startY: y,
                                    head: [entryCols],
                                    body: body,
                                    styles: { fontSize: 7, cellPadding: 1.5 },
                                    headStyles: { fillColor: sec.color },
                                    columnStyles: { 6: { halign: 'right', fontStyle: 'bold' } },
                                    margin: { left: 14, right: 14 },
                                    theme: 'grid',
                                });
                                y = doc.lastAutoTable.finalY + 4;
                            });

                            // Ledger: Net Amount
                            doc.setFontSize(10);
                            doc.setFont(undefined, 'bold');
                            doc.setTextColor(67, 56, 202);
                            doc.text('Ledger: Net Amount = ' + self.fmtAmountSigned(r.outstanding_items.ledger_net_amount), 14, y + 2);
                            doc.setTextColor(0, 0, 0);
                            y += 10;

                            // Statement sections
                            var stmtSections = [
                                { title: 'Statement-Debit (Outstanding Items)', data: r.outstanding_items.stmt_debit, net: r.outstanding_items.net_stmt_debit, color: [220, 38, 38] },
                                { title: 'Statement-Credit (Outstanding Items)', data: r.outstanding_items.stmt_credit, net: r.outstanding_items.net_stmt_credit, color: [5, 150, 105] },
                            ];

                            stmtSections.forEach(function (sec) {
                                if (y > 170) { doc.addPage(); y = 15; }
                                doc.setFontSize(10);
                                doc.setFont(undefined, 'bold');
                                doc.text(sec.title, 14, y);
                                y += 2;

                                var body = sec.data.map(entryRow);
                                body.push([{ content: 'Net Amount:', colSpan: 6, styles: { halign: 'right', fontStyle: 'bold' } }, self.fmtAmount(sec.net)]);

                                doc.autoTable({
                                    startY: y,
                                    head: [entryCols],
                                    body: body,
                                    styles: { fontSize: 7, cellPadding: 1.5 },
                                    headStyles: { fillColor: sec.color },
                                    columnStyles: { 6: { halign: 'right', fontStyle: 'bold' } },
                                    margin: { left: 14, right: 14 },
                                    theme: 'grid',
                                });
                                y = doc.lastAutoTable.finalY + 4;
                            });

                            // Statement: Net Amount
                            doc.setFontSize(10);
                            doc.setFont(undefined, 'bold');
                            doc.setTextColor(67, 56, 202);
                            doc.text('Statement: Net Amount = ' + self.fmtAmountSigned(r.outstanding_items.stmt_net_amount), 14, y + 2);
                            doc.setTextColor(0, 0, 0);
                            y += 10;

                            // ── Ledger/Statement Total Amount ──
                            if (y > 170) { doc.addPage(); y = 15; }
                            doc.setFontSize(11);
                            doc.setFont(undefined, 'bold');
                            doc.text('Ledger/Statement Total Amount', 14, y);
                            y += 2;

                            doc.autoTable({
                                startY: y,
                                body: [
                                    ['Ledger: Net Amount', self.fmtAmountSigned(r.totals.ledger_net_amount)],
                                    ['Statement: Net Amount', self.fmtAmountSigned(r.totals.statement_net_amount)],
                                    ['Ledger/Statement Total Amount', self.fmtAmountSigned(r.totals.total_amount)],
                                ],
                                styles: { fontSize: 9, cellPadding: 3 },
                                columnStyles: { 1: { halign: 'right', fontStyle: 'bold' } },
                                margin: { left: 14, right: 14 },
                                theme: 'grid',
                                didParseCell: function (data) {
                                    if (data.row.index === 2) { data.cell.styles.fillColor = [232, 245, 233]; data.cell.styles.fontStyle = 'bold'; data.cell.styles.fontSize = 11; }
                                },
                            });

                            doc.save(self.pdfFilename);
                        } catch (e) {
                            Swal.fire({ icon: 'error', title: 'Ошибка PDF', text: e.message || 'Не удалось сгенерировать PDF' });
                        } finally {
                            self.exporting = false;
                        }
                    },

                    diffClass: function (val) {
                        if (val === null || val === undefined) return '';
                        return val === 0 ? 'diff-zero' : 'diff-nonzero';
                    },

                    fmtDate: function (val) {
                        if (!val) return '—';
                        var parts = val.split('-');
                        if (parts.length === 3) return parts[2] + '.' + parts[1] + '.' + parts[0];
                        return val;
                    },

                    fmtDateTime: function (val) {
                        if (!val) return '—';
                        var dt = new Date(val.replace(' ', 'T'));
                        if (isNaN(dt)) return val;
                        var pad = function (n) { return String(n).padStart(2, '0'); };
                        return pad(dt.getDate()) + '.' + pad(dt.getMonth() + 1) + '.' + dt.getFullYear()
                            + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ':' + pad(dt.getSeconds());
                    },

                    fmtAmount: function (val) {
                        if (val === null || val === undefined) return '—';
                        var n = parseFloat(val);
                        if (isNaN(n)) return '—';
                        return n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },

                    fmtAmountSigned: function (val) {
                        if (val === null || val === undefined) return '—';
                        var n = parseFloat(val);
                        if (isNaN(n)) return '—';
                        var sign = n < 0 ? '−' : (n > 0 ? '+' : '');
                        return sign + Math.abs(n).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
                },
            });
        });
    }());
</script>
