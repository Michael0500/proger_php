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
        $index2343 = [];
        $returnData = [];

        foreach ($data2343 as $row2343) {
            $key = $this->makeKey(
                $row2343['date'],
                (float)$row2343['debit'],
                (float)$row2343['credit'],
                $row2343['purpose_of_payment']
            );

            $index2343[$key] = $row2343;
        }

        foreach ($data2914 as $i => $row2914) {
            $key = $this->makeKey(
                $row2914['date'],
                (float)$row2914['debit'],
                (float)$row2914['credit'],
                $row2914['purpose_of_payment']
            );

            if (isset($index2343[$key])) {
                $row2343 = $index2343[$key];

                // Проверка логики
                $isDebit = (float)$row2914['debit'] > 0;
                $isCredit = (float)$row2914['credit'] > 0;

                if ($isDebit) {
                    $data2914[$i]['key'] = $key;
                    $data2914[$i]['fio_or_org'] = $row2343['recv_name'];
                    $data2914[$i]['bank_name'] = $row2343['recv_bank'];
                    $data2914[$i]['bik'] = $row2343['recv_bik'];

                    $returnData[$key] = $data2914[$i];
                } elseif ($isCredit) {
                    $data2914[$i]['key'] = $key;
                    $data2914[$i]['fio_or_org'] = $row2343['payer_name'];
                    $data2914[$i]['bank_name'] = $row2343['payer_bank'];
                    $data2914[$i]['bik'] = $row2343['payer_bik'];

                    $returnData[$key] = $data2914[$i];
                }
            }
        }

        return $returnData;
    }

    private function makeKey($date, float $debit, float $credit, $purposeOfPayment)
    {
        return sprintf('%s|%.2f|%.2f|%s', $date, $debit, $credit, $purposeOfPayment);
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