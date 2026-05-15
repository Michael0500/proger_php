<?php

use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `AllNostroApiCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class AllNostroApiCest
{
    private User $user;
    private $company;
    private $poolA;
    private $poolB;
    private $accountA;
    private $accountB;
    private $foreignPool;
    private $foreignAccount;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        $this->company = SmartMatchTestHelper::createCompany();
        $this->poolA = SmartMatchTestHelper::createPool((int)$this->company->id, ['name' => 'BANK-A']);
        $this->poolB = SmartMatchTestHelper::createPool((int)$this->company->id, ['name' => 'BANK-B']);
        $this->accountA = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->poolA->id, ['name' => 'ACC-A']);
        $this->accountB = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->poolB->id, ['name' => 'ACC-B']);

        [$foreignCompany, $foreignPool, $foreignAccount] = SmartMatchTestHelper::createCompanyPoolAccount();
        $this->foreignPool = $foreignPool;
        $this->foreignAccount = $foreignAccount;

        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->accountA->id,
            'instruction_id' => 'POOL-A',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->accountB->id,
            'instruction_id' => 'POOL-B',
        ]);
        SmartMatchTestHelper::createEntry([
            'company_id' => $foreignCompany->id,
            'account_id' => $this->foreignAccount->id,
            'instruction_id' => 'FOREIGN',
        ]);

        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: list filters by selected nostro banks inside current company.
     *
     * @return void
     */
    public function listFiltersBySelectedNostroBanksInsideCurrentCompany(\FunctionalTester $I): void
    {
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/all-nostro/list']), [
            'limit' => 10,
            'filters' => json_encode(['pool_ids' => [$this->poolA->id]], JSON_UNESCAPED_UNICODE),
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, $response['total']);
        Assert::assertSame('POOL-A', $response['data'][0]['instruction_id']);
        Assert::assertSame('BANK-A', $response['data'][0]['pool_name']);
    }

    /**
     * Выполняет тестовый сценарий: list ignores foreign pool ids.
     *
     * @return void
     */
    public function listIgnoresForeignPoolIds(\FunctionalTester $I): void
    {
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/all-nostro/list']), [
            'limit' => 10,
            'filters' => json_encode(['pool_ids' => [$this->foreignPool->id]], JSON_UNESCAPED_UNICODE),
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(0, $response['total']);
        Assert::assertSame([], $response['data']);
    }

    /**
     * Выполняет тестовый сценарий: search accounts filters by pool and company.
     *
     * @return void
     */
    public function searchAccountsFiltersByPoolAndCompany(\FunctionalTester $I): void
    {
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/all-nostro/search-accounts']), [
            'q' => 'ACC',
            'pool_ids' => [$this->poolB->id],
        ]);
        $response = $this->grabJson($I);

        Assert::assertCount(1, $response['results']);
        Assert::assertSame((int)$this->accountB->id, (int)$response['results'][0]['id']);
        Assert::assertSame('ACC-B', $response['results'][0]['text']);
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
