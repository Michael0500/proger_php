<?php

namespace tests\unit\models;

use app\models\User;

/**
 * Тестовый класс `UserTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class UserTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    private User $activeUser;
    private User $deletedUser;

    /**
     * Подготавливает окружение перед тестом.
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
        $company = \SmartMatchTestHelper::createCompany();
        $this->activeUser = \SmartMatchTestHelper::createUser((int)$company->id, [
            'username' => 'admin',
            'email' => 'admin@example.test',
        ]);
        $this->deletedUser = \SmartMatchTestHelper::createUser((int)$company->id, [
            'username' => 'deleted',
            'email' => 'deleted@example.test',
            'status' => User::STATUS_DELETED,
        ]);
    }

    /**
     * Очищает состояние авторизации после теста.
     * @return void
     */
    protected function _after(): void
    {
        \Yii::$app->user->logout(false);
    }

    /**
     * Проверяет сценарий: find user by id.
     * @return void
     */
    public function testFindUserById(): void
    {
        verify($user = User::findIdentity($this->activeUser->id))->notEmpty();
        verify($user->username)->equals('admin');

        verify(User::findIdentity($this->deletedUser->id))->empty();

        $this->stdout('findIdentity по id: находит активного пользователя, удалённого (status=DELETED) — не возвращает.');
    }

    /**
     * Проверяет сценарий: find user by username.
     * @return void
     */
    public function testFindUserByUsername(): void
    {
        verify($user = User::findByUsername('admin'))->notEmpty();
        verify($user->id)->equals($this->activeUser->id);
        verify(User::findByUsername('deleted'))->empty();

        $this->stdout('findByUsername: находит активного по username, удалённого — не возвращает.');
    }

    /**
     * Проверяет внутренний вход найденного пользователя через cookie/session.
     * @return void
     */
    public function testLoginFoundUser(): void
    {
        $user = User::findByUsername('admin');

        verify(\Yii::$app->user->login($user, 0))->true();
        verify(\Yii::$app->user->isGuest)->false();
        verify((int)\Yii::$app->user->id)->equals((int)$this->activeUser->id);

        $this->stdout('Внутренний вход найденного пользователя через cookie/session: isGuest=false, id совпадает с активным пользователем.');
    }
}
