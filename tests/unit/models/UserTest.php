<?php

namespace tests\unit\models;

use app\models\User;
use yii\base\NotSupportedException;

/**
 * Тестовый класс `UserTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class UserTest extends \Codeception\Test\Unit
{
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
            'password' => 'admin',
        ]);
        $this->deletedUser = \SmartMatchTestHelper::createUser((int)$company->id, [
            'username' => 'deleted',
            'email' => 'deleted@example.test',
            'status' => User::STATUS_DELETED,
        ]);
    }

    /**
     * Проверяет сценарий: find user by id.
     * @return void
     */
    public function testFindUserById()
    {
        verify($user = User::findIdentity($this->activeUser->id))->notEmpty();
        verify($user->username)->equals('admin');

        verify(User::findIdentity($this->deletedUser->id))->empty();
    }

    /**
     * Проверяет сценарий: find user by access token.
     * @return void
     */
    public function testFindUserByAccessToken()
    {
        $this->expectException(NotSupportedException::class);
        User::findIdentityByAccessToken('unsupported-token');
    }

    /**
     * Проверяет сценарий: find user by username.
     * @return void
     */
    public function testFindUserByUsername()
    {
        verify($user = User::findByUsername('admin'))->notEmpty();
        verify($user->id)->equals($this->activeUser->id);
        verify(User::findByUsername('deleted'))->empty();
    }

    /**
     * @depends testFindUserByUsername
     */
    public function testValidateUser()
    {
        $user = User::findByUsername('admin');
        verify($user->validateAuthKey($this->activeUser->auth_key))->notEmpty();
        verify($user->validateAuthKey('wrong-key'))->empty();

        verify($user->validatePassword('admin'))->notEmpty();
        verify($user->validatePassword('123456'))->empty();
    }
}
