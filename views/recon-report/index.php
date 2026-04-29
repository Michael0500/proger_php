<?php

use yii\helpers\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var array $initData */

$this->title = 'Раккорд — Reconciliation Report';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="recon-app" v-cloak>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <div class="h4 mb-1">Reconciliation Report</div>
            <div class="text-muted small">Раккорд — NRE</div>
        </div>
        <div v-if="reports.length > 1" class="d-flex gap-2">
            <a class="btn-action btn-outline-secondary" :href="exportUrl('xlsx')" target="_blank">
                <i class="fas fa-file-excel me-1"></i> XLSX ZIP
            </a>
            <a class="btn-action btn-outline-secondary" :href="exportUrl('pdf')" target="_blank">
                <i class="fas fa-file-pdf me-1"></i> PDF ZIP
            </a>
        </div>
    </div>

    <div class="sm-card mb-3">
        <div class="sm-card-header">
            <i class="fas fa-sliders-h me-2"></i>Параметры формирования раккорда
        </div>
        <div class="sm-card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Категория</label>
                    <select class="form-select" v-model="form.categoryId" :disabled="!!form.poolId">
                        <option value="">Не выбрана</option>
                        <option v-for="category in init.categories" :key="category.id" :value="String(category.id)">
                            {{ category.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ностро-банк</label>
                    <select class="form-select" v-model="form.poolId" :disabled="!!form.categoryId">
                        <option value="">Не выбран</option>
                        <option v-for="pool in init.pools" :key="pool.id" :value="String(pool.id)">
                            {{ pool.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Режим</label>
                    <select class="form-select" v-model="form.periodMode">
                        <option value="auto">Авто (предедущий + текущий)</option>
                        <option value="custom">Произвольный период</option>
                    </select>
                </div>
                <div class="col-md-2" v-if="form.periodMode === 'auto'">
                    <label class="form-label">Дата раккорда</label>
                    <input type="text" v-datepicker class="form-control" v-model="form.dateRecon" :max="todayIso">
                </div>
            </div>

            <div class="row g-3 align-items-end mt-1" v-if="form.periodMode === 'custom'">
                <div class="col-md-3">
                    <label class="form-label">Период с</label>
                    <input type="text" v-datepicker class="form-control" v-model="form.dateFrom" :max="form.dateTo || todayIso">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Период по</label>
                    <input type="text" v-datepicker class="form-control" v-model="form.dateTo" :min="form.dateFrom" :max="todayIso">
                </div>
            </div>

            <div class="d-flex gap-2 align-items-center mt-3">
                <button class="btn-action btn-primary-violet" @click="generateReport" :disabled="loading || !canGenerate">
                    <span v-if="loading"><i class="fas fa-spinner fa-spin me-1"></i>Формирование...</span>
                    <span v-else><i class="fas fa-play me-1"></i>Сформировать</span>
                </button>
                <button class="btn-action btn-outline-secondary" @click="resetForm">
                    <i class="fas fa-times me-1"></i>Сбросить
                </button>
            </div>

            <div v-if="error" class="alert alert-danger mt-3 mb-0 py-2">
                {{ error }}
            </div>
        </div>
    </div>

    <div v-if="reportLevel && reports.length" class="sm-card mb-3">
        <div class="sm-card-body py-3">
            <strong>{{ reportLevel.type === 'category' ? 'Категория' : 'Ностро-банк' }}:</strong>
            {{ reportLevel.label }}
            <span class="text-muted ms-2">{{ reports.length }} {{ declReports(reports.length) }}</span>
        </div>
    </div>

    <template v-for="(report, rIdx) in reports">
        <div :id="'recon-report-' + rIdx" :key="report.pool_id" class="recon-report-printable recon-report-block">
            <div class="recon-report-block-title">
                <div>
                    <span>Отчет {{ rIdx + 1 }}</span>
                    <strong>{{ report.nostro_bank }}</strong>
                </div>
                <span>{{ fmtDate(report.date_recon) }}</span>
            </div>

            <div class="sm-card recon-inner-card mb-3">
                <div class="sm-card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-3">
                        <div>
                            <div class="h4 mb-2">
                                Reconciliation Report
                                <span v-if="reports.length > 1" class="text-muted fs-6">({{ rIdx + 1 }}/{{ reports.length }})</span>
                            </div>
                            <div class="small">
                                <div><strong>Company:</strong> {{ report.company }}</div>
                                <div><strong>Nostro Bank:</strong> {{ report.nostro_bank }}</div>
                                <div><strong>Account:</strong> {{ report.account_name }}</div>
                                <div><strong>Currency:</strong> {{ report.currency || '—' }}</div>
                            </div>
                        </div>
                        <div class="text-md-end small">
                            <div><strong>Date:</strong> {{ fmtDateTime(report.generated_at) }}</div>
                            <div><strong>Date Reconciliation:</strong> {{ fmtDate(report.date_recon) }}</div>
                            <div v-if="report.date_from && report.date_to">
                                <strong>Period:</strong> {{ fmtDate(report.date_from) }} — {{ fmtDate(report.date_to) }}
                            </div>
                            <div class="d-flex gap-2 justify-content-md-end mt-3">
                <a class="btn-action btn-outline-secondary" :href="exportUrl('xlsx', report.pool_id)" target="_blank">
                                    <i class="fas fa-file-excel me-1"></i>XLSX
                                </a>
                <a class="btn-action btn-outline-secondary" :href="exportUrl('pdf', report.pool_id)" target="_blank">
                                    <i class="fas fa-file-pdf me-1"></i>PDF
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sm-card recon-inner-card mb-3">
                <div class="sm-card-header">
                    Reconciliation Summary
                    <span class="text-muted small ms-2" v-if="report.date_from && report.date_to">
                        {{ fmtDate(report.date_from) }} — {{ fmtDate(report.date_to) }}
                    </span>
                    <span class="text-muted small ms-2" v-else>
                        {{ fmtDate(calcPrevDay(report.date_recon)) }} и {{ fmtDate(report.date_recon) }}
                    </span>
                </div>
                <div class="sm-card-body p-0">
                    <table class="recon-summary-table">
                        <thead>
                        <tr>
                            <th style="width:34%"></th>
                            <th class="text-end">Ledger</th>
                            <th class="text-end">Statement</th>
                            <th class="text-end">Difference</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr class="recon-summary-main-row">
                            <td><strong>Closing Balance</strong></td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.closing_balance.ledger) }}</td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.closing_balance.statement) }}</td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.closing_balance.difference) }}</td>
                        </tr>
                        <tr class="recon-summary-main-row">
                            <td><strong>Outstanding Items</strong></td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.outstanding_items.ledger) }}</td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.outstanding_items.statement) }}</td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.outstanding_items.difference) }}</td>
                        </tr>
                        <tr class="recon-summary-main-row recon-summary-total-row">
                            <td><strong>Trial Balance</strong></td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.trial_balance.ledger) }}</td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.trial_balance.statement) }}</td>
                            <td class="text-end fw-bold">{{ fmtAmountSigned(report.trial_balance.difference) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sm-card recon-inner-card mb-4">
                <div class="sm-card-header">Outstanding Items — Detail</div>
                <div class="sm-card-body p-0">
                    <div class="oi-section-header" style="background:#eef2ff;color:#3730a3">
                        Ledger
                    </div>
                    <detail-section title="Ledger — Debit (Outstanding Items)" :rows="report.outstanding_items.ledger_debit" :net="report.outstanding_items.net_ledger_debit" :fmt-date="fmtDate" :fmt-amount="fmtAmountSigned"></detail-section>
                    <detail-section title="Ledger — Credit (Outstanding Items)" :rows="report.outstanding_items.ledger_credit" :net="report.outstanding_items.net_ledger_credit" :fmt-date="fmtDate" :fmt-amount="fmtAmountSigned"></detail-section>
                    <table class="recon-summary-table recon-net-table">
                        <tbody>
                        <tr><td>Ledger Net Amount</td><td class="text-end fw-bold">{{ fmtAmountSigned(report.totals.ledger_net_amount) }}</td></tr>
                        </tbody>
                    </table>

                    <div class="oi-section-header" style="background:#ecfdf5;color:#065f46">
                        Statement
                    </div>
                    <detail-section title="Statement — Debit (Outstanding Items)" :rows="report.outstanding_items.stmt_debit" :net="report.outstanding_items.net_stmt_debit" :fmt-date="fmtDate" :fmt-amount="fmtAmountSigned"></detail-section>
                    <detail-section title="Statement — Credit (Outstanding Items)" :rows="report.outstanding_items.stmt_credit" :net="report.outstanding_items.net_stmt_credit" :fmt-date="fmtDate" :fmt-amount="fmtAmountSigned"></detail-section>
                    <table class="recon-summary-table recon-net-table">
                        <tbody>
                        <tr><td>Statement Net Amount</td><td class="text-end fw-bold">{{ fmtAmountSigned(report.totals.statement_net_amount) }}</td></tr>
                        </tbody>
                    </table>

                    <table class="recon-summary-table recon-net-table recon-total-table">
                        <tbody>
                        <tr><td><strong>Total Amount</strong></td><td class="text-end fw-bold">{{ fmtAmountSigned(report.totals.total_amount) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>
</div>

<script type="text/x-template" id="detail-section-template">
    <div>
        <div class="oi-section-header">
            {{ title }}
            <span class="oi-count">{{ rows.length }} записей</span>
        </div>
        <div class="oi-table-wrap">
            <table class="recon-entries-table">
                <thead>
                <tr>
                    <th>Value</th><th>Account</th><th>Instruction_ID</th><th>EndToEnd_ID</th><th>Transaction_ID</th><th>Message_ID</th><th>D/C Mark</th><th class="text-end">Amount</th>
                </tr>
                </thead>
                <tbody>
                <tr v-if="!rows.length"><td colspan="8" class="text-muted text-center">Нет записей</td></tr>
                <tr v-for="(row, idx) in rows" :key="idx">
                    <td>{{ fmtDate(row.value) }}</td>
                    <td>{{ row.account || '—' }}</td>
                    <td>{{ row.instruction_id || '—' }}</td>
                    <td>{{ row.end_to_end_id || '—' }}</td>
                    <td>{{ row.transaction_id || '—' }}</td>
                    <td>{{ row.message_id || '—' }}</td>
                    <td>{{ row.dc || '—' }}</td>
                    <td class="text-end">{{ fmtAmount(row.amount) }}</td>
                </tr>
                </tbody>
                <tfoot>
                <tr><td colspan="7" class="text-end fw-bold">Amount</td><td class="text-end fw-bold">{{ fmtAmount(net) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</script>

<?php
$generateUrl = Url::to(['/recon-report/generate']);
$exportUrl = Url::to(['/recon-report/export']);
$js = <<<JS
(function () {
    var initData = {$initJson};

    Vue.component('detail-section', {
        template: '#detail-section-template',
        props: ['title', 'rows', 'net', 'fmtDate', 'fmtAmount']
    });

    new Vue({
        el: '#recon-app',
        data: {
            init: initData,
            todayIso: new Date().toISOString().slice(0, 10),
            loading: false,
            error: null,
            reports: [],
            reportLevel: null,
            exportParams: null,
            form: {
                categoryId: '',
                poolId: '',
                periodMode: 'auto',
                dateRecon: new Date().toISOString().slice(0, 10),
                dateFrom: '',
                dateTo: ''
            }
        },
        computed: {
            canGenerate: function () {
                var hasScope = !!this.form.categoryId || !!this.form.poolId;
                if (!hasScope) return false;
                if (this.form.periodMode === 'custom') return !!this.form.dateFrom && !!this.form.dateTo;
                return !!this.form.dateRecon;
            }
        },
        methods: {
            payload: function () {
                return {
                    category_id: this.form.categoryId || '',
                    pool_id: this.form.poolId || '',
                    date_recon: this.form.periodMode === 'custom' ? this.form.dateTo : this.form.dateRecon,
                    date_from: this.form.periodMode === 'custom' ? this.form.dateFrom : '',
                    date_to: this.form.periodMode === 'custom' ? this.form.dateTo : ''
                };
            },
            generateReport: function () {
                if (!this.canGenerate) return;
                var self = this;
                this.loading = true;
                this.error = null;
                this.reports = [];
                this.reportLevel = null;
                this.exportParams = null;

                axios.post('{$generateUrl}', this.payload())
                    .then(function (resp) {
                        if (resp.data.success) {
                            self.reports = resp.data.reports || [];
                            self.reportLevel = resp.data.report_level || null;
                            self.exportParams = resp.data.export_params || self.payload();
                            self.\$nextTick(function () {
                                var el = document.getElementById('recon-report-0');
                                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            });
                        } else {
                            self.error = resp.data.message || 'Ошибка формирования отчёта';
                        }
                    })
                    .catch(function (err) {
                        self.error = 'Ошибка сети: ' + (err.message || 'неизвестная ошибка');
                    })
                    .finally(function () {
                        self.loading = false;
                    });
            },
            resetForm: function () {
                this.form.categoryId = '';
                this.form.poolId = '';
                this.form.periodMode = 'auto';
                this.form.dateRecon = this.todayIso;
                this.form.dateFrom = '';
                this.form.dateTo = '';
                this.reports = [];
                this.reportLevel = null;
                this.exportParams = null;
                this.error = null;
            },
            exportUrl: function (format, reportPoolId) {
                var params = Object.assign({}, this.exportParams || this.payload(), { format: format });
                if (reportPoolId) params.report_pool_id = reportPoolId;
                Object.keys(params).forEach(function (key) {
                    if (params[key] === null || params[key] === undefined || params[key] === '') {
                        delete params[key];
                    }
                });
                return '{$exportUrl}?' + new URLSearchParams(params).toString();
            },
            calcPrevDay: function (dateStr) {
                var d = new Date(dateStr + 'T00:00:00');
                d.setDate(d.getDate() - 1);
                return d.toISOString().slice(0, 10);
            },
            declReports: function (n) {
                var m = n % 100;
                if (m >= 11 && m <= 19) return 'отчетов';
                var d = m % 10;
                if (d === 1) return 'отчёт';
                if (d >= 2 && d <= 4) return 'отчёта';
                return 'отчетов';
            },
            diffClass: function (val) {
                if (val === null || val === undefined) return '';
                return parseFloat(val) === 0 ? 'diff-zero' : 'diff-nonzero';
            },
            fmtDate: function (val) {
                if (!val) return '—';
                var parts = String(val).slice(0, 10).split('-');
                return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : val;
            },
            fmtDateTime: function (val) {
                if (!val) return '—';
                var dt = new Date(String(val).replace(' ', 'T'));
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
                var sign = n < 0 ? '-' : (n > 0 ? '+' : '');
                return sign + Math.abs(n).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }
    });
}());
JS;

$this->registerJs($js, \yii\web\View::POS_END);
?>
