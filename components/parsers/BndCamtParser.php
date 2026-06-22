<?php

namespace app\components\parsers;

use app\models\NostroBalance;
use app\models\NostroEntry;

/**
 * Парсер Банк-клиент БНД (camt-based XML).
 *
 * Маппинг:
 *   ls_type          = 'S' (константа)
 *   statement_number = //Stmt/Id
 *   account          = //Stmt/Acct/Id/IBAN или //Othr/Id
 *   currency         = //Bal[Tp/CdOrPrtry/Cd='OPBD']/Amt/@Ccy
 *   value_date       = //Bal[.../Cd='OPBD']/Dt/Dt  → YYYY-MM-DD
 *   opening_balance  = //Bal[.../Cd='OPBD']/Amt
 *   opening_dc       = //Bal[.../Cd='OPBD']/CdtDbtInd → CRDT='C', DBIT='D'
 *   closing_balance  = //Bal[.../Cd='CLBD']/Amt
 *   closing_dc       = //Bal[.../Cd='CLBD']/CdtDbtInd
 *   entries          = //Ntry[Sts='BOOK']
 */
class BndCamtParser
{
    /** @var string[] Накопленные ошибки парсинга */
    private array $errors = [];

    /** @var array[] Строки выверки последнего парсинга */
    private array $entryRows = [];

    /**
     * Парсит XML-файл и возвращает строки для создания `NostroBalance`.
     *
     * Ошибки парсинга накапливаются в `$errors` и доступны через `getErrors()`.
     *
     * @param string $filePath Путь к загруженному XML-файлу.
     * @param int $accountId ID счёта из справочника.
     * @param string $section Раздел `NRE` или `INV`.
     * @return array[] Массив строк балансов.
     */
    public function parse(string $filePath, int $accountId, string $section): array
    {
        $this->errors = [];
        $this->entryRows = [];
        $rows = [];

        $xml = @simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false) {
            $this->errors[] = 'Не удалось прочитать XML-файл';
            return [];
        }

        // Регистрируем пространства имён camt
        $ns = $xml->getNamespaces(true);
        // Поддержка camt.052 и camt.053
        $defaultNs = $ns[''] ?? null;
        if ($defaultNs) {
            $xml->registerXPathNamespace('camt', $defaultNs);
        }

        // БНД по требованиям передаёт camt.052: BkToCstmrAcctRpt/Rpt.
        $blocks = $this->xpathSafe($xml, '//camt:Rpt') ?: $this->xpathSafe($xml, '//Rpt');

        if (empty($blocks)) {
            $this->errors[] = 'Не найдены блоки <Rpt> в файле';
            return [];
        }

        foreach ($blocks as $block) {
            $row = $this->parseReportBlock($block, $accountId, $section);
            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Возвращает ошибки последнего парсинга.
     *
     * @return string[] Список ошибок.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Возвращает строки выверки, собранные при последнем парсинге.
     *
     * @return array[] Строки для `NostroEntry`.
     */
    public function getEntryRows(): array
    {
        return $this->entryRows;
    }

    // ─── Приватные методы ─────────────────────────────────────────

    /**
     * Разбирает один блок `<Rpt>` CAMT.
     *
     * @param \SimpleXMLElement $block XML-узел выписки.
     * @param int $accountId ID счёта.
     * @param string $section Раздел баланса.
     * @return array|null Строка баланса или `null`, если данные неполные.
     */
    private function parseReportBlock(\SimpleXMLElement $block, int $accountId, string $section): ?array
    {
        // Номер выписки
        $stmtId = $this->firstText($block, './*[local-name()="Id"]');
        if (!$stmtId) {
            $this->errors[] = 'Не найден Id выписки';
        }

        // Счёт: IBAN или Othr/Id
        $acctId = $this->firstText($block, './*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]')
            ?: $this->firstText($block, './*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="Othr"]/*[local-name()="Id"]');

        // Разбираем балансы (OPBD и CLBD)
        $opbd = $this->findBalance($block, 'OPBD');
        $clbd = $this->findBalance($block, 'CLBD');

        if (!$opbd) {
            $this->errors[] = "Rpt {$stmtId}: не найден баланс OPBD";
            return null;
        }
        if (!$clbd) {
            $this->errors[] = "Rpt {$stmtId}: не найден баланс CLBD";
            return null;
        }

        // Дата из OPBD
        $rawDate = (string)($opbd['date'] ?? '');
        $valueDate = $this->parseDate($rawDate);
        if (!$valueDate) {
            $this->errors[] = "Rpt {$stmtId}: некорректная дата '{$rawDate}'";
            return null;
        }

        $this->entryRows = array_merge(
            $this->entryRows,
            $this->parseEntries($block, $accountId, $stmtId ?: null, $opbd['currency'])
        );

        return [
            'account_id'       => $accountId,
            'ls_type'          => NostroBalance::LS_STATEMENT,
            'statement_number' => $stmtId ?: null,
            'currency'         => $opbd['currency'],
            'value_date'       => $valueDate,
            'opening_balance'  => $opbd['amount'],
            'opening_dc'       => $opbd['dc'],
            'closing_balance'  => $clbd['amount'],
            'closing_dc'       => $clbd['dc'],
            'section'          => $section,
            'source'           => NostroBalance::SOURCE_BND,
            'status'           => NostroBalance::STATUS_NORMAL,
            '_acct_string'     => $acctId, // для информации, не сохраняется
        ];
    }

    /**
     * Находит баланс по коду OPBD/CLBD внутри выписки.
     *
     * @param \SimpleXMLElement $block XML-узел выписки.
     * @param string $code Код баланса `OPBD` или `CLBD`.
     * @return array|null Данные суммы, валюты, D/C и даты.
     */
    private function findBalance(\SimpleXMLElement $block, string $code): ?array
    {
        $balances = $this->xpathSafe($block, './*[local-name()="Bal"]');
        if (!$balances) {
            return null;
        }

        foreach ($balances as $bal) {
            $cd = $this->firstText($bal, './*[local-name()="Tp"]/*[local-name()="CdOrPrtry"]/*[local-name()="Cd"]');

            if ($cd !== $code) continue;

            $amtNode = $this->firstNode($bal, './*[local-name()="Amt"]');
            $amount  = $amtNode ? $this->normalizeAmount((string)$amtNode) : '0.00';
            $currency = $amtNode ? (string)($amtNode->attributes()['Ccy'] ?? '') : '';

            $cdtDbtInd = $this->firstText($bal, './*[local-name()="CdtDbtInd"]');
            $dc = $this->mapBalanceDc($cdtDbtInd);

            // Дата
            $date = $this->firstText($bal, './*[local-name()="Dt"]/*[local-name()="Dt"]')
                ?: $this->firstText($bal, './*[local-name()="Dt"]/*[local-name()="DtTm"]');
            // Если DtTm — берём только дату
            if (strlen($date) > 10) {
                $date = substr($date, 0, 10);
            }

            return [
                'amount'   => $amount,
                'currency' => $currency,
                'dc'       => $dc,
                'date'     => $date,
            ];
        }

        return null;
    }

    /**
     * Разбирает проводки выписки со статусом BOOK.
     *
     * @param \SimpleXMLElement $block XML-узел выписки.
     * @param int $accountId ID счёта.
     * @param string|null $stmtId Номер выписки.
     * @param string $fallbackCurrency Валюта из баланса.
     * @return array[] Строки для `NostroEntry`.
     */
    private function parseEntries(\SimpleXMLElement $block, int $accountId, ?string $stmtId, string $fallbackCurrency): array
    {
        $rows = [];
        $entries = $this->xpathSafe($block, './*[local-name()="Ntry"]');

        foreach ($entries as $entry) {
            $status = $this->firstText($entry, './*[local-name()="Sts"]')
                ?: $this->firstText($entry, './*[local-name()="Sts"]/*[local-name()="Cd"]');
            if ($status !== 'BOOK') {
                continue;
            }

            $amtNode = $this->firstNode($entry, './*[local-name()="Amt"]');
            $amount = $amtNode ? $this->normalizeAmount((string)$amtNode) : '0.00';
            if ((float)$amount <= 0) {
                continue;
            }

            $rawDate = $this->firstText($entry, './*[local-name()="ValDt"]/*[local-name()="Dt"]')
                ?: $this->firstText($entry, './*[local-name()="ValDt"]/*[local-name()="DtTm"]');
            if (strlen($rawDate) > 10) {
                $rawDate = substr($rawDate, 0, 10);
            }

            $valueDate = $this->parseDate($rawDate);
            if (!$valueDate) {
                $this->errors[] = "Rpt {$stmtId}: некорректная дата проводки '{$rawDate}'";
                continue;
            }

            $rawPostDate = $this->firstText($entry, './*[local-name()="BookgDt"]/*[local-name()="Dt"]')
                ?: $this->firstText($entry, './*[local-name()="BookgDt"]/*[local-name()="DtTm"]');
            if (strlen($rawPostDate) > 10) {
                $rawPostDate = substr($rawPostDate, 0, 10);
            }

            $rows[] = [
                'account_id'       => $accountId,
                'ls'               => NostroEntry::LS_STATEMENT,
                'dc'               => $this->mapEntryDc($this->firstText($entry, './*[local-name()="CdtDbtInd"]')),
                'amount'           => $amount,
                'currency'         => (string)($amtNode->attributes()['Ccy'] ?? $fallbackCurrency),
                'value_date'       => $valueDate,
                'post_date'        => $this->parseDate($rawPostDate),
                'statement_number' => $stmtId,
                'source'           => NostroBalance::SOURCE_BND,
                'match_status'     => NostroEntry::STATUS_UNMATCHED,
            ];
        }

        return $rows;
    }

    /**
     * Приводит дату к формату `Y-m-d`.
     *
     * @param string $raw Исходная дата `Y-m-d`, `d.m.Y` или `d/m/Y`.
     * @return string|null Нормализованная дата или `null`.
     */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!$raw) return null;

        // ISO уже в нужном формате
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        // DD.MM.YYYY или DD/MM/YYYY
        if (preg_match('/^(\d{2})[.\\/](\d{2})[.\\/](\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    /**
     * Приводит CAMT-признак дебета/кредита к внутреннему значению.
     *
     * @param string $value Значение `CdtDbtInd`.
     * @return string `C` или `D`.
     */
    private function mapBalanceDc(string $value): string
    {
        return strtoupper(trim($value)) === 'CRDT'
            ? NostroBalance::DC_CREDIT
            : NostroBalance::DC_DEBIT;
    }

    /**
     * Приводит CAMT-признак дебета/кредита к значению записи выверки.
     *
     * @param string $value Значение `CdtDbtInd`.
     * @return string `Debit` или `Credit`.
     */
    private function mapEntryDc(string $value): string
    {
        return strtoupper(trim($value)) === 'CRDT'
            ? NostroEntry::DC_CREDIT
            : NostroEntry::DC_DEBIT;
    }

    /**
     * Нормализует сумму CAMT без приведения к float.
     *
     * @param string $value Исходная сумма.
     * @return string Decimal-строка с двумя знаками.
     */
    private function normalizeAmount(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') {
            return '0.00';
        }

        if (strpos($value, '.') === false) {
            return $value . '.00';
        }

        [$integer, $fraction] = explode('.', $value, 2);
        return $integer . '.' . substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    /**
     * Возвращает первый XML-узел по XPath.
     *
     * @param \SimpleXMLElement $xml XML-узел.
     * @param string $path XPath-выражение.
     * @return \SimpleXMLElement|null Найденный узел.
     */
    private function firstNode(\SimpleXMLElement $xml, string $path): ?\SimpleXMLElement
    {
        $nodes = $this->xpathSafe($xml, $path);
        return $nodes[0] ?? null;
    }

    /**
     * Возвращает текст первого XML-узла по XPath.
     *
     * @param \SimpleXMLElement $xml XML-узел.
     * @param string $path XPath-выражение.
     * @return string Текст узла или пустая строка.
     */
    private function firstText(\SimpleXMLElement $xml, string $path): string
    {
        $node = $this->firstNode($xml, $path);
        return $node ? trim((string)$node) : '';
    }

    /**
     * Выполняет XPath без проброса исключений наружу.
     *
     * @param \SimpleXMLElement $xml XML-узел.
     * @param string $path XPath-выражение.
     * @return array Найденные узлы или пустой массив.
     */
    private function xpathSafe(\SimpleXMLElement $xml, string $path): array
    {
        try {
            return $xml->xpath($path) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
