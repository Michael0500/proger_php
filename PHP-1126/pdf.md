Чтобы установить **PdfMerger** (упрощенную обертку над FPDI), выполните следующие шаги:

---

### 1. Установка через Composer
Откройте терминал в корне вашего проекта и выполните команду:
```bash
composer require pdf-merger/pdf-merger
```

Это установит:
- **PdfMerger** (основная библиотека)
- **FPDI** (для работы с PDF)
- **TCPDF** (для генерации PDF)

---

### 2. Проверьте зависимости
Убедитесь, что в вашем `composer.json` добавлены необходимые пакеты:
```json
{
    "require": {
        "pdf-merger/pdf-merger": "^1.0",
        "setasign/fpdi": "^2.3",
        "tecnickcom/tcpdf": "^6.4"
    }
}
```

---

### 3. Пример использования
```php
<?php
// Подключаем автозагрузчик Composer
require_once 'vendor/autoload.php';

use PdfMerger\PdfMerger;

// Создаем объект PdfMerger
$pdfMerger = new PdfMerger();

// Добавляем PDF-файлы
$pdfMerger->addPDF('file1.pdf'); // Первый файл
$pdfMerger->addPDF('file2.pdf'); // Второй файл

// Объединяем и сохраняем результат
$pdfMerger->merge();
$pdfMerger->save('merged.pdf', 'F'); // 'F' — сохранить в файл
?>
```

---

### 4. Если возникли проблемы
- **Ошибка класса не найден**: Убедитесь, что подключили `vendor/autoload.php`.
- **Конфликты версий**: Если в проекте уже есть FPDI/TCPDF, обновите их через `composer update`.
- **Ручная установка**: Если Composer недоступен, [скачайте библиотеку](https://github.com/clegginabox/pdf-merger) и подключите вручную:
  ```php
  require_once 'path/to/PdfMerger.php';
  ```

---

### Альтернатива
Если PdfMerger не подходит, используйте **FPDI напрямую** (как в первом ответе).

Для объединения PDF-документов на PHP без искажения стилей можно использовать библиотеку **FPDI** (в сочетании с **TCPDF** или **FPDF**). FPDI позволяет импортировать существующие PDF-страницы и объединять их, сохраняя исходное форматирование. Вот пример:

### 1. Установка библиотек
Установите FPDI через Composer:
```bash
composer require setasign/fpdi
```

### 2. Пример кода
```php
<?php
require_once 'vendor/autoload.php';

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfReader;

// Создаем объект FPDI
$pdf = new Fpdi();

// Добавляем страницы из первого PDF
$pdf->setSourceFile('file1.pdf');
$pageCount = $pdf->setSourceFile('file1.pdf');
for ($i = 1; $i <= $pageCount; $i++) {
    $templateId = $pdf->importPage($i);
    $pdf->AddPage();
    $pdf->useTemplate($templateId);
}

// Добавляем страницы из второго PDF
$pdf->setSourceFile('file2.pdf');
$pageCount = $pdf->setSourceFile('file2.pdf');
for ($i = 1; $i <= $pageCount; $i++) {
    $templateId = $pdf->importPage($i);
    $pdf->AddPage();
    $pdf->useTemplate($templateId);
}

// Сохраняем итоговый PDF
$pdf->Output('merged.pdf', 'F');
?>
```

### 3. Важные замечания
- **Сохранение стилей**: FPDI импортирует страницы как векторные объекты, поэтому стили (шрифты, цвета, изображения) сохраняются без искажений.
- **Ограничения**:
    - Не поддерживаются PDF с шифрованием (если документ защищен паролем).
    - Некоторые сложные элементы (например, формы или скрипты) могут не переноситься корректно.
- **Альтернативы**:
    - Используйте **PdfMerger** (упрощенная обертка над FPDI).
    - Для сложных случаев — внешние утилиты (например, `pdftk` через `exec()`).

### 4. Пример с PdfMerger
```php
<?php
require_once 'vendor/autoload.php';

use PdfMerger\PdfMerger;

$merger = new PdfMerger();
$merger->addPDF('file1.pdf');
$merger->addPDF('file2.pdf');
$merger->merge();
$merger->save('merged.pdf');
?>
```

Если стили все же искажаются, проверьте:
1. Встроены ли шрифты в исходные PDF.
2. Нет ли конфликтов версий PDF (например, PDF/A).
3. Корректность самих исходных файлов.