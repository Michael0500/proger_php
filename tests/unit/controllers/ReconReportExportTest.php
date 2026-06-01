<?php

namespace tests\unit\controllers;

use app\controllers\ReconReportController;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Yii;

/**
 * Проверяет генерацию файлов экспорта Reconciliation Report
 * (XLSX/PDF/ZIP) и вспомогательные методы именования.
 *
 * Приватные методы контроллера вызываются через рефлексию, чтобы проверить
 * сборку файлов без HTTP-слоя и потоковой отправки.
 */
class ReconReportExportTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /** Временные файлы, создаваемые тестом, — удаляются в _after. */
    private array $tempFiles = [];

    /**
     * Удаляет созданные временные файлы после теста.
     *
     * @return void
     */
    protected function _after(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    // ── TC-060 ────────────────────────────────────────────────────────────

    /**
     * TC-060. createXlsxFile создаёт читаемый XLSX с заголовком отчёта.
     *
     * @return void
     */
    public function testCreateXlsxFileProducesReadableWorkbook(): void
    {
        $path = $this->invoke('createXlsxFile', [$this->sampleReport()]);
        $this->tempFiles[] = $path;

        $this->assertFileExists($path);
        $this->assertSame('xlsx', strtolower(pathinfo($path, PATHINFO_EXTENSION)));

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('Reconciliation Report', $sheet->getCell('A1')->getValue());
        $spreadsheet->disconnectWorksheets();

        $this->stdout('TC-060: createXlsxFile создал валидный XLSX — открывается PhpSpreadsheet, ячейка A1 = «Reconciliation Report».');
    }

    // ── TC-061 ────────────────────────────────────────────────────────────

    /**
     * TC-061. createPdfFile создаёт корректный PDF (сигнатура %PDF).
     *
     * @return void
     */
    public function testCreatePdfFileProducesPdf(): void
    {
        $path = $this->invoke('createPdfFile', [$this->sampleReport()]);
        $this->tempFiles[] = $path;

        $this->assertFileExists($path);
        $head = file_get_contents($path, false, null, 0, 4);
        $this->assertSame('%PDF', $head);

        $this->stdout('TC-061: createPdfFile создал корректный PDF — файл начинается с сигнатуры «%PDF».');
    }

    // ── TC-062 ────────────────────────────────────────────────────────────

    /**
     * TC-062. createZipFile упаковывает несколько отчётов в ZIP.
     *
     * @return void
     */
    public function testCreateZipFileBundlesReports(): void
    {
        $reportA = $this->sampleReport();
        $reportB = $this->sampleReport();
        $reportB['nostro_bank'] = 'BANK-B';

        $zipPath = $this->invoke('createZipFile', [[$reportA, $reportB], 'xlsx']);
        $this->tempFiles[] = $zipPath;

        $this->assertFileExists($zipPath);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(2, $zip->numFiles);
        $names = [$zip->getNameIndex(0), $zip->getNameIndex(1)];
        $zip->close();
        foreach ($names as $name) {
            $this->assertStringEndsWith('.xlsx', $name);
        }

        $this->stdout('TC-062: createZipFile упаковал 2 отчёта в ZIP — внутри 2 файла .xlsx.');
    }

    // ── TC-063 ────────────────────────────────────────────────────────────

    /**
     * TC-063. uniqueZipName разрешает конфликт одинаковых имён внутри ZIP.
     *
     * @return void
     */
    public function testUniqueZipNameDeduplicates(): void
    {
        $controller = $this->controller();
        $ref = new \ReflectionMethod($controller, 'uniqueZipName');
        $ref->setAccessible(true);

        $used = [];
        $first = $ref->invokeArgs($controller, ['ReconReport_BANK_2026.xlsx', &$used, 'BANK-A']);
        $second = $ref->invokeArgs($controller, ['ReconReport_BANK_2026.xlsx', &$used, 'BANK-A']);

        $this->assertSame('ReconReport_BANK_2026.xlsx', $first);
        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('.xlsx', $second);

        $this->stdout('TC-063: uniqueZipName при повторе того же имени вернул уникальный вариант (без конфликта внутри архива).');
    }

    // ── TC-063b ───────────────────────────────────────────────────────────

    /**
     * TC-063b. safeFilename убирает запрещённые символы, reportFilename формирует
     * имя ReconReport_<bank>_<dd.mm.yyyy>.<ext>.
     *
     * @return void
     */
    public function testFilenameHelpers(): void
    {
        $safe = $this->invoke('safeFilename', ['BANK/A:1*?']);
        $this->assertStringNotContainsString('/', $safe);
        $this->assertStringNotContainsString(':', $safe);

        $name = $this->invoke('reportFilename', [$this->sampleReport(), 'pdf']);
        $this->assertStringStartsWith('ReconReport_', $name);
        $this->assertStringEndsWith('.pdf', $name);
        $this->assertStringContainsString('10.01.2026', $name);

        $this->stdout('TC-063b: safeFilename вычищает символы / : * ?; reportFilename формирует «ReconReport_<банк>_10.01.2026.pdf».');
    }

    // ── Хелперы ─────────────────────────────────────────────────────────────

    /**
     * Создаёт экземпляр контроллера для вызова приватных методов.
     *
     * @return ReconReportController Контроллер раккорда.
     */
    private function controller(): ReconReportController
    {
        return new ReconReportController('recon-report', Yii::$app);
    }

    /**
     * Вызывает приватный метод контроллера через рефлексию.
     *
     * @param string $method Имя приватного метода.
     * @param array $args Позиционные аргументы.
     * @return mixed Результат вызова.
     */
    private function invoke(string $method, array $args)
    {
        $controller = $this->controller();
        $ref = new \ReflectionMethod($controller, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($controller, $args);
    }

    /**
     * Возвращает заполненную структуру отчёта, совместимую с createXlsxFile/_pdf.
     *
     * @return array Данные одной карточки Reconciliation Report.
     */
    private function sampleReport(): array
    {
        return [
            'generated_at' => '2026-01-12 10:00:00',
            'date_recon' => '2026-01-10',
            'date_from' => null,
            'date_to' => null,
            'company' => 'NRE',
            'nostro_bank' => 'BANK-A',
            'account_name' => 'BANK-A-L, BANK-A-S',
            'account_count' => 2,
            'pool_id' => 1,
            'currency' => 'USD',
            'selection' => ['pool_id' => 1, 'category_id' => 0],
            'closing_balance' => ['ledger' => 1000.0, 'statement' => 900.0, 'difference' => 100.0],
            'outstanding_items' => [
                'ledger_debit' => [[
                    'value' => '2026-01-09', 'instruction_id' => 'L-OUT', 'end_to_end_id' => null,
                    'transaction_id' => null, 'message_id' => null, 'dc' => 'Debit',
                    'amount' => -30.0, 'account' => 'BANK-A-L',
                ]],
                'ledger_credit' => [],
                'stmt_debit' => [],
                'stmt_credit' => [[
                    'value' => '2026-01-10', 'instruction_id' => 'S-OUT', 'end_to_end_id' => null,
                    'transaction_id' => null, 'message_id' => null, 'dc' => 'Credit',
                    'amount' => 20.0, 'account' => 'BANK-A-S',
                ]],
                'net_ledger_debit' => -30.0,
                'net_ledger_credit' => 0.0,
                'net_stmt_debit' => 0.0,
                'net_stmt_credit' => 20.0,
                'ledger_net_amount' => -30.0,
                'stmt_net_amount' => 20.0,
                'ledger' => -30.0,
                'statement' => 20.0,
                'difference' => -50.0,
            ],
            'trial_balance' => ['ledger' => 970.0, 'statement' => 920.0, 'difference' => 50.0],
            'totals' => ['ledger_net_amount' => -30.0, 'statement_net_amount' => 20.0, 'total_amount' => -10.0],
        ];
    }
}
