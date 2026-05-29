<?php

use app\models\NostroBalance;
use app\models\NostroBalanceAudit;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет JSON API активных балансов Ностро.
 */
class NostroBalanceApiCest
{
    private User $user;
    private $company;
    private $pool;
    private $account;
    private $foreignAccount;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();

        [$this->company, $this->pool, $this->account] = SmartMatchTestHelper::createCompanyPoolAccount();
        [, , $this->foreignAccount] = SmartMatchTestHelper::createCompanyPoolAccount();

        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: список балансов ограничен текущей компанией.
     *
     * @return void
     */
    public function listReturnsOnlyCurrentCompanyBalances(\FunctionalTester $I): void
    {
        $I->wantTo('Балансы: список возвращает только балансы текущей компании');
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'statement_number' => null,
            'closing_balance' => '100.00',
        ]);
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->foreignAccount->company_id,
            'account_id' => $this->foreignAccount->id,
            'closing_balance' => '999.00',
        ]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/nostro-balance/list']), ['limit' => 10]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, $response['total']);
        Assert::assertEquals(100.00, (float)$response['data'][0]['closing_balance']);
    }

    /**
     * Выполняет тестовый сценарий: список балансов фильтруется по ностро-банку.
     *
     * @return void
     */
    public function listFiltersByPoolAndAccount(\FunctionalTester $I): void
    {
        $I->wantTo('Балансы: список фильтруется по ностро-банку и счету');
        $otherPool = SmartMatchTestHelper::createPool((int)$this->company->id, ['name' => 'OTHER-POOL']);
        $otherAccount = SmartMatchTestHelper::createAccount((int)$this->company->id, (int)$otherPool->id, ['name' => 'OTHER-ACC']);
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'closing_balance' => '100.00',
        ]);
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $otherAccount->id,
            'closing_balance' => '200.00',
        ]);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/nostro-balance/list']), [
            'limit' => 10,
            'filters' => json_encode([
                'pool_id' => $this->pool->id,
                'account_id' => $this->account->id,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, $response['total']);
        Assert::assertSame((int)$this->account->id, (int)$response['data'][0]['account_id']);
    }

    /**
     * Выполняет тестовый сценарий: создание отвергает счет чужой компании.
     *
     * @return void
     */
    public function createRejectsForeignAccount(\FunctionalTester $I): void
    {
        $I->wantTo('Балансы: создание со счетом чужой компании запрещено');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-balance/create']), [
            'account_id' => $this->foreignAccount->id,
            'ls_type' => NostroBalance::LS_LEDGER,
            'currency' => 'RUB',
            'value_date' => '2026-01-20',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '10.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Счёт не найден', $response['message']);
        Assert::assertSame(0, (int)NostroBalance::find()->where([
            'company_id' => $this->company->id,
            'value_date' => '2026-01-20',
        ])->count());
    }

    /**
     * Выполняет тестовый сценарий: ручное создание пишет аудит.
     *
     * @return void
     */
    public function createWritesImportAudit(\FunctionalTester $I): void
    {
        $I->wantTo('Балансы: ручное создание пишет аудит import');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-balance/create']), [
            'account_id' => $this->account->id,
            'ls_type' => NostroBalance::LS_LEDGER,
            'currency' => 'rub',
            'value_date' => '2026-01-21',
            'opening_balance' => '1 000,00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '1 250,50',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame('RUB', $response['data']['currency']);
        Assert::assertEquals(1250.50, (float)$response['data']['closing_balance']);
        Assert::assertSame(1, (int)NostroBalanceAudit::find()->where([
            'balance_id' => $response['data']['id'],
            'action' => NostroBalanceAudit::ACTION_IMPORT,
        ])->count());
    }

    /**
     * Выполняет тестовый сценарий: обновление отвергает счет чужой компании.
     *
     * @return void
     */
    public function updateRejectsForeignAccount(\FunctionalTester $I): void
    {
        $I->wantTo('Балансы: обновление со счетом чужой компании запрещено');
        $balance = SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'closing_balance' => '100.00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-balance/update']), [
            'id' => $balance->id,
            'account_id' => $this->foreignAccount->id,
            'ls_type' => NostroBalance::LS_LEDGER,
            'currency' => 'RUB',
            'value_date' => '2026-01-10',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '500.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Счёт не найден', $response['message']);

        $balance->refresh();
        Assert::assertSame((int)$this->account->id, (int)$balance->account_id);
        Assert::assertEquals(100.00, (float)$balance->closing_balance);
    }

    /**
     * Выполняет тестовый сценарий: подтверждение требует причину и пишет аудит.
     *
     * @return void
     */
    public function confirmRequiresReasonAndWritesAudit(\FunctionalTester $I): void
    {
        $I->wantTo('Балансы: подтверждение требует причину и пишет аудит');
        $balance = SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'status' => NostroBalance::STATUS_ERROR,
            'comment' => 'Ошибка проверки',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-balance/confirm']), ['id' => $balance->id]);
        $response = $this->grabJson($I);
        Assert::assertFalse($response['success']);
        Assert::assertSame('Укажите причину корректировки', $response['message']);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-balance/confirm']), [
            'id' => $balance->id,
            'reason' => 'Проверено вручную',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(NostroBalance::STATUS_CONFIRMED, $response['data']['status']);
        Assert::assertSame(1, (int)NostroBalanceAudit::find()->where([
            'balance_id' => $balance->id,
            'action' => NostroBalanceAudit::ACTION_CONFIRM,
        ])->count());
    }

    /**
     * Выполняет тестовый сценарий: нельзя удалить чужой баланс.
     *
     * @return void
     */
    public function deleteRejectsForeignBalance(\FunctionalTester $I): void
    {
        $I->wantTo('Балансы: удаление чужого баланса запрещено');
        $foreignBalance = SmartMatchTestHelper::createBalance([
            'company_id' => $this->foreignAccount->company_id,
            'account_id' => $this->foreignAccount->id,
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/nostro-balance/delete']), ['id' => $foreignBalance->id]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Запись не найдена', $response['message']);
        Assert::assertSame(1, (int)NostroBalance::find()->where(['id' => $foreignBalance->id])->count());
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
