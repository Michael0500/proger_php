Отлично! Тогда логика следующая:

1. При нажатии на кнопку с плюсом — открывается **модальное окно Bootstrap 4**.
2. В модальном окне — выпадающий список с типами каналов (`SMS`, `EMAIL`, `ENTER`) и кнопка "Добавить".
3. После выбора и подтверждения — модальное окно закрывается, и в основной форме появляется блок с:

    * Выпадающим списком "приоритет"
    * Выпадающим списком "признак отправки"
    * `textarea` с подсчетом символов

---

### 📁 **1. HTML: Основная форма и кнопка**

```php
<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$priorities = [1 => 'Высокий', 2 => 'Средний', 3 => 'Низкий'];
$flags = [0 => 'Не отправлять', 1 => 'Отправлять'];
$channelTypes = ['sms' => 'SMS', 'email' => 'EMAIL', 'enter' => 'ENTER'];
?>

<?php $form = ActiveForm::begin(); ?>

<div id="channel-fields-wrapper"></div>

<!-- Кнопка вызова модалки -->
<?= Html::button('+ Добавить канал', ['class' => 'btn btn-success', 'data-toggle' => 'modal', 'data-target' => '#channelModal']) ?>
<br><br>
<?= Html::submitButton('Сохранить', ['class' => 'btn btn-primary']) ?>

<?php ActiveForm::end(); ?>
```

---

### 🧱 **2. Bootstrap Modal**

```html
<!-- Bootstrap Modal -->
<div class="modal fade" id="channelModal" tabindex="-1" role="dialog" aria-labelledby="channelModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Выберите тип канала</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Закрыть">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <select id="channel-type-select" class="form-control">
          <option value="">-- Выберите --</option>
          <option value="sms">SMS</option>
          <option value="email">EMAIL</option>
          <option value="enter">ENTER</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" id="confirm-channel-add" class="btn btn-primary">Добавить</button>
      </div>
    </div>
  </div>
</div>
```

---

### 📜 **3. JS: добавление блока с учетом выбора**

```php
<?php
$this->registerJs(<<<JS
let channelIndex = 0;

function updateCharCounters() {
    $('.template-input').off('input').on('input', function() {
        const len = $(this).val().length;
        $(this).next('.char-counter').text('Символов: ' + len);
    });
}

$('#confirm-channel-add').on('click', function() {
    const selectedType = $('#channel-type-select').val();
    if (!selectedType) {
        alert('Пожалуйста, выберите тип канала.');
        return;
    }

    // Добавляем блок с полями
    const html = `
        <div class="channel-block mb-4 border rounded p-3">
            <strong>Тип канала: ${selectedType.toUpperCase()}</strong>
            <input type="hidden" name="Channel[\${channelIndex}][type]" value="\${selectedType}">
            <div class="form-group mt-2">
                <label>Приоритет:</label>
                <select name="Channel[\${channelIndex}][priority]" class="form-control">
                    <option value="1">Высокий</option>
                    <option value="2">Средний</option>
                    <option value="3">Низкий</option>
                </select>
            </div>
            <div class="form-group">
                <label>Признак отправки:</label>
                <select name="Channel[\${channelIndex}][flag]" class="form-control">
                    <option value="1">Отправлять</option>
                    <option value="0">Не отправлять</option>
                </select>
            </div>
            <div class="form-group">
                <label>Шаблон:</label>
                <textarea name="Channel[\${channelIndex}][template]" class="form-control template-input" rows="3"></textarea>
                <div class="char-counter">Символов: 0</div>
            </div>
        </div>
    `;

    $('#channel-fields-wrapper').append(html);
    updateCharCounters();
    channelIndex++;

    // Сброс модалки
    $('#channel-type-select').val('');
    $('#channelModal').modal('hide');
});

updateCharCounters();
JS);
?>
```

---

### ✅ Что работает:

* Модальное окно открывается по клику на кнопку.
* В модалке пользователь выбирает тип канала.
* После нажатия "Добавить" появляется соответствующий блок с полями.
* Работает подсчет символов для каждого textarea.
* Все данные отправляются как `Channel[0]`, `Channel[1]` и т.д.

Хочешь, добавлю удаление блоков или вывод превью шаблона?
