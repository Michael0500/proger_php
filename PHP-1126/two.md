Для сохранения PDF-файла на сервер вместо вывода его в браузер, нужно изменить код так, чтобы результат работы `Dompdf` сохранялся в файл. Вот как это можно сделать:

---

### **Обновленный код контроллера**

```php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Dompdf\Dompdf;
use Dompdf\Options;

class DocumentController extends Controller
{
    public function actionMergeToPdf()
    {
        // Путь к папке с Word-документами
        $wordFilesDir = Yii::getAlias('@app') . '/uploads/word_files/';
        
        // Получаем список всех Word-документов
        $wordFiles = glob($wordFilesDir . '*.docx');
        
        if (empty($wordFiles)) {
            return "Нет доступных Word-документов.";
        }

        // Создаем HTML-контент для PDF
        $htmlContent = '';

        foreach ($wordFiles as $wordFile) {
            // Загружаем Word-документ
            $phpWord = WordIOFactory::load($wordFile);

            // Преобразуем содержимое документа в HTML
            $htmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML');
            ob_start();
            $htmlWriter->save('php://output');
            $htmlContent .= ob_get_clean() . '<div style="page-break-after: always;"></div>';
        }

        // Генерируем PDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Сохраняем PDF на сервер
        $output = $dompdf->output();
        $pdfFilePath = Yii::getAlias('@app') . '/uploads/merged_documents.pdf';
        file_put_contents($pdfFilePath, $output);

        // Возвращаем путь к файлу или сообщение об успешном сохранении
        return "PDF успешно сохранен: {$pdfFilePath}";
    }
}
```

---

### **Объяснение изменений**

1. **Сохранение файла**:
    - Используется функция `file_put_contents`, чтобы записать содержимое PDF в файл.
    - Путь к файлу задается через `Yii::getAlias('@app')`, что гарантирует корректное разрешение пути к директории приложения.

2. **Отказ от вывода в браузер**:
    - Убраны заголовки `header('Content-Type: application/pdf')` и `header('Content-Disposition: ...')`.
    - Вместо этого возвращается сообщение об успешном сохранении файла.

3. **Путь к файлу**:
    - PDF сохраняется в папку `uploads/` с именем `merged_documents.pdf`. Вы можете изменить путь и имя файла по своему усмотрению.

---

### **Пример структуры проекта после изменений**

```
your-yii2-project/
├── controllers/
│   └── DocumentController.php
├── uploads/
│   ├── word_files/
│   │   ├── file1.docx
│   │   ├── file2.docx
│   │   └── file3.docx
│   └── merged_documents.pdf  <-- Сюда сохраняется результат
├── vendor/
└── composer.json
```

---

### **Тестирование**

1. Разместите несколько `.docx` файлов в папке `uploads/word_files/`.
2. Откройте URL `/document/merge-to-pdf` в браузере.
3. После выполнения действия проверьте, что файл `merged_documents.pdf` появился в папке `uploads/`.

---

### **Результат**

Теперь PDF-файл сохраняется на сервер в указанную директорию, а не выводится в браузер. Это полезно, если вам нужно автоматизировать процесс генерации PDF-файлов для последующего использования или отправки по email.

**Ответ:** Код выше успешно сохраняет объединенный PDF-файл на сервер.