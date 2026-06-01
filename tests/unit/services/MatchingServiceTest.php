<?php

namespace tests\unit\services;

use app\models\MatchingRule;
use app\models\NostroEntry;
use app\services\MatchingService;
use Yii;

/**
 * Тестовый класс `MatchingServiceTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class MatchingServiceTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /**
     * Подготавливает окружение перед тестом.
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
    }

    /**
     * Проверяет сценарий: manual match balances ledger and statement.
     * @return void
     */
    public function testManualMatchBalancesLedgerAndStatement(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '100.00',
        ]);

        $result = (new MatchingService())->matchManual([$ledger->id, $statement->id]);

        verify($result['success'])->true();
        verify($result['match_id'])->equals('MTCH00000001');

        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($statement->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($ledger->match_id)->equals($statement->match_id);
        verify($ledger->matched_at)->notEmpty();

        $this->stdout('Ручное квитование сбалансированной пары Ledger(Debit 100)+Statement(Credit 100): обе записи получили статус M, общий match_id и matched_at.');
    }

    /**
     * Проверяет сценарий: manual match rejects imbalanced nre set.
     * @return void
     */
    public function testManualMatchRejectsImbalancedNreSet(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '90.00',
        ]);

        $result = (new MatchingService())->matchManual([$ledger->id, $statement->id]);

        verify($result['success'])->false();
        verify($result['warning'])->true();
        verify($result['diff'])->equals(10.0);

        $this->stdout('Ручное квитование несбалансированного NRE-набора (100 против 90): отказ с warning и разницей diff=10.');
    }

    /**
     * Проверяет сценарий: manual match allows single zero amount entry.
     * @return void
     */
    public function testManualMatchAllowsSingleZeroAmountEntry(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $entry = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'amount' => '0.00',
        ]);

        $result = (new MatchingService())->matchManual([$entry->id]);

        verify($result['success'])->true();
        verify($result['count'])->equals(1);

        $this->stdout('Ручное квитование одиночной записи с суммой 0: разрешено, count=1.');
    }

    /**
     * Проверяет сценарий: manual match balances inv by debit and credit.
     * @return void
     */
    public function testManualMatchBalancesInvByDebitAndCredit(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $debit = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '50.00',
        ]);
        $credit = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '50.00',
        ]);

        $result = (new MatchingService())->matchManual([$debit->id, $credit->id], MatchingRule::SECTION_INV);

        verify($result['success'])->true();
        verify($result['count'])->equals(2);

        $this->stdout('Ручное квитование INV по балансу Debit/Credit (50=50): успешно, count=2.');
    }

    /**
     * Запрещает квитовать записи в разных валютах, даже если они на одном счёте
     * и сбалансированы по сумме (защита от смешивания валют на /all-nostro).
     *
     * @return void
     */
    public function testManualMatchRejectsMixedCurrencies(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $usd = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
            'currency' => 'USD',
        ]);
        $eur = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '100.00',
            'currency' => 'EUR',
        ]);

        $result = (new MatchingService())->matchManual([$usd->id, $eur->id]);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('разных валютах');

        $usd->refresh();
        $eur->refresh();
        verify($usd->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($eur->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $this->stdout('Ручное квитование записей в разных валютах (USD+EUR): отказ, записи остаются незаквитованными.');
    }

    /**
     * Запрещает квитовать записи из разных ностро-банков, даже если валюта
     * одна и набор сбалансирован (требование страницы /all-nostro).
     *
     * @return void
     */
    public function testManualMatchRejectsEntriesFromDifferentPools(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $poolA = \SmartMatchTestHelper::createPool((int)$company->id);
        $poolB = \SmartMatchTestHelper::createPool((int)$company->id);
        $accountA = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$poolA->id);
        $accountB = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$poolB->id);

        $a = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $accountA->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '50.00',
            'currency' => 'USD',
        ]);
        $b = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $accountB->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '50.00',
            'currency' => 'USD',
        ]);

        $result = (new MatchingService())->matchManual([$a->id, $b->id]);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('разных ностро-банков');

        $a->refresh();
        $b->refresh();
        verify($a->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($b->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $this->stdout('Ручное квитование записей из разных ностро-банков (pool A и pool B): отказ, записи остаются незаквитованными.');
    }

    /**
     * Запрещает квитовать набор, где у одного из счетов `pool_id IS NULL` —
     * такой счёт нельзя считать частью какого-либо ностро-банка.
     *
     * @return void
     */
    public function testManualMatchRejectsEntryWithoutPool(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $accountWithPool = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        // Создаём счёт без pool_id (поле nullable в БД)
        $accountNoPool = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        Yii::$app->db->createCommand()
            ->update('accounts', ['pool_id' => null], ['id' => $accountNoPool->id])
            ->execute();

        $a = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $accountWithPool->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '30.00',
            'currency' => 'USD',
        ]);
        $b = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $accountNoPool->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '30.00',
            'currency' => 'USD',
        ]);

        $result = (new MatchingService())->matchManual([$a->id, $b->id]);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('разных ностро-банков');

        $this->stdout('Ручное квитование набора, где у счёта pool_id IS NULL: отказ (счёт без ностро-банка нельзя квитовать).');
    }

    /**
     * Сбалансированный набор записей одного банка и одной валюты должен
     * успешно квитоваться — проверка единства банка не ломает базовый сценарий.
     *
     * @return void
     */
    public function testManualMatchAllowsSamePoolSameCurrency(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $accountL = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        $accountS = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);

        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $accountL->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '75.00',
            'currency' => 'USD',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $accountS->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '75.00',
            'currency' => 'USD',
        ]);

        $result = (new MatchingService())->matchManual([$ledger->id, $statement->id]);

        verify($result['success'])->true();
        verify($result['count'])->equals(2);

        $this->stdout('Ручное квитование двух счетов одного ностро-банка и валюты (USD 75=75): успешно, count=2 (проверка единства банка не ломает базовый сценарий).');
    }

    /**
     * Проверяет сценарий: unmatch clears whole match group.
     * @return void
     */
    public function testUnmatchClearsWholeMatchGroup(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $matchId = 'MTCHTEST' . strtoupper(bin2hex(random_bytes(4)));
        $first = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'match_id' => $matchId,
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);
        $second = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'match_id' => $matchId,
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);

        verify(NostroEntry::find()->where(['match_id' => $matchId, 'company_id' => $company->id])->count())->equals(2);

        $result = (new MatchingService())->unmatch($matchId, (int)$company->id);

        verify($result['success'])->true();
        verify($result['count'])->equals(2);

        $first->refresh();
        $second->refresh();
        verify($first->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($second->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($first->match_id)->null();
        verify($second->matched_at)->null();

        $this->stdout('Расквитование группы по match_id: обе записи группы переведены в U, match_id и matched_at очищены.');
    }

    /**
     * Проверяет сценарий: calc summary returns ledger statement counters and diff.
     * @return void
     */
    public function testCalcSummaryReturnsLedgerStatementCountersAndDiff(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'amount' => '100.00',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'amount' => '70.00',
        ]);

        verify(NostroEntry::find()->where(['id' => [$ledger->id, $statement->id], 'company_id' => $company->id])->count())->equals(2);

        $summary = (new MatchingService())->calcSummary([$ledger->id, $statement->id], (int)$company->id);

        verify($summary['sum_ledger'])->equals(100.0);
        verify($summary['sum_statement'])->equals(70.0);
        verify($summary['diff'])->equals(30.0);
        verify($summary['cnt_ledger'])->equals(1);
        verify($summary['cnt_statement'])->equals(1);

        $this->stdout('calcSummary по набору (Ledger 100, Statement 70): суммы и счётчики корректны, diff=30.');
    }

    // ── TC-040 ────────────────────────────────────────────────────────────

    /**
     * TC-040. В наборе есть уже сквитованная запись: количество найденных
     * незаквитованных не совпадает с числом ID → отказ.
     *
     * @return void
     */
    public function testManualMatchRejectsWhenSomeEntryAlreadyMatched(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $unmatched = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '100.00',
        ]);
        $matched = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT, 'amount' => '100.00',
            'match_id' => 'MTCH00009999', 'match_status' => NostroEntry::STATUS_MATCHED,
        ]);

        $result = (new MatchingService())->matchManual([$unmatched->id, $matched->id]);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('Часть записей недоступна');
        $unmatched->refresh();
        verify($unmatched->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $this->stdout('TC-040: в наборе есть уже сквитованная запись → отказ «Часть записей недоступна», незаквитованная не трогается.');
    }

    // ── TC-041 ────────────────────────────────────────────────────────────

    /**
     * TC-041. Сбалансированный набор более чем из двух записей: два Ledger Debit
     * и один Statement Credit на ту же сумму квитуются успешно.
     *
     * @return void
     */
    public function testManualMatchBalancesMultiEntryNreSet(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $l1 = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '100.00',
        ]);
        $l2 = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '50.00',
        ]);
        $s1 = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT, 'amount' => '150.00',
        ]);

        $result = (new MatchingService())->matchManual([$l1->id, $l2->id, $s1->id]);

        verify($result['success'])->true();
        verify($result['count'])->equals(3);

        $this->stdout('TC-041: сбалансированный набор 2×Ledger Debit (100+50) + Statement Credit 150 → успешно, count=3.');
    }

    // ── TC-042 ────────────────────────────────────────────────────────────

    /**
     * TC-042. NRE-набор только из Ledger (нет Statement): балансировка L/S
     * пропускается (нет обеих сторон) и набор квитуется.
     *
     * @return void
     */
    public function testManualMatchLedgerOnlySetSkipsBalanceCheck(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $l1 = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '100.00',
        ]);
        $l2 = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '50.00',
        ]);

        $result = (new MatchingService())->matchManual([$l1->id, $l2->id]);

        verify($result['success'])->true();
        verify($result['count'])->equals(2);

        $this->stdout('TC-042: NRE-набор только из Ledger (без Statement) → проверка баланса L/S пропускается, квитование успешно (документирует текущее поведение).');
    }

    // ── TC-043 ────────────────────────────────────────────────────────────

    /**
     * TC-043. Валюта сравнивается регистронезависимо: `usd` и `USD` считаются
     * одной валютой и не блокируют квитование.
     *
     * @return void
     */
    public function testManualMatchCurrencyComparisonIsCaseInsensitive(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '100.00',
            'currency' => 'usd',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT, 'amount' => '100.00',
            'currency' => 'USD',
        ]);

        $result = (new MatchingService())->matchManual([$ledger->id, $statement->id]);

        verify($result['success'])->true();
        verify($result['count'])->equals(2);

        $this->stdout('TC-043: валюты «usd» и «USD» трактуются как одна (strtoupper) → квитование не блокируется, count=2.');
    }

    // ── TC-044 ────────────────────────────────────────────────────────────

    /**
     * TC-044. Одиночная запись с ненулевой суммой не квитуется.
     *
     * @return void
     */
    public function testManualMatchRejectsSingleNonZeroEntry(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $entry = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id, 'amount' => '100.00',
        ]);

        $result = (new MatchingService())->matchManual([$entry->id]);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('минимум 2 записи');
        $entry->refresh();
        verify($entry->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $this->stdout('TC-044: одиночная запись с ненулевой суммой 100 → отказ «минимум 2 записи», запись остаётся U.');
    }

    // ── TC-045 ────────────────────────────────────────────────────────────

    /**
     * TC-045. INV-набор с дисбалансом Debit/Credit: отказ с warning и разницей.
     *
     * @return void
     */
    public function testManualMatchRejectsImbalancedInvSet(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $debit = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '70.00',
        ]);
        $credit = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_CREDIT, 'amount' => '50.00',
        ]);

        $result = (new MatchingService())->matchManual([$debit->id, $credit->id], MatchingRule::SECTION_INV);

        verify($result['success'])->false();
        verify($result['warning'])->true();
        verify($result['diff'])->equals(20.0);

        $this->stdout('TC-045: INV-набор с дисбалансом Debit/Credit (70 против 50) → отказ с warning и diff=20.');
    }

    // ── TC-046 ────────────────────────────────────────────────────────────

    /**
     * TC-046. Ошибка БД при сохранении откатывает транзакцию: ни одна запись
     * не получает match_id. Эмулируется слишком длинным match_id (> varchar(255)).
     *
     * @return void
     */
    public function testManualMatchRollsBackOnDbError(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'amount' => '100.00',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT, 'amount' => '100.00',
        ]);

        $service = new class extends MatchingService {
            public function generateMatchId(): string
            {
                // 300 символов > varchar(255) → ошибка БД при save().
                return str_repeat('X', 300);
            }
        };

        $result = $service->matchManual([$ledger->id, $statement->id]);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('Ошибка БД');
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($statement->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $this->stdout('TC-046: ошибка БД при сохранении (match_id > 255 символов) → транзакция откатывается, обе записи остаются U.');
    }

    // ── TC-047 ────────────────────────────────────────────────────────────

    /**
     * TC-047. unmatch ограничен компанией: при общем match_id у двух компаний
     * расквитываются только записи указанной компании.
     *
     * @return void
     */
    public function testUnmatchIsScopedByCompany(): void
    {
        $matchId = 'MTCHSHARED01';

        $companyA = \SmartMatchTestHelper::createCompany();
        $poolA = \SmartMatchTestHelper::createPool((int)$companyA->id);
        $accountA = \SmartMatchTestHelper::createAccount((int)$companyA->id, (int)$poolA->id);
        $entryA = \SmartMatchTestHelper::createEntry([
            'company_id' => $companyA->id, 'account_id' => $accountA->id,
            'match_id' => $matchId, 'match_status' => NostroEntry::STATUS_MATCHED,
        ]);

        $companyB = \SmartMatchTestHelper::createCompany();
        $poolB = \SmartMatchTestHelper::createPool((int)$companyB->id);
        $accountB = \SmartMatchTestHelper::createAccount((int)$companyB->id, (int)$poolB->id);
        $entryB = \SmartMatchTestHelper::createEntry([
            'company_id' => $companyB->id, 'account_id' => $accountB->id,
            'match_id' => $matchId, 'match_status' => NostroEntry::STATUS_MATCHED,
        ]);

        $result = (new MatchingService())->unmatch($matchId, (int)$companyA->id);

        verify($result['success'])->true();
        verify($result['count'])->equals(1);
        $entryA->refresh();
        $entryB->refresh();
        verify($entryA->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($entryB->match_status)->equals(NostroEntry::STATUS_MATCHED);

        $this->stdout('TC-047: общий match_id у двух компаний → unmatch для компании A расквитовал только её запись, запись компании B осталась M.');
    }

    // ── TC-048 ────────────────────────────────────────────────────────────

    /**
     * TC-048. unmatch несуществующего match_id возвращает отказ.
     *
     * @return void
     */
    public function testUnmatchNonexistentMatchIdFails(): void
    {
        $company = \SmartMatchTestHelper::createCompany();

        $result = (new MatchingService())->unmatch('MTCHNOPE99', (int)$company->id);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('MTCHNOPE99');

        $this->stdout('TC-048: unmatch несуществующего match_id → отказ с упоминанием искомого ID.');
    }

    // ── TC-049 ────────────────────────────────────────────────────────────

    /**
     * TC-049. calcSummary не учитывает записи чужой компании: ID из другой
     * компании отбрасываются tenant-фильтром.
     *
     * @return void
     */
    public function testCalcSummaryExcludesOtherCompany(): void
    {
        $companyA = \SmartMatchTestHelper::createCompany();
        $poolA = \SmartMatchTestHelper::createPool((int)$companyA->id);
        $accountA = \SmartMatchTestHelper::createAccount((int)$companyA->id, (int)$poolA->id);
        $ledgerA = \SmartMatchTestHelper::createEntry([
            'company_id' => $companyA->id, 'account_id' => $accountA->id,
            'ls' => NostroEntry::LS_LEDGER, 'amount' => '100.00',
        ]);

        $companyB = \SmartMatchTestHelper::createCompany();
        $poolB = \SmartMatchTestHelper::createPool((int)$companyB->id);
        $accountB = \SmartMatchTestHelper::createAccount((int)$companyB->id, (int)$poolB->id);
        $ledgerB = \SmartMatchTestHelper::createEntry([
            'company_id' => $companyB->id, 'account_id' => $accountB->id,
            'ls' => NostroEntry::LS_LEDGER, 'amount' => '999.00',
        ]);

        $summary = (new MatchingService())->calcSummary([$ledgerA->id, $ledgerB->id], (int)$companyA->id);

        verify($summary['sum_ledger'])->equals(100.0);
        verify($summary['cnt_ledger'])->equals(1);

        $this->stdout('TC-049: calcSummary с ID из чужой компании → чужая запись (999) исключена, sum_ledger=100, cnt_ledger=1.');
    }

    // ── TC-050 ────────────────────────────────────────────────────────────

    /**
     * TC-050. Пустой набор ID: matchManual возвращает отказ.
     *
     * @return void
     */
    public function testManualMatchRejectsEmptyIdSet(): void
    {
        $result = (new MatchingService())->matchManual([]);

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('Незаквитованные записи не найдены');

        $this->stdout('TC-050: пустой набор ID → отказ «Незаквитованные записи не найдены».');
    }
}
