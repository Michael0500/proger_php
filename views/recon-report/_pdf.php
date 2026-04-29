<?php
/** @var array $report */

$fmtDate = function ($value) {
    return $value ? date('d.m.Y', strtotime($value)) : '—';
};
$fmtDateTime = function ($value) {
    return $value ? date('d.m.Y H:i:s', strtotime($value)) : '—';
};
$fmtAmount = function ($value) {
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, 2, '.', ' ');
};
$fmtSigned = function ($value) use ($fmtAmount) {
    if ($value === null || $value === '') {
        return '—';
    }
    $value = (float)$value;
    $sign = $value < 0 ? '-' : ($value > 0 ? '+' : '');
    return $sign . $fmtAmount(abs($value));
};
$ledgerSections = [
    ['Ledger — Debit (Outstanding Items)', $report['outstanding_items']['ledger_debit'], $report['outstanding_items']['net_ledger_debit']],
    ['Ledger — Credit (Outstanding Items)', $report['outstanding_items']['ledger_credit'], $report['outstanding_items']['net_ledger_credit']],
];
$statementSections = [
    ['Statement — Debit (Outstanding Items)', $report['outstanding_items']['stmt_debit'], $report['outstanding_items']['net_stmt_debit']],
    ['Statement — Credit (Outstanding Items)', $report['outstanding_items']['stmt_credit'], $report['outstanding_items']['net_stmt_credit']],
];
?>
<style>
    body { font-family: sans-serif; font-size: 10px; color: #111827; }
    h1 { font-size: 20px; margin: 0 0 12px; }
    h2 { font-size: 13px; margin: 14px 0 6px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border: 1px solid #d1d5db; padding: 5px 6px; vertical-align: top; }
    th { background: #f3f4f6; font-weight: bold; }
    .meta td { border: 0; padding: 2px 4px; }
    .num { text-align: right; white-space: nowrap; }
    .total td { font-weight: bold; background: #f9fafb; }
    .group-title { font-weight: bold; background: #eef2ff; padding: 7px; border: 1px solid #d1d5db; margin-top: 12px; }
    .group-title.statement { background: #ecfdf5; }
</style>

<h1>Reconciliation Report</h1>
<table class="meta">
    <tr><td><strong>Company:</strong> <?= htmlspecialchars($report['company']) ?></td><td><strong>Date:</strong> <?= $fmtDateTime($report['generated_at']) ?></td></tr>
    <tr><td><strong>Date Reconciliation:</strong> <?= $fmtDate($report['date_recon']) ?></td><td><strong>Nostro Bank:</strong> <?= htmlspecialchars($report['nostro_bank']) ?></td></tr>
    <tr><td><strong>Account:</strong> <?= htmlspecialchars($report['account_name']) ?></td><td><strong>Currency:</strong> <?= htmlspecialchars((string)$report['currency']) ?></td></tr>
    <?php if (!empty($report['date_from']) && !empty($report['date_to'])): ?>
        <tr><td colspan="2"><strong>Period:</strong> <?= $fmtDate($report['date_from']) ?> - <?= $fmtDate($report['date_to']) ?></td></tr>
    <?php endif; ?>
</table>

<h2>Reconciliation Summary</h2>
<table>
    <tr><th></th><th class="num">Ledger</th><th class="num">Statement</th><th class="num">Difference</th></tr>
    <tr class="total"><td>Closing Balance</td><td class="num"><?= $fmtSigned($report['closing_balance']['ledger']) ?></td><td class="num"><?= $fmtSigned($report['closing_balance']['statement']) ?></td><td class="num"><?= $fmtSigned($report['closing_balance']['difference']) ?></td></tr>
    <tr class="total"><td>Outstanding Items</td><td class="num"><?= $fmtSigned($report['outstanding_items']['ledger']) ?></td><td class="num"><?= $fmtSigned($report['outstanding_items']['statement']) ?></td><td class="num"><?= $fmtSigned($report['outstanding_items']['difference']) ?></td></tr>
    <tr class="total"><td>Trial Balance</td><td class="num"><?= $fmtSigned($report['trial_balance']['ledger']) ?></td><td class="num"><?= $fmtSigned($report['trial_balance']['statement']) ?></td><td class="num"><?= $fmtSigned($report['trial_balance']['difference']) ?></td></tr>
</table>

<div class="group-title">Ledger</div>
<?php foreach ($ledgerSections as $section): ?>
    <h2><?= htmlspecialchars($section[0]) ?></h2>
    <table>
        <tr>
            <th>Value</th><th>Account</th><th>Instruction_ID</th><th>EndToEnd_ID</th><th>Transaction_ID</th><th>Message_ID</th><th>D/C Mark</th><th class="num">Amount</th>
        </tr>
        <?php if (empty($section[1])): ?>
            <tr><td colspan="8">Нет записей</td></tr>
        <?php endif; ?>
        <?php foreach ($section[1] as $entry): ?>
            <tr>
                <td><?= $fmtDate($entry['value']) ?></td>
                <td><?= htmlspecialchars((string)$entry['account']) ?></td>
                <td><?= htmlspecialchars((string)$entry['instruction_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['end_to_end_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['transaction_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['message_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['dc']) ?></td>
                <td class="num"><?= $fmtSigned($entry['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total"><td colspan="7">Amount</td><td class="num"><?= $fmtSigned($section[2]) ?></td></tr>
    </table>
<?php endforeach; ?>
<table>
    <tr class="total"><td>Ledger Net Amount</td><td class="num"><?= $fmtSigned($report['totals']['ledger_net_amount']) ?></td></tr>
</table>

<div class="group-title statement">Statement</div>
<?php foreach ($statementSections as $section): ?>
    <h2><?= htmlspecialchars($section[0]) ?></h2>
    <table>
        <tr>
            <th>Value</th><th>Account</th><th>Instruction_ID</th><th>EndToEnd_ID</th><th>Transaction_ID</th><th>Message_ID</th><th>D/C Mark</th><th class="num">Amount</th>
        </tr>
        <?php if (empty($section[1])): ?>
            <tr><td colspan="8">Нет записей</td></tr>
        <?php endif; ?>
        <?php foreach ($section[1] as $entry): ?>
            <tr>
                <td><?= $fmtDate($entry['value']) ?></td>
                <td><?= htmlspecialchars((string)$entry['account']) ?></td>
                <td><?= htmlspecialchars((string)$entry['instruction_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['end_to_end_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['transaction_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['message_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['dc']) ?></td>
                <td class="num"><?= $fmtSigned($entry['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total"><td colspan="7">Amount</td><td class="num"><?= $fmtSigned($section[2]) ?></td></tr>
    </table>
<?php endforeach; ?>
<table>
    <tr class="total"><td>Statement Net Amount</td><td class="num"><?= $fmtSigned($report['totals']['statement_net_amount']) ?></td></tr>
</table>

<h2>Ledger / Statement Total Amount</h2>
<table>
    <tr class="total"><td>Total Amount</td><td class="num"><?= $fmtSigned($report['totals']['total_amount']) ?></td></tr>
</table>
