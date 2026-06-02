<?php

use app\models\NostroEntry;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `SecurityApiCest`.
 *
 * Проверяет защиту JSON API от SQL-инъекций через пользовательские фильтры
 * и недопущение неавторизованного доступа к защищённым endpoint'ам.
 */
class SecurityApiCest
{
    private User $user;
    private $company;
    private $pool;
    private $account;

    /**
     * Подготавливает окружение перед тестом.
     *
     * Создаёт компанию/пользователя, но не логинит — тесты сами решают,
     * нужен ли им аутентифицированный контекст.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        [$this->company, $this->pool, $this->account] = SmartMatchTestHelper::createCompanyPoolAccount();
        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
    }

    /**
     * TC-134. Поиск по `filters.search_value` использует параметризованный
     * `ilike` — SQL-метасимволы трактуются как литерал, инъекция не проходит.
     *
     * @return void
     */
    public function listFiltersSanitizeSearchValueAgainstInjection(\FunctionalTester $I): void
    {
        $I->wantTo('Безопасность: filters.search_value не позволяет SQL-инъекцию (литерал ilike)');
        $I->amLoggedInAs($this->user);
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 1, 'match_id' => 'MTCH-SAFE-1', 'instruction_id' => 'NORMAL-A',
        ]);
        SmartMatchTestHelper::createArchivedEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'original_id' => 2, 'match_id' => 'MTCH-SAFE-2', 'instruction_id' => 'NORMAL-B',
        ]);

        $payload = "' OR 1=1--";
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/archive/list']), [
            'filters' => json_encode([
                'search_field' => 'instruction_id',
                'search_value' => $payload,
            ]),
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        // Без экранирования инъекция вернула бы 2 строки. Параметризованный ilike → 0.
        Assert::assertSame(0, (int)$response['total']);
    }

    /**
     * TC-135. Параметр `pool_id` приводится к `int`: SQL-метасимволы безопасны,
     * таблица `accounts` после запроса остаётся в базе.
     *
     * @return void
     */
    public function listCastsPoolIdSafelyAgainstInjection(\FunctionalTester $I): void
    {
        $I->wantTo('Безопасность: pool_id приводится к int — попытка инъекции безопасна');
        $I->amLoggedInAs($this->user);
        SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'instruction_id' => 'OWN',
        ]);

        $malicious = "1; DROP TABLE accounts; --";
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/nostro-entry/list']), [
            'pool_id' => $malicious,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        // Таблица accounts должна по-прежнему существовать.
        $accountsExist = (bool)\Yii::$app->db->createCommand(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'accounts'"
        )->queryScalar();
        Assert::assertTrue($accountsExist);
    }

    /**
     * TC-137. Гость не может выполнить защищённый POST: AccessControl
     * редиректит на login (302) и состояние БД не меняется.
     *
     * @return void
     */
    public function guestPostToProtectedApiIsBlocked(\FunctionalTester $I): void
    {
        $I->wantTo('Безопасность: гость не может POST к защищённому API (редирект 302, БД без изменений)');
        $entry = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id, 'account_id' => $this->account->id,
            'amount' => '0.00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/match-manual']), [
            'ids' => [$entry->id],
        ]);

        $I->seeResponseCodeIs(302);
        $entry->refresh();
        Assert::assertSame(NostroEntry::STATUS_UNMATCHED, $entry->match_status);
        Assert::assertNull($entry->match_id);
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
