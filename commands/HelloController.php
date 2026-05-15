<?php

/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace app\commands;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Пример консольной команды Yii.
 *
 * Команда оставлена как стандартный пример и выводит переданное сообщение.
 */
class HelloController extends Controller
{
    /**
     * Выводит сообщение в консоль.
     *
     * @param string $message Сообщение для вывода.
     * @return int Код завершения команды.
     */
    public function actionIndex($message = 'hello world')
    {
        echo $message . "\n";

        return ExitCode::OK;
    }
}
