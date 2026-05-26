<?php

use app\models\NostroBalance;
use app\models\NostroBalanceArchive;
use app\models\NostroBalanceAudit;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет API архива балансов.
 */
class BalanceArchiveApiCest
{
    private User $user;
    private $company;
    private $account;

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
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: batch переносит старые балансы в архив.
     *
     * @return void
     */
    public function runBatchMovesOldBalancesToArchiveAndKeepsAudit(\FunctionalTester $I): void
    {
        $I->wantTo('Архив балансов: batch переносит старые балансы и пишет аудит');
        SmartMatchTestHelper::createArchiveSettings((int)$this->company->id, ['archive_after_days' => 1]);
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'value_date' => '2026-01-01',
            'status' => NostroBalance::STATUS_NORMAL,
        ]);
        SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'ls_type' => NostroBalance::LS_STATEMENT,
            'statement_number' => 'ST-001',
            'value_date' => '2026-01-02',
            'status' => NostroBalance::STATUS_CONFIRMED,
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/balance-archive/run-batch']), [
            'total_done' => 0,
            'total_all' => 2,
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(2, $response['archived']);
        Assert::assertSame(0, (int)NostroBalance::find()->count());
        Assert::assertSame(2, (int)NostroBalanceArchive::find()->count());
        Assert::assertSame(2, (int)NostroBalanceAudit::find()->where([
            'action' => NostroBalanceAudit::ACTION_ARCHIVE,
        ])->count());
    }

    /**
     * Выполняет тестовый сценарий: restore возвращает баланс из архива.
     *
     * @return void
     */
    public function restoreReturnsArchivedBalanceToActiveTable(\FunctionalTester $I): void
    {
        $I->wantTo('Архив балансов: restore возвращает баланс в активную таблицу');
        $archived = SmartMatchTestHelper::createArchivedBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'original_id' => 15,
            'value_date' => '2026-01-03',
            'closing_balance' => '250.00',
        ]);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/balance-archive/restore']), ['id' => $archived->id]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, (int)NostroBalance::find()->where([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'value_date' => '2026-01-03',
        ])->count());
        Assert::assertSame(0, (int)NostroBalanceArchive::find()->count());
        Assert::assertSame(1, (int)NostroBalanceAudit::find()->where([
            'action' => NostroBalanceAudit::ACTION_RESTORE,
        ])->count());
    }

    /**
     * Выполняет тестовый сценарий: история повторно архивного баланса показывает все циклы.
     *
     * @return void
     */
    public function historyKeepsPreviousArchiveRestoreCycleAfterRearchive(\FunctionalTester $I): void
    {
        $I->wantTo('Архив балансов: история повторной архивации содержит предыдущий цикл');
        SmartMatchTestHelper::createArchiveSettings((int)$this->company->id, ['archive_after_days' => 1]);
        $balance = SmartMatchTestHelper::createBalance([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'value_date' => '2026-01-01',
            'status' => NostroBalance::STATUS_NORMAL,
        ]);
        NostroBalanceAudit::log(
            (int)$balance->id,
            NostroBalanceAudit::ACTION_IMPORT,
            null,
            $balance->toApiArray(),
            'Ручной ввод'
        );

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/balance-archive/run-batch']), [
            'total_done' => 0,
            'total_all' => 1,
        ]);
        $firstArchive = NostroBalanceArchive::find()->one();
        Assert::assertNotNull($firstArchive);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/balance-archive/restore']), ['id' => $firstArchive->id]);
        $restoredBalance = NostroBalance::find()->one();
        Assert::assertNotNull($restoredBalance);
        Assert::assertNotSame((int)$balance->id, (int)$restoredBalance->id);

        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/balance-archive/run-batch']), [
            'total_done' => 0,
            'total_all' => 1,
        ]);
        $currentArchive = NostroBalanceArchive::find()->one();
        Assert::assertNotNull($currentArchive);

        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/balance-archive/history', 'id' => $currentArchive->id]));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame([
            NostroBalanceAudit::ACTION_ARCHIVE,
            NostroBalanceAudit::ACTION_RESTORE,
            NostroBalanceAudit::ACTION_ARCHIVE,
            NostroBalanceAudit::ACTION_IMPORT,
        ], array_column($response['data'], 'action'));
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
