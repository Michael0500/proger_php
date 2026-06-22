<?php

namespace app\components\parsers;

use app\models\NostroBalance;
use app\models\NostroEntry;

/**
 * Парсер Банк-клиент АСБ (текстовый формат, КОИ8-R / Windows-1251).
 *
 * Маппинг:
 *   ls_type          = 'S'
 *   statement_number = поле НомерВыписки или из имени файла
 *   account          = значение после РасчСчет=
 *   currency         = 'RUB' (фиксировано)
 *   value_date       = ДатаНачала= → YYYY-MM-DD
 *   opening_balance  = НачальныйОстаток=
 *   opening_dc       = если ВсегоПоступило > 0 → C; иначе D
 *   closing_balance  = КонечныйОстаток=
 *   closing_dc       = аналогично
 *   entries          = агрегированные строки по ВсегоПоступило/ВсегоСписано
 */
class AsbTextParser
{
    /** @var string[] Ошибки последнего парсинга */
    private array $errors = [];

    /** @var array[] Строки выверки последнего парсинга */
    private array $entryRows = [];

    /**
     * Парсит текстовый файл АСБ и возвращает строки для `NostroBalance`.
     *
     * Строки выверки для `NostroEntry` доступны через `getEntryRows()`.
     *
     * Поддерживает UTF-8, Windows-1251 и KOI8-R. Многосекционные файлы
     * разбиваются на отдельные выписки.
     *
     * @param string $filePath Путь к файлу.
     * @param int $accountId ID счёта.
     * @param string $section Раздел `NRE` или `INV`.
     * @return array[] Массив строк балансов.
     */
    public function parse(string $filePath, int $accountId, string $section): array
    {
        $this->errors = [];
        $this->entryRows = [];

        $raw = @file_get_contents($filePath);
        if ($raw === false) {
            $this->errors[] = 'Не удалось прочитать файл';
            return [];
        }

        // Попытка определить кодировку и привести к UTF-8
        $content = $this->toUtf8($raw);

        // Разбиваем на секции — каждая секция начинается с ЗаголовокВыписки или 1C-схожего разделителя
        $sections = $this->splitSections($content);

        if (empty($sections)) {
            // Если секций нет — весь файл как одна секция
            $sections = [$content];
        }

        $rows = [];
        foreach ($sections as $section_text) {
            $row = $this->parseSection($section_text, $accountId, $section, $filePath);
            if ($row) $rows[] = $row;
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

    // ─── Приватные ────────────────────────────────────────────────

    /**
     * Разбирает одну секцию выписки АСБ.
     *
     * @param string $text Текст секции.
     * @param int $accountId ID счёта.
     * @param string $section Раздел баланса.
     * @param string $filePath Путь к файлу для извлечения номера выписки.
     * @return array|null Строка баланса или `null`, если секция некорректна.
     */
    private function parseSection(string $text, int $accountId, string $section, string $filePath): ?array
    {
        $fields = $this->extractFields($text);

        // Номер выписки: из поля или из имени файла
        $stmtNumber = $fields['НомерВыписки']
            ?? $fields['НомерДокумента']
            ?? $this->extractFromFileName($filePath);

        // Счёт
        $acct = $fields['РасчСчет'] ?? $fields['СчетОтправителя'] ?? '';

        // Дата
        $rawDate = $fields['ДатаНачала'] ?? $fields['ДатаДокумента'] ?? '';
        $valueDate = $this->parseDate($rawDate);
        if (!$valueDate) {
            $this->errors[] = "АСБ: некорректная дата '{$rawDate}'";
            return null;
        }

        // Суммы — заменяем запятую на точку
        $openingRaw = $this->parseAmount($fields['НачальныйОстаток'] ?? $fields['ВходящийОстаток'] ?? '0');
        $closingRaw = $this->parseAmount($fields['КонечныйОстаток']  ?? $fields['ИсходящийОстаток'] ?? '0');
        $incoming   = $this->parseAmount($fields['ВсегоПоступило']   ?? '0');
        $outgoing   = $this->parseAmount($fields['ВсегоСписано']     ?? '0');

        // D/C: кредит если поступило > 0, дебет если списано > 0
        $openingDc = $incoming > 0 ? NostroBalance::DC_CREDIT : NostroBalance::DC_DEBIT;
        $closingDc = $outgoing > 0 ? NostroBalance::DC_DEBIT  : NostroBalance::DC_CREDIT;

        $this->entryRows = array_merge(
            $this->entryRows,
            $this->buildEntryRows($accountId, $stmtNumber, $valueDate, $incoming, $outgoing)
        );

        return [
            'account_id'       => $accountId,
            'ls_type'          => NostroBalance::LS_STATEMENT,
            'statement_number' => $stmtNumber ?: null,
            'currency'         => 'RUB',
            'value_date'       => $valueDate,
            'opening_balance'  => $openingRaw,
            'opening_dc'       => $openingDc,
            'closing_balance'  => $closingRaw,
            'closing_dc'       => $closingDc,
            'section'          => $section,
            'source'           => NostroBalance::SOURCE_ASB,
            'status'           => NostroBalance::STATUS_NORMAL,
            '_acct_string'     => $acct,
        ];
    }

    /**
     * Извлекает поля вида `Ключ=Значение`.
     *
     * @param string $text Текст секции.
     * @return array Карта полей.
     */
    private function extractFields(string $text): array
    {
        $fields = [];
        $lines = preg_split('/\r?\n/', $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '=') !== false) {
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                if ($key !== '') {
                    $fields[$key] = $val;
                }
            }
        }
        return $fields;
    }

    /**
     * Строит агрегированные строки выверки по оборотам выписки.
     *
     * @param int $accountId ID счёта.
     * @param string|null $stmtNumber Номер выписки.
     * @param string $valueDate Дата валютирования.
     * @param float $incoming Сумма поступлений.
     * @param float $outgoing Сумма списаний.
     * @return array[] Строки для `NostroEntry`.
     */
    private function buildEntryRows(int $accountId, ?string $stmtNumber, string $valueDate, float $incoming, float $outgoing): array
    {
        $rows = [];

        if ($incoming > 0) {
            $rows[] = $this->buildEntryRow($accountId, $stmtNumber, $valueDate, NostroEntry::DC_CREDIT, $incoming);
        }

        if ($outgoing > 0) {
            $rows[] = $this->buildEntryRow($accountId, $stmtNumber, $valueDate, NostroEntry::DC_DEBIT, $outgoing);
        }

        return $rows;
    }

    /**
     * Строит одну строку выверки ASB.
     *
     * @param int $accountId ID счёта.
     * @param string|null $stmtNumber Номер выписки.
     * @param string $valueDate Дата валютирования.
     * @param string $dc Направление Debit/Credit.
     * @param float $amount Сумма операции.
     * @return array Строка для `NostroEntry`.
     */
    private function buildEntryRow(int $accountId, ?string $stmtNumber, string $valueDate, string $dc, float $amount): array
    {
        return [
            'account_id'       => $accountId,
            'ls'               => NostroEntry::LS_STATEMENT,
            'dc'               => $dc,
            'amount'           => number_format($amount, 2, '.', ''),
            'currency'         => 'RUB',
            'value_date'       => $valueDate,
            'post_date'        => null,
            'statement_number' => $stmtNumber ?: null,
            'source'           => NostroBalance::SOURCE_ASB,
            'match_status'     => NostroEntry::STATUS_UNMATCHED,
        ];
    }

    /**
     * Разделяет файл на блоки выписок.
     *
     * @param string $text Текст файла в UTF-8.
     * @return string[] Секции файла.
     */
    private function splitSections(string $text): array
    {
        // АСБ иногда разделяет секции строкой "СекцияРасчСчет" или пустыми строками
        $parts = preg_split('/\[ЗаголовокВыписки\]|\[СчетВыписки\]/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return count($parts) > 1 ? $parts : [];
    }

    /**
     * Конвертирует содержимое файла в UTF-8.
     *
     * @param string $raw Сырые байты файла.
     * @return string UTF-8 строка или исходная строка, если конвертация не удалась.
     */
    private function toUtf8(string $raw): string
    {
        // Если уже UTF-8
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        // Пробуем Windows-1251
        $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $raw);
        if ($converted && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
        // KOI8-R
        $converted = @iconv('KOI8-R', 'UTF-8//IGNORE', $raw);
        if ($converted && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
        return $raw;
    }

    /**
     * Преобразует дату в формат `Y-m-d`.
     *
     * @param string $raw Исходная дата.
     * @return string|null Нормализованная дата или `null`.
     */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!$raw) return null;

        if (preg_match('/^(\d{2})[.\\/](\d{2})[.\\/](\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return null;
    }

    /**
     * Преобразует строку суммы в число.
     *
     * @param string $raw Сумма с возможными пробелами и запятой.
     * @return float Числовое значение.
     */
    private function parseAmount(string $raw): float
    {
        $raw = trim($raw);
        $raw = str_replace([' ', "\xc2\xa0", "\xa0"], '', $raw); // убрать неразрывные пробелы
        $raw = str_replace(',', '.', $raw);
        return (float)$raw;
    }

    /**
     * Извлекает предполагаемый номер выписки из имени файла.
     *
     * @param string $filePath Путь к файлу.
     * @return string|null Номер выписки или имя файла без расширения.
     */
    private function extractFromFileName(string $filePath): ?string
    {
        $base = pathinfo($filePath, PATHINFO_FILENAME);
        // Ищем числовую часть
        if (preg_match('/(\d[\d_\-]+\d)/', $base, $m)) {
            return $m[1];
        }
        return $base ?: null;
    }
}
