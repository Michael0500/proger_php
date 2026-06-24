<?php

namespace app\controllers;

use app\models\BusinessObjectBook;
use app\models\BusinessObjectsUploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use phpread\Spout\Reader\Common\Creator\ReaderEntityFactory;
use phpread\Spout\Writer\Common\Creator\WriterEntityFactory;
use Yii;
use yii\base\BaseObject;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\UploadedFile;

class BusinessObjectsController extends Controller
{
    public $book_list = [];

    public function actionIndex()
    {
        $model = new BusinessObjectsUploadedFile();

        if (Yii::$app->request->isPost) {
            $model->file2914 = UploadedFile::getInstance($model, 'file2914');
            $model->file2343 = UploadedFile::getInstance($model, 'file2343');

            if ($model->validate()) {
                $tempPath2914 = Yii::getAlias('@runtime/' . $model->file2914->baseName . '.' . $model->file2914->extension);
                $tempPath2343 = Yii::getAlias('@runtime/' . $model->file2343->baseName . '.' . $model->file2343->extension);

                $model->file2914->saveAs($tempPath2914);
                $model->file2343->saveAs($tempPath2343);

                return $this->redirect([
                    'process',
                    'path2914' => basename($tempPath2914),
                    'path2343' => basename($tempPath2343),
                ]);
            }
        }

        return $this->render('index', [
            'model' => $model,
        ]);
    }

    public function checkYur($text)
    {
        $check = false;

        foreach ($this->book_list as $item) {
            $pattern = '/(?<!\w)' . preg_quote($item, '/') . '(?!\w)/iu';

            if ((bool)preg_match($pattern, $text) == false) {
                $check = true;
                break;
            }
        }

        if ($check === false) {
            $swiftRegex = '/\b[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?\b/';
            $check = preg_match($swiftRegex, $text) === 1;
        }

        return $check;
    }

    public function actionProcess($path2914, $path2343)
    {
        set_time_limit(0);
        ini_set('memory_limit', '4256M');

        $this->book_list = BusinessObjectBook::find()->select(['name'])->asArray()->column();

        $fullPath2914 = Yii::getAlias('@runtime/' . $path2914);
        $fullPath2343 = Yii::getAlias('@runtime/' . $path2343);

        if (!file_exists($fullPath2914) || !file_exists($fullPath2343)) {
            throw new \yii\web\NotFoundHttpException('Файлы не найдены');
        }

        $data2914 = $this->readReport2914($fullPath2914);
        $data2343 = $this->readReport2343($fullPath2343);

        $updated2914 = $this->processReports($data2914, $data2343);

        return $this->writeUpdated2914($updated2914, $fullPath2914);

        // return Yii::$app->response->sendFile($outputFile, 'report_2914_result.xlsx');
    }

    private function readReport2914($filePath)
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($filePath);

        $rowsData = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            if (!$sheet->isActive()) {
                continue;
            }

            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $cells = $row->toArray();

                $flagYur = 0;
                $isEmpty = false;

                if (empty($cells[8] ?? '') && empty($cells[9] ?? '')) {
                    $isEmpty = true;
                    $flagYur = 1;
                } elseif ($this->checkYur($cells[8] ?? '') || $this->checkYur($cells[9] ?? '')) {
                    $flagYur = 1;
                }

                if ($isEmpty === true || $this->checkYur($cells[9] ?? '') || !$this->checkYur($cells[8] ?? '')) {
                    $rowsData[] = [
                        'date' => (($cells[1] ?? '') instanceof \DateTime)
                            ? $cells[1]->format('d.m.Y')
                            : ($cells[1] ?? ''),
                        'debit' => number_format((float)($cells[13] ?? null), 2, '.', ''),
                        'credit' => number_format((float)($cells[14] ?? null), 2, '.', ''),
                        'flagYur' => $flagYur,
                        'purpose_of_payment' => $this->trimPurposeOfPayment($cells[15] ?? ''),
                    ];
                }
            }

            break;
        }

        $reader->close();

        return $rowsData;
    }

    private function trimPurposeOfPayment($name)
    {
        $pos = stripos($name, 'Банк контрагента');

        if ($pos === false) {
            $substring = $name;
        } else {
            $substring = substr($name, 0, $pos);
        }

        $name = preg_replace('/\s+/', ' ', $substring);

        return base64_encode($name);
    }

    private function readReport2343($filePath)
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($filePath);

        $rowsData = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            if (!$sheet->isActive()) {
                continue;
            }

            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $cells = $row->toArray();

                $rowsData[] = [
                    'date' => (($cells[1] ?? '') instanceof \DateTime)
                        ? $cells[1]->format('d.m.Y')
                        : ($cells[1] ?? ''),
                    'debit' => number_format((float)($cells[13] ?? null), 2, '.', ''),
                    'credit' => number_format((float)($cells[12] ?? null), 2, '.', ''),
                    'recv_name' => $cells[9] ?? null,
                    'recv_bank' => $cells[7] ?? null,
                    'recv_bik' => $cells[8] ?? null,
                    'payer_name' => $cells[4] ?? null,
                    'payer_bank' => $cells[2] ?? null,
                    'payer_bik' => $cells[3] ?? null,
                    'purpose_of_payment' => $this->trimPurposeOfPayment($cells[14] ?? ''),
                ];
            }

            break;
        }

        $reader->close();

        return $rowsData;
    }

    private function processReports($data2914, $data2343)
    {
        $returnData = [];

        // Группируем строки 2343 по «Дата + Назначение платежа» (без суммы),
        // т.к. одной строке 2914 может соответствовать несколько строк 2343
        // с одинаковым назначением, суммы которых вместе дают сумму 2914.
        // Строки в 2343 могут идти не подряд (вразброс), поэтому собираем их в группы.
        $groups2343 = [];

        foreach ($data2343 as $row2343) {
            $groupKey = $this->makeGroupKey(
                $row2343['date'],
                $row2343['purpose_of_payment']
            );

            $groups2343[$groupKey][] = $row2343;
        }

        // Помечаем уже использованные строки 2343, чтобы одну и ту же строку
        // не учитывать в нескольких совпадениях.
        $usedFlags = [];
        foreach ($groups2343 as $groupKey => $rows) {
            $usedFlags[$groupKey] = array_fill(0, count($rows), false);
        }

        foreach ($data2914 as $i => $row2914) {
            $groupKey = $this->makeGroupKey(
                $row2914['date'],
                $row2914['purpose_of_payment']
            );

            if (!isset($groups2343[$groupKey])) {
                continue;
            }

            $rows = $groups2343[$groupKey];

            $isDebit = (float)$row2914['debit'] > 0;
            $isCredit = (float)$row2914['credit'] > 0;

            if ($isDebit) {
                // Подбираем подмножество строк 2343, у которых сумма «По дебету»
                // в совокупности равна сумме дебета строки 2914.
                $target = (float)$row2914['debit'];
                $matched = $this->findSubset($rows, $usedFlags[$groupKey], 'debit', $target);

                if ($matched !== null) {
                    $source = $rows[$matched[0]];

                    $key = $this->makeKey(
                        $row2914['date'],
                        $target,
                        (float)$row2914['credit'],
                        $row2914['purpose_of_payment']
                    );

                    $data2914[$i]['key'] = $key;
                    $data2914[$i]['fio_or_org'] = $source['recv_name'];
                    $data2914[$i]['bank_name'] = $source['recv_bank'];
                    $data2914[$i]['bik'] = $source['recv_bik'];

                    $returnData[$key] = $data2914[$i];

                    foreach ($matched as $idx) {
                        $usedFlags[$groupKey][$idx] = true;
                    }
                }
            } elseif ($isCredit) {
                // Подбираем подмножество строк 2343, у которых сумма «По кредиту»
                // в совокупности равна сумме кредита строки 2914.
                $target = (float)$row2914['credit'];
                $matched = $this->findSubset($rows, $usedFlags[$groupKey], 'credit', $target);

                if ($matched !== null) {
                    $source = $rows[$matched[0]];

                    $key = $this->makeKey(
                        $row2914['date'],
                        (float)$row2914['debit'],
                        $target,
                        $row2914['purpose_of_payment']
                    );

                    $data2914[$i]['key'] = $key;
                    $data2914[$i]['fio_or_org'] = $source['payer_name'];
                    $data2914[$i]['bank_name'] = $source['payer_bank'];
                    $data2914[$i]['bik'] = $source['payer_bik'];

                    $returnData[$key] = $data2914[$i];

                    foreach ($matched as $idx) {
                        $usedFlags[$groupKey][$idx] = true;
                    }
                }
            }
        }

        return $returnData;
    }

    /**
     * Ищет подмножество ещё не использованных строк группы 2343,
     * сумма указанного поля ($field = 'debit' | 'credit') которых
     * с точностью до копейки равна $target.
     *
     * Сначала пробуем одиночное совпадение (быстрый и самый частый случай),
     * затем — комбинацию из нескольких строк (subset-sum).
     *
     * Возвращает массив индексов строк в группе либо null, если совпадения нет.
     */
    private function findSubset(array $rows, array $usedFlags, string $field, float $target)
    {
        $cents = (int)round($target * 100);

        // Доступные кандидаты: не использованы и значение нужного поля > 0.
        $candidates = [];
        foreach ($rows as $idx => $row) {
            if (!empty($usedFlags[$idx])) {
                continue;
            }

            $value = (int)round((float)$row[$field] * 100);

            if ($value <= 0) {
                continue;
            }

            $candidates[$idx] = $value;
        }

        // 1) Точное одиночное совпадение.
        foreach ($candidates as $idx => $value) {
            if ($value === $cents) {
                return [$idx];
            }
        }

        // 2) Комбинация из нескольких строк (subset-sum) методом
        //    динамического программирования по достижимым суммам.
        //    Ключ — накопленная сумма в копейках, значение — список индексов.
        if ($cents <= 0) {
            return null;
        }

        $reachable = [0 => []];

        foreach ($candidates as $idx => $value) {
            // Перебираем имеющиеся суммы в порядке убывания, чтобы каждый
            // элемент использовался не более одного раза.
            foreach (array_reverse(array_keys($reachable), true) as $sum) {
                $newSum = $sum + $value;

                if ($newSum > $cents) {
                    continue;
                }

                if (!isset($reachable[$newSum])) {
                    $reachable[$newSum] = array_merge($reachable[$sum], [$idx]);

                    if ($newSum === $cents) {
                        return $reachable[$newSum];
                    }
                }
            }
        }

        return isset($reachable[$cents]) ? $reachable[$cents] : null;
    }

    private function makeKey($date, float $debit, float $credit, $purposeOfPayment)
    {
        return sprintf('%s|%.2f|%.2f|%s', $date, $debit, $credit, $purposeOfPayment);
    }

    private function makeGroupKey($date, $purposeOfPayment)
    {
        return sprintf('%s|%s', $date, $purposeOfPayment);
    }

    private function writeUpdated2914($updatedData, $filePath)
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();

            foreach ($worksheet->getRowIterator() as $row) {
                $cells = [];
                $index = $row->getRowIndex();

                foreach ($row->getCellIterator() as $cell) {
                    $value = $cell->getValue();

                    if ($cell->getDataType() === DataType::TYPE_NUMERIC) {
                        if (Date::isDateTime($cell)) {
                            $value = Date::excelToDateTimeObject($value)->format('d.m.Y');
                        }
                    }

                    $cells[] = $value;
                }

                $debit = number_format((float)($cells[13] ?? null), 2, '.', '');
                $credit = number_format((float)($cells[14] ?? null), 2, '.', '');
                $purposeOfPayment = $this->trimPurposeOfPayment($cells[15] ?? '');
                $date = $cells[1] ?? '';

                $key = $this->makeKey($date, (float)$debit, (float)$credit, $purposeOfPayment);

                if (array_key_exists($key, $updatedData)) {
                    $flagYur = (int)$updatedData[$key]['flagYur'];

                    if ($flagYur === 1) {
                        $worksheet->setCellValue('I' . $index, $updatedData[$key]['fio_or_org']);
                        $worksheet->setCellValue('G' . $index, $updatedData[$key]['bank_name']);
                        $worksheet->setCellValue('H' . $index, $updatedData[$key]['bik']);
                        $worksheet->setCellValue('J' . $index, '');
                    } else {
                        $worksheet->setCellValue('J' . $index, $updatedData[$key]['fio_or_org']);
                        $worksheet->setCellValue('G' . $index, $updatedData[$key]['bank_name']);
                        $worksheet->setCellValue('H' . $index, $updatedData[$key]['bik']);
                        $worksheet->setCellValue('I' . $index, '');
                    }
                }
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

            ob_start();
            $writer->save('php://output');
            $excelOutput = ob_get_clean();

            return Yii::$app->response->sendContentAsFile(
                $excelOutput,
                'report_2914.xlsx',
                [
                    'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'inline' => false,
                ]
            );
        } catch (\Exception $e) {
            return $this->asJson([
                'status' => 'error',
                'message' => 'Произошла ошибка ' . $e->getMessage(),
            ]);
        }
    }
}