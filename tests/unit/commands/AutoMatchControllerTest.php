<?php

namespace tests\unit\commands;

use app\commands\AutoMatchController;
use app\models\NostroEntry;
use Yii;
use yii\console\ExitCode;

/**
 * Проверяет консольный wrapper автоквитования.
 *
 * AutoMatchController оркеструет проход по компаниям и делегирует квитование
 * `MatchingService`. Сами правила и SQL-логика покрыты `AutoMatchingServiceTest`;
 * здесь проверяются опции `--company`/`--account`, `status` без побочных
 * эффектов и установка `updated_by=NULL` для консольного запуска.
 */
class AutoMatchControllerTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
        // Гарантируем, что пользователь не залогинен — консольный сценарий.
        Yii::$app->user->logout(false);
    }

    // ── TC-150 ────────────────────────────────────────────────────────────

    /**
     * TC-150. Без фильтров `auto-match/run` обходит все компании и
     * квитует пары в каждой.
     *
     * @return void
     */
    public function testRunMatchesAllCompaniesWhenNoFilter(): void
    {
        $a = $this->seedCompanyWithMatchablePair('A-1');
        $b = $this->seedCompanyWithMatchablePair('B-1');

        $code = $this->runAutoMatch();

        verify($code)->equals(ExitCode::OK);
        verify($this->matchedCount((int)$a['company']->id))->equals(2);
        verify($this->matchedCount((int)$b['company']->id))->equals(2);

        $this->stdout('TC-150: auto-match/run без фильтров — обе компании обработаны, в каждой по сквитованной паре (всего 2+2 записи в статусе M).');
    }

    // ── TC-151 ────────────────────────────────────────────────────────────

    /**
     * TC-151. `--company` и `--account` ограничивают область одной компанией
     * и одним счётом; чужие записи и счета остаются незаквитованными.
     *
     * @return void
     */
    public function testRunRespectsCompanyAndAccountFilters(): void
    {
        $a = $this->seedCompanyWithMatchablePair('SCOPE-1');
        $b = $this->seedCompanyWithMatchablePair('SCOPE-2');

        // Дополнительная пара в первой компании на другом счёте — НЕ должна квитоваться.
        $extraAccount = \SmartMatchTestHelper::createAccount(
            (int)$a['company']->id, (int)$a['pool']->id, ['name' => 'EXTRA']
        );
        \SmartMatchTestHelper::createEntry([
            'company_id' => $a['company']->id, 'account_id' => $extraAccount->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => 'EXTRA',
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $a['company']->id, 'account_id' => $extraAccount->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => 'EXTRA',
        ]);

        $code = $this->runAutoMatch((int)$a['company']->id, (int)$a['account']->id);

        verify($code)->equals(ExitCode::OK);
        // Сквитована только пара на целевом счёте компании A.
        verify($this->matchedCount((int)$a['company']->id))->equals(2);
        // Чужая компания не тронута.
        verify($this->matchedCount((int)$b['company']->id))->equals(0);
        // Дополнительная пара того же компании, но на другом счёте — не сквитована.
        $unmatchedExtra = NostroEntry::find()->where([
            'account_id' => $extraAccount->id, 'match_status' => NostroEntry::STATUS_UNMATCHED,
        ])->count();
        verify((int)$unmatchedExtra)->equals(2);

        $this->stdout('TC-151: --company=A --account=accA — сквитована только пара на целевом счёте; чужая компания и другие счета той же компании не тронуты.');
    }

    // ── TC-152 ────────────────────────────────────────────────────────────

    /**
     * TC-152. `auto-match/status` выводит статистику без побочных эффектов:
     * статусы записей не меняются.
     *
     * @return void
     */
    public function testStatusDoesNotChangeDb(): void
    {
        $a = $this->seedCompanyWithMatchablePair('STAT-1');

        $code = (new AutoMatchController('auto-match', Yii::$app))->actionStatus();

        verify($code)->equals(ExitCode::OK);
        verify($this->matchedCount((int)$a['company']->id))->equals(0);
        $unmatched = NostroEntry::find()->where([
            'company_id' => $a['company']->id,
            'match_status' => NostroEntry::STATUS_UNMATCHED,
        ])->count();
        verify((int)$unmatched)->equals(2);

        $this->stdout('TC-152: auto-match/status — счётчики выведены, БД не изменена (записи остаются U).');
    }

    // ── TC-153 ────────────────────────────────────────────────────────────

    /**
     * TC-153. Консольный запуск (guest) пишет `updated_by=NULL` в
     * сквитованные записи — пользователь не известен в CLI-контексте.
     *
     * @return void
     */
    public function testConsoleMatchingLeavesUpdatedByNull(): void
    {
        $a = $this->seedCompanyWithMatchablePair('CONSOLE-1');

        verify(Yii::$app->user->isGuest)->true();

        $code = $this->runAutoMatch((int)$a['company']->id);
        verify($code)->equals(ExitCode::OK);

        $matched = NostroEntry::find()->where([
            'company_id' => $a['company']->id,
            'match_status' => NostroEntry::STATUS_MATCHED,
        ])->all();
        verify(count($matched))->equals(2);
        foreach ($matched as $entry) {
            verify($entry->updated_by)->null();
        }

        $this->stdout('TC-153: консольный (guest) запуск автоквитования — updated_by сквитованных записей IS NULL.');
    }

    // ── Хелперы ─────────────────────────────────────────────────────────────

    /**
     * Запускает actionRun контроллера авто-квитования с заданными опциями.
     *
     * @param int|null $companyId Значение опции --company.
     * @param int|null $accountId Значение опции --account.
     * @return int Код завершения.
     */
    private function runAutoMatch(?int $companyId = null, ?int $accountId = null): int
    {
        $controller = new AutoMatchController('auto-match', Yii::$app);
        $controller->company = $companyId;
        $controller->account = $accountId;
        return $controller->actionRun();
    }

    /**
     * Создаёт компанию с правилом и одной L+S парой, готовой к авто-квитованию
     * по совпадению `instruction_id`.
     *
     * @param string $key Уникальный ключ instruction_id для пары.
     * @return array `['company' => Company, 'pool' => AccountPool, 'account' => Account]`.
     */
    private function seedCompanyWithMatchablePair(string $key): array
    {
        $company = \SmartMatchTestHelper::createCompany();
        $pool = \SmartMatchTestHelper::createPool((int)$company->id);
        $account = \SmartMatchTestHelper::createAccount((int)$company->id, (int)$pool->id);
        \SmartMatchTestHelper::createRule((int)$company->id, [
            'match_instruction_id' => true,
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_LEDGER, 'dc' => NostroEntry::DC_DEBIT,
            'instruction_id' => $key,
        ]);
        \SmartMatchTestHelper::createEntry([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls' => NostroEntry::LS_STATEMENT, 'dc' => NostroEntry::DC_CREDIT,
            'instruction_id' => $key,
        ]);

        return ['company' => $company, 'pool' => $pool, 'account' => $account];
    }

    /**
     * Возвращает количество сквитованных записей компании.
     *
     * @param int $companyId ID компании.
     * @return int Количество записей со статусом M.
     */
    private function matchedCount(int $companyId): int
    {
        return (int)NostroEntry::find()->where([
            'company_id' => $companyId,
            'match_status' => NostroEntry::STATUS_MATCHED,
        ])->count();
    }
}
