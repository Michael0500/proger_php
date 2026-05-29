<?php

use app\models\Account;
use app\models\AccountPool;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет JSON API ностро-банков.
 */
class AccountPoolApiCest
{
    private User $user;
    private $company;
    private $pool;
    private $account;
    private $secondPool;
    private $secondAccount;
    private $foreignCategory;
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
        $this->pool = SmartMatchTestHelper::createPool((int)$this->company->id, ['name' => 'OWN-POOL']);
        $this->secondPool = SmartMatchTestHelper::createPool((int)$this->company->id, ['name' => 'SECOND-POOL']);
        $this->account = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->pool->id, ['name' => 'OWN-ACC']);
        $this->secondAccount = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->secondPool->id, ['name' => 'SECOND-ACC']);
        $freeAccount = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->pool->id, ['name' => 'FREE-ACC']);
        $freeAccount->pool_id = null;
        $freeAccount->save(false);
        $this->account = $freeAccount;

        $foreignCompany = SmartMatchTestHelper::createCompany();
        $this->foreignCategory = SmartMatchTestHelper::createCategory((int)$foreignCompany->id, ['name' => 'FOREIGN-CAT']);
        $foreignPool = SmartMatchTestHelper::createPool((int)$foreignCompany->id, ['name' => 'FOREIGN-POOL']);
        $this->foreignAccount = SmartMatchTestHelper::createAccount((int)$foreignCompany->id, (int)$foreignPool->id, ['name' => 'FOREIGN-ACC']);

        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: список ностро-банков ограничен текущей компанией.
     *
     * @return void
     */
    public function listReturnsOnlyCurrentCompanyPools(\FunctionalTester $I): void
    {
        $I->wantTo('Ностро-банки: список возвращает только банки текущей компании');
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/account-pool/list']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        $names = array_column($response['data'], 'name');
        sort($names);
        Assert::assertSame(['OWN-POOL', 'SECOND-POOL'], $names);
    }

    /**
     * Выполняет тестовый сценарий: создание не привязывает чужие счета.
     *
     * @return void
     */
    public function createAttachesOnlyCurrentCompanyFreeAccounts(\FunctionalTester $I): void
    {
        $I->wantTo('Ностро-банки: создание привязывает только свободные счета текущей компании');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account-pool/create']), [
            'name' => 'NEW-POOL',
            'description' => 'Новый банк',
            'ledger_accounts' => [$this->account->id, $this->foreignAccount->id],
            'statement_accounts' => [],
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        $newPoolId = (int)$response['data']['id'];

        $this->account->refresh();
        $this->foreignAccount->refresh();
        Assert::assertSame($newPoolId, (int)$this->account->pool_id);
        Assert::assertNotSame($newPoolId, (int)$this->foreignAccount->pool_id);
    }

    /**
     * Выполняет тестовый сценарий: привязка чужого счета запрещена.
     *
     * @return void
     */
    public function assignRejectsForeignAccount(\FunctionalTester $I): void
    {
        $I->wantTo('Ностро-банки: привязка чужого счета запрещена');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account-pool/assign-account']), [
            'pool_id' => $this->pool->id,
            'account_id' => $this->foreignAccount->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Счёт не найден', $response['message']);
    }

    /**
     * Выполняет тестовый сценарий: нельзя переместить банк в чужую категорию.
     *
     * @return void
     */
    public function moveRejectsForeignCategory(\FunctionalTester $I): void
    {
        $I->wantTo('Ностро-банки: перемещение в чужую категорию запрещено');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account-pool/move-to-category']), [
            'id' => $this->pool->id,
            'category_id' => $this->foreignCategory->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Категория не найдена', $response['message']);

        $this->pool->refresh();
        Assert::assertNull($this->pool->category_id);
    }

    /**
     * Выполняет тестовый сценарий: обновление не забирает счет у другого банка.
     *
     * @return void
     */
    public function updateDoesNotStealAccountFromAnotherPool(\FunctionalTester $I): void
    {
        $I->wantTo('Ностро-банки: обновление не забирает счет у другого банка');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account-pool/update']), [
            'id' => $this->pool->id,
            'name' => 'OWN-POOL-EDITED',
            'ledger_accounts' => [$this->secondAccount->id],
            'statement_accounts' => [],
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);

        $this->secondAccount->refresh();
        Assert::assertSame((int)$this->secondPool->id, (int)$this->secondAccount->pool_id);
    }

    /**
     * Выполняет тестовый сценарий: удаление банка отвязывает только его счета.
     *
     * @return void
     */
    public function deleteUnassignsOwnAccounts(\FunctionalTester $I): void
    {
        $I->wantTo('Ностро-банки: удаление отвязывает счета удаляемого банка');
        $assigned = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->pool->id, ['name' => 'DELETE-ACC']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account-pool/delete']), [
            'id' => $this->pool->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(0, (int)AccountPool::find()->where(['id' => $this->pool->id])->count());

        $assigned->refresh();
        Assert::assertNull($assigned->pool_id);
    }

    /**
     * Декодирует JSON-ответ текущей страницы.
     *
     * @return array
     */
    private function grabJson(\FunctionalTester $I): array
    {
        $decoded = json_decode($I->grabPageSource(), true);
        Assert::assertIsArray($decoded);
        return $decoded;
    }
}
