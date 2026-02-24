<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Настройки архивирования по компании.
 *
 * @property int  $id
 * @property int  $company_id
 * @property int  $archive_after_days   Через сколько дней архивировать
 * @property int  $retention_years      Срок хранения в архиве (лет)
 * @property bool $auto_archive_enabled Включить автоархивирование
 * @property int|null $updated_by
 * @property string $created_at
 * @property string $updated_at
 */
class ArchiveSettings extends ActiveRecord
{
    // Дефолтные значения
    const DEFAULT_ARCHIVE_AFTER_DAYS = 90;
    const DEFAULT_RETENTION_YEARS    = 5;

    public static function tableName(): string
    {
        return '{{%archive_settings}}';
    }

    public function rules(): array
    {
        return [
            [['company_id', 'archive_after_days', 'retention_years'], 'required'],
            [['company_id', 'archive_after_days', 'retention_years', 'updated_by'], 'integer'],
            [['archive_after_days'], 'integer', 'min' => 1, 'max' => 3650],
            [['retention_years'],    'integer', 'min' => 1, 'max' => 20],
            [['auto_archive_enabled'], 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'archive_after_days'   => 'Дней до архивирования',
            'retention_years'      => 'Лет хранения в архиве',
            'auto_archive_enabled' => 'Автоархивирование',
        ];
    }

    /**
     * Получить настройки для компании (или вернуть дефолтные если ещё нет).
     */
    public static function getForCompany(int $companyId): self
    {
        $s = self::findOne(['company_id' => $companyId]);
        if (!$s) {
            $s              = new self();
            $s->company_id  = $companyId;
            $s->archive_after_days  = self::DEFAULT_ARCHIVE_AFTER_DAYS;
            $s->retention_years     = self::DEFAULT_RETENTION_YEARS;
            $s->auto_archive_enabled = true;
        }
        return $s;
    }

    public function toApiArray(): array
    {
        return [
            'archive_after_days'   => (int)$this->archive_after_days,
            'retention_years'      => (int)$this->retention_years,
            'auto_archive_enabled' => (bool)$this->auto_archive_enabled,
        ];
    }
}