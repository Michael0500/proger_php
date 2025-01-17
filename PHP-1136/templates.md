Ниже приведены примерные коды недостающих файлов представлений (**create.php**, **update.php**, **_form.php**, **view.php**) для каждого из модулей: **Template**, **Channel** и, где уместно, **Report**.

> Все файлы помещаются в соответствующие папки:
> - `views/template/` для модуля шаблонов уведомлений
> - `views/channel/` для модуля каналов
> - `views/report/` для модуля отчётности

---
## 1. Модуль шаблонов уведомлений (Template)

### 1.1. `views/template/create.php`
```php
<?php
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Template $model */

$this->title = 'Создать новый шаблон уведомлений';
$this->params['breadcrumbs'][] = ['label' => 'Шаблоны уведомлений', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="template-create">
    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', ['model' => $model]) ?>
</div>
```

### 1.2. `views/template/update.php`
```php
<?php
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Template $model */

$this->title = 'Редактировать шаблон: ' . $model->template_name;
$this->params['breadcrumbs'][] = ['label' => 'Шаблоны уведомлений', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->template_name, 'url' => ['view', 'id' => $model->template_id]];
$this->params['breadcrumbs'][] = 'Редактировать';
?>
<div class="template-update">
    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', ['model' => $model]) ?>
</div>
```

### 1.3. `views/template/_form.php`
```php
<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var app\models\Template $model */
/** @var yii\widgets\ActiveForm $form */
?>

<div class="template-form">
    <?php $form = ActiveForm::begin(); ?>

    <?= $form->field($model, 'template_name')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'contract_id')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'client_id')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'product')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'client_segment')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'creation_date')->textInput(['placeholder' => 'гггг-мм-дд']) ?>
    <?= $form->field($model, 'status')->dropDownList([
        'Active' => 'Active',
        'Pending' => 'Pending',
        'Error'   => 'Error',
        'Stopped' => 'Stopped',
    ], ['prompt' => 'Выберите статус']) ?>
    <?= $form->field($model, 'send_date')->textInput(['placeholder' => 'гггг-мм-дд']) ?>
    <?= $form->field($model, 'comment')->textarea(['rows' => 3]) ?>

    <div class="form-group">
        <?= Html::submitButton('Сохранить', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Отмена', ['index'], ['class' => 'btn btn-default']) ?>
    </div>

    <?php ActiveForm::end(); ?>
</div>
```

### 1.4. `views/template/view.php`
```php
<?php
use yii\helpers\Html;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var app\models\Template $model */

$this->title = $model->template_name;
$this->params['breadcrumbs'][] = ['label' => 'Шаблоны уведомлений', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="template-view">
    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a('Редактировать', ['update', 'id' => $model->template_id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('К списку', ['index'], ['class' => 'btn btn-default']) ?>
    </p>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            'template_id',
            'template_name',
            'contract_id',
            'client_id',
            'product',
            'client_segment',
            'creation_date',
            'status',
            'send_date',
            'comment',
        ],
    ]) ?>
</div>
```

---

## 2. Модуль управления каналами (Channel)

### 2.1. `views/channel/create.php`
```php
<?php
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Channel $model */

$this->title = 'Создать новый канал';
$this->params['breadcrumbs'][] = ['label' => 'Управление каналами', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="channel-create">
    <h1><?= Html::encode($this->title) ?></h1>
    
    <?= $this->render('_form', ['model' => $model]) ?>
</div>
```

### 2.2. `views/channel/update.php`
```php
<?php
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Channel $model */

$this->title = 'Редактировать канал: ' . $model->channel_name;
$this->params['breadcrumbs'][] = ['label' => 'Управление каналами', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->channel_name, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Редактировать';
?>
<div class="channel-update">
    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', ['model' => $model]) ?>
</div>
```

### 2.3. `views/channel/_form.php`
```php
<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var app\models\Channel $model */
/** @var yii\widgets\ActiveForm $form */
?>

<div class="channel-form">
    <?php $form = ActiveForm::begin(); ?>

    <?= $form->field($model, 'channel_name')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>
    <?= $form->field($model, 'status')->dropDownList([
        'Active'       => 'Active',
        'Stopped'      => 'Stopped',
        'Maintenance'  => 'Maintenance',
    ], ['prompt' => 'Выберите статус']) ?>
    <?= $form->field($model, 'max_length')->textInput(['type' => 'number']) ?>
    <?= $form->field($model, 'time_window')->textInput(['maxlength' => true]) ?>

    <div class="form-group">
        <?= Html::submitButton('Сохранить', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Отмена', ['index'], ['class' => 'btn btn-default']) ?>
    </div>

    <?php ActiveForm::end(); ?>
</div>
```

### 2.4. `views/channel/view.php`
```php
<?php
use yii\helpers\Html;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var app\models\Channel $model */

$this->title = $model->channel_name;
$this->params['breadcrumbs'][] = ['label' => 'Управление каналами', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="channel-view">
    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a('Редактировать', ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('К списку', ['index'], ['class' => 'btn btn-default']) ?>
        
        <?php if ($model->status !== 'Stopped'): ?>
            <?= Html::a('Остановить', ['stop', 'id' => $model->id], [
                'class' => 'btn btn-warning',
                'data' => [
                    'confirm' => 'Приостановить канал?',
                    'method' => 'post',
                ],
            ]) ?>
        <?php else: ?>
            <?= Html::a('Продолжить', ['resume', 'id' => $model->id], [
                'class' => 'btn btn-success',
                'data' => [
                    'confirm' => 'Возобновить канал?',
                    'method'  => 'post',
                ],
            ]) ?>
        <?php endif; ?>
    </p>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            'id',
            'channel_name',
            'description',
            'status',
            'max_length',
            'time_window',
        ],
    ]) ?>
</div>
```

---

## 3. Модуль отчётности (Report)

### 3.1. (Повторно) `views/report/index.php`
Мы уже показывали пример, но приведём здесь ещё раз для удобства. Этот файл выводит основной список с фильтром.

```php
<?php
use yii\helpers\Html;
use yii\grid\GridView;

/** @var yii\web\View $this */
/** @var app\models\ReportSearch $searchModel */
/** @var yii\data\ArrayDataProvider $dataProvider */

$this->title = 'Модуль отчётности';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="report-index">
    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a('Сформировать оперативный отчёт', ['operational'], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Сформировать исторический отчёт', ['historical'], ['class' => 'btn btn-warning']) ?>
        <?= Html::a('Выгрузить', ['export'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider'=>$dataProvider,
        'filterModel'=>$searchModel,
        'columns'=>[
            'template_id',
            'template_name',
            'client_id',
            'status',
            'channel',
            'mobile_phone',
            'email',
            'planned_send_date',
            'actual_send_date',
        ],
    ]) ?>
</div>
```

### 3.2. `views/report/operational.php`
Файл для «оперативного отчёта» (без пагинации, например):

```php
<?php
use yii\helpers\Html;
use yii\grid\GridView;

/** @var yii\web\View $this */
/** @var app\models\ReportSearch $searchModel */
/** @var yii\data\ArrayDataProvider $dataProvider */

$this->title = 'Оперативный отчёт';
$this->params['breadcrumbs'][] = ['label' => 'Модуль отчётности', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="report-operational">
    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a('Вернуться к списку отчётов', ['index'], ['class' => 'btn btn-default']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        // Здесь нет filterModel, если не нужно
        'columns' => [
            'template_id',
            'template_name',
            'client_id',
            'status',
            'channel',
            'mobile_phone',
            'email',
            'planned_send_date',
            'actual_send_date',
        ],
    ]) ?>
</div>
```

### 3.3. Дополнительные представления для «исторического отчёта» и т. п.

В примере выше `actionHistorical()` сразу отдает CSV-файл, так что отдельного `historical.php` может не быть. Но если нужна страница перед выгрузкой, можно сделать:

```php
<?php
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\ReportSearch $searchModel */
/** @var yii\data\ArrayDataProvider $dataProvider */

$this->title = 'Исторический отчёт';
$this->params['breadcrumbs'][] = ['label' => 'Модуль отчётности', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="report-historical">
    <h1><?= Html::encode($this->title) ?></h1>
    <p>
        Здесь можно вывести какую-то информацию о формировании исторического отчёта 
        и, например, кнопку «Выгрузить в CSV/XLSX».
    </p>
    <?= Html::a('Выгрузить в CSV', ['historical'], ['class' => 'btn btn-warning']) ?>
</div>
```

Однако это — необязательный файл, если логика выгрузки сразу происходит без отображения промежуточной страницы.

---

### Итого

Сгенерированные (или показанные) **недостающие файлы представлений** включают:

- **Для модуля шаблонов (Template)**:
    - `create.php`
    - `update.php`
    - `_form.php`
    - `view.php`
- **Для модуля каналов (Channel)**:
    - `create.php`
    - `update.php`
    - `_form.php`
    - `view.php`
- **Для модуля отчётности (Report)**:
    - `operational.php` (пример)
    - (опционально) `historical.php`, если нужно

Теперь в каждой из трёх папок (`views/template/`, `views/channel/`, `views/report/`) у вас есть **index** и все CRUD-шаблоны, необходимые для базовой работы.