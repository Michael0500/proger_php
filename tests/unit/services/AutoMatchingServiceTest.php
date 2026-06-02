<?php

namespace tests\unit\services;

use app\models\MatchingRule;
use app\models\NostroEntry;
use app\services\MatchingService;
use Yii;

/**
 * Тестовый класс `AutoMatchingServiceTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class AutoMatchingServiceTest extends \Codeception\Test\Unit
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
     * Проверяет сценарий: run rule matches unique ledger statement pair.
     * @return void
     */
    public function testRunRuleMatchesUniqueLedgerStatementPair(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_instruction_id' => true,
        ]);
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '250.00',
            'value_date' => '2026-01-15',
            'instruction_id' => 'AUTO-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '250.00',
            'value_date' => '2026-01-15',
            'instruction_id' => 'AUTO-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($statement->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($ledger->match_id)->equals($statement->match_id);
        verify($ledger->match_id)->equals('MTCH00000001');

        $this->stdout('Автоквитование: правило по instruction_id находит уникальную пару Ledger+Statement, обе записи → M с общим match_id (MTCH00000001).');
    }

    /**
     * Проверяет сценарий: run rule supports cross id search.
     * @return void
     */
    public function testRunRuleSupportsCrossIdSearch(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_instruction_id' => true,
            'match_end_to_end_id' => true,
            'cross_id_search' => true,
        ]);
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '10.00',
            'value_date' => '2026-01-15',
            'instruction_id' => 'CROSS-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '10.00',
            'value_date' => '2026-01-15',
            'end_to_end_id' => 'CROSS-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_id)->equals($statement->match_id);

        $this->stdout('Автоквитование cross_id: instruction_id одной стороны совпал с end_to_end_id другой → пара сквитована.');
    }

    /**
     * Проверяет сценарий: run rule returns zero when rule has no join conditions.
     * @return void
     */
    public function testRunRuleReturnsZeroWhenRuleHasNoJoinConditions(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_dc' => false,
            'match_amount' => false,
            'match_value_date' => false,
            'match_instruction_id' => false,
            'match_end_to_end_id' => false,
            'match_transaction_id' => false,
            'match_message_id' => false,
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
        ]);

        verify((new MatchingService())->runRule($rule, (int)$company->id, null))->equals(0);

        $this->stdout('Автоквитование: правило без единого условия JOIN → 0 пар.');
    }

    /**
     * Проверяет сценарий: auto match start and step process category scope.
     * @return void
     */
    public function testAutoMatchStartAndStepProcessCategoryScope(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $category = \SmartMatchTestHelper::createCategory((int)$company->id);
        $pool = \SmartMatchTestHelper::createPool((int)$company->id, ['category_id' => $category->id]);
        $account = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        \SmartMatchTestHelper::createRule((int)$company->id, ['match_instruction_id' => true]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'STEP-1',
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'STEP-1',
        ]);

        $service = new MatchingService();
        $start = $service->autoMatchStart((int)$company->id, null, MatchingRule::SECTION_NRE, 'category', (int)$category->id);

        verify($start['success'])->true();
        verify($start['total_steps'])->equals(1);
        verify($start['unmatched_count'])->equals(2);

        $step = $service->autoMatchStep($start['job_id']);

        verify($step['success'])->true();
        verify($step['is_finished'])->true();
        verify($step['total_matched'])->equals(1);

        $this->stdout('Пошаговое автоквитование в области категории: start вернул 1 шаг и 2 незаквитованных, step завершил с 1 сквитованной парой.');
    }

    // ── TC-020 ────────────────────────────────────────────────────────────

    /**
     * TC-020. pair_type=LL: две Ledger-записи с противоположным D/C и общим
     * instruction_id квитуются в одну пару (дедуп `a.id < b.id`, без дублей).
     *
     * @return void
     */
    public function testRunRuleMatchesLedgerToLedgerPair(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'pair_type' => MatchingRule::PAIR_LL,
            'match_instruction_id' => true,
        ]);
        $a = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'LL-1',
        ]);
        $b = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'LL-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $a->refresh();
        $b->refresh();
        verify($a->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($b->match_status)->equals(NostroEntry::STATUS_MATCHED);
        verify($a->match_id)->equals($b->match_id);

        $this->stdout('TC-020: pair_type=LL — две Ledger с противоположным D/C и общим instruction_id сквитованы в одну пару (дедуп a.id<b.id, без дублей).');
    }

    // ── TC-021 ────────────────────────────────────────────────────────────

    /**
     * TC-021. pair_type=SS: две Statement-записи квитуются в одну пару.
     *
     * @return void
     */
    public function testRunRuleMatchesStatementToStatementPair(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'pair_type' => MatchingRule::PAIR_SS,
            'match_instruction_id' => true,
        ]);
        $a = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'SS-1',
        ]);
        $b = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'SS-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $a->refresh();
        $b->refresh();
        verify($a->match_id)->equals($b->match_id);
        verify($a->match_id)->notEmpty();

        $this->stdout('TC-021: pair_type=SS — две Statement сквитованы в одну пару.');
    }

    // ── TC-022 ────────────────────────────────────────────────────────────

    /**
     * TC-022. match_dc=true требует противоположные D/C: пара с одинаковым D/C
     * не квитуется; то же правило с match_dc=false её сквитовывает.
     *
     * @return void
     */
    public function testRunRuleMatchDcRequiresOppositeSides(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'DC-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'DC-1',
        ]);

        $strictRule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_dc' => true,
            'match_instruction_id' => true,
        ]);
        verify((new MatchingService())->runRule($strictRule, (int)$company->id, null))->equals(0);
        $ledger->refresh();
        verify($ledger->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $looseRule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_dc' => false,
            'match_instruction_id' => true,
        ]);
        verify((new MatchingService())->runRule($looseRule, (int)$company->id, null))->equals(1);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_id)->equals($statement->match_id);

        $this->stdout('TC-022: match_dc=true не матчит одинаковый D/C (0 пар); то же правило с match_dc=false сквитовывает пару.');
    }

    // ── TC-023 ────────────────────────────────────────────────────────────

    /**
     * TC-023. Дедупликация: один Ledger и два подходящих Statement дают ровно
     * одну пару; второй Statement остаётся незаквитованным.
     *
     * @return void
     */
    public function testRunRuleDeduplicatesWhenOneLedgerMatchesTwoStatements(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_instruction_id' => true,
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'DUP-1',
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'DUP-1',
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'DUP-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $unmatchedStatements = NostroEntry::find()
            ->where([
                'company_id' => $company->id,
                'ls' => NostroEntry::LS_STATEMENT,
                'match_status' => NostroEntry::STATUS_UNMATCHED,
            ])
            ->count();
        verify((int)$unmatchedStatements)->equals(1);

        $this->stdout('TC-023: один Ledger и два подходящих Statement → ровно 1 пара, второй Statement остаётся U (дедуп s_dedup).');
    }

    // ── TC-024 ────────────────────────────────────────────────────────────

    /**
     * TC-024. Правило только по amount + value_date (без ID): матчатся лишь
     * полностью совпадающие пары, расхождение суммы исключает квитование.
     *
     * @return void
     */
    public function testRunRuleMatchesByAmountAndValueDateOnly(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_dc' => true,
            'match_amount' => true,
            'match_value_date' => true,
            'match_instruction_id' => false,
        ]);
        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'amount' => '500.00', 'value_date' => '2026-01-20',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'amount' => '500.00', 'value_date' => '2026-01-20',
        ]);
        // Запись с другой суммой не должна квитоваться.
        $other = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'amount' => '499.00', 'value_date' => '2026-01-20',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(1);
        $ledger->refresh();
        $statement->refresh();
        $other->refresh();
        verify($ledger->match_id)->equals($statement->match_id);
        verify($other->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $this->stdout('TC-024: правило только по amount+value_date — сквитована совпадающая пара (500), запись с суммой 499 осталась U.');
    }

    // ── TC-025 ────────────────────────────────────────────────────────────

    /**
     * TC-025. scope `limitAccountIds` ограничивает обе стороны пары: запись на
     * счёте вне области не квитуется, пока область не расширена.
     *
     * @return void
     */
    public function testRunRuleScopeLimitsBothSides(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $accountA = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        $accountB = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, ['match_instruction_id' => true]);

        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $accountA->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'SCOPE-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $accountB->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'SCOPE-1',
        ]);

        // Область только accountA — Statement на accountB вне области, пар нет.
        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null, [(int)$accountA->id]);
        verify($matched)->equals(0);

        // Расширяем область на оба счёта — пара находится.
        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null, [(int)$accountA->id, (int)$accountB->id]);
        verify($matched)->equals(1);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_id)->equals($statement->match_id);

        $this->stdout('TC-025: scope limitAccountIds ограничивает обе стороны — только accountA → 0 пар; область [A,B] → 1 пара.');
    }

    // ── TC-026 ────────────────────────────────────────────────────────────

    /**
     * TC-026. Параметр accountId ограничивает только сторону A; сторона B может
     * быть на другом счёте того же ностро-банка.
     *
     * @return void
     */
    public function testRunRuleAccountIdLimitsOnlySideA(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $accountA = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        $accountB = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, ['match_instruction_id' => true]);

        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $accountA->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'SIDEA-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $accountB->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'SIDEA-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, (int)$accountA->id);

        verify($matched)->equals(1);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_id)->equals($statement->match_id);

        $this->stdout('TC-026: accountId ограничивает только сторону A — Ledger на accountA сквитован со Statement на accountB того же пула.');
    }

    // ── TC-027 ────────────────────────────────────────────────────────────

    /**
     * TC-027. Записи из разных ностро-банков (пулов) не квитуются: автоквитование
     * обрабатывает каждый пул отдельно.
     *
     * @return void
     */
    public function testRunRuleDoesNotMatchAcrossPools(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $poolA = \SmartMatchTestHelper::createPool((int)$company->id);
        $poolB = \SmartMatchTestHelper::createPool((int)$company->id);
        $accountA = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$poolA->id);
        $accountB = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$poolB->id);
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, ['match_instruction_id' => true]);

        $ledger = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $accountA->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'POOL-1',
        ]);
        $statement = \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $accountB->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'POOL-1',
        ]);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals(0);
        $ledger->refresh();
        $statement->refresh();
        verify($ledger->match_status)->equals(NostroEntry::STATUS_UNMATCHED);
        verify($statement->match_status)->equals(NostroEntry::STATUS_UNMATCHED);

        $this->stdout('TC-027: записи из разных пулов (A и B) не матчатся — автоквитование обрабатывает каждый пул отдельно (0 пар).');
    }

    // ── TC-028 ────────────────────────────────────────────────────────────

    /**
     * TC-028. autoMatch применяет правила по возрастанию priority; раньше
     * сквитованную пару следующее правило уже не трогает (порядок через onProgress).
     *
     * @return void
     */
    public function testAutoMatchAppliesRulesInPriorityOrder(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        \SmartMatchTestHelper::createRule((int)$company->id, [
            'name' => 'ByInstruction', 'priority' => 5,
            'match_instruction_id' => true,
        ]);
        \SmartMatchTestHelper::createRule((int)$company->id, [
            'name' => 'ByAmount', 'priority' => 10,
            'match_amount' => true, 'match_value_date' => true,
        ]);

        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'amount' => '300.00', 'value_date' => '2026-01-15', 'instruction_id' => 'PRIO-1',
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'amount' => '300.00', 'value_date' => '2026-01-15', 'instruction_id' => 'PRIO-1',
        ]);

        $order = [];
        $perRule = [];
        $result = (new MatchingService())->autoMatch(
            (int)$company->id,
            null,
            function ($i, $name, $matchedInRule, $total) use (&$order, &$perRule) {
                $order[] = $name;
                $perRule[$name] = $matchedInRule;
            }
        );

        verify($result['success'])->true();
        verify($result['matched'])->equals(1);
        verify($order)->equals(['ByInstruction', 'ByAmount']);
        verify($perRule['ByInstruction'])->equals(1);
        verify($perRule['ByAmount'])->equals(0);

        $this->stdout('TC-028: autoMatch применяет правила по возрастанию priority — порядок [ByInstruction, ByAmount]; пару забрало ByInstruction (1), ByAmount получил 0.');
    }

    // ── TC-029 ────────────────────────────────────────────────────────────

    /**
     * TC-029. Ошибка одного правила логируется и не останавливает остальные:
     * autoMatch продолжает обработку и возвращает success с сообщением об ошибке.
     *
     * @return void
     */
    public function testAutoMatchContinuesAfterRuleError(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        \SmartMatchTestHelper::createRule((int)$company->id, ['name' => 'BOOM', 'priority' => 5]);
        \SmartMatchTestHelper::createRule((int)$company->id, ['name' => 'OK', 'priority' => 10]);

        $service = new class extends MatchingService {
            /** @var string[] */
            public array $attempted = [];

            public function runRule(MatchingRule $rule, int $companyId, ?int $accountId, ?array $limitAccountIds = null): int
            {
                $this->attempted[] = $rule->name;
                if ($rule->name === 'BOOM') {
                    throw new \RuntimeException('boom');
                }
                return 1;
            }
        };

        $result = $service->autoMatch((int)$company->id);

        verify($result['success'])->true();
        verify($result['matched'])->equals(1);
        verify($result['rules_count'])->equals(2);
        verify($result['message'])->stringContainsString('Ошибки');
        verify($service->attempted)->equals(['BOOM', 'OK']);

        $this->stdout('TC-029: ошибка правила BOOM перехвачена и не остановила обработку — правило OK выполнилось, matched=1, в message есть «Ошибки».');
    }

    // ── TC-030 ────────────────────────────────────────────────────────────

    /**
     * TC-030. Нет активных правил: autoMatch и autoMatchStart возвращают отказ.
     *
     * @return void
     */
    public function testAutoMatchWithoutActiveRulesFails(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        \SmartMatchTestHelper::createRule((int)$company->id, ['is_active' => false]);

        $service = new MatchingService();
        $run = $service->autoMatch((int)$company->id);
        verify($run['success'])->false();
        verify($run['message'])->stringContainsString('Нет активных правил');

        $start = $service->autoMatchStart((int)$company->id);
        verify($start['success'])->false();
        verify($start['message'])->stringContainsString('Нет активных правил');

        $this->stdout('TC-030: нет активных правил — autoMatch и autoMatchStart возвращают отказ «Нет активных правил».');
    }

    // ── TC-031 ────────────────────────────────────────────────────────────

    /**
     * TC-031. autoMatchStep с неизвестным/истёкшим job_id возвращает отказ.
     *
     * @return void
     */
    public function testAutoMatchStepUnknownJobFails(): void
    {
        $result = (new MatchingService())->autoMatchStep('job_does_not_exist');

        verify($result['success'])->false();
        verify($result['message'])->stringContainsString('не найдено');

        $this->stdout('TC-031: autoMatchStep с неизвестным job_id → отказ «Задание не найдено или истекло».');
    }

    // ── TC-032 ────────────────────────────────────────────────────────────

    /**
     * TC-032. Повторный autoMatchStep по завершённому заданию идемпотентен:
     * is_finished=true, total_matched не меняется.
     *
     * @return void
     */
    public function testAutoMatchStepIsIdempotentWhenFinished(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $account = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        \SmartMatchTestHelper::createRule((int)$company->id, ['match_instruction_id' => true]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT, 'instruction_id' => 'STEP-2',
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT, 'instruction_id' => 'STEP-2',
        ]);

        $service = new MatchingService();
        $start = $service->autoMatchStart((int)$company->id);

        $first = $service->autoMatchStep($start['job_id']);
        verify($first['is_finished'])->true();
        verify($first['total_matched'])->equals(1);

        $second = $service->autoMatchStep($start['job_id']);
        verify($second['success'])->true();
        verify($second['is_finished'])->true();
        verify($second['total_matched'])->equals(1);

        $this->stdout('TC-032: повторный autoMatchStep по завершённому заданию идемпотентен — is_finished=true, total_matched остаётся 1.');
    }

    // ── TC-033 ────────────────────────────────────────────────────────────

    /**
     * TC-033. resolveScopeAccounts: all → null (без ограничения); pool/category →
     * список счетов; category без пулов → пустой массив.
     *
     * @return void
     */
    public function testResolveScopeAccountsBranches(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $category = \SmartMatchTestHelper::createCategory((int)$company->id);
        $pool = \SmartMatchTestHelper::createPool((int)$company->id, ['category_id' => $category->id]);
        $account = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        $emptyCategory = \SmartMatchTestHelper::createCategory((int)$company->id);

        $service = new MatchingService();

        verify($service->resolveScopeAccounts((int)$company->id, 'all', null))->null();

        $byPool = $service->resolveScopeAccounts((int)$company->id, 'pool', (int)$pool->id);
        verify($byPool)->equals([(int)$account->id]);

        $byCategory = $service->resolveScopeAccounts((int)$company->id, 'category', (int)$category->id);
        verify($byCategory)->equals([(int)$account->id]);

        $emptyScope = $service->resolveScopeAccounts((int)$company->id, 'category', (int)$emptyCategory->id);
        verify($emptyScope)->equals([]);

        $this->stdout('TC-033: resolveScopeAccounts — all→null (без ограничения), pool/category→список счетов, категория без пулов→[].');
    }

    /**
     * TC-033b. autoMatchStart по области без счетов возвращает отказ
     * «Нет счетов в выбранной области».
     *
     * @return void
     */
    public function testAutoMatchStartRejectsEmptyScope(): void
    {
        $company = \SmartMatchTestHelper::createCompany();
        $emptyPool = \SmartMatchTestHelper::createPool((int)$company->id);
        \SmartMatchTestHelper::createRule((int)$company->id, ['match_instruction_id' => true]);

        $start = (new MatchingService())->autoMatchStart(
            (int)$company->id, null, MatchingRule::SECTION_NRE, 'pool', (int)$emptyPool->id
        );

        verify($start['success'])->false();
        verify($start['message'])->stringContainsString('Нет счетов');

        $this->stdout('TC-033b: autoMatchStart по пулу без счетов → отказ «Нет счетов в выбранной области».');
    }

    // ── TC-034 ────────────────────────────────────────────────────────────

    /**
     * TC-034. generateMatchId инкрементирует sequence и форматирует как MTCH+8 цифр.
     *
     * @return void
     */
    public function testGenerateMatchIdIncrementsAndFormats(): void
    {
        $service = new MatchingService();
        verify($service->generateMatchId())->equals('MTCH00000001');
        verify($service->generateMatchId())->equals('MTCH00000002');
        verify($service->generateMatchId())->equals('MTCH00000003');

        $this->stdout('TC-034: generateMatchId инкрементирует sequence и форматирует MTCH+8 цифр (00000001..00000003).');
    }

    // ── TC-035 ────────────────────────────────────────────────────────────

    /**
     * TC-035. generateMatchId не порождает коллизий: серия вызовов даёт уникальные
     * строго возрастающие идентификаторы (атомарность nextval).
     *
     * @return void
     */
    public function testGenerateMatchIdProducesUniqueSequentialIds(): void
    {
        $service = new MatchingService();
        $ids = [];
        $prev = 0;
        for ($i = 0; $i < 50; $i++) {
            $id = $service->generateMatchId();
            verify(preg_match('/^MTCH\d{8}$/', $id))->equals(1);
            $num = (int)substr($id, 4);
            verify($num)->greaterThan($prev);
            $prev = $num;
            $ids[$id] = true;
        }
        verify(count($ids))->equals(50);

        $this->stdout('TC-035: серия из 50 generateMatchId даёт уникальные строго возрастающие идентификаторы (атомарность nextval).');
    }

    // ── TC-036 ────────────────────────────────────────────────────────────

    /**
     * TC-036. Набор пар больше batchSize (5000) обрабатывается циклом do/while
     * полностью: квитуются все 5001 пар.
     *
     * Нагрузочный сценарий: ~14 с (10k вставок + два batched UPDATE). Для
     * быстрого локального TDD можно исключить группой: `--skip-group slow`.
     *
     * @group slow
     * @return void
     */
    public function testRunRuleProcessesMoreThanOneBatch(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();
        $rule = \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_instruction_id' => true,
        ]);

        $pairs = 5001; // > batchSize=5000
        $this->bulkInsertPairs((int)$company->id, (int)$account->id, $pairs);

        $matched = (new MatchingService())->runRule($rule, (int)$company->id, null);

        verify($matched)->equals($pairs);
        $remaining = NostroEntry::find()
            ->where(['company_id' => $company->id, 'match_status' => NostroEntry::STATUS_UNMATCHED])
            ->count();
        verify((int)$remaining)->equals(0);

        $this->stdout('TC-036: набор 5001 пар (> batchSize 5000) обработан циклом do/while полностью — matched=5001, незаквитованных не осталось.');
    }

    /**
     * Массово вставляет уникальные L/S пары напрямую в nostro_entries
     * (минуя модельные хуки) для нагрузочных сценариев автоквитования.
     *
     * @param int $companyId ID компании.
     * @param int $accountId ID счёта.
     * @param int $count Количество пар (L+S).
     * @return void
     */
    private function bulkInsertPairs(int $companyId, int $accountId, int $count): void
    {
        $now = date('Y-m-d H:i:s');
        $cols = ['account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'source', 'match_status',
            'created_at', 'updated_at'];
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $key = 'BULK-' . $i;
            $rows[] = [$accountId, $companyId, 'L', 'Debit', '100.00', 'RUB',
                '2026-01-10', '2026-01-10', $key, 'TEST', 'U', $now, $now];
            $rows[] = [$accountId, $companyId, 'S', 'Credit', '100.00', 'RUB',
                '2026-01-10', '2026-01-10', $key, 'TEST', 'U', $now, $now];
        }
        Yii::$app->db->createCommand()
            ->batchInsert('{{%nostro_entries}}', $cols, $rows)
            ->execute();
    }
}
