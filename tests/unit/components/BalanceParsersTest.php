<?php

namespace tests\unit\components;

use app\components\parsers\AsbTextParser;
use app\components\parsers\BndCamtParser;
use app\models\NostroBalance;
use app\models\NostroEntry;

/**
 * Проверяет парсеры файлов балансов BND/CAMT и ASB.
 */
class BalanceParsersTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /** @var string[] Временные файлы теста */
    private array $files = [];

    /**
     * Удаляет временные файлы.
     *
     * @return void
     */
    protected function _after(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->files = [];
    }

    /**
     * Проверяет разбор CAMT XML с namespace.
     *
     * @return void
     */
    public function testBndCamtParserParsesStatementWithNamespace(): void
    {
        $path = $this->tempFile('xml', '<?xml version="1.0" encoding="UTF-8"?>
<PaymentMessages>
  <AppHdr>
    <BizMsgIdr>9869799</BizMsgIdr>
    <MsgDefIdr>camt.052.001.06</MsgDefIdr>
  </AppHdr>
  <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.06.cm521">
    <BkToCstmrAcctRpt>
      <Rpt>
      <Id>STMT-001</Id>
      <Acct><Id><Othr><Id>BY00TESTACCOUNT</Id></Othr></Id></Acct>
      <Bal>
        <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
        <Amt Ccy="USD">1000.25</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Dt><Dt>2026-01-10</Dt></Dt>
      </Bal>
      <Bal>
        <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
        <Amt Ccy="USD">900.10</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Dt><Dt>2026-01-10</Dt></Dt>
      </Bal>
      <Ntry>
        <Amt Ccy="USD">150.75</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <BookgDt><Dt>2026-01-09</Dt></BookgDt>
        <ValDt><Dt>2026-01-10</Dt></ValDt>
      </Ntry>
      <Ntry>
        <Amt Ccy="USD">25.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Sts>PDNG</Sts>
        <ValDt><Dt>2026-01-10</Dt></ValDt>
      </Ntry>
      </Rpt>
    </BkToCstmrAcctRpt>
  </Document>
</PaymentMessages>');

        $parser = new BndCamtParser();
        $rows = $parser->parse($path, 15, NostroBalance::SECTION_NRE);

        $this->assertSame([], $parser->getErrors());
        $this->assertCount(1, $rows);
        $this->assertSame(15, $rows[0]['account_id']);
        $this->assertSame(NostroBalance::LS_STATEMENT, $rows[0]['ls_type']);
        $this->assertSame('STMT-001', $rows[0]['statement_number']);
        $this->assertSame('USD', $rows[0]['currency']);
        $this->assertSame('2026-01-10', $rows[0]['value_date']);
        $this->assertEquals(1000.25, $rows[0]['opening_balance']);
        $this->assertSame(NostroBalance::DC_CREDIT, $rows[0]['opening_dc']);
        $this->assertEquals(900.10, $rows[0]['closing_balance']);
        $this->assertSame(NostroBalance::DC_DEBIT, $rows[0]['closing_dc']);
        $this->assertSame(NostroBalance::SOURCE_BND, $rows[0]['source']);

        $entryRows = $parser->getEntryRows();
        $this->assertCount(1, $entryRows);
        $this->assertSame(15, $entryRows[0]['account_id']);
        $this->assertSame(NostroEntry::LS_STATEMENT, $entryRows[0]['ls']);
        $this->assertSame(NostroEntry::DC_DEBIT, $entryRows[0]['dc']);
        $this->assertSame('150.75', $entryRows[0]['amount']);
        $this->assertSame('USD', $entryRows[0]['currency']);
        $this->assertSame('2026-01-10', $entryRows[0]['value_date']);
        $this->assertSame('2026-01-09', $entryRows[0]['post_date']);
        $this->assertSame('STMT-001', $entryRows[0]['statement_number']);
        $this->assertSame(NostroBalance::SOURCE_BND, $entryRows[0]['source']);
        $this->assertSame(NostroEntry::STATUS_UNMATCHED, $entryRows[0]['match_status']);

        $this->stdout('BND/CAMT парсер: разбирает CAMT052 Rpt с namespace — баланс OPBD/CLBD и только BOOK-записи выверки.');
    }

    /**
     * Проверяет ошибку CAMT при отсутствии closing balance.
     *
     * @return void
     */
    public function testBndCamtParserReportsMissingClosingBalance(): void
    {
        $path = $this->tempFile('xml', '<?xml version="1.0" encoding="UTF-8"?>
<Document>
  <Rpt>
    <Id>STMT-002</Id>
    <Bal>
      <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
      <Amt Ccy="EUR">10.00</Amt>
      <CdtDbtInd>CRDT</CdtDbtInd>
      <Dt><Dt>2026-01-11</Dt></Dt>
    </Bal>
  </Rpt>
</Document>');

        $parser = new BndCamtParser();
        $rows = $parser->parse($path, 10, NostroBalance::SECTION_NRE);

        $this->assertSame([], $rows);
        $this->assertContains('Rpt STMT-002: не найден баланс CLBD', $parser->getErrors());

        $this->stdout('BND/CAMT парсер: при отсутствии closing balance (CLBD) в Rpt строки не возвращаются и фиксируется ошибка «не найден баланс CLBD».');
    }

    /**
     * Проверяет разбор ASB-файла в Windows-1251.
     *
     * @return void
     */
    public function testAsbParserParsesWindows1251Statement(): void
    {
        $content = "[ЗаголовокВыписки]\n"
            . "НомерВыписки=ASB-001\n"
            . "РасчСчет=30101810000000000001\n"
            . "ДатаНачала=10.01.2026\n"
            . "НачальныйОстаток=1 000,50\n"
            . "КонечныйОстаток=900,25\n"
            . "ВсегоПоступило=10,00\n"
            . "ВсегоСписано=0,00\n";
        $encoded = iconv('UTF-8', 'Windows-1251//IGNORE', $content);
        $path = $this->tempFile('txt', $encoded);

        $parser = new AsbTextParser();
        $rows = $parser->parse($path, 22, NostroBalance::SECTION_INV);

        $this->assertSame([], $parser->getErrors());
        $this->assertCount(1, $rows);
        $this->assertSame(22, $rows[0]['account_id']);
        $this->assertSame('ASB-001', $rows[0]['statement_number']);
        $this->assertSame('RUB', $rows[0]['currency']);
        $this->assertSame('2026-01-10', $rows[0]['value_date']);
        $this->assertEquals(1000.50, $rows[0]['opening_balance']);
        $this->assertSame(NostroBalance::DC_CREDIT, $rows[0]['opening_dc']);
        $this->assertEquals(900.25, $rows[0]['closing_balance']);
        $this->assertSame(NostroBalance::DC_CREDIT, $rows[0]['closing_dc']);
        $this->assertSame(NostroBalance::SOURCE_ASB, $rows[0]['source']);
        $this->assertSame(NostroBalance::SECTION_INV, $rows[0]['section']);

        $entryRows = $parser->getEntryRows();
        $this->assertCount(1, $entryRows);
        $this->assertSame(22, $entryRows[0]['account_id']);
        $this->assertSame('S', $entryRows[0]['ls']);
        $this->assertSame('Credit', $entryRows[0]['dc']);
        $this->assertSame('10.00', $entryRows[0]['amount']);
        $this->assertSame('RUB', $entryRows[0]['currency']);
        $this->assertSame('2026-01-10', $entryRows[0]['value_date']);
        $this->assertSame('ASB-001', $entryRows[0]['statement_number']);
        $this->assertSame(NostroBalance::SOURCE_ASB, $entryRows[0]['source']);
        $this->assertSame('U', $entryRows[0]['match_status']);

        $this->stdout('ASB парсер: разбирает Windows-1251 файл (числа с запятой и пробелами), валюта RUB, дата dd.mm.yyyy→ISO, source=ASB, секция INV.');
    }

    /**
     * Проверяет ошибку ASB при некорректной дате.
     *
     * @return void
     */
    public function testAsbParserReportsInvalidDate(): void
    {
        $path = $this->tempFile('txt', "ДатаНачала=2026/99/99\nНачальныйОстаток=0\nКонечныйОстаток=0\n");

        $parser = new AsbTextParser();
        $rows = $parser->parse($path, 1, NostroBalance::SECTION_NRE);

        $this->assertSame([], $rows);
        $this->assertContains("АСБ: некорректная дата '2026/99/99'", $parser->getErrors());

        $this->stdout('ASB парсер: некорректная дата «2026/99/99» → строки не возвращаются, фиксируется ошибка о некорректной дате.');
    }

    /**
     * Создаёт временный файл с содержимым.
     *
     * @param string $ext Расширение файла.
     * @param string $content Содержимое.
     * @return string Путь к файлу.
     */
    private function tempFile(string $ext, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sm_parser_') . '.' . $ext;
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }
}
