<?php

use app\models\NostroEntry;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `NostroEntryApiCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class NostroEntryApiCest
{
    private User $user;
    private $company;
    private $account;
    private $foreignAccount;
    private $ownEntry;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        [$this->company, , $this->account] = SmartMatchTestHelper::createCompanyPoolAccount();
        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);

        [$foreignCompany, , $this->foreignAccount] = SmartMatchTestHelper::createCompanyPoolAccount();

        $this->ownEntry = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'instruction_id' => 'OWN',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $foreignCompany->id,
            'account_id' => $this->foreignAccount->id,
            'instruction_id' => 'FOREIGN',
        ]);

        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: list returns only current company entries.
     *
     * @return void
     */
    public function listReturnsOnlyCurrentCompanyEntries(\FunctionalTester $I): void
    {
        $I->wantTo('Список записей: возвращает только записи текущей компании');
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/nostro-entry/list']), ['limit' => 10]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, $response['total']);
        Assert::assertSame('OWN', $response['data'][0]['instruction_id']);
    }

    /**
     * Выполняет тестовый сценарий: create normalizes money and uppercases currency.
     *
     * @return void
     */
    public function createNormalizesMoneyAndUppercasesCurrency(\FunctionalTester $I): void
    {
        $I->wantTo('Создание записи: нормализует сумму и приводит валюту к верхнему регистру');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-entry/create']), [
            'account_id' => $this->account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '1 234,56',
            'currency' => 'rub',
            'value_date' => '2026-01-20',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame('RUB', $response['data']['currency']);
        Assert::assertEquals(1234.56, (float)$response['data']['amount']);
    }

    /**
     * Выполняет тестовый сценарий: create rejects account from another company.
     *
     * @return void
     */
    public function createRejectsAccountFromAnotherCompany(\FunctionalTester $I): void
    {
        $I->wantTo('Создание записи: отклоняет счёт чужой компании');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-entry/create']), [
            'account_id' => $this->foreignAccount->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '10.00',
            'currency' => 'RUB',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Счёт не найден', $response['message']);
    }

    /**
     * Выполняет тестовый сценарий: update rejects account from another company.
     *
     * @return void
     */
    public function updateRejectsAccountFromAnotherCompany(\FunctionalTester $I): void
    {
        $I->wantTo('Обновление записи: отклоняет смену счёта на счёт чужой компании');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-entry/update']), [
            'id' => $this->ownEntry->id,
            'account_id' => $this->foreignAccount->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '50.00',
            'currency' => 'RUB',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Счёт не найден', $response['message']);

        $this->ownEntry->refresh();
        Assert::assertSame((int)$this->account->id, (int)$this->ownEntry->account_id);
    }

    /**
     * Выполняет тестовый сценарий: grab json.
     *
     * @return void
     */
    private function grabJson(\FunctionalTester $I): array
    {
        $decoded = json_decode($I->grabPageSource(), true);
        Assert::assertIsArray($decoded);
        return $decoded;
    }
}
