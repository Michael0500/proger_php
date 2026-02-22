<?php

namespace app\components\parsers;

use app\models\NostroBalance;

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
 */
class AsbTextParser
{
    private array $errors = [];

    /**
     * @param string $filePath  Путь к файлу
     * @param int    $accountId FK счёта
     * @param string $section   NRE|INV
     * @return array[]
     */
    public function parse(string $filePath, int $accountId, string $section): array
    {
        $this->errors = [];

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

    public function getErrors(): array
    {
        return $this->errors;
    }

    // ─── Приватные ────────────────────────────────────────────────

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
     * Извлекает поля вида Ключ=Значение из текста (одна строка — одна пара)
     */
    private function extractFields(string $text): array
    {
        $fields = [];
        $lines = preg_split('/\r?\n/', $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_contains($line, '=')) {
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
     * Разделить файл на блоки (для многосекционных файлов)
     */
    private function splitSections(string $text): array
    {
        // АСБ иногда разделяет секции строкой "СекцияРасчСчет" или пустыми строками
        $parts = preg_split('/\[ЗаголовокВыписки\]|\[СчетВыписки\]/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return count($parts) > 1 ? $parts : [];
    }

    /**
     * Конвертация в UTF-8: пробуем win-1251 и koi8-r
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
     * Преобразовать дату в YYYY-MM-DD
     * Форматы: DD.MM.YYYY, DD/MM/YYYY, YYYY-MM-DD
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
     * Преобразовать строку суммы в float (запятая → точка)
     */
    private function parseAmount(string $raw): float
    {
        $raw = trim($raw);
        $raw = str_replace([' ', "\xc2\xa0", "\xa0"], '', $raw); // убрать неразрывные пробелы
        $raw = str_replace(',', '.', $raw);
        return (float)$raw;
    }

    /**
     * Извлечь предполагаемый номер выписки из имени файла
     * Например: statement_2024_001.txt → 2024_001
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