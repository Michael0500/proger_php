Для решения задачи объединения нескольких Word-документов в один PDF-файл с использованием PHP и Yii2, можно воспользоваться следующими шагами:

---

### **1. Установка необходимых библиотек**
Для работы с Word-документами и PDF-файлами потребуются сторонние библиотеки. Мы будем использовать:
- **PHPWord** для чтения и обработки Word-документов.
- **TCPDF** или **Dompdf** для генерации PDF-файлов.

Установите эти библиотеки через Composer:
```bash
composer require phpoffice/phpword
composer require dompdf/dompdf
```

---

### **2. Подготовка проекта**
Создайте в контроллере Yii2 метод, который будет выполнять всю логику. Например, создадим метод `actionMergeToPdf` в контроллере `DocumentController`.

---

### **3. Реализация логики**

#### **Код контроллера**
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

        // Выводим PDF в браузер
        $output = $dompdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="merged_documents.pdf"');
        echo $output;
        exit;
    }
}
```

---

### **4. Объяснение кода**

1. **Чтение Word-документов**:
    - Используем `glob` для получения списка всех файлов `.docx` из указанной директории.
    - Каждый документ загружается с помощью `PhpOffice\PhpWord\IOFactory`.

2. **Преобразование в HTML**:
    - Каждый Word-документ преобразуется в HTML с помощью `PhpWord` и сохраняется в переменную `$htmlContent`.
    - После каждого документа добавляется CSS-правило `<div style="page-break-after: always;"></div>`, чтобы каждый документ начинался с новой страницы в PDF.

3. **Генерация PDF**:
    - Используем библиотеку `Dompdf` для создания PDF-файла из HTML-контента.
    - Устанавливаем формат страницы (например, A4) и ориентацию (портретная).
    - Результат выводится в браузер как PDF-файл.

---

### **5. Дополнительные настройки**

- **Проверка прав доступа**: Убедитесь, что папка с Word-документами доступна для чтения.
- **Оптимизация производительности**: Если документов много, рассмотрите возможность асинхронной обработки или разбиения задачи на части.
- **Обработка ошибок**: Добавьте обработку ошибок (например, если файл поврежден или не является `.docx`).

---

### **6. Пример структуры проекта**

```
your-yii2-project/
├── controllers/
│   └── DocumentController.php
├── uploads/
│   └── word_files/
│       ├── file1.docx
│       ├── file2.docx
│       └── file3.docx
├── vendor/
└── composer.json
```

---

### **7. Тестирование**
1. Разместите несколько `.docx` файлов в папке `uploads/word_files/`.
2. Откройте URL `/document/merge-to-pdf` в браузере.
3. Убедитесь, что PDF-файл содержит все документы, каждый на отдельной странице.

---

### **Итог**
Этот подход позволяет автоматически объединить несколько Word-документов в один PDF-файл с использованием PHP и Yii2. Он прост в реализации и масштабируем для обработки большого количества документов.

**Ответ:** Код выше успешно решает задачу объединения Word-документов в один PDF-файл.