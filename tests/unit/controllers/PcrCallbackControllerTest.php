<?php

namespace tests\unit\controllers;

use app\controllers\PcrCallbackController;
use Yii;

/**
 * Проверяет публичный приёмник callback СЦР (PcrCallbackController::actionWalletInfo):
 * нормализацию FIWalletInfo в pcr_callback / pcr_wallet_info / pcr_operation,
 * идемпотентность по (operation_id, part_id) и Basic Auth.
 */
class PcrCallbackControllerTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /**
     * Подготавливает окружение перед тестом.
     *
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
        Yii::$app->db->createCommand('TRUNCATE pcr_operation, pcr_wallet_info, pcr_callback, pcr_request RESTART IDENTITY CASCADE')->execute();
        $this->clearUnknownCallbackLogs();
        // По умолчанию проверка Basic Auth выключена (enabled=false).
        Yii::$app->params['pcr']['callbackAuth'] = ['enabled' => false, 'username' => '', 'password' => ''];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    }

    /**
     * Сбрасывает суперглобалы запроса между тестами.
     *
     * @return void
     */
    protected function _after(): void
    {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->clearUnknownCallbackLogs();
    }

    // ── TC-001 ────────────────────────────────────────────────────────────

    /**
     * TC-001. FIWalletInfo нормализуется: pcr_callback + pcr_wallet_info + операции.
     *
     * @return void
     */
    public function testNormalizesWalletInfoAndOperations(): void
    {
        $payload = $this->samplePayload();
        $this->seedAcceptedRequest($payload['correlationId']);

        $result = $this->postCallback($payload);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(1, $result['reports']);
        $this->assertSame(2, $result['operations']);

        $db = Yii::$app->db;
        $this->assertSame(1, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_callback}}')->queryScalar());
        $this->assertSame(1, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_wallet_info}}')->queryScalar());
        $this->assertSame(2, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_operation}}')->queryScalar());

        $wi = $db->createCommand('SELECT * FROM {{%pcr_wallet_info}} LIMIT 1')->queryOne();
        $this->assertSame('11112222333344445555', $wi['dc_account_number']);
        $this->assertSame('10000.07', $wi['opening_balance']);
        $this->assertSame('24000.07', $wi['outgoing_balance']);
        $this->assertSame('RUB', trim((string)$wi['current_balance_ccy']));
        $this->assertSame('Active', $wi['wallet_status']);

        $op = $db->createCommand("SELECT * FROM {{%pcr_operation}} WHERE type = 'FIBuyingDC'")->queryOne();
        $this->assertSame('132.33', $op['amount']);
        $this->assertSame('Debit', $op['credit_debit_indicator']);
        $this->assertSame('d4e63a38-841e-4380-9e5a-a59f4e4007fd', $op['operation_id']);
        $this->assertNotNull($op['other_details']); // otherDetails сохранён как JSON

        $this->stdout('TC-001: callback нормализован — pcr_callback(1), pcr_wallet_info(1), pcr_operation(2); поля баланса и операции на месте.');
    }

    // ── TC-002 ────────────────────────────────────────────────────────────

    /**
     * TC-002. Повторный callback с тем же (operation_id, part_id) идемпотентен:
     * duplicate=true, новых строк не создаётся.
     *
     * @return void
     */
    public function testDuplicateCallbackIsIdempotent(): void
    {
        $payload = $this->samplePayload();
        $this->seedAcceptedRequest($payload['correlationId']);

        $first  = $this->postCallback($payload);
        $second = $this->postCallback($payload);

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('accepted', $second['status']);
        $this->assertTrue($second['duplicate'] ?? false);

        $db = Yii::$app->db;
        $this->assertSame(1, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_callback}}')->queryScalar());
        $this->assertSame(1, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_wallet_info}}')->queryScalar());
        $this->assertSame(2, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_operation}}')->queryScalar());

        $this->stdout('TC-002: повторный callback с тем же (operation_id, part_id) → duplicate=true, дублей в pcr_* нет.');
    }

    // ── TC-003 ────────────────────────────────────────────────────────────

    /**
     * TC-003. Basic Auth: неверные/отсутствующие реквизиты → 401, данные не пишутся;
     * верные — обрабатываются.
     *
     * @return void
     */
    public function testBasicAuthGuardsEndpoint(): void
    {
        Yii::$app->params['pcr']['callbackAuth'] = ['enabled' => true, 'username' => 'scr', 'password' => 'secret'];
        $payload = $this->samplePayload();
        $this->seedAcceptedRequest($payload['correlationId']);

        // Без реквизитов → 401.
        $res = $this->postCallback($payload, null, null, false);
        $this->assertSame('error', $res['status']);
        $this->assertSame(401, Yii::$app->response->statusCode);
        $this->assertSame(0, (int)Yii::$app->db->createCommand('SELECT COUNT(*) FROM {{%pcr_callback}}')->queryScalar());

        // Неверный пароль → 401.
        $res = $this->postCallback($payload, 'scr', 'wrong', false);
        $this->assertSame('error', $res['status']);
        $this->assertSame(401, Yii::$app->response->statusCode);

        // Верные реквизиты → accepted.
        $res = $this->postCallback($payload, 'scr', 'secret', false);
        $this->assertSame('accepted', $res['status']);
        $this->assertSame(1, (int)Yii::$app->db->createCommand('SELECT COUNT(*) FROM {{%pcr_callback}}')->queryScalar());

        $this->stdout('TC-003: Basic Auth — без/с неверными реквизитами 401 и запись не создаётся; с верными — accepted.');
    }

    // ── TC-004 ────────────────────────────────────────────────────────────

    /**
     * TC-004. Callback с correlationId, которого нет среди успешных pcr/request,
     * игнорируется и не пишет строки в pcr_*.
     *
     * @return void
     */
    public function testUnknownCorrelationIdIsIgnored(): void
    {
        $dir = $this->unknownCallbackLogDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $currentLog = $this->unknownCallbackLogPath(0);
        $previousLog = $this->unknownCallbackLogPath(-1);
        $oldLog = $this->unknownCallbackLogPath(-2);
        file_put_contents($previousLog, "previous week\n");
        file_put_contents($oldLog, "old week\n");

        $result = $this->postCallback($this->samplePayload());

        $this->assertSame('ignored', $result['status']);
        $this->assertSame('unknown_correlation_id', $result['reason']);

        $db = Yii::$app->db;
        $this->assertSame(0, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_callback}}')->queryScalar());
        $this->assertSame(0, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_wallet_info}}')->queryScalar());
        $this->assertSame(0, (int)$db->createCommand('SELECT COUNT(*) FROM {{%pcr_operation}}')->queryScalar());

        $logFiles = glob($dir . DIRECTORY_SEPARATOR . 'unknown-correlation-*.log') ?: [];
        $this->assertCount(2, $logFiles);
        $this->assertFileExists($currentLog);
        $this->assertFileExists($previousLog);
        $this->assertFileDoesNotExist($oldLog);
        $content = file_get_contents($currentLog);
        $this->assertStringContainsString('"event":"unknown_correlation_id"', $content);
        $this->assertStringContainsString('"correlation_id":"ef71d287-995f-4582-b5ec-d8585beecf45"', $content);
        $this->assertStringContainsString('"payload":{', $content);
        $this->assertStringContainsString('"dcAccountNumber":"11112222333344445555"', $content);
        $this->assertStringContainsString('"operationsInformation":[', $content);

        $this->stdout('TC-004: callback с неизвестным correlationId проигнорирован, строки pcr_* не созданы; недельный runtime-лог записан, третий файл удалён.');
    }

    // ── Хелперы ───────────────────────────────────────────────────────────

    /**
     * Удаляет тестовые недельные логи неизвестных correlationId.
     *
     * @return void
     */
    private function clearUnknownCallbackLogs(): void
    {
        foreach (glob($this->unknownCallbackLogDir() . DIRECTORY_SEPARATOR . 'unknown-correlation-*.log') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Возвращает каталог недельных логов callback с неизвестным correlationId.
     *
     * @return string
     */
    private function unknownCallbackLogDir(): string
    {
        return Yii::getAlias('@runtime/pcr-callback');
    }

    /**
     * Возвращает путь недельного лог-файла относительно текущей недели.
     *
     * @param int $weekOffset Смещение в неделях: 0 — текущая, -1 — прошлая.
     * @return string
     */
    private function unknownCallbackLogPath(int $weekOffset): string
    {
        $tz = new \DateTimeZone(Yii::$app->timeZone ?: date_default_timezone_get());
        $date = (new \DateTimeImmutable('now', $tz))
            ->modify('monday this week')
            ->modify($weekOffset . ' weeks')
            ->format('Y-m-d');

        return $this->unknownCallbackLogDir() . DIRECTORY_SEPARATOR . 'unknown-correlation-' . $date . '.log';
    }

    /**
     * Добавляет успешный исходящий pcr/request с correlationId, который callback
     * вправе обработать.
     *
     * @param string $correlationId correlationId из ответа СЦР.
     * @return void
     */
    private function seedAcceptedRequest(string $correlationId): void
    {
        Yii::$app->db->createCommand()->insert('{{%pcr_request}}', [
            'request_type'   => 'operationHistory',
            'wallet_id_list' => '[]',
            'date_from'      => '2025-02-14 00:00:00',
            'date_to'        => '2025-02-15 00:00:00',
            'correlation_id' => $correlationId,
            'operation_id'   => 'request-op',
            'http_status'    => 200,
            'response_raw'   => '{}',
            'status'         => 'accepted',
        ])->execute();
    }

    /**
     * Имитирует POST callback и возвращает результат actionWalletInfo.
     *
     * @param array       $payload Тело FIWalletInfo.
     * @param string|null $user    Basic Auth user (если задан).
     * @param string|null $pass    Basic Auth password.
     * @param bool        $fresh   Сбрасывать ли auth-суперглобалы перед вызовом.
     * @return array
     */
    private function postCallback(array $payload, ?string $user = null, ?string $pass = null, bool $fresh = true): array
    {
        if ($fresh) {
            unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        }
        if ($user !== null) {
            $_SERVER['PHP_AUTH_USER'] = $user;
            $_SERVER['PHP_AUTH_PW']   = (string)$pass;
        } else {
            unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        }

        $request = Yii::$app->request;
        $request->setBodyParams($payload);

        $controller = new PcrCallbackController('pcr-callback', Yii::$app);
        return $controller->runAction('wallet-info');
    }

    /**
     * Возвращает пример тела FIWalletInfo (по структуре из API СЦР).
     *
     * @return array
     */
    private function samplePayload(): array
    {
        return [
            'messageCreationDateTime' => '2025-02-14T15:59:56.665Z',
            'operationId'             => '5bd3ce0a-2bc1-4176-bdcc-a007bd8bef2f',
            'correlationId'           => 'ef71d287-995f-4582-b5ec-d8585beecf45',
            'partNo'                  => '1',
            'partQuantity'            => '2',
            'partId'                  => '23a7e955-294c-4b5d-bcc3-d07661743c29',
            'reports'                 => [
                [
                    'fiWalletId'      => 'g.ru.cbrdc.wlt.fi.178f4988-ca4e-4016-9b6f-6781b13ce1b8',
                    'dcAccountNumber' => '11112222333344445555',
                    'balanceInfo'     => [
                        'totalAmount'        => 100.07,
                        'totalAmountCcy'     => 'RUB',
                        'currentBalance'     => 200.07,
                        'currentBalanceCcy'  => 'RUB',
                    ],
                    'openingBalance'      => 10000.07,
                    'openingBalanceCcy'   => 'RUB',
                    'outgoingBalance'     => 24000.07,
                    'outgoingBalanceCcy'  => 'RUB',
                    'totalAmountDebit'    => 2000.07,
                    'totalAmountCredit'   => 3000.07,
                    'walletStatus'        => 'Active',
                    'fromDateTime'        => '2022-08-04T10:40:00.000Z',
                    'toDateTime'          => '2022-08-04T12:40:00.000Z',
                    'operationsInformation' => [
                        [
                            'type'                 => 'FIBuyingDC',
                            'amount'               => 132.33,
                            'amountCcy'            => 'RUB',
                            'creditDebitIndicator' => 'Debit',
                            'operationId'          => 'd4e63a38-841e-4380-9e5a-a59f4e4007fd',
                            'settlementDateTime'   => '2022-08-04T10:40:00.000Z',
                            'otherDetails'         => ['exchangeDetails' => ['accountId' => '30101643600000000957']],
                        ],
                        [
                            'type'                 => 'FIRefundDC',
                            'amount'               => 24600.00,
                            'amountCcy'            => 'RUB',
                            'creditDebitIndicator' => 'Credit',
                            'operationId'          => 'aa11bb22-cccc-4380-9e5a-a59f4e4007fd',
                            'settlementDateTime'   => '2022-08-04T11:00:00.000Z',
                        ],
                    ],
                ],
            ],
        ];
    }
}
