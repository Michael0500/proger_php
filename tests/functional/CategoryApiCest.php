<?php

use app\models\Category;
use app\models\User;
use PHPUnit\Framework\Assert;

/**
 * Проверяет JSON API категорий сайдбара.
 */
class CategoryApiCest
{
    private User $user;
    private $company;
    private $category;
    private $pool;
    private $foreignCategory;
    private $foreignPool;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    public function _before(\FunctionalTester $I): void
    {
        SmartMatchTestHelper::resetDatabase();

        $this->company = SmartMatchTestHelper::createCompany();
        $this->category = SmartMatchTestHelper::createCategory((int)$this->company->id, ['name' => 'OWN-CAT']);
        $this->pool = SmartMatchTestHelper::createPool((int)$this->company->id, [
            'name' => 'OWN-POOL',
            'category_id' => $this->category->id,
        ]);

        $foreignCompany = SmartMatchTestHelper::createCompany();
        $this->foreignCategory = SmartMatchTestHelper::createCategory((int)$foreignCompany->id, ['name' => 'FOREIGN-CAT']);
        $this->foreignPool = SmartMatchTestHelper::createPool((int)$foreignCompany->id, [
            'name' => 'FOREIGN-POOL',
            'category_id' => $this->foreignCategory->id,
        ]);

        $this->user = SmartMatchTestHelper::createUser((int)$this->company->id);
        $I->amLoggedInAs($this->user);
    }

    /**
     * Выполняет тестовый сценарий: список категорий ограничен текущей компанией.
     *
     * @return void
     */
    public function getCategoriesReturnsOnlyCurrentCompanyTree(\FunctionalTester $I): void
    {
        $I->wantTo('Категории: список возвращает только дерево текущей компании');
        $I->sendAjaxGetRequest(\yii\helpers\Url::to(['/category/get-categories']));
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertCount(1, $response['data']);
        Assert::assertSame('OWN-CAT', $response['data'][0]['name']);
        Assert::assertCount(1, $response['data'][0]['pools']);
        Assert::assertSame('OWN-POOL', $response['data'][0]['pools'][0]['name']);
    }

    /**
     * Выполняет тестовый сценарий: создание категории привязывает её к текущей компании.
     *
     * @return void
     */
    public function createUsesCurrentUserCompany(\FunctionalTester $I): void
    {
        $I->wantTo('Категории: создание использует компанию текущего пользователя');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/category/create']), [
            'name' => 'NEW-CAT',
            'description' => 'Новая категория',
        ]);
        $response = $this->grabJson($I);

        Assert::assertTrue($response['success']);
        Assert::assertSame(1, (int)Category::find()->where([
            'company_id' => $this->company->id,
            'name' => 'NEW-CAT',
        ])->count());
    }

    /**
     * Выполняет тестовый сценарий: нельзя обновить чужую категорию.
     *
     * @return void
     */
    public function updateRejectsForeignCategory(\FunctionalTester $I): void
    {
        $I->wantTo('Категории: обновление чужой категории запрещено');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/category/update']), [
            'id' => $this->foreignCategory->id,
            'name' => 'HACKED',
            'description' => 'Попытка изменения',
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Категория не найдена', $response['message']);

        $this->foreignCategory->refresh();
        Assert::assertSame('FOREIGN-CAT', $this->foreignCategory->name);
    }

    /**
     * Выполняет тестовый сценарий: нельзя удалить чужую категорию.
     *
     * @return void
     */
    public function deleteRejectsForeignCategory(\FunctionalTester $I): void
    {
        $I->wantTo('Категории: удаление чужой категории запрещено');
        $I->sendAjaxPostRequest(\yii\helpers\Url::to(['/category/delete']), [
            'id' => $this->foreignCategory->id,
        ]);
        $response = $this->grabJson($I);

        Assert::assertFalse($response['success']);
        Assert::assertSame('Категория не найдена', $response['message']);
        Assert::assertSame(1, (int)Category::find()->where(['id' => $this->foreignCategory->id])->count());
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
