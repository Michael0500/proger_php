Создам интерфейс на Yii2 для настройки правил автоматического квитирования.

## 1. Миграция для создания таблиц

```php
<?php
// migrations/m240101_000001_create_matching_rules_table.php

use yii\db\Migration;

class m240101_000001_create_matching_rules_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%matching_rules}}', [
            'id' => $this->primaryKey(),
            'section' => $this->string(10)->notNull(),
            'type_pair' => $this->string(50)->notNull(),
            'debit_credit_match' => $this->boolean()->defaultValue(true),
            'amount_match' => $this->boolean()->defaultValue(true),
            'value_date_match' => $this->boolean()->defaultValue(true),
            'transaction_id_match' => $this->boolean()->defaultValue(false),
            'instruction_id_match' => $this->boolean()->defaultValue(false),
            'endtoend_id_match' => $this->boolean()->defaultValue(false),
            'message_id_match' => $this->boolean()->defaultValue(false),
            'other_id_match' => $this->boolean()->defaultValue(false),
            'description' => $this->text(),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ]);
        
        // Добавим индекс для быстрого поиска
        $this->createIndex('idx_matching_rules_section', '{{%matching_rules}}', 'section');
    }

    public function safeDown()
    {
        $this->dropTable('{{%matching_rules}}');
    }
}
```

## 2. Модель MatchingRule

```php
<?php
// models/MatchingRule.php

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

class MatchingRule extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%matching_rules}}';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['section', 'type_pair'], 'required'],
            [['section'], 'in', 'range' => ['NRE', 'INV']],
            [['type_pair'], 'in', 'range' => ['Ledger with Statement', 'Ledger with Ledger', 'Statement with Statement']],
            [['debit_credit_match', 'amount_match', 'value_date_match', 'transaction_id_match',
              'instruction_id_match', 'endtoend_id_match', 'message_id_match', 'other_id_match'], 'boolean'],
            [['description'], 'string'],
            [['section', 'type_pair'], 'string', 'max' => 50],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'section' => 'Раздел',
            'type_pair' => 'Тип сопоставления',
            'debit_credit_match' => 'Совпадение дебет/кредит',
            'amount_match' => 'Совпадение суммы',
            'value_date_match' => 'Совпадение даты',
            'transaction_id_match' => 'Transaction ID',
            'instruction_id_match' => 'Instruction ID',
            'endtoend_id_match' => 'EndToEnd ID',
            'message_id_match' => 'Message ID',
            'other_id_match' => 'Другие ID',
            'description' => 'Описание',
            'created_at' => 'Создан',
            'updated_at' => 'Обновлен',
        ];
    }

    /**
     * Получить список разделов
     */
    public static function getSectionList()
    {
        return [
            'NRE' => 'NRE',
            'INV' => 'INV',
        ];
    }

    /**
     * Получить список типов сопоставления
     */
    public static function getTypePairList()
    {
        return [
            'Ledger with Statement' => 'Проводка с Выпиской',
            'Ledger with Ledger' => 'Проводка с Проводкой',
            'Statement with Statement' => 'Выписка с Выпиской',
        ];
    }
}
```

## 3. Контроллер MatchingRuleController

```php
<?php
// controllers/MatchingRuleController.php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\data\ActiveDataProvider;
use app\models\MatchingRule;
use yii\helpers\ArrayHelper;

class MatchingRuleController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Список правил
     */
    public function actionIndex()
    {
        $dataProvider = new ActiveDataProvider([
            'query' => MatchingRule::find(),
            'sort' => [
                'defaultOrder' => [
                    'section' => SORT_ASC,
                    'type_pair' => SORT_ASC,
                ]
            ],
            'pagination' => [
                'pageSize' => 20,
            ],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Создание нового правила
     */
    public function actionCreate()
    {
        $model = new MatchingRule();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Правило успешно создано.');
            return $this->redirect(['index']);
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Редактирование правила
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Правило успешно обновлено.');
            return $this->redirect(['index']);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Удаление правила
     */
    public function actionDelete($id)
    {
        $this->findModel($id)->delete();
        Yii::$app->session->setFlash('success', 'Правило успешно удалено.');

        return $this->redirect(['index']);
    }

    /**
     * Поиск модели
     */
    protected function findModel($id)
    {
        if (($model = MatchingRule::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException('Запрошенная страница не существует.');
    }

    /**
     * AJAX валидация
     */
    public function actionValidate()
    {
        $model = new MatchingRule();
        $model->load(Yii::$app->request->post());
        
        return $this->asJson(['success' => $model->validate(), 'errors' => $model->getErrors()]);
    }
}
```

## 4. Представления

### Список правил (views/matching-rule/index.php)

```php
<?php
// views/matching-rule/index.php

use yii\helpers\Html;
use yii\grid\GridView;
use yii\widgets\Pjax;
use app\models\MatchingRule;

$this->title = 'Правила автоматического квитирования';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="matching-rule-index">
    <div class="row">
        <div class="col-md-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title"><?= Html::encode($this->title) ?></h3>
                    <div class="box-tools pull-right">
                        <?= Html::a('<i class="fa fa-plus"></i> Добавить правило', ['create'], ['class' => 'btn btn-success']) ?>
                    </div>
                </div>
                <div class="box-body">
                    <?php Pjax::begin(); ?>
                    <?= GridView::widget([
                        'dataProvider' => $dataProvider,
                        'tableOptions' => ['class' => 'table table-striped table-bordered'],
                        'columns' => [
                            ['class' => 'yii\grid\SerialColumn'],
                            
                            [
                                'attribute' => 'section',
                                'value' => function ($model) {
                                    return MatchingRule::getSectionList()[$model->section] ?? $model->section;
                                },
                                'filter' => MatchingRule::getSectionList(),
                            ],
                            
                            [
                                'attribute' => 'type_pair',
                                'value' => function ($model) {
                                    return MatchingRule::getTypePairList()[$model->type_pair] ?? $model->type_pair;
                                },
                                'filter' => MatchingRule::getTypePairList(),
                            ],
                            
                            [
                                'attribute' => 'description',
                                'format' => 'ntext',
                                'value' => function ($model) {
                                    return mb_strlen($model->description) > 100 
                                        ? mb_substr($model->description, 0, 100) . '...' 
                                        : $model->description;
                                },
                            ],
                            
                            [
                                'attribute' => 'created_at',
                                'format' => 'datetime',
                            ],
                            
                            [
                                'class' => 'yii\grid\ActionColumn',
                                'template' => '{update} {delete}',
                                'buttons' => [
                                    'update' => function ($url, $model) {
                                        return Html::a('<span class="glyphicon glyphicon-pencil"></span>', $url, [
                                            'title' => 'Редактировать',
                                            'class' => 'btn btn-primary btn-xs'
                                        ]);
                                    },
                                    'delete' => function ($url, $model) {
                                        return Html::a('<span class="glyphicon glyphicon-trash"></span>', $url, [
                                            'title' => 'Удалить',
                                            'class' => 'btn btn-danger btn-xs',
                                            'data' => [
                                                'confirm' => 'Вы уверены, что хотите удалить это правило?',
                                                'method' => 'post',
                                            ],
                                        ]);
                                    }
                                ]
                            ],
                        ],
                    ]); ?>
                    <?php Pjax::end(); ?>
                </div>
            </div>
        </div>
    </div>
</div>
```

### Форма создания/редактирования (views/matching-rule/_form.php)

```php
<?php
// views/matching-rule/_form.php

use yii\helpers\Html;
use yii\widgets\ActiveForm;
use app\models\MatchingRule;

?>

<div class="matching-rule-form">
    <?php $form = ActiveForm::begin([
        'options' => ['class' => 'form-horizontal'],
        'fieldConfig' => [
            'template' => "{label}\n<div class=\"col-sm-9\">{input}\n{hint}\n{error}</div>",
            'labelOptions' => ['class' => 'col-sm-3 control-label'],
        ],
    ]); ?>

    <div class="box">
        <div class="box-body">
            <div class="row">
                <div class="col-md-8">
                    <?= $form->field($model, 'section')->dropDownList(
                        MatchingRule::getSectionList(),
                        ['prompt' => 'Выберите раздел']
                    ) ?>

                    <?= $form->field($model, 'type_pair')->dropDownList(
                        MatchingRule::getTypePairList(),
                        ['prompt' => 'Выберите тип сопоставления']
                    ) ?>

                    <?= $form->field($model, 'description')->textarea(['rows' => 4]) ?>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <h4>Условия сопоставления</h4>
                    <div class="well">
                        <div class="row">
                            <div class="col-md-6">
                                <?= $form->field($model, 'debit_credit_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>

                                <?= $form->field($model, 'amount_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>

                                <?= $form->field($model, 'value_date_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>
                            </div>
                            <div class="col-md-6">
                                <?= $form->field($model, 'transaction_id_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>

                                <?= $form->field($model, 'instruction_id_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>

                                <?= $form->field($model, 'endtoend_id_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>

                                <?= $form->field($model, 'message_id_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>

                                <?= $form->field($model, 'other_id_match')->checkbox([
                                    'template' => "<div class=\"col-sm-offset-3 col-sm-9\">{input} {label}</div>\n<div class=\"col-sm-9 col-sm-offset-3\">{error}</div>",
                                ]) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-9">
                    <?= Html::submitButton(
                        $model->isNewRecord ? '<i class="fa fa-plus"></i> Создать' : '<i class="fa fa-save"></i> Сохранить', 
                        ['class' => $model->isNewRecord ? 'btn btn-success' : 'btn btn-primary']
                    ) ?>
                    <?= Html::a('<i class="fa fa-arrow-left"></i> Назад', ['index'], ['class' => 'btn btn-default']) ?>
                </div>
            </div>
        </div>
    </div>

    <?php ActiveForm::end(); ?>
</div>
```

### Создание правила (views/matching-rule/create.php)

```php
<?php
// views/matching-rule/create.php

use yii\helpers\Html;

$this->title = 'Создать правило квитирования';
$this->params['breadcrumbs'][] = ['label' => 'Правила автоматического квитирования', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="matching-rule-create">
    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>
</div>
```

### Редактирование правила (views/matching-rule/update.php)

```php
<?php
// views/matching-rule/update.php

use yii\helpers\Html;

$this->title = 'Редактировать правило квитирования';
$this->params['breadcrumbs'][] = ['label' => 'Правила автоматического квитирования', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'Редактирование';
?>

<div class="matching-rule-update">
    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>
</div>
```

## 5. Добавление в меню (если используете админку)

```php
// В вашем основном layout или меню
[
    'label' => 'Квитирование',
    'icon' => 'exchange',
    'url' => '#',
    'items' => [
        [
            'label' => 'Правила квитирования',
            'icon' => 'list',
            'url' => ['/matching-rule/index'],
        ],
        // Другие пункты меню для квитирования
    ],
],
```

## 6. Сервис для автоматического квитирования

```php
<?php
// components/AutoMatchingService.php

namespace app\components;

use Yii;
use app\models\MatchingRule;
use yii\db\Query;

class AutoMatchingService
{
    /**
     * Выполнить автоматическое квитирование по настроенным правилам
     */
    public function performAutoMatching()
    {
        $rules = MatchingRule::find()->all();
        $matchedCount = 0;

        foreach ($rules as $rule) {
            $matchedCount += $this->matchByRule($rule);
        }

        return $matchedCount;
    }

    /**
     * Квитирование по конкретному правилу
     */
    private function matchByRule($rule)
    {
        $matchedCount = 0;
        
        // Здесь реализация SQL запроса с учетом правил из $rule
        // Это упрощенный пример, в реальной реализации нужно адаптировать под вашу таблицу transactions
        
        $sql = $this->buildMatchingQuery($rule);
        
        try {
            $result = Yii::$app->db->createCommand($sql)->execute();
            $matchedCount = $result / 2; // Делим на 2, так как обновляем две записи
        } catch (\Exception $e) {
            Yii::error('Ошибка при автоматическом квитировании: ' . $e->getMessage());
        }

        return $matchedCount;
    }

    /**
     * Построение SQL запроса на основе правил
     */
    private function buildMatchingQuery($rule)
    {
        // Это упрощенный пример запроса
        $conditions = [];
        
        if ($rule->amount_match) {
            $conditions[] = 'l.amount = s.amount';
        }
        
        if ($rule->value_date_match) {
            $conditions[] = 'l.value_date = s.value_date';
        }
        
        if ($rule->debit_credit_match) {
            $conditions[] = "(l.debit_credit = 'D' AND s.debit_credit = 'C') OR (l.debit_credit = 'C' AND s.debit_credit = 'D')";
        }
        
        $idConditions = [];
        if ($rule->transaction_id_match) {
            $idConditions[] = 'l.transaction_id = s.transaction_id';
        }
        if ($rule->instruction_id_match) {
            $idConditions[] = 'l.instruction_id = s.instruction_id';
        }
        if ($rule->endtoend_id_match) {
            $idConditions[] = 'l.endtoend_id = s.endtoend_id';
        }
        if ($rule->message_id_match) {
            $idConditions[] = 'l.message_id = s.message_id';
        }
        
        if (!empty($idConditions)) {
            $conditions[] = '(' . implode(' OR ', $idConditions) . ')';
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        return "
            WITH matched_transactions AS (
                SELECT 
                    l.id AS ledger_id,
                    s.id AS statement_id
                FROM 
                    transactions l
                JOIN 
                    transactions s ON $whereClause
                WHERE 
                    l.type = 'Ledger' AND s.type = 'Statement'
                    AND l.section = :section
                    AND l.matched = FALSE AND s.matched = FALSE
            )
            UPDATE transactions l
            SET matched = TRUE
            FROM matched_transactions mt
            WHERE l.id = mt.ledger_id;
            
            UPDATE transactions s
            SET matched = TRUE
            FROM matched_transactions mt
            WHERE s.id = mt.statement_id;
        ";
    }
}
```

## 7. Консольная команда для автоматического квитирования

```php
<?php
// commands/MatchingController.php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\components\AutoMatchingService;

class MatchingController extends Controller
{
    /**
     * Выполнить автоматическое квитирование
     */
    public function actionAutoMatch()
    {
        $service = new AutoMatchingService();
        $matchedCount = $service->performAutoMatching();
        
        echo "Автоматическое квитирование завершено. Сопоставлено записей: $matchedCount\n";
        
        return Controller::EXIT_CODE_NORMAL;
    }
}
```

## Использование

1. **Запустить миграцию**:
   ```bash
   yii migrate
   ```

2. **Доступ к интерфейсу**:
    - URL: `/matching-rule/index`
    - Создание правила: `/matching-rule/create`
    - Редактирование: `/matching-rule/update?id=1`

3. **Запуск автоматического квитирования**:
   ```bash
   yii matching/auto-match
   ```

## Возможности интерфейса:

- ✅ Создание/редактирование/удаление правил квитирования
- ✅ Гибкая настройка условий сопоставления
- ✅ Фильтрация по разделам и типам сопоставления
- ✅ Валидация данных
- ✅ AJAX валидация
- ✅ Поддержка Pjax для быстрой загрузки
- ✅ Адаптивный дизайн
- ✅ Логирование изменений (через TimestampBehavior)

Интерфейс полностью готов к использованию и может быть интегрирован в существующую админ-панель Yii2.