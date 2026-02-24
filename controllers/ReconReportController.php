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

    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * GET /recon-report/index
     * Главная страница раздела «Раккорд»
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию для работы с отчётами.');
            return $this->redirect(['/site/index']);
        }

        // Список пулов (групп банков) для выбора
        $pools = AccountPool::find()
            ->where(['company_id' => $cid, 'is_active' => true])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $accounts = Account::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $initData = [
            'pools'    => array_map(fn($p) => ['id' => $p->id, 'name' => $p->name], $pools),
            'accounts' => array_map(fn($a) => [
                'id'       => $a->id,
                'name'     => $a->name,
                'currency' => $a->currency,
                'pool_id'  => $a->pool_id,
            ], $accounts),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    /**
     * POST /recon-report/generate
     * Генерация данных отчёта (JSON)
     *
     * Params:
     *   account_id  - int
     *   date_recon  - string (Y-m-d) — дата ракорда (Date Reconciliation)
     */
    public function actionGenerate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $r         = Yii::$app->request;
        $accountId = (int)$r->post('account_id');
        $dateRecon = $r->post('date_recon'); // Y-m-d

        if (!$accountId || !$dateRecon) {
            return ['success' => false, 'message' => 'Не указан счёт или дата формирования'];
        }

        // Валидация даты
        $dtRecon = \DateTime::createFromFormat('Y-m-d', $dateRecon);
        if (!$dtRecon) {
            return ['success' => false, 'message' => 'Неверный формат даты'];
        }

        $account = Account::findOne(['id' => $accountId, 'company_id' => $cid]);
        if (!$account) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

        $generatedAt = date('Y-m-d H:i:s');
        $prevDay     = (clone $dtRecon)->modify('-1 day')->format('Y-m-d');

        // ── 1. Closing Balance из nostro_balance ──────────────────────────────────
        $closingLedger = NostroBalance::find()
            ->where(['account_id' => $accountId, 'ls_type' => 'L'])
            ->andWhere(['<=', 'value_date', $dateRecon])
            ->orderBy(['value_date' => SORT_DESC])
            ->one();

        $closingStatement = NostroBalance::find()
            ->where(['account_id' => $accountId, 'ls_type' => 'S'])
            ->andWhere(['<=', 'value_date', $dateRecon])
            ->orderBy(['value_date' => SORT_DESC])
            ->one();

        $cbLedgerAmt = $closingLedger
            ? ($closingLedger->closing_dc === 'D' ? -$closingLedger->closing_balance : $closingLedger->closing_balance)
            : null;
        $cbStatAmt   = $closingStatement
            ? ($closingStatement->closing_dc === 'D' ? -$closingStatement->closing_balance : $closingStatement->closing_balance)
            : null;

        // ── 2. Outstanding Items — несквитованные записи ──────────────────────────
        // Берём записи за предыдущий день И за день ракорда (до момента формирования)
        $outstanding = NostroEntry::find()
            ->where(['ne.account_id' => $accountId, 'ne.company_id' => $cid])
            ->andWhere(['is', 'ne.match_id', null])
            ->andWhere(['ne.match_status' => NostroEntry::STATUS_UNMATCHED])
            ->andWhere(['in', 'DATE(ne.post_date)', [$prevDay, $dateRecon]])
            ->from(['ne' => NostroEntry::tableName()])
            ->all();

        // Разбивка по категориям
        $ledgerDebit   = [];
        $ledgerCredit  = [];
        $stmtDebit     = [];
        $stmtCredit    = [];

        foreach ($outstanding as $entry) {
            $row = [
                'value'          => $entry->value_date,
                'instruction_id' => $entry->instruction_id,
                'end_to_end_id'  => $entry->end_to_end_id,
                'transaction_id' => $entry->transaction_id,
                'message_id'     => $entry->message_id,
                'dc'             => $entry->dc,
                'amount'         => (float)$entry->amount,
            ];

            if ($entry->ls === NostroEntry::LS_LEDGER) {
                if ($entry->dc === NostroEntry::DC_DEBIT)  $ledgerDebit[]  = $row;
                else                                        $ledgerCredit[] = $row;
            } else {
                if ($entry->dc === NostroEntry::DC_DEBIT)  $stmtDebit[]    = $row;
                else                                        $stmtCredit[]   = $row;
            }
        }

        // Net Amount по каждой группе
        $netLedgerDebit   = array_sum(array_column($ledgerDebit,  'amount'));
        $netLedgerCredit  = array_sum(array_column($ledgerCredit, 'amount'));
        $netStmtDebit     = array_sum(array_column($stmtDebit,    'amount'));
        $netStmtCredit    = array_sum(array_column($stmtCredit,   'amount'));

        // Ledger Net Amount = Debit − Credit (знаковая сумма)
        $ledgerNetAmount = $netLedgerDebit - $netLedgerCredit;
        $stmtNetAmount   = $netStmtDebit   - $netStmtCredit;

        // Outstanding Items summary
        $oiLedger = $ledgerNetAmount;
        $oiStmt   = $stmtNetAmount;
        $oiDiff   = $oiLedger - $oiStmt;

        // ── 3. Trial Balance ──────────────────────────────────────────────────────
        $tbLedger = ($cbLedgerAmt ?? 0) + $oiLedger;
        $tbStmt   = ($cbStatAmt   ?? 0) + $oiStmt;
        $tbDiff   = $tbLedger - $tbStmt;

        // ── 4. Closing Balance Difference ─────────────────────────────────────────
        $cbDiff = ($cbLedgerAmt !== null && $cbStatAmt !== null)
            ? $cbLedgerAmt - $cbStatAmt
            : null;

        return [
            'success' => true,
            'report'  => [
                'generated_at'  => $generatedAt,
                'date_recon'    => $dateRecon,
                'company'       => 'NRE',
                'nostro_bank'   => $account->name,
                'account_id'    => $account->id,
                'currency'      => $account->currency,

                'closing_balance' => [
                    'ledger'     => $cbLedgerAmt,
                    'ledger_dc'  => $closingLedger  ? $closingLedger->closing_dc  : null,
                    'statement'  => $cbStatAmt,
                    'statement_dc' => $closingStatement ? $closingStatement->closing_dc : null,
                    'difference' => $cbDiff,
                ],

                'outstanding_items' => [
                    'ledger_debit'   => $ledgerDebit,
                    'ledger_credit'  => $ledgerCredit,
                    'stmt_debit'     => $stmtDebit,
                    'stmt_credit'    => $stmtCredit,
                    'net_ledger_debit'  => $netLedgerDebit,
                    'net_ledger_credit' => $netLedgerCredit,
                    'net_stmt_debit'    => $netStmtDebit,
                    'net_stmt_credit'   => $netStmtCredit,
                    'ledger_net_amount' => $oiLedger,
                    'stmt_net_amount'   => $oiStmt,
                    'ledger'     => $oiLedger,
                    'statement'  => $oiStmt,
                    'difference' => $oiDiff,
                ],

                'trial_balance' => [
                    'ledger'     => $tbLedger,
                    'statement'  => $tbStmt,
                    'difference' => $tbDiff,
                ],

                'totals' => [
                    'ledger_net_amount'    => $oiLedger,
                    'statement_net_amount' => $oiStmt,
                    'total_amount'         => $oiLedger + $oiStmt,
                ],
            ],
        ];
    }

    /**
     * GET /recon-report/accounts
     * Список счётов для выбранного пула (JSON)
     */
    public function actionAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid    = $this->cid();
        $poolId = (int)Yii::$app->request->get('pool_id', 0);

        $q = Account::find()->where(['company_id' => $cid]);
        if ($poolId > 0) $q->andWhere(['pool_id' => $poolId]);

        $rows = $q->orderBy(['name' => SORT_ASC])->all();
        return [
            'success' => true,
            'data'    => array_map(fn($a) => [
                'id'       => $a->id,
                'name'     => $a->name,
                'currency' => $a->currency,
                'pool_id'  => $a->pool_id,
            ], $rows),
        ];
    }
}