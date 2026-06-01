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
            'statement_number' => 'DUP-001',
        ]);

        $duplicate = new NostroBalance([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => 'DUP-001',
            'currency' => 'RUB',
            'value_date' => '2026-01-11',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '0.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
            'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
        ]);

        verify($duplicate->checkStatementSequence())->stringContainsString('Дублирующийся номер');

        $this->stdout('Последовательность выписок: повтор statement_number на счёте → сообщение «Дублирующийся номер».');
    }
}
