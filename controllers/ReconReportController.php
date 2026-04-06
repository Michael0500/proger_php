<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\NostroBalance;
use app\models\Account;
use app\models\AccountPool;
use app\models\Category;
use app\models\Group;
use app\models\GroupFilter;

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

        $categories = Category::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $groups = Group::find()
            ->where(['company_id' => $cid, 'is_active' => true])
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
            'categories' => array_map(function ($c) {
                return ['id' => $c->id, 'name' => $c->name];
            }, $categories),
            'groups' => array_map(function ($g) {
                return ['id' => $g->id, 'name' => $g->name, 'category_id' => $g->category_id];
            }, $groups),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    /**
     * POST /recon-report/generate
     *
     * Принимает один из вариантов:
     *   1) account_id — отчёт по одному счёту
     *   2) pool_id (без account_id) — отчёт по всем счетам ностро-банка
     *   3) group_id (без account_id и pool_id) — отчёт по счетам группы
     */
    public function actionGenerate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $r         = Yii::$app->request;
        $accountId = (int)$r->post('account_id');
        $poolId    = (int)$r->post('pool_id');
        $groupId   = (int)$r->post('group_id');
        $dateRecon = $r->post('date_recon');
        $dateFrom  = $r->post('date_from');
        $dateTo    = $r->post('date_to');

        if (!$dateRecon) {
            return ['success' => false, 'message' => 'Не указана дата формирования'];
        }

        $dtRecon = \DateTime::createFromFormat('Y-m-d', $dateRecon);
        if (!$dtRecon) {
            return ['success' => false, 'message' => 'Неверный формат даты'];
        }

        // Определяем список счетов для отчёта
        $accounts = $this->resolveAccounts($accountId, $poolId, $groupId, $cid);

        if (empty($accounts)) {
            return ['success' => false, 'message' => 'Не найдено ни одного счёта для формирования отчёта'];
        }

        // Определяем label для уровня отчёта
        $reportLevel = $this->resolveReportLevel($accountId, $poolId, $groupId, $cid);

        $reports = [];
        foreach ($accounts as $account) {
            $poolName = '';
            if ($account->pool_id) {
                $pool = AccountPool::findOne($account->pool_id);
                $poolName = $pool ? $pool->name : '';
            }
            $reports[] = $this->buildReportData($account, $poolName, $dateRecon, $dateFrom, $dateTo, $cid);
        }

        return [
            'success'      => true,
            'reports'      => $reports,
            'report_level' => $reportLevel,
        ];
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

    // ── Вспомогательные методы ───────────────────────────────────────────────

    /**
     * Определяет список счетов по переданным параметрам.
     * @return Account[]
     */
    private function resolveAccounts(int $accountId, int $poolId, int $groupId, int $cid): array
    {
        // 1) Конкретный счёт
        if ($accountId > 0) {
            $account = Account::findOne(['id' => $accountId, 'company_id' => $cid]);
            return $account ? [$account] : [];
        }

        // 2) По ностро-банку
        if ($poolId > 0) {
            return Account::find()
                ->where(['company_id' => $cid, 'pool_id' => $poolId])
                ->orderBy(['name' => SORT_ASC])
                ->all();
        }

        // 3) По группе (через GroupFilter)
        if ($groupId > 0) {
            $group = Group::findOne(['id' => $groupId, 'company_id' => $cid]);
            if (!$group) return [];

            $filters = GroupFilter::find()
                ->where(['group_id' => $groupId])
                ->orderBy(['sort_order' => SORT_ASC])
                ->all();

            $query = Account::find()->where(['company_id' => $cid]);

            if (!empty($filters)) {
                $accountFilters = array_values(array_filter($filters, function ($f) { return $f->isAccountField(); }));

                if (!empty($accountFilters)) {
                    $first = true;
                    foreach ($accountFilters as $pf) {
                        $condition = $pf->buildAccountCondition();
                        if ($condition === null) continue;
                        if ($first) {
                            $query->andWhere($condition);
                            $first = false;
                        } else {
                            if ($pf->logic === 'OR') {
                                $query->orWhere($condition);
                            } else {
                                $query->andWhere($condition);
                            }
                        }
                    }
                }
            }

            return $query->orderBy(['name' => SORT_ASC])->all();
        }

        return [];
    }

    /**
     * Формирует описание уровня отчёта для шапки.
     */
    private function resolveReportLevel(int $accountId, int $poolId, int $groupId, int $cid): array
    {
        if ($accountId > 0) {
            return ['type' => 'account', 'label' => ''];
        }
        if ($poolId > 0) {
            $pool = AccountPool::findOne(['id' => $poolId, 'company_id' => $cid]);
            return ['type' => 'pool', 'label' => $pool ? $pool->name : ''];
        }
        if ($groupId > 0) {
            $group = Group::findOne(['id' => $groupId, 'company_id' => $cid]);
            return ['type' => 'group', 'label' => $group ? $group->name : ''];
        }
        return ['type' => 'unknown', 'label' => ''];
    }

    // ── Построение данных отчёта ─────────────────────────────────────────────

    private function buildReportData(Account $account, $poolName, $dateRecon, $dateFrom, $dateTo, $cid)
    {
        $generatedAt  = date('Y-m-d H:i:s');
        $dtRecon      = \DateTime::createFromFormat('Y-m-d', $dateRecon);
        $prevDay      = (clone $dtRecon)->modify('-1 day')->format('Y-m-d');
        // В режиме произвольного периода closing balance берём на конец периода
        $balanceDate  = ($dateFrom && $dateTo) ? $dateTo : $dateRecon;

        // ── 1. Closing Balance из nostro_balance ─────────────────────────────
        $closingLedger = NostroBalance::find()
            ->where(['account_id' => $account->id, 'ls_type' => 'L'])
            ->andWhere(['<=', 'value_date', $balanceDate])
            ->orderBy(['value_date' => SORT_DESC])
            ->one();

        $closingStatement = NostroBalance::find()
            ->where(['account_id' => $account->id, 'ls_type' => 'S'])
            ->andWhere(['<=', 'value_date', $balanceDate])
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
