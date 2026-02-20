<?php

namespace app\commands;

use yii\console\Controller;
use app\models\User;
use yii\helpers\Console;

class UserController extends Controller
{
    public function actionCreateAdmin($username, $email, $password)
    {
        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->setPassword($password);
        $user->generateAuthKey();
        $user->status = User::STATUS_ACTIVE;

        if ($user->save()) {
            $auth = \Yii::$app->authManager;
            $adminRole = $auth->getRole('admin');
            $auth->assign($adminRole, $user->id);

            $this->stdout("Администратор создан успешно! ID: {$user->id}\n", Console::FG_GREEN);
        } else {
            $this->stderr("Ошибка создания администратора:\n");
            foreach ($user->errors as $error) {
                $this->stderr("  - " . implode("\n    ", $error) . "\n");
            }
        }
    }
}