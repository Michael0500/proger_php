<?php

namespace app\components\parsers;

use app\models\NostroBalance;

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
 */
class BndCamtParser
{
    /** @var string[] Накопленные ошибки парсинга */
    private array $errors = [];

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

        // Ищем Stmt-блоки (Statement)
        $stmts = $this->xpathSafe($xml, '//camt:Stmt') ?: $this->xpathSafe($xml, '//Stmt');

        if (empty($stmts)) {
            // Попробуем без NS
            $stmts = $xml->xpath('//Stmt');
        }

        if (empty($stmts)) {
            $this->errors[] = 'Не найдены блоки <Stmt> в файле';
            return [];
        }

        foreach ($stmts as $stmt) {
            $row = $this->parseStmt($stmt, $accountId, $section, $defaultNs);
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

    // ─── Приватные методы ─────────────────────────────────────────

    /**
     * Разбирает один блок `<Stmt>` CAMT.
     *
     * @param \SimpleXMLElement $stmt XML-узел выписки.
     * @param int $accountId ID счёта.
     * @param string $section Раздел баланса.
     * @param string|null $ns Default namespace CAMT.
     * @return array|null Строка баланса или `null`, если данные неполные.
     */
    private function parseStmt(\SimpleXMLElement $stmt, int $accountId, string $section, ?string $ns): ?array
    {
        // Номер выписки
        $stmtId = (string)($stmt->Id ?? $stmt->children($ns)->Id ?? '');
        if (!$stmtId) {
            $this->errors[] = 'Не найден Id выписки';
        }

        // Счёт: IBAN или Othr/Id
        $acctId = (string)($stmt->Acct->Id->IBAN
            ?? $stmt->Acct->Id->Othr->Id
            ?? $stmt->children($ns)->Acct->Id->IBAN
            ?? $stmt->children($ns)->Acct->Id->Othr->Id
            ?? '');

        // Разбираем балансы (OPBD и CLBD)
        $opbd = $this->findBalance($stmt, 'OPBD', $ns);
        $clbd = $this->findBalance($stmt, 'CLBD', $ns);

        if (!$opbd) {
            $this->errors[] = "Stmt {$stmtId}: не найден баланс OPBD";
            return null;
        }
        if (!$clbd) {
            $this->errors[] = "Stmt {$stmtId}: не найден баланс CLBD";
            return null;
        }

        // Дата из OPBD
        $rawDate = (string)($opbd['date'] ?? '');
        $valueDate = $this->parseDate($rawDate);
        if (!$valueDate) {
            $this->errors[] = "Stmt {$stmtId}: некорректная дата '{$rawDate}'";
            return null;
        }

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
     * @param \SimpleXMLElement $stmt XML-узел выписки.
     * @param string $code Код баланса `OPBD` или `CLBD`.
     * @param string|null $ns Default namespace CAMT.
     * @return array|null Данные суммы, валюты, D/C и даты.
     */
    private function findBalance(\SimpleXMLElement $stmt, string $code, ?string $ns): ?array
    {
        $balances = $stmt->Bal ?? ($ns ? $stmt->children($ns)->Bal : null);
        if (!$balances) return null;

        foreach ($balances as $bal) {
            // Получаем код типа баланса
            $cd = (string)(
                $bal->Tp->CdOrPrtry->Cd
                ?? ($ns ? $bal->children($ns)->Tp->CdOrPrtry->Cd : null)
                ?? ''
            );

            if ($cd !== $code) continue;

            $amtNode = $bal->Amt ?? ($ns ? $bal->children($ns)->Amt : null);
            $amount  = $amtNode ? (float)(string)$amtNode : 0.0;
            $currency = $amtNode ? (string)($amtNode->attributes()['Ccy'] ?? '') : '';

            $cdtDbtInd = (string)(
                $bal->CdtDbtInd
                ?? ($ns ? $bal->children($ns)->CdtDbtInd : null)
                ?? ''
            );
            $dc = ($cdtDbtInd === 'CRDT') ? NostroBalance::DC_CREDIT : NostroBalance::DC_DEBIT;

            // Дата
            $date = (string)(
                $bal->Dt->Dt
                ?? $bal->Dt->DtTm
                ?? ($ns ? $bal->children($ns)->Dt->Dt : null)
                ?? ''
            );
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
