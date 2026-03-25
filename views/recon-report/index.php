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
                <i class="fas fa-print"></i> Печать / PDF
            </button>
            <button v-if="report" class="btn-action btn-primary-violet" @click="exportPdf">
                <i class="fas fa-file-pdf"></i> Скачать PDF
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         ПАНЕЛЬ ФИЛЬТРОВ (форма формирования)
    ══════════════════════════════════════ -->
    <div class="sm-card" style="margin-bottom:18px">
        <div class="sm-card-header">
            <i class="fas fa-sliders-h me-2" style="color:#4f46e5"></i>
            Параметры формирования ракорда
        </div>
        <div class="sm-card-body">
            <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">

                <!-- Пул / группа банков -->
                <div style="min-width:180px;flex:1">
                    <label class="form-label">Группа банков</label>
                    <select class="form-select" v-model="form.poolId" @change="onPoolChange">
                        <option value="">— Все счета —</option>
                        <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>

                <!-- Ностро счёт -->
                <div style="min-width:220px;flex:2">
                    <label class="form-label">Ностро банк / счёт <span style="color:#ef4444">*</span></label>
                    <select class="form-select" v-model="form.accountId" :disabled="filteredAccounts.length===0">
                        <option value="">— Выберите счёт —</option>
                        <option v-for="a in filteredAccounts" :key="a.id" :value="a.id">
                            {{ a.name }} ({{ a.currency }})
                        </option>
                    </select>
                </div>

                <!-- Дата ракорда -->
                <div style="min-width:160px">
                    <label class="form-label">Дата ракорда <span style="color:#ef4444">*</span></label>
                    <input type="date" class="form-control" v-model="form.dateRecon" :max="todayIso">
                </div>

                <!-- Кнопка -->
                <div>
                    <button class="btn-action btn-primary-violet"
                            @click="generateReport"
                            :disabled="loading || !form.accountId || !form.dateRecon"
                            style="height:38px;padding:0 20px">
                        <span v-if="loading"><i class="fas fa-spinner fa-spin me-1"></i>Формирование...</span>
                        <span v-else><i class="fas fa-play me-1"></i>Сформировать</span>
                    </button>
                </div>
            </div>

            <!-- Ошибки -->
            <div v-if="error" style="margin-top:12px;padding:10px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#dc2626;font-size:13px">
                <i class="fas fa-exclamation-triangle me-1"></i>{{ error }}
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         ОТЧЁТ
    ══════════════════════════════════════ -->
    <div v-if="report" id="recon-report-printable">

        <!-- ШАПКА ОТЧЁТА -->
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
                            <div><span style="color:#9ca3af;font-weight:600">Nostro Bank:</span>
                                <span style="font-weight:700;color:#1a1f36;margin-left:5px">{{ report.nostro_bank }}</span>
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
                        <div style="margin-top:3px"><span style="color:#9ca3af;font-weight:600">Account ID:</span>
                            <span style="font-family:monospace;font-weight:600;color:#6b7280;margin-left:5px">{{ report.account_id }}</span>
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
                        <th style="width:50%">Тип</th>
                        <th style="text-align:right">Сумма</th>
                        <th style="text-align:center;width:60px">D/C</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><span class="badge-ls badge-l">L</span> Ledger</td>
                        <td style="text-align:right;font-family:monospace;font-weight:700">
                            {{ report.closing_balance.ledger !== null ? fmtAmount(Math.abs(report.closing_balance.ledger)) : '—' }}
                        </td>
                        <td style="text-align:center">
                                <span v-if="report.closing_balance.ledger_dc" :class="report.closing_balance.ledger_dc==='D'?'dc-d':'dc-c'">
                                    {{ report.closing_balance.ledger_dc }}
                                </span>
                            <span v-else style="color:#9ca3af">—</span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge-ls badge-s">S</span> Statement</td>
                        <td style="text-align:right;font-family:monospace;font-weight:700">
                            {{ report.closing_balance.statement !== null ? fmtAmount(Math.abs(report.closing_balance.statement)) : '—' }}
                        </td>
                        <td style="text-align:center">
                                <span v-if="report.closing_balance.statement_dc" :class="report.closing_balance.statement_dc==='D'?'dc-d':'dc-c'">
                                    {{ report.closing_balance.statement_dc }}
                                </span>
                            <span v-else style="color:#9ca3af">—</span>
                        </td>
                    </tr>
                    <tr class="recon-diff-row" :class="report.closing_balance.difference===0?'diff-zero':Math.abs(report.closing_balance.difference||0)>0?'diff-nonzero':''">
                        <td><strong>Difference</strong></td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">
                            {{ report.closing_balance.difference !== null ? fmtAmountSigned(report.closing_balance.difference) : '—' }}
                        </td>
                        <td></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── OUTSTANDING ITEMS ── -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-header">
                <i class="fas fa-list-alt me-2" style="color:#f59e0b"></i>
                Outstanding Items
                <span style="margin-left:10px;font-size:11px;font-weight:500;color:#9ca3af">
                    (несквитованные записи за {{ fmtDate(prevDay) }} и {{ fmtDate(report.date_recon) }})
                </span>
            </div>
            <div class="sm-card-body" style="padding:0">

                <!-- Ledger Debit -->
                <div class="oi-section-header oi-ledger-debit">
                    <i class="fas fa-arrow-up me-1"></i>
                    Ledger — Debit
                    <span class="oi-count">{{ report.outstanding_items.ledger_debit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.ledger_debit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value Date</th>
                            <th>Instruction ID</th>
                            <th>EndToEnd ID</th>
                            <th>Transaction ID</th>
                            <th>Message ID</th>
                            <th>D/C</th>
                            <th style="text-align:right">Amount</th>
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
                        <tfoot>
                        <tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_ledger_debit) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет несквитованных Ledger Debit записей</div>

                <!-- Ledger Credit -->
                <div class="oi-section-header oi-ledger-credit">
                    <i class="fas fa-arrow-down me-1"></i>
                    Ledger — Credit
                    <span class="oi-count">{{ report.outstanding_items.ledger_credit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.ledger_credit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value Date</th>
                            <th>Instruction ID</th>
                            <th>EndToEnd ID</th>
                            <th>Transaction ID</th>
                            <th>Message ID</th>
                            <th>D/C</th>
                            <th style="text-align:right">Amount</th>
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
                        <tfoot>
                        <tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_ledger_credit) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет несквитованных Ledger Credit записей</div>

                <!-- Statement Debit -->
                <div class="oi-section-header oi-stmt-debit">
                    <i class="fas fa-arrow-up me-1"></i>
                    Statement — Debit
                    <span class="oi-count">{{ report.outstanding_items.stmt_debit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.stmt_debit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value Date</th>
                            <th>Instruction ID</th>
                            <th>EndToEnd ID</th>
                            <th>Transaction ID</th>
                            <th>Message ID</th>
                            <th>D/C</th>
                            <th style="text-align:right">Amount</th>
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
                        <tfoot>
                        <tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_stmt_debit) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет несквитованных Statement Debit записей</div>

                <!-- Statement Credit -->
                <div class="oi-section-header oi-stmt-credit">
                    <i class="fas fa-arrow-down me-1"></i>
                    Statement — Credit
                    <span class="oi-count">{{ report.outstanding_items.stmt_credit.length }} записей</span>
                </div>
                <div v-if="report.outstanding_items.stmt_credit.length>0" class="oi-table-wrap">
                    <table class="recon-entries-table">
                        <thead><tr>
                            <th>Value Date</th>
                            <th>Instruction ID</th>
                            <th>EndToEnd ID</th>
                            <th>Transaction ID</th>
                            <th>Message ID</th>
                            <th>D/C</th>
                            <th style="text-align:right">Amount</th>
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
                        <tfoot>
                        <tr class="oi-net-row">
                            <td colspan="6" style="font-weight:700;text-align:right">Net Amount:</td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmount(report.outstanding_items.net_stmt_credit) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-else class="oi-empty">Нет несквитованных Statement Credit записей</div>

                <!-- Outstanding Items Summary -->
                <div style="padding:16px 20px;border-top:2px solid #e5e9f2;background:#f9fafb">
                    <table class="recon-summary-table" style="margin:0">
                        <tbody>
                        <tr>
                            <td style="width:50%;padding:5px 0;color:#9ca3af;font-size:12px;font-weight:600">Ledger: Net Amount (D−C)</td>
                            <td style="text-align:right;font-family:monospace;font-weight:700">{{ fmtAmountSigned(report.outstanding_items.ledger_net_amount) }}</td>
                        </tr>
                        <tr>
                            <td style="padding:5px 0;color:#9ca3af;font-size:12px;font-weight:600">Statement: Net Amount (D−C)</td>
                            <td style="text-align:right;font-family:monospace;font-weight:700">{{ fmtAmountSigned(report.outstanding_items.stmt_net_amount) }}</td>
                        </tr>
                        <tr class="recon-diff-row" :class="report.outstanding_items.difference===0?'diff-zero':'diff-nonzero'">
                            <td style="padding:6px 0"><strong>Difference</strong></td>
                            <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.outstanding_items.difference) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
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
                        <th style="width:50%">Показатель</th>
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
                    <tr class="recon-diff-row" :class="report.trial_balance.difference===0?'diff-zero':'diff-nonzero'">
                        <td><strong>Trial Balance</strong></td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.trial_balance.ledger) }}</td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.trial_balance.statement) }}</td>
                        <td style="text-align:right;font-family:monospace;font-weight:800">{{ fmtAmountSigned(report.trial_balance.difference) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── ИТОГИ (Ledger/Statement Total Amount) ── -->
        <div class="sm-card" style="margin-bottom:14px">
            <div class="sm-card-header">
                <i class="fas fa-sigma me-2" style="color:#7c3aed"></i>
                Итого
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
        <div style="font-size:15px;font-weight:600;margin-bottom:6px">Ракорд не сформирован</div>
        <div style="font-size:13px">Выберите счёт и дату, затем нажмите «Сформировать»</div>
    </div>

</div><!-- /recon-app -->


<!-- ══════════════════════════════════════
     Vue2 Script
══════════════════════════════════════ -->
<script>
    (function () {
        var _init = <?= $initJson ?>;

        document.addEventListener('DOMContentLoaded', function () {
            // CSRF
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
                        poolId:    '',
                        accountId: '',
                        dateRecon: (function () {
                            var d = new Date();
                            d.setDate(d.getDate() - 1);
                            return d.toISOString().slice(0, 10);
                        }()),
                    },
                    report:  null,
                    loading: false,
                    error:   null,
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

                    generateReport: function () {
                        var self = this;
                        if (!this.form.accountId || !this.form.dateRecon) return;
                        this.loading = true;
                        this.error   = null;
                        this.report  = null;

                        axios.post('<?= Url::to(['/recon-report/generate']) ?>', {
                            account_id: this.form.accountId,
                            date_recon: this.form.dateRecon,
                        })
                            .then(function (resp) {
                                var data = resp.data;
                                if (data.success) {
                                    self.report = data.report;
                                    self.$nextTick(function () {
                                        self.scrollToReport();
                                    });
                                } else {
                                    self.error = data.message || 'Ошибка формирования отчёта';
                                }
                            })
                            .catch(function (err) {
                                self.error = 'Ошибка сети: ' + (err.message || 'неизвестная ошибка');
                            })
                            .finally(function () {
                                self.loading = false;
                            });
                    },

                    scrollToReport: function () {
                        var el = document.getElementById('recon-report-printable');
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    },

                    printReport: function () {
                        window.print();
                    },

                    exportPdf: function () {
                        // Браузерная печать в PDF (нет сервера PDF) — открыть диалог печати
                        // с предложением сохранить как PDF
                        window.print();
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