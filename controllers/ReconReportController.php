<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\NostroBalance;
use app\models\Account;
use app\models\AccountPool;

class ReconReportController extends BaseController
{
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    private function cid()
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * GET /recon-report/index
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию для работы с отчётами.');
            return $this->redirect(['/site/index']);
        }

        $pools = AccountPool::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $accounts = Account::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $initData = [
            'pools'    => array_map(function ($p) { return ['id' => $p->id, 'name' => $p->name]; }, $pools),
            'accounts' => array_map(function ($a) {
                return [
                    'id'       => $a->id,
                    'name'     => $a->name,
                    'currency' => $a->currency,
                    'pool_id'  => $a->pool_id,
                ];
            }, $accounts),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    /**
     * POST /recon-report/generate
     */
    public function actionGenerate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $r         = Yii::$app->request;
        $accountId = (int)$r->post('account_id');
        $dateRecon = $r->post('date_recon');
        $dateFrom  = $r->post('date_from');
        $dateTo    = $r->post('date_to');

        if (!$accountId || !$dateRecon) {
            return ['success' => false, 'message' => 'Не указан счёт или дата формирования'];
        }

        $dtRecon = \DateTime::createFromFormat('Y-m-d', $dateRecon);
        if (!$dtRecon) {
            return ['success' => false, 'message' => 'Неверный формат даты'];
        }

        $account = Account::findOne(['id' => $accountId, 'company_id' => $cid]);
        if (!$account) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

        $poolName = '';
        if ($account->pool_id) {
            $pool = AccountPool::findOne($account->pool_id);
            $poolName = $pool ? $pool->name : '';
        }

        $reportData = $this->buildReportData($account, $poolName, $dateRecon, $dateFrom, $dateTo, $cid);

        return ['success' => true, 'report' => $reportData];
    }

    /**
     * GET /recon-report/accounts
     */
    public function actionAccounts()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid    = $this->cid();
        $poolId = (int)Yii::$app->request->get('pool_id', 0);

        $q = Account::find()->where(['company_id' => $cid]);
        if ($poolId > 0) $q->andWhere(['pool_id' => $poolId]);

        $rows = $q->orderBy(['name' => SORT_ASC])->all();
        return [
            'success' => true,
            'data'    => array_map(function ($a) {
                return [
                    'id'       => $a->id,
                    'name'     => $a->name,
                    'currency' => $a->currency,
                    'pool_id'  => $a->pool_id,
                ];
            }, $rows),
        ];
    }

    // ── Построение данных отчёта ─────────────────────────────────────────────

    private function buildReportData(Account $account, $poolName, $dateRecon, $dateFrom, $dateTo, $cid)
    {
        $generatedAt = date('Y-m-d H:i:s');
        $dtRecon     = \DateTime::createFromFormat('Y-m-d', $dateRecon);
        $prevDay     = (clone $dtRecon)->modify('-1 day')->format('Y-m-d');

        // ── 1. Closing Balance из nostro_balance ─────────────────────────────
        $closingLedger = NostroBalance::find()
            ->where(['account_id' => $account->id, 'ls_type' => 'L'])
            ->andWhere(['<=', 'value_date', $dateRecon])
            ->orderBy(['value_date' => SORT_DESC])
            ->one();

        $closingStatement = NostroBalance::find()
            ->where(['account_id' => $account->id, 'ls_type' => 'S'])
            ->andWhere(['<=', 'value_date', $dateRecon])
            ->orderBy(['value_date' => SORT_DESC])
            ->one();

        $cbLedgerAmt = $closingLedger ? (float)$closingLedger->closing_balance : null;
        $cbStatAmt   = $closingStatement ? (float)$closingStatement->closing_balance : null;

        if ($closingLedger && $closingLedger->closing_dc === 'D') {
            $cbLedgerAmt = -$cbLedgerAmt;
        }
        if ($closingStatement && $closingStatement->closing_dc === 'D') {
            $cbStatAmt = -$cbStatAmt;
        }

        $cbDiff = ($cbLedgerAmt !== null && $cbStatAmt !== null) ? $cbLedgerAmt - $cbStatAmt : null;

        // ── 2. Outstanding Items — несквитованные записи ─────────────────────
        $query = NostroEntry::find()
            ->from(['ne' => NostroEntry::tableName()])
            ->where(['ne.account_id' => $account->id, 'ne.company_id' => $cid])
            ->andWhere(['or', ['ne.match_id' => null], ['ne.match_id' => '']])
            ->andWhere(['ne.match_status' => NostroEntry::STATUS_UNMATCHED]);

        if ($dateFrom && $dateTo) {
            $query->andWhere(['between', 'DATE(ne.value_date)', $dateFrom, $dateTo]);
        } else {
            $query->andWhere(['in', 'DATE(ne.value_date)', [$prevDay, $dateRecon]]);
        }

        $outstanding = $query->all();

        $ledgerDebit  = [];
        $ledgerCredit = [];
        $stmtDebit    = [];
        $stmtCredit   = [];

        foreach ($outstanding as $entry) {
            $amt = (float)$entry->amount;

            $row = [
                'value'          => $entry->value_date,
                'instruction_id' => $entry->instruction_id,
                'end_to_end_id'  => $entry->end_to_end_id,
                'transaction_id' => $entry->transaction_id,
                'message_id'     => $entry->message_id,
                'dc'             => $entry->dc,
            ];

            if ($entry->ls === NostroEntry::LS_LEDGER) {
                if ($entry->dc === NostroEntry::DC_DEBIT) {
                    $row['amount'] = -abs($amt);
                    $ledgerDebit[] = $row;
                } else {
                    $row['amount'] = abs($amt);
                    $ledgerCredit[] = $row;
                }
            } else {
                if ($entry->dc === NostroEntry::DC_DEBIT) {
                    $row['amount'] = abs($amt);
                    $stmtDebit[] = $row;
                } else {
                    $row['amount'] = abs($amt);
                    $stmtCredit[] = $row;
                }
            }
        }

        $netLedgerDebit  = array_sum(array_column($ledgerDebit,  'amount'));
        $netLedgerCredit = array_sum(array_column($ledgerCredit, 'amount'));
        $netStmtDebit    = array_sum(array_column($stmtDebit,    'amount'));
        $netStmtCredit   = array_sum(array_column($stmtCredit,   'amount'));

        $ledgerNetAmount = $netLedgerDebit + $netLedgerCredit;
        $stmtNetAmount   = $netStmtDebit + $netStmtCredit;

        $oiLedger = $ledgerNetAmount;
        $oiStmt   = $stmtNetAmount;
        $oiDiff   = $oiLedger - $oiStmt;

        // ── 3. Trial Balance ─────────────────────────────────────────────────
        $tbLedger = ($cbLedgerAmt !== null ? $cbLedgerAmt : 0) + $oiLedger;
        $tbStmt   = ($cbStatAmt   !== null ? $cbStatAmt   : 0) + $oiStmt;
        $tbDiff   = $tbLedger - $tbStmt;

        // ── 4. Ledger/Statement Total Amount ─────────────────────────────────
        $totalAmount = $ledgerNetAmount + $stmtNetAmount;

        return [
            'generated_at'  => $generatedAt,
            'date_recon'    => $dateRecon,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'company'       => 'NRE',
            'nostro_bank'   => $poolName ? $poolName : $account->name,
            'account_name'  => $account->name,
            'account_id'    => $account->id,
            'currency'      => $account->currency,

            'closing_balance' => [
                'ledger'       => $cbLedgerAmt,
                'ledger_dc'    => $closingLedger ? $closingLedger->closing_dc : null,
                'statement'    => $cbStatAmt,
                'statement_dc' => $closingStatement ? $closingStatement->closing_dc : null,
                'difference'   => $cbDiff,
            ],

            'outstanding_items' => [
                'ledger_debit'      => $ledgerDebit,
                'ledger_credit'     => $ledgerCredit,
                'stmt_debit'        => $stmtDebit,
                'stmt_credit'       => $stmtCredit,
                'net_ledger_debit'  => $netLedgerDebit,
                'net_ledger_credit' => $netLedgerCredit,
                'net_stmt_debit'    => $netStmtDebit,
                'net_stmt_credit'   => $netStmtCredit,
                'ledger_net_amount' => $ledgerNetAmount,
                'stmt_net_amount'   => $stmtNetAmount,
                'ledger'            => $oiLedger,
                'statement'         => $oiStmt,
                'difference'        => $oiDiff,
            ],

            'trial_balance' => [
                'ledger'     => $tbLedger,
                'statement'  => $tbStmt,
                'difference' => $tbDiff,
            ],

            'totals' => [
                'ledger_net_amount'    => $ledgerNetAmount,
                'statement_net_amount' => $stmtNetAmount,
                'total_amount'         => $totalAmount,
            ],
        ];
    }
}
