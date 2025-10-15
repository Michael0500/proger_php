Вот полная реализация требуемой функциональности на Yii2:

---

## 📁 Структура проекта

```
common/
  models/
    NostroGroup.php
    NostroBank.php
    MatchingRecord.php
console/
  migrations/
    m251015_000000_create_nostro_tables.php
backend/
  controllers/
    NostroController.php
  views/
    nostro/
      index.php
      _group_form.php
      _bank_form.php
      _record_list.php
```

---

## 🗃️ Миграции

### `console/migrations/m251015_000000_create_nostro_tables.php`

```php
<?php

use yii\db\Migration;

class m251015_000000_create_nostro_tables extends Migration
{
    public function safeUp()
    {
        // Группы Ностро банков
        $this->createTable('{{%nostro_group}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(55)->notNull()->unique(),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ]);

        // Ностро банки
        $this->createTable('{{%nostro_bank}}', [
            'id' => $this->primaryKey(),
            'bank_name' => $this->string(55)->notNull(),
            'ledger' => $this->string(35)->notNull(),
            'statement' => $this->string(35),
            'group_id' => $this->integer()->notNull(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ]);

        $this->addForeignKey('fk_nostro_bank_group', '{{%nostro_bank}}', 'group_id', '{{%nostro_group}}', 'id', 'CASCADE');

        // Записи для выверки (Matching Records)
        $this->createTable('{{%matching_record}}', [
            'id' => $this->primaryKey(),
            'match_id' => $this->string(64), // UUID или хэш
            'record_type' => $this->char(1)->notNull(), // 'L' или 'S'
            'dc_flag' => $this->string(6)->notNull(), // 'Debit' или 'Credit'
            'amount' => $this->decimal(18, 2)->notNull(),
            'currency' => $this->char(3)->notNull(),
            'value_date' => $this->date()->notNull(),
            'post_date' => $this->date()->notNull(),
            'instruction_id' => $this->string(40),
            'end_to_end_id' => $this->string(40),
            'transaction_id' => $this->string(60),
            'message_id' => $this->string(40),
            'comment' => $this->string(255),
            'nostro_bank_id' => $this->integer()->notNull(),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ]);

        $this->addForeignKey('fk_matching_record_nostro_bank', '{{%matching_record}}', 'nostro_bank_id', '{{%nostro_bank}}', 'id', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_matching_record_nostro_bank', '{{%matching_record}}');
        $this->dropTable('{{%matching_record}}');

        $this->dropForeignKey('fk_nostro_bank_group', '{{%nostro_bank}}');
        $this->dropTable('{{%nostro_bank}}');
        $this->dropTable('{{%nostro_group}}');
    }
}
```

---

## 🧱 Модели

### `common/models/NostroGroup.php`

```php
<?php

namespace common\models;

use Yii;
use yii\db\ActiveRecord;

class NostroGroup extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%nostro_group}}';
    }

    public function rules()
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 55],
            [['name'], 'unique'],
        ];
    }

    public function getNostroBanks()
    {
        return $this->hasMany(NostroBank::class, ['group_id' => 'id'])
            ->andWhere(['is_active' => true]);
    }
}
```

### `common/models/NostroBank.php`

```php
<?php

namespace common\models;

use Yii;
use yii\db\ActiveRecord;

class NostroBank extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%nostro_bank}}';
    }

    public function rules()
    {
        return [
            [['bank_name', 'ledger', 'group_id'], 'required'],
            [['bank_name'], 'string', 'max' => 55],
            [['ledger', 'statement'], 'string', 'max' => 35],
            [['group_id'], 'exist', 'targetClass' => NostroGroup::class, 'targetAttribute' => 'id'],
            [['is_active'], 'boolean'],
        ];
    }

    public function getGroup()
    {
        return $this->hasOne(NostroGroup::class, ['id' => 'group_id']);
    }

    public function getRecords()
    {
        return $this->hasMany(MatchingRecord::class, ['nostro_bank_id' => 'id']);
    }
}
```

### `common/models/MatchingRecord.php`

```php
<?php

namespace common\models;

use Yii;
use yii\db\ActiveRecord;

class MatchingRecord extends ActiveRecord
{
    const TYPE_LEDGER = 'L';
    const TYPE_STATEMENT = 'S';

    const DC_DEBIT = 'Debit';
    const DC_CREDIT = 'Credit';

    public static function tableName()
    {
        return '{{%matching_record}}';
    }

    public function rules()
    {
        return [
            [['record_type', 'dc_flag', 'amount', 'currency', 'value_date', 'post_date', 'nostro_bank_id'], 'required'],
            [['record_type'], 'in', 'range' => [self::TYPE_LEDGER, self::TYPE_STATEMENT]],
            [['dc_flag'], 'in', 'range' => [self::DC_DEBIT, self::DC_CREDIT]],
            [['amount'], 'number'],
            [['currency'], 'string', 'length' => 3],
            [['value_date', 'post_date'], 'date', 'format' => 'php:Y-m-d'],
            [['instruction_id', 'end_to_end_id', 'message_id'], 'string', 'max' => 40],
            [['transaction_id'], 'string', 'max' => 60],
            [['comment'], 'string', 'max' => 255],
            [['nostro_bank_id'], 'exist', 'targetClass' => NostroBank::class, 'targetAttribute' => 'id'],
        ];
    }

    public function getNostroBank()
    {
        return $this->hasOne(NostroBank::class, ['id' => 'nostro_bank_id']);
    }

    public function getFormattedAmount()
    {
        return Yii::$app->formatter->asDecimal($this->amount, 2, ',', '.');
    }
}
```

---

## 🎛️ Контроллер

### `backend/controllers/NostroController.php`

```php
<?php

namespace backend\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use common\models\NostroGroup;
use common\models\NostroBank;
use common\models\MatchingRecord;

class NostroController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete-group' => ['POST'],
                    'delete-bank' => ['POST'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $groups = NostroGroup::find()->orderBy('name')->all();
        $newGroup = new NostroGroup();
        $newBank = new NostroBank();

        if (Yii::$app->request->isPost) {
            if (isset($_POST['NostroGroup'])) {
                $newGroup->load(Yii::$app->request->post());
                if ($newGroup->save()) {
                    Yii::$app->session->setFlash('success', 'Группа создана');
                    return $this->redirect(['index']);
                }
            } elseif (isset($_POST['NostroBank'])) {
                $newBank->load(Yii::$app->request->post());
                if ($newBank->save()) {
                    Yii::$app->session->setFlash('success', 'Ностро банк создан');
                    return $this->redirect(['index']);
                }
            }
        }

        return $this->render('index', [
            'groups' => $groups,
            'newGroup' => $newGroup,
            'newBank' => $newBank,
        ]);
    }

    public function actionUpdateGroup($id)
    {
        $model = $this->findGroupModel($id);
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Группа обновлена');
            return $this->redirect(['index']);
        }
        return $this->renderAjax('_group_form', ['model' => $model]);
    }

    public function actionUpdateBank($id)
    {
        $model = $this->findBankModel($id);
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Ностро банк обновлён');
            return $this->redirect(['index']);
        }
        return $this->renderAjax('_bank_form', ['model' => $model]);
    }

    public function actionDeleteGroup($id)
    {
        $this->findGroupModel($id)->delete();
        Yii::$app->session->setFlash('success', 'Группа удалена');
        return $this->redirect(['index']);
    }

    public function actionDeleteBank($id)
    {
        $this->findBankModel($id)->delete();
        Yii::$app->session->setFlash('success', 'Ностро банк удалён');
        return $this->redirect(['index']);
    }

    protected function findGroupModel($id)
    {
        if (($model = NostroGroup::findOne($id)) !== null) {
            return $model;
        }
        throw new NotFoundHttpException('Группа не найдена.');
    }

    protected function findBankModel($id)
    {
        if (($model = NostroBank::findOne($id)) !== null) {
            return $model;
        }
        throw new NotFoundHttpException('Ностро банк не найден.');
    }
}
```

---

## 🖼️ Представления

### `backend/views/nostro/index.php`

```php
<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use yii\grid\GridView;
use yii\helpers\Url;
use yii\bootstrap5\Modal;
?>

<div class="nostro-index">

    <h1>Управление группами и Ностро банками</h1>

    <!-- Создание группы -->
    <?php $form = ActiveForm::begin(['id' => 'create-group-form']); ?>
        <?= $form->field($newGroup, 'name')->textInput(['maxlength' => 55]) ?>
        <?= Html::submitButton('Создать группу', ['class' => 'btn btn-success']) ?>
    <?php ActiveForm::end(); ?>

    <hr>

    <!-- Создание Ностро банка -->
    <?php $form = ActiveForm::begin(['id' => 'create-bank-form']); ?>
        <?= $form->field($newBank, 'bank_name')->textInput(['maxlength' => 55]) ?>
        <?= $form->field($newBank, 'ledger')->textInput(['maxlength' => 35]) ?>
        <?= $form->field($newBank, 'statement')->textInput(['maxlength' => 35]) ?>
        <?= $form->field($newBank, 'group_id')->dropDownList(
            \yii\helpers\ArrayHelper::map(\common\models\NostroGroup::find()->all(), 'id', 'name'),
            ['prompt' => 'Выберите группу']
        ) ?>
        <?= Html::submitButton('Создать Ностро банк', ['class' => 'btn btn-primary']) ?>
    <?php ActiveForm::end(); ?>

    <hr>

    <!-- Отображение групп -->
    <?php foreach ($groups as $group): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><?= Html::encode($group->name) ?></h5>
                <div>
                    <?= Html::a('Редактировать', '#', [
                        'class' => 'btn btn-sm btn-outline-secondary',
                        'data-bs-toggle' => 'modal',
                        'data-bs-target' => "#edit-group-{$group->id}"
                    ]) ?>
                    <?= Html::a('Удалить', ['delete-group', 'id' => $group->id], [
                        'class' => 'btn btn-sm btn-outline-danger',
                        'data-method' => 'post',
                        'data-confirm' => 'Удалить группу?'
                    ]) ?>
                </div>
            </div>
            <div class="card-body">
                <?php if ($group->nostroBanks): ?>
                    <ul class="list-group">
                        <?php foreach ($group->nostroBanks as $bank): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= Html::encode($bank->bank_name) ?></strong><br>
                                    Ledger: <?= Html::encode($bank->ledger) ?> |
                                    Statement: <?= $bank->statement ?: '—' ?>
                                </div>
                                <div>
                                    <?= Html::a('Редактировать', '#', [
                                        'class' => 'btn btn-sm btn-outline-secondary',
                                        'data-bs-toggle' => 'modal',
                                        'data-bs-target' => "#edit-bank-{$bank->id}"
                                    ]) ?>
                                    <?= Html::a('Удалить', ['delete-bank', 'id' => $bank->id], [
                                        'class' => 'btn btn-sm btn-outline-danger',
                                        'data-method' => 'post',
                                        'data-confirm' => 'Удалить банк?'
                                    ]) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted">Нет активных Ностро банков в группе.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Модальные окна редактирования -->
        <?php Modal::begin([
            'title' => 'Редактировать группу',
            'id' => "edit-group-{$group->id}",
        ]); ?>
            <?= $this->render('_group_form', ['model' => $group]) ?>
        <?php Modal::end(); ?>

        <?php foreach ($group->nostroBanks as $bank): ?>
            <?php Modal::begin([
                'title' => 'Редактировать Ностро банк',
                'id' => "edit-bank-{$bank->id}",
            ]); ?>
                <?= $this->render('_bank_form', ['model' => $bank]) ?>
            <?php Modal::end(); ?>
        <?php endforeach; ?>

    <?php endforeach; ?>

</div>
```

### `backend/views/nostro/_group_form.php`

```php
<?php
use yii\widgets\ActiveForm;
use yii\helpers\Html;
?>

<?php $form = ActiveForm::begin([
    'action' => ['update-group', 'id' => $model->id],
    'method' => 'post',
]); ?>
    <?= $form->field($model, 'name')->textInput(['maxlength' => 55]) ?>
    <?= Html::submitButton('Сохранить', ['class' => 'btn btn-primary']) ?>
<?php ActiveForm::end(); ?>
```

### `backend/views/nostro/_bank_form.php`

```php
<?php
use yii\widgets\ActiveForm;
use yii\helpers\Html;
?>

<?php $form = ActiveForm::begin([
    'action' => ['update-bank', 'id' => $model->id],
    'method' => 'post',
]); ?>
    <?= $form->field($model, 'bank_name')->textInput(['maxlength' => 55]) ?>
    <?= $form->field($model, 'ledger')->textInput(['maxlength' => 35]) ?>
    <?= $form->field($model, 'statement')->textInput(['maxlength' => 35]) ?>
    <?= $form->field($model, 'group_id')->dropDownList(
        \yii\helpers\ArrayHelper::map(\common\models\NostroGroup::find()->all(), 'id', 'name'),
        ['prompt' => 'Выберите группу']
    ) ?>
    <?= Html::submitButton('Сохранить', ['class' => 'btn btn-primary']) ?>
<?php ActiveForm::end(); ?>
```

---

## 🧩 Дополнительно: Загрузка записей (Matching Records)

Для загрузки данных из внешних источников (FCC12, MT950 и т.д.) рекомендуется:

1. Создать отдельный **консольный контроллер** для импорта:
   ```bash
   yii nostro/import-ledger --file=data.csv
   yii nostro/import-statement --file=mt950.xml
   ```

2. В процессе импорта:
    - сопоставлять `Ledger` и `Statement` по `nostro_bank.ledger` и `nostro_bank.statement`
    - автоматически проставлять `match_id` при совпадении (например, по сумме + дате + референсу)
    - сохранять записи в `matching_record`

3. Добавить в интерфейс отдельную вкладку **«Записи»** с фильтрацией по группе → банку → типу (L/S)

---

## ✅ Итог

Вы получаете:

- Полноценную иерархию: **Группа → Ностро банк → Записи**
- Возможность создания/редактирования/удаления групп и банков
- Поддержку обязательных полей и валидации
- Гибкую структуру для будущего расширения (например, добавление NRE/INV как отдельных модулей)

Если нужно — могу добавить:
- REST API для интеграции
- Интерфейс просмотра записей (Matching Records)
- Автоматическое матчинг-сопоставление

Готов доработать!