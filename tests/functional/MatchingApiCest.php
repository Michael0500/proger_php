<?php

use app\models\NostroEntry;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Тестовый класс `MatchingApiCest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class MatchingApiCest
{
    private User $user;
    private $company;
    private $account;
    private $foreignCompany;
    private $foreignAccount;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();
        [$this->company, , $this->account] = SmartMatchTestHelper::createCompanyPoolAccount();
        [$this->foreignCompany, , $this->foreignAccount] = SmartMatchTestHelper::createCompanyPoolAccount();
        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: manual match rejects foreign entry ids.
     *
     * @return void
     */
    public function manualMatchRejectsForeignEntryIds(\FunctionalTester $I): void
    {
        $I->wantTo('Ручное квитование: отклонить выборку, где есть запись из чужой компании');
        $own = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
        ]);
        $foreign = SmartMatchTestHelper::createEntry([
            'company_id' => $this->foreignCompany->id,
            'account_id' => $this->foreignAccount->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '100.00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/match-manual']), [
            'ids' => [$own->id, $foreign->id],
            'section' => 'NRE',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Часть записей недоступна или уже сквитована', $response['message']);

        $own->refresh();
        $foreign->refresh();
        Assert::assertSame(NostroEntry::STATUS_UNMATCHED, $own->match_status);
        Assert::assertSame(NostroEntry::STATUS_UNMATCHED, $foreign->match_status);
    }

    /**
     * Выполняет тестовый сценарий: manual match matches current company pair.
     *
     * @return void
     */
    public function manualMatchMatchesCurrentCompanyPair(\FunctionalTester $I): void
    {
        $I->wantTo('Ручное квитование: успешно квитует сбалансированную пару текущей компании');
        $ledger = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
        ]);
        $statement = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'dc' => NostroEntry::DC_CREDIT,
            'amount' => '100.00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/match-manual']), [
            'ids' => [$ledger->id, $statement->id],
            'section' => 'NRE',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(2, $response['count']);
        Assert::assertSame('MTCH00000001', $response['match_id']);

        $ledger->refresh();
        $statement->refresh();
        Assert::assertSame(NostroEntry::STATUS_MATCHED, $ledger->match_status);
        Assert::assertSame($ledger->match_id, $statement->match_id);
    }

    /**
     * Выполняет тестовый сценарий: unmatch does not touch foreign company rows with same match id.
     *
     * @return void
     */
    public function unmatchDoesNotTouchForeignCompanyRowsWithSameMatchId(\FunctionalTester $I): void
    {
        $I->wantTo('Расквитование: не трогает записи чужой компании с тем же match_id');
        $ownFirst = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'match_id' => 'MTCHSAME',
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);
        $ownSecond = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'match_id' => 'MTCHSAME',
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);
        $foreign = SmartMatchTestHelper::createEntry([
            'company_id' => $this->foreignCompany->id,
            'account_id' => $this->foreignAccount->id,
            'match_id' => 'MTCHSAME',
            'match_status' => NostroEntry::STATUS_MATCHED,
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/unmatch']), [
            'match_id' => 'MTCHSAME',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(2, $response['count']);

        $ownFirst->refresh();
        $ownSecond->refresh();
        $foreign->refresh();
        Assert::assertSame(NostroEntry::STATUS_UNMATCHED, $ownFirst->match_status);
        Assert::assertSame(NostroEntry::STATUS_UNMATCHED, $ownSecond->match_status);
        Assert::assertSame(NostroEntry::STATUS_MATCHED, $foreign->match_status);
        Assert::assertSame('MTCHSAME', $foreign->match_id);
    }

    /**
     * Выполняет тестовый сценарий: calc summary uses only current company rows.
     *
     * @return void
     */
    public function calcSummaryUsesOnlyCurrentCompanyRows(\FunctionalTester $I): void
    {
        $I->wantTo('CalcSummary: суммирует только записи текущей компании');
        $own = SmartMatchTestHelper::createEntry([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'ls' => NostroEntry::LS_LEDGER,
            'amount' => '100.00',
        ]);
        $foreign = SmartMatchTestHelper::createEntry([
            'company_id' => $this->foreignCompany->id,
            'account_id' => $this->foreignAccount->id,
            'ls' => NostroEntry::LS_STATEMENT,
            'amount' => '90.00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/matching/calc-summary']), [
            'ids' => [$own->id, $foreign->id],
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertEquals(100.0, $response['data']['sum_ledger']);
        Assert::assertEquals(0.0, $response['data']['sum_statement']);
        Assert::assertEquals(100.0, $response['data']['diff']);
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
