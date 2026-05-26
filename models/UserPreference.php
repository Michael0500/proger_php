<?php

namespace app\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Пользовательские настройки UI (JSONB).
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $pref_key
 * @property mixed  $pref_value   JSONB-значение (массив/объект)
 * @property int    $created_at
 * @property int    $updated_at
 */
class UserPreference extends ActiveRecord
{
    const KEY_ENTRIES_TABLE_COLUMNS = 'entries_table_columns';
    const KEY_BALANCE_TABLE_COLUMNS = 'balance_table_columns';
    const KEY_ARCHIVE_TABLE_COLUMNS = 'archive_table_columns';
    const KEY_BALANCE_ARCHIVE_TABLE_COLUMNS = 'balance_archive_table_columns';

    /**
     * Возвращает имя таблицы пользовательских настроек.
     *
     * @return string Имя таблицы `user_preferences` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%user_preferences}}';
    }

    /**
     * Подключает автоматическое заполнение timestamp-полей.
     *
     * @return array Конфигурация Yii behaviors.
     */
    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    /**
     * Описывает базовые правила валидации настройки.
     *
     * Whitelist допустимых ключей применяется в контроллере, а модель
     * отвечает за обязательность пользователя и строкового ключа.
     *
     * @return array Правила Yii Validator.
     */
    public function rules(): array
    {
        return [
            [['user_id', 'pref_key'], 'required'],
            [['user_id'], 'integer'],
            [['pref_key'], 'string', 'max' => 100],
        ];
    }

    /**
     * Возвращает значение настройки пользователя.
     *
     * Значение хранится в JSONB. Метод умеет читать как нормальный JSONB,
     * так и старый double-encoded формат, постепенно декодируя JSON-строки.
     *
     * @param int $userId ID пользователя.
     * @param string $key Ключ настройки.
     * @param mixed $default Значение по умолчанию, если настройка отсутствует.
     * @return mixed Декодированное значение настройки или `$default`.
     */
    public static function getValue(int $userId, string $key, $default = null)
    {
        $row = (new \yii\db\Query())
            ->select(['pref_value'])
            ->from(self::tableName())
            ->where(['user_id' => $userId, 'pref_key' => $key])
            ->scalar();

        if ($row === false || $row === null) return $default;

        // Если драйвер уже вернул массив/объект — используем как есть
        $decoded = is_string($row) ? json_decode($row, true) : $row;

        // Self-heal: если в колонке лежит JSON-строка (старый double-encoded формат)
        // — декодируем ещё раз. Проверка через is_string гарантирует,
        // что мы не цикличим на нормальной строке.
        while (is_string($decoded)) {
            $again = json_decode($decoded, true);
            if ($again === null) break;
            $decoded = $again;
        }

        return $decoded === null ? $default : $decoded;
    }

    /**
     * Сохраняет значение пользовательской настройки через UPSERT.
     *
     * ВАЖНО: передаём PHP-массив/скаляр как есть. Yii2 при записи в колонку
     * типа `jsonb` автоматически вызывает `json_encode` один раз
     * (см. `yii\db\Schema::getColumnPhpType`). Если вручную передать
     * JSON-строку — получится double-encoded значение.
     *
     * @param int $userId ID пользователя.
     * @param string $key Ключ настройки из whitelist контроллера.
     * @param mixed $value Значение, совместимое с JSONB.
     * @return bool Всегда `true`, если SQL-команда выполнена без исключения.
     */
    public static function setValue(int $userId, string $key, $value): bool
    {
        $now = time();

        Yii::$app->db->createCommand()->upsert(
            self::tableName(),
            [
                'user_id'    => $userId,
                'pref_key'   => $key,
                'pref_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'pref_value' => $value,
                'updated_at' => $now,
            ]
        )->execute();

        return true;
    }
}
