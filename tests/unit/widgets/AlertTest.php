<?php

namespace tests\unit\widgets;

use app\widgets\Alert;
use Yii;

/**
 * Тестовый класс `AlertTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class AlertTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /**
     * Проверяет сценарий: single error message.
     * @return void
     */
    public function testSingleErrorMessage()
    {
        $message = 'This is an error message';

        Yii::$app->session->setFlash('error', $message);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($message);
        verify($renderingResult)->stringContainsString('alert-danger');

        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-info');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: одиночное flash «error» рендерится с классом alert-danger и без классов других типов.');
    }

    /**
     * Проверяет сценарий: multiple error messages.
     * @return void
     */
    public function testMultipleErrorMessages()
    {
        $firstMessage = 'This is the first error message';
        $secondMessage = 'This is the second error message';

        Yii::$app->session->setFlash('error', [$firstMessage, $secondMessage]);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($firstMessage);
        verify($renderingResult)->stringContainsString($secondMessage);
        verify($renderingResult)->stringContainsString('alert-danger');

        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-info');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: массив flash «error» рендерит оба сообщения с классом alert-danger.');
    }

    /**
     * Проверяет сценарий: single danger message.
     * @return void
     */
    public function testSingleDangerMessage()
    {
        $message = 'This is a danger message';

        Yii::$app->session->setFlash('danger', $message);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($message);
        verify($renderingResult)->stringContainsString('alert-danger');

        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-info');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: одиночное flash «danger» рендерится с классом alert-danger.');
    }

    /**
     * Проверяет сценарий: multiple danger messages.
     * @return void
     */
    public function testMultipleDangerMessages()
    {
        $firstMessage = 'This is the first danger message';
        $secondMessage = 'This is the second danger message';

        Yii::$app->session->setFlash('danger', [$firstMessage, $secondMessage]);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($firstMessage);
        verify($renderingResult)->stringContainsString($secondMessage);
        verify($renderingResult)->stringContainsString('alert-danger');

        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-info');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: массив flash «danger» рендерит оба сообщения с классом alert-danger.');
    }

    /**
     * Проверяет сценарий: single success message.
     * @return void
     */
    public function testSingleSuccessMessage()
    {
        $message = 'This is a success message';

        Yii::$app->session->setFlash('success', $message);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($message);
        verify($renderingResult)->stringContainsString('alert-success');

        verify($renderingResult)->stringNotContainsString('alert-danger');
        verify($renderingResult)->stringNotContainsString('alert-info');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: одиночное flash «success» рендерится с классом alert-success.');
    }

    /**
     * Проверяет сценарий: multiple success messages.
     * @return void
     */
    public function testMultipleSuccessMessages()
    {
        $firstMessage = 'This is the first danger message';
        $secondMessage = 'This is the second danger message';

        Yii::$app->session->setFlash('success', [$firstMessage, $secondMessage]);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($firstMessage);
        verify($renderingResult)->stringContainsString($secondMessage);
        verify($renderingResult)->stringContainsString('alert-success');

        verify($renderingResult)->stringNotContainsString('alert-danger');
        verify($renderingResult)->stringNotContainsString('alert-info');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: массив flash «success» рендерит оба сообщения с классом alert-success.');
    }

    /**
     * Проверяет сценарий: single info message.
     * @return void
     */
    public function testSingleInfoMessage()
    {
        $message = 'This is an info message';

        Yii::$app->session->setFlash('info', $message);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($message);
        verify($renderingResult)->stringContainsString('alert-info');

        verify($renderingResult)->stringNotContainsString('alert-danger');
        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: одиночное flash «info» рендерится с классом alert-info.');
    }

    /**
     * Проверяет сценарий: multiple info messages.
     * @return void
     */
    public function testMultipleInfoMessages()
    {
        $firstMessage = 'This is the first info message';
        $secondMessage = 'This is the second info message';

        Yii::$app->session->setFlash('info', [$firstMessage, $secondMessage]);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($firstMessage);
        verify($renderingResult)->stringContainsString($secondMessage);
        verify($renderingResult)->stringContainsString('alert-info');

        verify($renderingResult)->stringNotContainsString('alert-danger');
        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-warning');

        $this->stdout('Alert: массив flash «info» рендерит оба сообщения с классом alert-info.');
    }

    /**
     * Проверяет сценарий: single warning message.
     * @return void
     */
    public function testSingleWarningMessage()
    {
        $message = 'This is a warning message';

        Yii::$app->session->setFlash('warning', $message);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($message);
        verify($renderingResult)->stringContainsString('alert-warning');

        verify($renderingResult)->stringNotContainsString('alert-danger');
        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-info');

        $this->stdout('Alert: одиночное flash «warning» рендерится с классом alert-warning.');
    }

    /**
     * Проверяет сценарий: multiple warning messages.
     * @return void
     */
    public function testMultipleWarningMessages()
    {
        $firstMessage = 'This is the first warning message';
        $secondMessage = 'This is the second warning message';

        Yii::$app->session->setFlash('warning', [$firstMessage, $secondMessage]);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($firstMessage);
        verify($renderingResult)->stringContainsString($secondMessage);
        verify($renderingResult)->stringContainsString('alert-warning');

        verify($renderingResult)->stringNotContainsString('alert-danger');
        verify($renderingResult)->stringNotContainsString('alert-success');
        verify($renderingResult)->stringNotContainsString('alert-info');

        $this->stdout('Alert: массив flash «warning» рендерит оба сообщения с классом alert-warning.');
    }

    /**
     * Проверяет сценарий: single mixed messages.
     * @return void
     */
    public function testSingleMixedMessages()
    {
        $errorMessage = 'This is an error message';
        $dangerMessage = 'This is a danger message';
        $successMessage = 'This is a success message';
        $infoMessage = 'This is a info message';
        $warningMessage = 'This is a warning message';

        Yii::$app->session->setFlash('error', $errorMessage);
        Yii::$app->session->setFlash('danger', $dangerMessage);
        Yii::$app->session->setFlash('success', $successMessage);
        Yii::$app->session->setFlash('info', $infoMessage);
        Yii::$app->session->setFlash('warning', $warningMessage);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($errorMessage);
        verify($renderingResult)->stringContainsString($dangerMessage);
        verify($renderingResult)->stringContainsString($successMessage);
        verify($renderingResult)->stringContainsString($infoMessage);
        verify($renderingResult)->stringContainsString($warningMessage);

        verify($renderingResult)->stringContainsString('alert-danger');
        verify($renderingResult)->stringContainsString('alert-success');
        verify($renderingResult)->stringContainsString('alert-info');
        verify($renderingResult)->stringContainsString('alert-warning');

        $this->stdout('Alert: набор одиночных flash всех типов рендерится с соответствующими классами danger/success/info/warning одновременно.');
    }

    /**
     * Проверяет сценарий: multiple mixed messages.
     * @return void
     */
    public function testMultipleMixedMessages()
    {
        $firstErrorMessage = 'This is the first error message';
        $secondErrorMessage = 'This is the second error message';
        $firstDangerMessage = 'This is the first danger message';
        $secondDangerMessage = 'This is the second';
        $firstSuccessMessage = 'This is the first success message';
        $secondSuccessMessage = 'This is the second success message';
        $firstInfoMessage = 'This is the first info message';
        $secondInfoMessage = 'This is the second info message';
        $firstWarningMessage = 'This is the first warning message';
        $secondWarningMessage = 'This is the second warning message';

        Yii::$app->session->setFlash('error', [$firstErrorMessage, $secondErrorMessage]);
        Yii::$app->session->setFlash('danger', [$firstDangerMessage, $secondDangerMessage]);
        Yii::$app->session->setFlash('success', [$firstSuccessMessage, $secondSuccessMessage]);
        Yii::$app->session->setFlash('info', [$firstInfoMessage, $secondInfoMessage]);
        Yii::$app->session->setFlash('warning', [$firstWarningMessage, $secondWarningMessage]);

        $renderingResult = Alert::widget();

        verify($renderingResult)->stringContainsString($firstErrorMessage);
        verify($renderingResult)->stringContainsString($secondErrorMessage);
        verify($renderingResult)->stringContainsString($firstDangerMessage);
        verify($renderingResult)->stringContainsString($secondDangerMessage);
        verify($renderingResult)->stringContainsString($firstSuccessMessage);
        verify($renderingResult)->stringContainsString($secondSuccessMessage);
        verify($renderingResult)->stringContainsString($firstInfoMessage);
        verify($renderingResult)->stringContainsString($secondInfoMessage);
        verify($renderingResult)->stringContainsString($firstWarningMessage);
        verify($renderingResult)->stringContainsString($secondWarningMessage);

        verify($renderingResult)->stringContainsString('alert-danger');
        verify($renderingResult)->stringContainsString('alert-success');
        verify($renderingResult)->stringContainsString('alert-info');
        verify($renderingResult)->stringContainsString('alert-warning');

        $this->stdout('Alert: массивы flash всех типов рендерят все сообщения с корректными классами одновременно.');
    }

    /**
     * Проверяет сценарий: flash integrity.
     * @testdox Проверяет сценарий: flash integrity.
     * @return void
     */
    public function testFlashIntegrity()
    {
        $errorMessage = 'This is an error message';
        $unrelatedMessage = 'This is a message that is not related to the alert widget';

        Yii::$app->session->setFlash('error', $errorMessage);
        Yii::$app->session->setFlash('unrelated', $unrelatedMessage);

        Alert::widget();

        // Simulate redirect
        Yii::$app->session->close();
        Yii::$app->session->open();

        verify(Yii::$app->session->getFlash('error'))->empty();
        verify(Yii::$app->session->getFlash('unrelated'))->equals($unrelatedMessage);

        $this->stdout('Alert: виджет потребляет только свои flash (error удалён после рендера), посторонний flash «unrelated» сохраняется.');
    }
}
