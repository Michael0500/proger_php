<?php

/**
 * Трейт для печати человекочитаемого описания теста в STDOUT.
 *
 * Codeception показывает имена тестов в англоязычном «гуманизированном» виде,
 * поэтому в конце каждого теста удобно выводить русское описание сценария и
 * того, что было проверено. Подключается в unit-тестах: `use PrintsTestDescription;`
 * и вызывается как `$this->stdout('...')`.
 *
 * Применяется только в unit-тестах (`Codeception\Test\Unit`). Для функциональных
 * Cest используется `$I->wantTo('...')` (там $this — это Cest, не тест-объект).
 *
 * Важное предупреждение: не добавляйте `use Yii;` в Cest-файлы без namespace —
 * PHP выводит предупреждение «use statement with non-compound name 'Yii' has no
 * effect» в STDOUT, и в момент инициализации сессии получаете «Session cookie
 * parameters cannot be changed after headers have already been sent». В Cest
 * пишите `\Yii::$app->...` напрямую.
 */
trait PrintsTestDescription
{
    /**
     * Печатает описание сценария теста в STDOUT.
     *
     * @param string $message Описание теста и проверенного поведения.
     * @return void
     */
    protected function stdout(string $message): void
    {
        fwrite(STDOUT, "\n    → " . $message . "\n");
    }
}
