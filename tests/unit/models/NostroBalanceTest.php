<?php

namespace tests\unit\models;

use app\models\NostroBalance;

/**
 * Тестовый класс `NostroBalanceTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class NostroBalanceTest extends \Codeception\Test\Unit
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
     * Проверяет сценарий: money validation allows signed decimal with two digits.
     * @return void
     */
    public function testMoneyValidationAllowsSignedDecimalWithTwoDigits(): void
    {
        $balance = new NostroBalance();

        $balance->opening_balance = '-123456789012345678.99';
        verify($balance->validate(['opening_balance']))->true();

        $balance->clearErrors();
        $balance->opening_balance = '10.999';
        verify($balance->validate(['opening_balance']))->false();

        $balance->clearErrors();
        $balance->opening_balance = '1234567890123456789.00';
        verify($balance->validate(['opening_balance']))->false();

        $this->stdout('Денежная валидация баланса decimal(20,2): знаковое 18+2 знака — ок; 3 знака после точки и 19 цифр до точки — отклоняются.');
    }

    /**
     * Проверяет сценарий: statement continuity detects opening mismatch.
     * @return void
     */
    public function testStatementContinuityDetectsOpeningMismatch(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();

        \SmartMatchTestHelper::createBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '001',
            'value_date' => '2026-01-10',
            'closing_balance' => '150.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
        ]);

        $ok = \SmartMatchTestHelper::createBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '002',
            'value_date' => '2026-01-11',
            'opening_balance' => '150.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
        ]);
        verify($ok->checkBalanceContinuity())->null();

        $bad = new NostroBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '003',
            'currency' => 'RUB',
            'value_date' => '2026-01-12',
            'opening_balance' => '149.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '149.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
            'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
        ]);

        verify($bad->checkBalanceContinuity())->stringContainsString('не совпадает');

        $this->stdout('Непрерывность Statement-балансов: opening следующего = closing предыдущего — ок; расхождение → сообщение «не совпадает».');
    }

    /**
     * Проверяет сценарий: statement sequence detects duplicate number.
     * @return void
     */
    public function testStatementSequenceDetectsDuplicateNumber(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();

        \SmartMatchTestHelper::createBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '2',
            'value_date' => '2026-05-22',
        ]);

        $duplicate = new NostroBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '2',
            'currency' => 'RUB',
            'value_date' => '2026-05-23',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '0.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
            'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
        ]);

        $message = $duplicate->checkStatementSequence();
        verify($message)->stringContainsString('Проверка не пройдена. Порядковый номер');
        verify($message)->stringContainsString('должен быть "3"');

        $this->stdout('Последовательность выписок: повтор номера поздней выписки после номера 2 → ошибка с ожидаемым номером 3.');
    }

    /**
     * Проверяет сценарий: statement sequence detects lower number for later statement.
     * @return void
     */
    public function testStatementSequenceDetectsLowerNumberForLaterStatement(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();

        \SmartMatchTestHelper::createBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '2',
            'value_date' => '2026-05-22',
        ]);

        $lower = new NostroBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '1',
            'currency' => 'RUB',
            'value_date' => '2026-05-23',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '0.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
            'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
        ]);

        $lower->runValidations(['enable_sequence_check' => true, 'enable_balance_check' => false]);

        verify($lower->status)->equals(NostroBalance::STATUS_ERROR);
        verify($lower->comment)->stringContainsString('Проверка не пройдена. Порядковый номер');
        verify($lower->comment)->stringContainsString('должен быть "3"');

        $this->stdout('Последовательность выписок: номер поздней записи меньше предыдущего → статус error и комментарий проверки.');
    }

    /**
     * Проверяет сценарий: statement sequence accepts next alphanumeric ordinal.
     * @return void
     */
    public function testStatementSequenceAcceptsNextAlphanumericOrdinal(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();

        \SmartMatchTestHelper::createBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '20240220K102350',
            'value_date' => '2026-05-22',
        ]);

        $next = new NostroBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => '20240220K102351',
            'currency' => 'RUB',
            'value_date' => '2026-05-23',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '0.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
            'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
        ]);

        verify($next->checkStatementSequence())->null();

        $this->stdout('Последовательность выписок: буквенно-числовой номер с числовым хвостом +1 принимается.');
    }

    // ── TC-120 ────────────────────────────────────────────────────────────

    /**
     * TC-120. statement_number обязателен для ls_type=S и не обязателен для L.
     *
     * @return void
     */
    public function testStatementNumberRequiredForStatementType(): void
    {
        [$company, , $account] = \SmartMatchTestHelper::createCompanyPoolAccount();

        $stmt = new NostroBalance([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'currency' => 'RUB', 'value_date' => '2026-01-10',
            'opening_balance' => '0.00', 'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '0.00', 'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE, 'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
        ]);
        verify($stmt->validate())->false();
        verify($stmt->errors)->arrayHasKey('statement_number');

        $ledger = new NostroBalance([
            'company_id' => $company->id, 'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_LEDGER,
            'currency' => 'RUB', 'value_date' => '2026-01-10',
            'opening_balance' => '0.00', 'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '0.00', 'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE, 'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
        ]);
        verify($ledger->validate())->true();

        $this->stdout('TC-120: statement_number обязателен для ls_type=S (ошибка валидации) и не требуется для ls_type=L.');
    }

    // ── TC-121 ────────────────────────────────────────────────────────────

    /**
     * TC-121. opening_dc/closing_dc валидируются как D или C (in range).
     *
     * @return void
     */
    public function testDcEnumRejectsInvalidValues(): void
    {
        $balance = new NostroBalance();

        $balance->opening_dc = 'X';
        verify($balance->validate(['opening_dc']))->false();
        $balance->clearErrors();

        $balance->closing_dc = 'Debit';
        verify($balance->validate(['closing_dc']))->false();
        $balance->clearErrors();

        $balance->opening_dc = NostroBalance::DC_DEBIT;
        $balance->closing_dc = NostroBalance::DC_CREDIT;
        verify($balance->validate(['opening_dc']))->true();
        verify($balance->validate(['closing_dc']))->true();

        $this->stdout('TC-121: opening_dc/closing_dc принимают только «D»/«C» (in range): «X» и «Debit» — отвергаются.');
    }
}
