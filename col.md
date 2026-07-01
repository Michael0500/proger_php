Собираю всё воедино. Ниже — итоговое решение целиком: три файла с готовым кодом плюс проверочный SQL. Один момент (формат дат) остаётся на твою проверку — я заложил защиту, которая работает в обоих случаях, чтобы ты не блокировался.

## Что и где меняется

Три файла. Вьюху не трогаем (доступа нет), всё на стороне PHP.

**1. Новый файл — модель `StatusGemo`** (`app/models/StatusGemo.php`)

Читает всю историю GEMO (200k строк) один раз в память, строит индекс по `account_number`, и отдаёт статус на дату события.

```php
<?php

namespace app\models;

use Yii;

/**
 * Модель вью DM_RRE.V_STATUS_GEMO — версионная история статусов GEMO.
 *
 * @property string      $status_cd
 * @property string      $account_number
 * @property string|null $valid_from_dttm
 * @property string|null $valid_to_dttm
 */
class StatusGemo extends \yii\db\ActiveRecord
{
    /** Заглушка «открытого» интервала для версий с valid_to = null */
    private const OPEN_INTERVAL = '5999-01-01';

    public static function tableName()
    {
        return 'DM_RRE.V_STATUS_GEMO';
    }

    public static function getDb()
    {
        return Yii::$app->get('db_dwh');
    }

    /**
     * Грузит всю историю GEMO и строит индекс:
     *   account_number => [ ['from'=>..., 'to'=>..., 'status'=>...], ... ]
     * Версии внутри каждого account_number отсортированы по дате начала.
     *
     * 200k строк ≈ десятки МБ, спокойно помещается в memory_limit.
     */
    public static function buildIndex(): array
    {
        $index = [];

        foreach (self::find()->asArray()->each(1000) as $row) {
            $index[$row['account_number']][] = [
                'from'   => self::normalizeDate($row['valid_from_dttm']),
                'to'     => self::normalizeDate($row['valid_to_dttm']),
                'status' => $row['status_cd'],
            ];
        }

        foreach ($index as &$versions) {
            usort($versions, fn($a, $b) => strcmp($a['from'], $b['from']));
        }
        unset($versions);

        return $index;
    }

    /**
     * Возвращает status_cd на дату события среди версий одного account_number.
     * Интервал полуоткрытый: from <= eventDate < to.
     */
    public static function resolveStatus(array $versions, ?string $eventDate): ?string
    {
        $eventDate = self::normalizeDate($eventDate);
        if ($eventDate === null) {
            return null;
        }

        foreach ($versions as $v) {
            $to = $v['to'] ?? self::OPEN_INTERVAL;
            if ($v['from'] <= $eventDate && $eventDate < $to) {
                return $v['status'];
            }
        }

        return null;
    }

    /**
     * Приводит любую дату к 'YYYY-MM-DD HH:MM:SS' для корректного
     * строкового сравнения независимо от того, как её отдаёт Oracle/AR.
     */
    private static function normalizeDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $ts = strtotime($date);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : $date;
    }
}
```

Обрати внимание: я добавил `normalizeDate()` — она приводит и даты GEMO, и дату события к единому формату `Y-m-d H:i:s`. Это снимает риск разных форматов (у одного есть время, у другого нет), из-за которого строковое сравнение могло сломаться. Поэтому проверка формата дат перестаёт быть блокером — код работает в любом случае. Но прогнать её всё равно стоит для контроля (SQL ниже).

**2. Правка — `CalculatingTransferDate.php` (Image 4)**

Добавляем в `getInsertData` два параметра: `$eventDate` и `$statusCd`. `status_cd` теперь из аргумента, а не из `$this`.

```php
public function getInsertData(int $batchId, string $type, $eventDate, ?string $statusCd): array
{
    return [
        'mandate_num'    => $this->mandate_num,
        'folder'         => $this->folder,
        'status_cd'      => $statusCd,          // статус GEMO на дату события
        'account_number' => $this->account_number,
        'type'           => $type,
        'event_date'     => $eventDate,
        'batch_id'       => $batchId,
    ];
}
```

⚠️ **Проверь одну вещь перед этим.** В `actionDownloadCalculate` (Image 5) есть вызов `$item->getInsertData($transferBatch->id)` с одним аргументом — но там модель `DwhTransferCalculate`, а не `CalculatingTransferDate`. Если это действительно **разные классы** (судя по скринам — да), правка выше безопасна. Если вдруг один класс — не меняй сигнатуру, а сделай отдельный метод `getEventInsertData(...)` с тем же телом и вызывай его из `actionGenerate`.

**3. Правка — `DwhTransferController::actionGenerate` (Image 1)**

```php
public function actionGenerate()
{
    ini_set('memory_limit', '3256M');

    if ($batch = TransferBatch::find()->where(['status' => TransferBatch::STATUS_LOAD])->one()) {
        $model = CalculatingTransferDate::find()->where(['batch_id' => $batch->id])->all();

        // Один раз грузим всю историю статусов GEMO в память
        $gemoIndex = StatusGemo::buildIndex();

        $data = [];

        foreach ($model as $item) {
            // Заглушка '1000-01-01' = нет даты закрытия → передача (transfer)
            $isTransfer = in_array($item->close_mandate_dt, ['1000-01-01', '1000-01-01 00:00:00'], true);

            if ($isTransfer) {
                $type      = 'transfer';
                $eventDate = $item->created_at;
            } else {
                $type      = 'withdraw';
                $eventDate = $item->close_mandate_dt;
            }

            $versions = $gemoIndex[$item->account_number] ?? [];
            $statusCd = StatusGemo::resolveStatus($versions, $eventDate);

            $data[] = $item->getInsertData($batch->id, $type, $eventDate, $statusCd);
        }

        Yii::$app->db->createCommand()
            ->batchInsert('events',
                [
                    'mandate_num',
                    'folder',
                    'status_cd',
                    'account_number',
                    'type',
                    'event_date',
                    'batch_id',
                ],
                $data)
            ->execute();

        $batch->status = TransferBatch::STATUS_SUCCESS;
        $batch->save();
    }
}
```

Что изменилось против оригинала (Image 1): убрал хрупкое `=== '1000-01-01'` (теперь `in_array` ловит и вариант со временем), добавил загрузку индекса GEMO и вычисление `$statusCd` перед `getInsertData`.

## Проверочный SQL (прогнать до внедрения)

**Пересечения интервалов** — если пусто, статус выбирается однозначно:

```sql
SELECT s1.account_number
FROM DM_RRE.V_STATUS_GEMO s1
JOIN DM_RRE.V_STATUS_GEMO s2
  ON s1.account_number = s2.account_number
 AND s1.rowid <> s2.rowid
 AND s1.valid_from_dttm < COALESCE(s2.valid_to_dttm, DATE '5999-01-01')
 AND s2.valid_from_dttm < COALESCE(s1.valid_to_dttm, DATE '5999-01-01')
FETCH FIRST 50 ROWS ONLY;
```

Если вернёт строки — у этих account_number версии перекрываются, и `resolveStatus` вернёт первую подходящую. Тогда надо решать, какую версию считать правильной (обычно свежую) — скажи, добавлю выбор по max `valid_from`.

**Формат дат** (для контроля, код и так нормализует):

```sql
SELECT TO_CHAR(valid_from_dttm, 'YYYY-MM-DD HH24:MI:SS') vf,
       TO_CHAR(valid_to_dttm,   'YYYY-MM-DD HH24:MI:SS') vt
FROM DM_RRE.V_STATUS_GEMO
FETCH FIRST 10 ROWS ONLY;
```

## Порядок внедрения

1. Прогнать SQL на пересечения — убедиться, что версии GEMO не перекрываются.
2. Создать `app/models/StatusGemo.php`.
3. Проверить, что `getInsertData` в `actionDownloadCalculate` — это другой класс (`DwhTransferCalculate`), а не тот, что правим. Если тот же — сделать отдельный метод.
4. Внести правки в `CalculatingTransferDate::getInsertData` и `DwhTransferController::actionGenerate`.
5. Тест на одном батче: сверить несколько событий вручную — что `status_cd` в `events` соответствует версии GEMO на `event_date`.

Решение самодостаточное и не требует доступа к вьюхе. Единственное место, где нужен твой контроль по коду, — пункт 3 (один класс `getInsertData` или два). Если скинешь `CalculatingTransferDate.php` и `DwhTransferCalculate.php` целиком, подтвержу сигнатуру точно.