<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Конвертация шаблона ЦБ "МР УОД ПУРЦБ" (xlsx) в пакет XBRL-CSV для сдачи в Банк России.
 *
 * Точка входа таксономии: ep_nso_purcb_oper_nr_uod_reestr (версия 6.1.0.7 от 2025-07-04).
 * Спецификация: «Правила формирования УОД ПУРЦБ в формате XBRL-CSV», версия 2.0 от 01.08.2025.
 *
 * Поток работы:
 *   1. Читаем "Прил 1" → реестр concept'ов (раздел, prefix, qname, datatype, periodType, roleUri).
 *   2. По листам "Раздел 1." … "Раздел 11." вытаскиваем данные:
 *      - строка 4 = заголовки с QName в скобках типа "(dim-int:C_CdTaxis)";
 *      - строка 5 = служебная (Open Open) — пропускается;
 *      - со строки 6 — данные.
 *   3. Для каждого непустого раздела пишем sr_R{N}.csv (UTF-8 без BOM, разделитель |, LF).
 *   4. Собираем sr_sved_purcb.csv с пометкой DannyeOtsutstvuyutMember/DannyePeredanyMember.
 *   5. Генерим mapping.json (полный вид по разделу 2.2.7 Правил).
 *   6. Кладём всё в zip.
 *
 * Использование:
 *   php yii xbrl-convert/run \
 *       --identifier=1027700000000 \
 *       --report-date=2025-10-31 \
 *       --period-start=2024-12-15 \
 *       --period-end=2025-02-02 \
 *       --request-number=00000_11
 *
 * Пути:
 *   вход:  <корень проекта>/web/uploads/report.xlsx
 *   выход: <корень проекта>/web/xbrl-output/
 */
class XbrlConvertController extends Controller
{
    public $identifier;                // ОГРН отчитывающейся организации (13 цифр)
    public $identifierPredecessor;     // ОГРН правопредшественника (опционально)
    public $reportDate;                // дата среза, YYYY-MM-DD
    public $periodStart;               // periodStart_Dt (только для sr_sved_purcb)
    public $periodEnd;                 // periodEnd_Dt   (только для sr_sved_purcb)
    public $requestNumber = '00000_00';
    public $delimiter = '|';
    public $zip = 1;

    /** Полные пути, вычисляются в actionRun() */
    private string $input;
    private string $output;

    /** Пути относительно корня проекта Yii2 (директория, где лежит yii) */
    private const INPUT_RELATIVE  = 'web/uploads/report.xlsx';
    private const OUTPUT_RELATIVE = 'web/xbrl-output';

    /** Точка входа — фиксирована для УОД ПУРЦБ */
    private const TAXONOMY_HREF      = 'http://www.cbr.ru/xbrl_csv/20250704/20250731/ep_nso_purcb_oper_nr_uod_reestr.def.json';
    private const DOCUMENT_VERSION   = 'http://www.cbr.ru/xbrl_csv2/20250704/20250731/difp';
    private const ROLE_URI_BASE      = 'http://www.cbr.ru/xbrl/nso/purcb/rep/2025-07-04/tab/';
    private const TOTAL_SECTIONS     = 11;

    /** Имена служебных листов */
    private const SHEET_PRIL1 = 'Прил 1';

    private const SECTION_HEADER_ROW = 4;
    private const SECTION_DATA_FROM  = 6;

    /**
     * Справочник typedDomain ↔ dimension для таксономии 6.1.0.7 от 2025-07-04.
     * Закономерности в именах нет, поэтому хардкодим. Источник — пример example_full
     * с сайта ЦБ. При обновлении версии таксономии — обновить.
     */
    private const TYPED_DOMAINS = [
        'dim-int_AA_PrdBgnTaxis'        => 'dim-int_AA_PrdBgnTypedName',
        'dim-int_AA_PrdEndTaxis'        => 'dim-int_AA_PrdEndTypedName',
        'dim-int_ALF_ClntAmnt_DtTaxis'  => 'dim-int_ALF_ClntAmnt_DtTypedname',
        'dim-int_A_NTaxis'              => 'dim-int_ID_account_TypedName',
        'dim-int_A_PrtflCdTaxis'        => 'dim-int_A_PrtflCdTypedName',
        'dim-int_A_SctnTaxis'           => 'dim-int_ID_account_TypedName',
        'dim-int_AmntF_DtTaxis'         => 'dim-int_AmntF_DtTypedname',
        'dim-int_Asst_IdTaxis'          => 'dim-int_IDAktivaTypedName',
        'dim-int_C_CdTaxis'             => 'dim-int_ID_FL_YUL_ReestrTypedName',
        'dim-int_Cntrct_CdTaxis'        => 'dim-int_ID_Dogovora_Typedname',
        'dim-int_Cntrct_DtTaxis'        => 'dim-int_Cntrct_DtTypedname',
        'dim-int_ID_strokiTaxis'        => 'dim-int_ID_strokiTypedname',
        'dim-int_Rqst_IdTaxis'          => 'dim-int_IDTrebObyazTypedName',
        'dim-int_Rqst_IdTrdTaxis'       => 'dim-int_Rqst_IdTrdTypedName',
        'dim-int_T_IdTaxis'             => 'dim-int_T_IdTypedName',
        'dim-int_T_IdTrdTaxis'          => 'dim-int_T_IdTrdTypedName',
    ];

    /** Реестр concept'ов: section => columnId(в стиле CSV) => meta */
    private array $registry = [];
    /** roleUri по разделу: section => roleUri */
    private array $roleUriBySection = [];
    /** Какие разделы фактически содержат данные (для заполнения sr_sved_purcb) */
    private array $sectionsHaveData = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'identifier', 'identifierPredecessor',
            'reportDate', 'periodStart', 'periodEnd', 'requestNumber',
            'delimiter', 'zip',
        ]);
    }

    public function actionRun(): int
    {
        try {
            $this->resolvePaths();
            $this->validate();

            $this->info("→ Открываем xlsx: {$this->input}");
            $book = IOFactory::load($this->input);

            $this->info('→ Парсим лист "Прил 1"');
            $this->parsePril1($book);
            $this->info('  разделов: ' . count($this->registry)
                . ', концептов всего: ' . array_sum(array_map('count', $this->registry)));

            $pkgDir = $this->preparePackageDir();
            $this->info("→ Каталог пакета: {$pkgDir}");

            $tablesForMapping = []; // массив элементов tables[] для mapping.json

            // Обходим разделы 1…11 в порядке, как они идут в шаблоне
            for ($n = 1; $n <= self::TOTAL_SECTIONS; $n++) {
                $tableInfo = $this->processSection($book, $n, $pkgDir);
                if ($tableInfo !== null) {
                    $tablesForMapping[] = $tableInfo;
                }
            }

            // sr_sved_purcb — обязательная сводная таблица
            $this->info('→ Формируем sr_sved_purcb.csv');
            $svedTable = $this->buildSvedTable($pkgDir);
            $tablesForMapping[] = $svedTable;

            $this->info('→ Генерируем mapping.json');
            $this->writeMappingJson($pkgDir, $tablesForMapping);

            if ($this->zip) {
                $zipPath = $this->zipPackage($pkgDir);
                $this->success("✓ Готовый пакет: {$zipPath}");
            } else {
                $this->success("✓ Готовый пакет: {$pkgDir}");
            }
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ {$e->getMessage()}\n", Console::FG_RED);
            $this->stderr($e->getTraceAsString() . "\n");
            return ExitCode::SOFTWARE;
        }
    }

    // =====================================================================
    // ВАЛИДАЦИЯ
    // =====================================================================

    /**
     * Резолвит пути входа и выхода относительно корня Yii2-приложения.
     * Гарантирует существование выходного каталога.
     */
    private function resolvePaths(): void
    {
        $appRoot = \Yii::getAlias('@app');
        $this->input  = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::INPUT_RELATIVE);
        $this->output = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::OUTPUT_RELATIVE);
        FileHelper::createDirectory($this->output);
    }

    private function validate(): void
    {
        if (!is_file($this->input)) {
            throw new \RuntimeException(
                "Не найден входной файл: {$this->input}\n"
                . "Положите xlsx в " . self::INPUT_RELATIVE . " относительно корня проекта."
            );
        }
        if (empty($this->identifier) || !preg_match('/^\d{13}$/', $this->identifier)) {
            throw new \InvalidArgumentException("--identifier (ОГРН) должен быть 13-значным числом");
        }
        if ($this->identifierPredecessor && !preg_match('/^\d{13}$/', $this->identifierPredecessor)) {
            throw new \InvalidArgumentException("--identifierPredecessor должен быть 13-значным числом");
        }
        if (empty($this->reportDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->reportDate)) {
            throw new \InvalidArgumentException("--reportDate обязателен в формате YYYY-MM-DD");
        }
        if ($this->periodStart && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->periodStart)) {
            throw new \InvalidArgumentException("--periodStart должен быть в формате YYYY-MM-DD");
        }
        if ($this->periodEnd && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->periodEnd)) {
            throw new \InvalidArgumentException("--periodEnd должен быть в формате YYYY-MM-DD");
        }
        if (!preg_match('/^\d{5}_\d{2}$/', $this->requestNumber)) {
            throw new \InvalidArgumentException("--requestNumber должен быть в формате XXXXX_XX");
        }
        if (!in_array($this->delimiter, ['|', ','], true)) {
            throw new \InvalidArgumentException("--delimiter может быть только '|' или ','");
        }
    }

    // =====================================================================
    // ПАРСИНГ ПРИЛ 1
    // =====================================================================

    private function parsePril1(Spreadsheet $book): void
    {
        $sheet = $book->getSheetByName(self::SHEET_PRIL1);
        if (!$sheet) {
            throw new \RuntimeException("Лист '" . self::SHEET_PRIL1 . "' не найден в xlsx");
        }

        $highestRow = $sheet->getHighestDataRow();
        for ($row = 6; $row <= $highestRow; $row++) {
            $form        = $this->cellString($sheet, 'B', $row);
            $conceptCode = $this->cellString($sheet, 'C', $row);
            $itemType    = $this->cellString($sheet, 'E', $row);
            $periodType  = strtolower($this->cellString($sheet, 'F', $row));
            $prefix      = $this->cellString($sheet, 'G', $row);
            $roleUri     = $this->cellString($sheet, 'H', $row);

            if ($conceptCode === '' || $form === '') continue;

            $sectionNo = $this->extractSectionNumber($form);
            if ($sectionNo === null) continue;

            // Если в C нет префикса — склеиваем
            if (strpos($conceptCode, ':') === false && $prefix !== '') {
                $conceptCode = $prefix . ':' . $conceptCode;
            }

            $columnId = $this->buildColumnId($conceptCode, $prefix);
            $isDimension = ($prefix === 'dim-int');

            $this->registry[$sectionNo][$columnId] = [
                'qname'       => $conceptCode,           // "purcb-dic:Asst_Amnt"
                'prefix'      => $prefix,                // "purcb-dic"
                'itemType'    => $itemType,              // "monetaryItemType"
                'periodType'  => $periodType ?: 'instant',
                'roleUri'     => $roleUri,
                'isDimension' => $isDimension,
            ];

            if ($roleUri !== '' && !isset($this->roleUriBySection[$sectionNo])) {
                $this->roleUriBySection[$sectionNo] = $roleUri;
            }
        }

        if (empty($this->registry)) {
            throw new \RuntimeException("Не удалось распарсить 'Прил 1' — реестр пустой");
        }
    }

    private function extractSectionNumber(string $form): ?int
    {
        if (preg_match('/Раздел\s+(\d+)/u', $form, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Имя колонки в CSV. Правило (по эталону):
     *   - dim-int:* (открытые оси)            → "dim_int_X" (просто :→_, -→_)
     *   - всё остальное (concept-показатели)  → "purcb_dic_X_dimGrp_1_periodGrp_1"
     */
    private function buildColumnId(string $qname, string $prefix): string
    {
        $base = str_replace([':', '-'], '_', $qname);
        if ($prefix === 'dim-int') {
            return $base; // dim_int_C_CdTaxis
        }
        return $base . '_dimGrp_1_periodGrp_1';
    }

    /**
     * Преобразует QName "purcb-dic:Asst_Amnt" в "xbrl:concept" формат "purcb-dic_Asst_Amnt"
     * (двоеточие → подчёркивание, дефис в префиксе остаётся).
     */
    private function qnameToConcept(string $qname): string
    {
        return str_replace(':', '_', $qname);
    }

    /**
     * Преобразует "dim-int:C_CdTaxis" в "dim-int_C_CdTaxis" (для поля dimension).
     */
    private function qnameToDimension(string $qname): string
    {
        return str_replace(':', '_', $qname);
    }

    // =====================================================================
    // ОБРАБОТКА РАЗДЕЛОВ
    // =====================================================================

    /**
     * Возвращает структуру для tables[] в mapping.json, или null если раздел пустой
     * (по спецификации пустые таблицы не включаются в пакет).
     */
    private function processSection(Spreadsheet $book, int $sectionNo, string $pkgDir): ?array
    {
        $this->sectionsHaveData[$sectionNo] = false;

        if (!isset($this->registry[$sectionNo])) {
            $this->warn("  Раздел {$sectionNo}: нет в Прил 1, пропускаем");
            return null;
        }

        $sheet = $this->findSectionSheet($book, $sectionNo);
        if ($sheet === null) {
            $this->warn("  Раздел {$sectionNo}: лист не найден, пропускаем");
            return null;
        }

        // Реестр для раздела упорядочен: сначала все dim-int, потом все остальные
        $registryOrdered = $this->orderRegistry($this->registry[$sectionNo]);
        $columnIds = array_keys($registryOrdered);

        // Сопоставляем буквы Excel ↔ columnId по тех. имени в скобках
        $excelToColumnId = $this->mapSheetColumns($sheet, $registryOrdered);

        $rows = $this->extractDataRows($sheet, $excelToColumnId, $registryOrdered);

        if (empty($rows)) {
            $this->info("  Раздел {$sectionNo}: данных нет, пропускаем");
            return null;
        }

        // Пишем CSV
        $csvName = "sr_R{$sectionNo}.csv";
        $csvPath = $pkgDir . DIRECTORY_SEPARATOR . $csvName;
        $this->writeCsv($csvPath, $columnIds, $rows, $registryOrdered);
        $this->info("  Раздел {$sectionNo}: {$csvName} — " . count($rows) . " строк");

        $this->sectionsHaveData[$sectionNo] = true;

        // Структура для mapping.json
        $hasTypedDimension = false;
        foreach ($registryOrdered as $meta) {
            if ($meta['isDimension']) { $hasTypedDimension = true; break; }
        }

        return [
            'uri'            => $csvName,
            'roleUri'        => $this->roleUriBySection[$sectionNo] ?? (self::ROLE_URI_BASE . "sr_R{$sectionNo}"),
            'csvRowsCount'   => count($rows),
            'typedFiltering' => $hasTypedDimension,
            'columns'        => $this->buildColumnsForMapping($registryOrdered),
        ];
    }

    /**
     * Сортирует реестр: dim-int (открытые оси) в начале, в порядке их появления.
     * По спецификации (§2.2.5.1 и §2.3.2) колонки открытых осей должны идти первыми.
     */
    private function orderRegistry(array $registry): array
    {
        $dims = [];
        $facts = [];
        foreach ($registry as $cid => $meta) {
            if ($meta['isDimension']) $dims[$cid] = $meta;
            else $facts[$cid] = $meta;
        }
        return $dims + $facts;
    }

    private function findSectionSheet(Spreadsheet $book, int $sectionNo): ?Worksheet
    {
        foreach (["Раздел {$sectionNo}.", "Раздел {$sectionNo}"] as $name) {
            $s = $book->getSheetByName($name);
            if ($s) return $s;
        }
        return null;
    }

    /**
     * Из заголовка строки 4: "Идентификатор клиента (dim-int:C_CdTaxis)" → ['B' => 'dim_int_C_CdTaxis']
     *
     * @return array<string,string>  excelCol → columnId
     */
    private function mapSheetColumns(Worksheet $sheet, array $registry): array
    {
        $highestColLetter = $sheet->getHighestDataColumn(self::SECTION_HEADER_ROW);
        $highestColIdx = Coordinate::columnIndexFromString($highestColLetter);

        $map = [];
        for ($i = 1; $i <= $highestColIdx; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            $header = $this->cellString($sheet, $col, self::SECTION_HEADER_ROW);
            if ($header === '') continue;

            // Достаём ПОСЛЕДНЕЕ QName в скобках
            if (!preg_match_all('/\(([a-zA-Z][\w-]*:[A-Za-z][\w]*)\)/u', $header, $m)) {
                continue;
            }
            $qname = end($m[1]);
            $prefix = explode(':', $qname, 2)[0];
            $columnId = $this->buildColumnId($qname, $prefix);

            if (isset($registry[$columnId])) {
                $map[$col] = $columnId;
            }
        }
        return $map;
    }

    /**
     * Возвращает массив строк, где каждая — [columnId => нормализованное_значение].
     */
    private function extractDataRows(Worksheet $sheet, array $excelToColumnId, array $registry): array
    {
        if (empty($excelToColumnId)) return [];

        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($row = self::SECTION_DATA_FROM; $row <= $highestRow; $row++) {
            $rowData = [];
            $isEmpty = true;
            $isOpenMarker = true;

            foreach ($excelToColumnId as $col => $cid) {
                $raw = $this->readCellValue($sheet, $col, $row);

                if ($raw !== null && $raw !== '') {
                    $isEmpty = false;
                    if (strcasecmp(trim((string)$raw), 'open') !== 0) {
                        $isOpenMarker = false;
                    }
                } else {
                    $isOpenMarker = false; // пустая ячейка в Open-строке тоже бывает
                }

                $rowData[$cid] = $this->normalizeValue($raw, $registry[$cid]);
            }

            if ($isEmpty) continue;
            if ($isOpenMarker) continue; // строка 5 с маркерами Open Open Open

            // Проверка инвариантов: typed-dimension не может быть пустой
            $skipDueToMissingDim = false;
            foreach ($registry as $cid => $meta) {
                if ($meta['isDimension'] && ($rowData[$cid] ?? '') === '') {
                    $skipDueToMissingDim = true;
                    break;
                }
            }
            if ($skipDueToMissingDim) continue;

            $rows[] = $rowData;
        }
        return $rows;
    }

    private function readCellValue(Worksheet $sheet, string $col, int $row)
    {
        $cell = $sheet->getCell($col . $row);
        try {
            return $cell->getCalculatedValue();
        } catch (\Throwable $e) {
            return $cell->getValue();
        }
    }

    /**
     * Нормализация по itemType из Прил 1.
     */
    private function normalizeValue($value, array $meta): string
    {
        if ($value === null || $value === '') return '';

        $t = strtolower((string)$meta['itemType']);

        // Даты
        if (str_contains($t, 'date')) {
            if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float)$value)->format('Y-m-d');
            }
            $s = trim((string)$value);
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
            return $s;
        }

        // Числа: monetary / decimal / pure / integer
        if (str_contains($t, 'monetary') || str_contains($t, 'decimal') || str_contains($t, 'pure')) {
            if (is_numeric($value)) {
                $s = rtrim(rtrim(number_format((float)$value, 10, '.', ''), '0'), '.');
                return $s === '' ? '0' : $s;
            }
            $clean = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], (string)$value);
            return is_numeric($clean) ? $clean : trim((string)$value);
        }
        if (str_contains($t, 'integer')) {
            return is_numeric($value) ? (string)(int)$value : trim((string)$value);
        }

        // boolean
        if (str_contains($t, 'boolean')) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        // string / enumeration / domain — как есть
        return trim((string)$value);
    }

    // =====================================================================
    // ЗАПИСЬ CSV
    // =====================================================================

    /**
     * Пишет CSV строго по спецификации ЦБ:
     *   - UTF-8 без BOM
     *   - разделитель строк LF
     *   - разделитель колонок: $this->delimiter (| или ,)
     *   - обрамление кавычками — только если значение содержит разделитель или кавычку
     *   - пустые значения = две границы подряд
     */
    private function writeCsv(string $path, array $columnIds, array $rows, array $registry): void
    {
        FileHelper::createDirectory(dirname($path));
        $fh = fopen($path, 'wb');
        if (!$fh) throw new \RuntimeException("Не удалось создать {$path}");

        // Заголовок
        $header = implode($this->delimiter, array_map([$this, 'csvEscape'], $columnIds));
        fwrite($fh, $header . "\n");

        foreach ($rows as $r) {
            $line = [];
            foreach ($columnIds as $cid) {
                $line[] = $this->csvEscape($r[$cid] ?? '');
            }
            fwrite($fh, implode($this->delimiter, $line) . "\n");
        }
        fclose($fh);
    }

    /**
     * Экранирование значения для CSV (RFC 4180 + правила ЦБ §2.3.5).
     */
    private function csvEscape(string $v): string
    {
        if ($v === '') return '';

        $needsQuotes = (
            strpos($v, $this->delimiter) !== false
            || strpos($v, '"') !== false
            || strpos($v, "\n") !== false
            || strpos($v, "\r") !== false
        );

        // Внутри значений переносы строк запрещены — заменяем на пробел
        $v = str_replace(["\r\n", "\r", "\n"], ' ', $v);

        if ($needsQuotes) {
            $v = str_replace('"', '""', $v);
            return '"' . $v . '"';
        }
        return $v;
    }

    // =====================================================================
    // sr_sved_purcb.csv (сводка по разделам)
    // =====================================================================

    /**
     * Сводная таблица — обязательная для пакета. Формирует по одной колонке
     * R{N}Enumerator с признаком "данные переданы / отсутствуют".
     *
     * Колонки в реальном пакете ЦБ берутся из эталона: их состав фиксирован и
     * включает identifier/period поля. Здесь упрощённая версия по флагам разделов.
     */
    private function buildSvedTable(string $pkgDir): array
    {
        // Колонки сводной таблицы: набор берётся фиксированным (по эталону example_full).
        // Часть значений заполняется параметрами команды, часть — флагами разделов.
        $cols = [
            // Информационные поля (заполняются параметрами / по умолчанию пустые)
            'purcb_dic_PeriodStart_Dt_dimGrp_1_periodGrp_1' => [
                'concept'    => 'purcb-dic_PeriodStart_Dt',
                'datatype'   => 'date',
                'columnType' => 'xbrli:dateItemType',
                'value'      => $this->periodStart ?? '',
            ],
            'purcb_dic_PeriodEnd_Dt_dimGrp_1_periodGrp_1' => [
                'concept'    => 'purcb-dic_PeriodEnd_Dt',
                'datatype'   => 'date',
                'columnType' => 'xbrli:dateItemType',
                'value'      => $this->periodEnd ?? '',
            ],
        ];

        // R1Enumerator…R11Enumerator — флаги наличия данных по разделам
        for ($n = 1; $n <= self::TOTAL_SECTIONS; $n++) {
            $key = "purcb_dic_R{$n}Enumerator_dimGrp_1_periodGrp_1";
            $flag = ($this->sectionsHaveData[$n] ?? false)
                ? 'mem-int:DannyePeredanyMember'
                : 'mem-int:DannyeOtsutstvuyutMember';
            $cols[$key] = [
                'concept'    => "purcb-dic_R{$n}Enumerator",
                'datatype'   => 'string',
                'columnType' => 'enum:enumerationItemType',
                'value'      => $flag,
            ];
        }

        // Пишем CSV
        $columnIds = array_keys($cols);
        $row = [];
        foreach ($cols as $cid => $info) {
            $row[$cid] = $info['value'];
        }
        $csvPath = $pkgDir . DIRECTORY_SEPARATOR . 'sr_sved_purcb.csv';
        $this->writeCsv($csvPath, $columnIds, [$row], []);
        $this->info('  sr_sved_purcb.csv — 1 строка');

        // Структура для mapping.json
        $mapColumns = [];
        foreach ($cols as $cid => $info) {
            $mapColumns[] = [
                'columnId' => $cid,
                'aspect' => [
                    'type' => [
                        'datatype' => $info['datatype'],
                        'http://www.cbr.ru/xbrl-csv/model#columnType' => $info['columnType'],
                    ],
                    'xbrl:concept' => $info['concept'],
                ],
                'xbrli:period' => [
                    'periodType'   => 'instant',
                    'xbrli:instant' => '$par:refPeriodEnd',
                ],
                'flatDimension' => true,
            ];
        }
        return [
            'uri'            => 'sr_sved_purcb.csv',
            'roleUri'        => self::ROLE_URI_BASE . 'sr_sved_purcb',
            'csvRowsCount'   => 1,
            'typedFiltering' => false,
            'columns'        => $mapColumns,
        ];
    }

    // =====================================================================
    // ГЕНЕРАЦИЯ mapping.json
    // =====================================================================

    /**
     * Строит массив columns[] для одной таблицы в mapping.json.
     */
    private function buildColumnsForMapping(array $registry): array
    {
        $columns = [];
        foreach ($registry as $columnId => $meta) {
            if ($meta['isDimension']) {
                $dimension = $this->qnameToDimension($meta['qname']);
                $typedDomain = self::TYPED_DOMAINS[$dimension]
                    ?? $this->guessTypedDomain($dimension);

                $columns[] = [
                    'columnId' => $columnId,
                    'xbrldi:typedMember' => [
                        'dimension'   => $dimension,
                        'typedDomain' => $typedDomain,
                    ],
                ];
            } else {
                $columns[] = $this->buildFactColumn($columnId, $meta);
            }
        }
        return $columns;
    }

    /**
     * Строит описание колонки-показателя.
     */
    private function buildFactColumn(string $columnId, array $meta): array
    {
        $itemType = $meta['itemType'];
        $type = $this->buildAspectType($itemType);

        return [
            'columnId' => $columnId,
            'aspect'   => [
                'type'         => $type,
                'xbrl:concept' => $this->qnameToConcept($meta['qname']),
            ],
            'xbrli:period' => [
                'periodType'   => $meta['periodType'] === 'duration' ? 'duration' : 'instant',
                'xbrli:instant' => '$par:refPeriodEnd',
            ],
            'flatDimension' => true,
        ];
    }

    /**
     * Строит блок aspect.type на основе itemType из Прил 1.
     * Соответствует наблюдаемым 7 комбинациям в эталоне.
     */
    private function buildAspectType(string $itemType): array
    {
        $t = strtolower($itemType);

        // monetary
        if (str_contains($t, 'monetary')) {
            return [
                'datatype' => 'decimal',
                'http://www.cbr.ru/xbrl-csv/model#columnType' => 'xbrli:monetaryItemType',
                'xbrli:unit' => [
                    'id' => 'RUB',
                    'xbrli:measure' => 'iso4217:RUB',
                ],
            ];
        }
        // pure / decimal — единица измерения "pure"
        if (str_contains($t, 'pure') || ($t === 'decimalitemtype')) {
            return [
                'datatype' => 'decimal',
                'http://www.cbr.ru/xbrl-csv/model#columnType' => 'xbrli:decimalItemType',
                'xbrli:unit' => [
                    'id' => 'PURE',
                    'xbrli:measure' => 'xbrli:pure',
                ],
            ];
        }
        // integer
        if (str_contains($t, 'integer')) {
            return [
                'datatype' => 'integer',
                'http://www.cbr.ru/xbrl-csv/model#columnType' => 'xbrli:integerItemType',
            ];
        }
        // date
        if (str_contains($t, 'date')) {
            return [
                'datatype' => 'date',
                'http://www.cbr.ru/xbrl-csv/model#columnType' => 'xbrli:dateItemType',
            ];
        }
        // enumeration2 (несколько значений)
        if (str_contains($t, 'enumerationsetitemtype') || str_contains($t, 'enumeration2')) {
            return [
                'datatype' => 'string',
                'http://www.cbr.ru/xbrl-csv/model#columnType' => 'enum2:enumerationSetItemType',
            ];
        }
        // enumeration (одно значение)
        if (str_contains($t, 'enumeration')) {
            return [
                'datatype' => 'string',
                'http://www.cbr.ru/xbrl-csv/model#columnType' => 'enum:enumerationItemType',
            ];
        }
        // string и всё прочее
        return [
            'datatype' => 'string',
            'http://www.cbr.ru/xbrl-csv/model#columnType' => 'xbrli:stringItemType',
        ];
    }

    /**
     * Если в TYPED_DOMAINS нет записи — пытаемся угадать по соглашению Taxis→TypedName.
     */
    private function guessTypedDomain(string $dimension): string
    {
        if (preg_match('/^(.+)Taxis$/', $dimension, $m)) {
            return $m[1] . 'TypedName';
        }
        return $dimension . 'TypedName';
    }

    /**
     * Пишет mapping.json в полном виде по разделу 2.2 Правил.
     */
    private function writeMappingJson(string $pkgDir, array $tables): void
    {
        $mapping = [
            '@context' => 'www.cbr.ru/xbrl_csv2',
            'header' => [
                'encoding' => 'UTF-8',
                'delimiter' => $this->delimiter,
                'reportDate' => $this->reportDate,
                'textQualifier' => '"',
                'documentVersion' => self::DOCUMENT_VERSION,
                'textValueLengthLimit' => 8192,
                'identifier' => $this->identifier,
                'requestNumberDifp' => $this->requestNumber,
            ],
            'dtsReferences' => [
                'type' => 'schema',
                'href' => self::TAXONOMY_HREF,
            ],
            'tables' => $tables,
        ];

        if ($this->identifierPredecessor) {
            // Вставляем после identifier
            $newHeader = [];
            foreach ($mapping['header'] as $k => $v) {
                $newHeader[$k] = $v;
                if ($k === 'identifier') {
                    $newHeader['identifierPredecessor'] = $this->identifierPredecessor;
                }
            }
            $mapping['header'] = $newHeader;
        }

        $json = json_encode($mapping,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // По §2.2 файл должен быть UTF-8 БЕЗ BOM, переносы LF
        $json = str_replace("\r\n", "\n", $json);
        file_put_contents($pkgDir . DIRECTORY_SEPARATOR . 'mapping.json', $json);
    }

    // =====================================================================
    // ZIP
    // =====================================================================

    private function preparePackageDir(): string
    {
        // Имя архива по §2.1: CSV_<ОГРН>_<ТочкаВхода>_<Запрос>_<Дата>
        $stamp = preg_replace('/-/', '', $this->reportDate);
        $base = sprintf('%s_ep_nso_purcb_oper_nr_uod_reestr_%s_%s',
            $this->identifier, $this->requestNumber, $stamp);
        $dir = rtrim($this->output, '/\\') . DIRECTORY_SEPARATOR . $base;
        if (is_dir($dir)) FileHelper::removeDirectory($dir);
        FileHelper::createDirectory($dir);
        return $dir;
    }

    /**
     * По §2.1: пакет собирается в один zip без вложенных папок (CSV + mapping.json).
     */
    private function zipPackage(string $pkgDir): string
    {
        // Внутренний архив (1 уровень) — корневые файлы пакета
        $innerName = 'CSV_' . basename($pkgDir) . '.zip';
        $innerPath = dirname($pkgDir) . DIRECTORY_SEPARATOR . $innerName;

        $zip = new \ZipArchive();
        if ($zip->open($innerPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Не удалось создать {$innerPath}");
        }
        foreach (new \DirectoryIterator($pkgDir) as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getPathname(), $f->getFilename());
            }
        }
        $zip->close();

        return $innerPath;
    }

    // =====================================================================
    // УТИЛИТЫ
    // =====================================================================

    private function cellString(Worksheet $sheet, string $col, int $row): string
    {
        $v = $sheet->getCell($col . $row)->getValue();
        if ($v === null) return '';
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
        return trim((string)$v);
    }

    private function info(string $msg): void   { $this->stdout($msg . "\n"); }
    private function warn(string $msg): void   { $this->stdout($msg . "\n", Console::FG_YELLOW); }
    private function success(string $msg): void{ $this->stdout($msg . "\n", Console::FG_GREEN); }
}