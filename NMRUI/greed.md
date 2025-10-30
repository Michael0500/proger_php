Если список шаблонов приходит из внешнего **API-сервиса**, а не из локальной модели Yii2, то вам нужно:

1. Получить данные от API.
2. Сгруппировать их по имени шаблона.
3. Для каждого шаблона определить **последнюю версию** (по дате `created_at`).
4. Подготовить массив только с последними версиями — именно он пойдёт в `GridView`.
5. Сохранить полную историю (все версии по имени), чтобы использовать её в `detail`-части раскрывающейся строки.

---

### ✅ Пример: подготовка данных для `GridView` (без модели Yii2)

Предположим, API возвращает такой JSON:

```json
[
  {"name": "Шаблон А", "version": "1.0", "created_at": "2025-01-10T10:00:00Z", "status": "active"},
  {"name": "Шаблон А", "version": "1.1", "created_at": "2025-02-15T14:30:00Z", "status": "active"},
  {"name": "Шаблон Б", "version": "2.0", "created_at": "2025-03-01T09:15:00Z", "status": "draft"}
]
```

---

### 📦 PHP-код в контроллере

```php
use yii\data\ArrayDataProvider;
use DateTime;

public function actionIndex()
{
    // 1. Получаем данные из API (пример через file_get_contents или Guzzle)
    $apiUrl = 'https://your-api.com/templates';
    $jsonData = file_get_contents($apiUrl);
    $allTemplates = json_decode($jsonData, true);

    // 2. Преобразуем created_at в timestamp для сравнения (если нужно)
    foreach ($allTemplates as &$item) {
        $item['created_at_ts'] = (new DateTime($item['created_at']))->getTimestamp();
    }
    unset($item);

    // 3. Группируем по имени
    $grouped = [];
    foreach ($allTemplates as $template) {
        $name = $template['name'];
        if (!isset($grouped[$name])) {
            $grouped[$name] = [];
        }
        $grouped[$name][] = $template;
    }

    // 4. Для каждой группы находим последнюю версию (по created_at_ts)
    $latestVersions = [];
    $fullHistory = []; // понадобится позже для detail-блока

    foreach ($grouped as $name => $versions) {
        // Сортируем по дате по убыванию
        usort($versions, fn($a, $b) => $b['created_at_ts'] <=> $a['created_at_ts']);
        
        $latest = $versions[0]; // самая свежая
        $latestVersions[] = $latest;
        
        // Сохраняем всю историю (включая последнюю — потом уберём при отображении)
        $fullHistory[$name] = $versions;
    }

    // 5. Передаём в представление
    $dataProvider = new ArrayDataProvider([
        'allModels' => $latestVersions,
        'pagination' => false,
    ]);

    return $this->render('index', [
        'dataProvider' => $dataProvider,
        'fullHistory' => $fullHistory, // нужно для раскрывающихся строк
    ]);
}
```

---

### 🖼️ В представлении (`views/template/index.php`)

Используем `kartik\grid\GridView` как раньше, но берём историю из `$fullHistory`:

```php
use kartik\grid\GridView;
use yii\helpers\Html;

echo GridView::widget([
    'dataProvider' => $dataProvider,
    'columns' => [
        [
            'class' => 'kartik\grid\ExpandRowColumn',
            'width' => '30px',
            'value' => function () {
                return GridView::ROW_COLLAPSED;
            },
            'detail' => function ($model, $key, $index, $column) use ($fullHistory) {
                $name = $model['name'];
                $versions = $fullHistory[$name] ?? [];
                
                // Убираем первую запись — это и есть текущая строка
                array_shift($versions);

                if (empty($versions)) {
                    return '<div class="alert alert-info">Нет предыдущих версий.</div>';
                }

                $rows = [];
                foreach ($versions as $ver) {
                    $rows[] = Html::tag('tr', implode('', [
                        Html::tag('td', Html::encode($ver['version'])),
                        Html::tag('td', Yii::$app->formatter->asDatetime($ver['created_at'])),
                        Html::tag('td', Html::encode($ver['status'])),
                    ]));
                }

                return Html::tag('div',
                    Html::tag('table',
                        Html::tag('thead',
                            Html::tag('tr', implode('', [
                                Html::tag('th', 'Версия'),
                                Html::tag('th', 'Дата создания'),
                                Html::tag('th', 'Статус'),
                            ]))
                        ) .
                        Html::tag('tbody', implode('', $rows)),
                        ['class' => 'table table-sm table-bordered']
                    ),
                    ['style' => 'margin-top:10px;']
                );
            },
        ],
        'name',
        'version',
        [
            'attribute' => 'created_at',
            'format' => 'datetime',
        ],
        'status',
    ],
    'responsive' => true,
    'hover' => true,
]);
```

---

### 💡 Важно:

- Все данные — из API, **никакой ActiveRecord** не используется.
- `$fullHistory` передаётся в view через `use` в анонимной функции.
- Формат даты в API должен быть понятен `DateTime` (ISO 8601 — идеален).
- Если API возвращает много данных, можно кэшировать ответ (`Yii::$app->cache`).

---

Готово! Теперь ваш `GridView` показывает только последние версии, а при клике на стрелку — историю из API.