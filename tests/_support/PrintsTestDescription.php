<?php

/**
 * Трейт для печати человекочитаемого описания теста в STDOUT.
 *
 * Codeception показывает имена тестов в англоязычном «гуманизированном» виде,
 * поэтому в конце каждого теста удобно выводить русское описание сценария и
 * того, что было проверено. Подключается в unit-тестах: `use PrintsTestDescription;`
 * и вызывается как `$this->stdout('...')`.
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
