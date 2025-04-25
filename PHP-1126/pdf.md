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