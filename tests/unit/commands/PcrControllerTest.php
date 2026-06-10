<?php

namespace tests\unit\commands;

use app\commands\PcrController;
use Yii;
use yii\console\ExitCode;

/**
 * Проверяет консольную сборку текстового файла IntelliMatch PCRFIHIST
 * из pcr_wallet_info / pcr_operation (команда pcr/export).
 *
 * Покрывает формат строк 60/61: фиксированные ширины и паддинг, разделитель |,
 * RUB→RUR, дату баланса dd/MM/YYYY, суммы с разделителем «.», operationId без дефисов,
 * Debit→D / Credit→C, отдельные файлы по correlation_id,
 * а также фильтры --correlation-id / --date и пустой случай.
 */
class PcrControllerTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /** Сюда пишем выходные файлы экспорта; чистим в _after. */
    private array $tmpFiles = [];

    /**
     * Подготавливает окружение перед тестом.
     *
     * resetDatabase() не трогает pcr_*, поэтому чистим их явно и задаём
     * предсказуемые константы params.pcr для строки баланса.
     *
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
        $db = Yii::$app->db;
        $db->createCommand('TRUNCATE pcr_operation, pcr_wallet_info, pcr_callback, pcr_request RESTART IDENTITY CASCADE')->execute();

        Yii::$app->params['pcr']['nostroAccount'] = 'ACC123';
        Yii::$app->params['pcr']['dcIn']          = 'D';
        Yii::$app->params['pcr']['dcOut']         = 'D';
        Yii::$app->params['pcr']['exportDir']     = '@runtime/pcr_test';
        Yii::$app->params['pcr']['fileEncoding']  = 'UTF-8';
    }

    /**
     * Убирает временные файлы экспорта.
     *
     * @return void
     */
    protected function _after(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];
    }

    // ── TC-001 ────────────────────────────────────────────────────────────

    /**
     * TC-001. Строка баланса (тег 60) и строки операций (тег 61) формируются
     * по маппингу: ширины, паддинг, RUB→RUR, дата, D/C, operationId без дефисов.
     *
     * @return void
     */
    public function testBuildsBalanceAndOperationLines(): void
    {
        $wi = $this->seedWalletInfo([
            'correlation_id'      => 'corr-1',
            'current_balance_ccy' => 'RUB',
            'opening_balance'     => '10000.07',
            'outgoing_balance'    => '24000.07',
            'from_date_time'      => '2022-08-04 10:40:00',
        ]);
        $this->seedOperation($wi, [
            'operation_id'           => 'd4e63a38-841e-4380-9e5a-a59f4e4007fd',
            'amount'                 => '132.33',
            'amount_ccy'             => 'RUB',
            'credit_debit_indicator' => 'Debit',
            'settlement_date_time'   => '2022-08-04 10:40:00',
            'type'                   => 'FIBuyingDC',
        ]);
        $this->seedOperation($wi, [
            'operation_id'           => 'aa11bb22-cccc-4380-9e5a-a59f4e4007fd',
            'amount'                 => '24600.00',
            'amount_ccy'             => 'RUB',
            'credit_debit_indicator' => 'Credit',
            'settlement_date_time'   => '2022-08-04 11:00:00',
            'type'                   => 'FIRefundDC',
        ]);

        $lines = $this->runExport(['correlationId' => 'corr-1']);
        $this->assertCount(3, $lines);

        // ── Строка баланса (60) ──
        $bal = explode('|', $lines[0]);
        $this->assertCount(8, $bal);
        $this->assertSame('60', $bal[0]);
        $this->assertSame('ACC123', trim($bal[1]));
        $this->assertSame(25, mb_strlen($bal[1]));     // паддинг счёта справа до 25
        $this->assertSame('RUR', trim($bal[2]));        // RUB→RUR
        $this->assertSame('04/08/2022', $bal[3]);       // dd/MM/YYYY из from_date_time
        $this->assertSame('10000.07', trim($bal[4]));
        $this->assertSame(25, mb_strlen($bal[4]));      // паддинг суммы слева до 25
        $this->assertSame('D', $bal[5]);
        $this->assertSame('24000.07', trim($bal[6]));
        $this->assertSame('D', $bal[7]);

        // ── Строка операции 1 (61) — Debit→D ──
        $op1 = explode('|', $lines[1]);
        $this->assertCount(6, $op1);
        $this->assertSame('61', $op1[0]);
        $this->assertSame('d4e63a38841e43809e5aa59f4e4007fd', trim($op1[1])); // без дефисов
        $this->assertSame(40, mb_strlen($op1[1]));      // паддинг operationId справа до 40
        $this->assertSame('RUR', trim($op1[2]));
        $this->assertSame('132.33', trim($op1[3]));
        $this->assertSame(25, mb_strlen($op1[3]));
        $this->assertSame('D', $op1[4]);
        $this->assertSame('FIBuyingDC', trim($op1[5]));
        $this->assertSame(35, mb_strlen($op1[5]));      // паддинг type справа до 35

        // ── Строка операции 2 (61) — Credit→C ──
        $op2 = explode('|', $lines[2]);
        $this->assertSame('C', $op2[4]);
        $this->assertSame('FIRefundDC', trim($op2[5]));

        $this->stdout('TC-001: строки 60/61 по маппингу — ширины/паддинг, RUB→RUR, дата баланса dd/MM/YYYY, Debit→D/Credit→C, operationId без дефисов.');
    }

    // ── TC-002 ────────────────────────────────────────────────────────────

    /**
     * TC-002. Несколько reports с одним correlation_id → один файл: блоки идут подряд,
     * для каждого баланса сначала строка 60, затем его строки 61.
     *
     * @return void
     */
    public function testMultipleReportsInSingleFile(): void
    {
        $wiA = $this->seedWalletInfo(['correlation_id' => 'corr-2', 'fi_wallet_id' => 'WALLET-A', 'from_date_time' => '2025-05-27 00:00:00']);
        $this->seedOperation($wiA, ['operation_id' => 'op-a1', 'credit_debit_indicator' => 'Debit']);
        $wiB = $this->seedWalletInfo(['correlation_id' => 'corr-2', 'fi_wallet_id' => 'WALLET-B', 'from_date_time' => '2025-05-27 00:00:00']);
        $this->seedOperation($wiB, ['operation_id' => 'op-b1', 'credit_debit_indicator' => 'Credit']);
        $this->seedOperation($wiB, ['operation_id' => 'op-b2', 'credit_debit_indicator' => 'Debit']);

        $lines = $this->runExport(['correlationId' => 'corr-2']);

        // 2 баланса + 3 операции = 5 строк.
        $this->assertCount(5, $lines);
        $tags = array_map(fn($l) => substr($l, 0, 2), $lines);
        $this->assertSame(['60', '61', '60', '61', '61'], $tags);

        $this->stdout('TC-002: несколько reports одного correlation_id собраны в один файл — на каждый баланс строка 60, затем его строки 61.');
    }

    // ── TC-003 ────────────────────────────────────────────────────────────

    /**
     * TC-003. Фильтр --date выбирает reports по дате from_date_time.
     *
     * @return void
     */
    public function testDateFilterSelectsReports(): void
    {
        $wi1 = $this->seedWalletInfo(['correlation_id' => 'd1', 'from_date_time' => '2025-05-27 10:00:00']);
        $this->seedOperation($wi1, ['operation_id' => 'x1']);
        $wi2 = $this->seedWalletInfo(['correlation_id' => 'd2', 'from_date_time' => '2025-05-28 10:00:00']);
        $this->seedOperation($wi2, ['operation_id' => 'x2']);

        $lines = $this->runExport(['date' => '2025-05-27']);

        $this->assertCount(2, $lines); // 1 баланс + 1 операция только за 27-е
        $this->assertSame('60', substr($lines[0], 0, 2));
        $this->assertStringContainsString('x1', $lines[1]);

        $this->stdout('TC-003: --date=2025-05-27 выбрал только reports с этой from_date_time.');
    }

    // ── TC-004 ────────────────────────────────────────────────────────────

    /**
     * TC-004. Нет данных под фильтр → ExitCode::OK и файл не создаётся.
     *
     * @return void
     */
    public function testNoDataReturnsOkAndWritesNoFile(): void
    {
        $path = Yii::getAlias('@runtime') . '/pcr_test_empty_' . uniqid() . '.txt';
        $this->tmpFiles[] = $path;

        $c = new PcrController('pcr', Yii::$app);
        $c->correlationId = 'no-such';
        $c->out = $path;
        $rc = $c->actionExport();

        $this->assertSame(ExitCode::OK, $rc);
        $this->assertFileDoesNotExist($path);

        $this->stdout('TC-004: пустая выборка → ExitCode::OK, файл не создаётся.');
    }

    // ── TC-005 ────────────────────────────────────────────────────────────

    /**
     * TC-005. Экспорт создаёт отдельный файл на каждый correlation_id,
     * добавляет correlation_id в имя и повторно не экспортирует уже отмеченные запросы.
     *
     * @return void
     */
    public function testCreatesSeparateFilesPerCorrelationAndSkipsExported(): void
    {
        $wiA = $this->seedWalletInfo(['correlation_id' => 'corr-a', 'from_date_time' => '2025-05-27 10:00:00']);
        $this->seedOperation($wiA, ['operation_id' => 'op-a']);
        $wiB = $this->seedWalletInfo(['correlation_id' => 'corr-b', 'from_date_time' => '2025-05-27 11:00:00']);
        $this->seedOperation($wiB, ['operation_id' => 'op-b']);

        $files = $this->runExportFiles([]);

        $this->assertCount(2, $files);
        $names = array_map('basename', array_keys($files));
        sort($names);
        $this->assertStringContainsString('corr-a', $names[0]);
        $this->assertStringContainsString('corr-b', $names[1]);

        foreach ($files as $lines) {
            $this->assertCount(2, $lines); // 1 баланс + 1 операция в каждом файле
            $this->assertSame(['60', '61'], array_map(fn($l) => substr($l, 0, 2), $lines));
        }

        $exported = Yii::$app->db->createCommand(
            "SELECT correlation_id, is_exported, export_file
               FROM {{%pcr_request}}
              ORDER BY correlation_id"
        )->queryAll();
        $this->assertCount(2, $exported);
        $this->assertSame('corr-a', $exported[0]['correlation_id']);
        $this->assertTrue((bool)$exported[0]['is_exported']);
        $this->assertStringContainsString('corr-a', $exported[0]['export_file']);
        $this->assertSame('corr-b', $exported[1]['correlation_id']);
        $this->assertTrue((bool)$exported[1]['is_exported']);
        $this->assertStringContainsString('corr-b', $exported[1]['export_file']);

        $basePath = Yii::getAlias('@runtime') . '/pcr_test_repeat_' . uniqid() . '.txt';
        $c = new PcrController('pcr', Yii::$app);
        $c->out = $basePath;
        $rc = $c->actionExport();
        $this->assertSame(ExitCode::OK, $rc);
        $this->assertSame([], glob(dirname($basePath) . DIRECTORY_SEPARATOR . pathinfo($basePath, PATHINFO_FILENAME) . '_*.txt') ?: []);

        $this->stdout('TC-005: два correlation_id → два файла с correlation_id в имени; pcr_request помечены is_exported=true; повторный экспорт файлов не создаёт.');
    }

    // ── Хелперы ───────────────────────────────────────────────────────────

    /**
     * Запускает pcr/export с заданными опциями и возвращает строки файла.
     *
     * @param array $options Опции контроллера (correlationId|date).
     * @return array Непустые строки файла без CRLF.
     */
    private function runExport(array $options): array
    {
        $files = $this->runExportFiles($options);
        $this->assertCount(1, $files);
        return reset($files);
    }

    /**
     * Запускает pcr/export с заданными опциями и возвращает строки всех созданных файлов.
     *
     * @param array $options Опции контроллера (correlationId|date).
     * @return array<string,array> Карта путь файла → непустые строки файла без CRLF.
     */
    private function runExportFiles(array $options): array
    {
        $path = Yii::getAlias('@runtime') . '/pcr_test_' . uniqid() . '.txt';

        $c = new PcrController('pcr', Yii::$app);
        $c->out = $path;
        foreach ($options as $k => $v) {
            $c->$k = $v;
        }
        $rc = $c->actionExport();
        $this->assertSame(ExitCode::OK, $rc);

        $pattern = dirname($path) . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '_*.txt';
        $files = glob($pattern) ?: [];
        sort($files);
        $this->assertNotEmpty($files);

        $result = [];
        foreach ($files as $file) {
            $this->tmpFiles[] = $file;
            $content = file_get_contents($file);
            $result[$file] = array_values(array_filter(explode("\r\n", $content), fn($l) => $l !== ''));
        }

        return $result;
    }

    /**
     * Вставляет pcr_callback + pcr_wallet_info и возвращает id wallet_info.
     *
     * @param array $attributes Переопределения полей wallet_info.
     * @return int ID pcr_wallet_info.
     */
    private function seedWalletInfo(array $attributes): int
    {
        $db = Yii::$app->db;
        $correlationId = $attributes['correlation_id'] ?? 'corr';
        $this->seedAcceptedRequest($correlationId);

        $db->createCommand()->insert('{{%pcr_callback}}', [
            'correlation_id' => $correlationId,
            'operation_id'   => 'op-' . bin2hex(random_bytes(3)),
            'part_no'        => 1,
            'part_quantity'  => 1,
            'part_id'        => 'part-' . bin2hex(random_bytes(3)),
            'payload'        => '{}',
        ])->execute();
        $callbackId = (int)$db->getLastInsertID('pcr_callback_id_seq');

        $db->createCommand()->insert('{{%pcr_wallet_info}}', array_merge([
            'callback_id'         => $callbackId,
            'correlation_id'      => 'corr',
            'fi_wallet_id'        => 'WALLET',
            'dc_account_number'   => '11112222333344445555',
            'current_balance'     => '200.07',
            'current_balance_ccy' => 'RUB',
            'opening_balance'     => '10000.07',
            'opening_balance_ccy' => 'RUB',
            'outgoing_balance'    => '24000.07',
            'outgoing_balance_ccy'=> 'RUB',
            'from_date_time'      => '2025-05-27 00:00:00',
            'to_date_time'        => '2025-05-27 23:59:59',
        ], $attributes))->execute();

        return (int)$db->getLastInsertID('pcr_wallet_info_id_seq');
    }

    /**
     * Вставляет строку pcr_operation для заданного wallet_info.
     *
     * @param int   $walletInfoId ID pcr_wallet_info.
     * @param array $attributes   Переопределения полей операции.
     * @return void
     */
    private function seedOperation(int $walletInfoId, array $attributes): void
    {
        Yii::$app->db->createCommand()->insert('{{%pcr_operation}}', array_merge([
            'wallet_info_id'         => $walletInfoId,
            'operation_id'           => 'op-id',
            'type'                   => 'FIBuyingDC',
            'amount'                 => '100.00',
            'amount_ccy'             => 'RUB',
            'credit_debit_indicator' => 'Debit',
            'settlement_date_time'   => '2025-05-27 10:00:00',
            'other_details'          => null,
        ], $attributes))->execute();
    }

    /**
     * Вставляет принятый pcr_request, если такого correlation_id ещё нет.
     *
     * @param string $correlationId correlation_id запроса.
     * @return void
     */
    private function seedAcceptedRequest(string $correlationId): void
    {
        $db = Yii::$app->db;
        $exists = $db->createCommand(
            "SELECT 1 FROM {{%pcr_request}} WHERE correlation_id = :c LIMIT 1",
            [':c' => $correlationId]
        )->queryScalar();
        if ($exists !== false) {
            return;
        }

        $db->createCommand()->insert('{{%pcr_request}}', [
            'request_type'   => 'operationHistory',
            'wallet_id_list' => '[]',
            'date_from'      => '2025-05-27 00:00:00',
            'date_to'        => '2025-05-28 00:00:00',
            'correlation_id' => $correlationId,
            'operation_id'   => 'request-op-' . bin2hex(random_bytes(3)),
            'http_status'    => 200,
            'response_raw'   => '{}',
            'status'         => 'accepted',
            'is_exported'    => false,
        ])->execute();
    }
}
