<?php

use app\models\Account;
use app\models\NostroBalance;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет JSON API ностро-счетов.
 */
class AccountApiCest
{
    private User $user;
    private $company;
    private $pool;
    private $account;
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
        $this->pool = SmartMatchTestHelper::createPool((int)$this->company->id, ['name' => 'OWN-POOL']);
        $this->account = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$this->pool->id, ['name' => 'OWN-ACC']);

        [$foreignCompany, $foreignPool, $foreignAccount] = SmartMatchTestHelper::createCompanyPoolAccount();
        $this->foreignPool = $foreignPool;
        $this->foreignAccount = $foreignAccount;

        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: список счетов ограничен текущей компанией.
     *
     * @return void
     */
    public function listReturnsOnlyCurrentCompanyAccounts(\FunctionalTester $I): void
    {
        $I->wantTo('Счета: список возвращает только счета текущей компании');
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/account/list']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(['OWN-ACC'], array_column($response['data'], 'name'));
    }

    /**
     * Выполняет тестовый сценарий: создание отвергает чужой ностро-банк.
     *
     * @return void
     */
    public function createRejectsForeignPool(\FunctionalTester $I): void
    {
        $I->wantTo('Счета: создание со ссылкой на чужой ностро-банк запрещено');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account/create']), [
            'name' => 'BAD-ACC',
            'currency' => 'rub',
            'account_type' => NostroBalance::LS_LEDGER,
            'country' => 'RU',
            'pool_id' => $this->foreignPool->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Ностро-банк не найден', $response['message']);
        Assert::assertSame(0, (int)Account::find()->where(['name' => 'BAD-ACC'])->count());
    }

    /**
     * Выполняет тестовый сценарий: обновление отвергает чужой ностро-банк.
     *
     * @return void
     */
    public function updateRejectsForeignPool(\FunctionalTester $I): void
    {
        $I->wantTo('Счета: обновление со ссылкой на чужой ностро-банк запрещено');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account/update']), [
            'id' => $this->account->id,
            'name' => 'OWN-ACC-EDITED',
            'currency' => 'RUB',
            'account_type' => NostroBalance::LS_LEDGER,
            'country' => 'RU',
            'pool_id' => $this->foreignPool->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Ностро-банк не найден', $response['message']);

        $this->account->refresh();
        Assert::assertSame((int)$this->pool->id, (int)$this->account->pool_id);
        Assert::assertSame('OWN-ACC', $this->account->name);
    }

    /**
     * Выполняет тестовый сценарий: создание счета создает начальный баланс.
     *
     * @return void
     */
    public function createCreatesInitialBalance(\FunctionalTester $I): void
    {
        $I->wantTo('Счета: создание счета добавляет начальный нулевой баланс');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account/create']), [
            'name' => 'NEW-SUSPENSE',
            'currency' => 'usd',
            'account_type' => NostroBalance::LS_STATEMENT,
            'country' => 'US',
            'is_suspense' => '1',
            'load_barsgl' => '1',
            'load_status' => 'L',
            'date_open' => '2026-02-01',
            'pool_id' => $this->pool->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        $accountId = (int)$response['data']['id'];
        Assert::assertSame('USD', $response['data']['currency']);

        $balance = NostroBalance::findOne([
            'company_id' => $this->company->id,
            'account_id' => $accountId,
            'value_date' => '2026-02-01',
        ]);
        Assert::assertNotNull($balance);
        Assert::assertSame(NostroBalance::LS_STATEMENT, $balance->ls_type);
        Assert::assertSame(NostroBalance::SECTION_INV, $balance->section);
        Assert::assertEquals(0.0, (float)$balance->closing_balance);
    }

    /**
     * Выполняет тестовый сценарий: нельзя удалить чужой счет.
     *
     * @return void
     */
    public function deleteRejectsForeignAccount(\FunctionalTester $I): void
    {
        $I->wantTo('Счета: удаление чужого счета запрещено');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/account/delete']), [
            'id' => $this->foreignAccount->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Счёт не найден', $response['message']);
        Assert::assertSame(1, (int)Account::find()->where(['id' => $this->foreignAccount->id])->count());
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
