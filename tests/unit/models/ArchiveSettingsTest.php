<?php

namespace tests\unit\models;

use app\models\ArchiveSettings;

/**
 * Тестовый класс `ArchiveSettingsTest`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
class ArchiveSettingsTest extends \Codeception\Test\Unit
{
    use \PrintsTestDescription;

    /**
     * Подготавливает окружение перед тестом.
     * @return void
     */
    protected function _before(): void
    {
        \SmartMatchTestHelper::resetDatabase();
    }

    /**
     * Проверяет сценарий: get for company returns defaults when settings do not exist.
     * @return void
     */
    public function testGetForCompanyReturnsDefaultsWhenSettingsDoNotExist(): void
    {
        $company = \SmartMatchTestHelper::createCompany();

        $settings = ArchiveSettings::getForCompany((int)$company->id);

        verify($settings->isNewRecord)->true();
        verify($settings->archive_after_days)->equals(ArchiveSettings::DEFAULT_ARCHIVE_AFTER_DAYS);
        verify($settings->retention_years)->equals(ArchiveSettings::DEFAULT_RETENTION_YEARS);
        verify($settings->toApiArray())->equals([
            'archive_after_days' => ArchiveSettings::DEFAULT_ARCHIVE_AFTER_DAYS,
            'retention_years' => ArchiveSettings::DEFAULT_RETENTION_YEARS,
            'auto_archive_enabled' => true,
        ]);

        $this->stdout('Настройки архива для компании без записи: возвращается новый объект с дефолтами (archive_after_days/retention_years) и корректным toApiArray.');
    }

    /**
     * Проверяет сценарий: validation rejects out of range values.
     * @return void
     */
    public function testValidationRejectsOutOfRangeValues(): void
    {
        $settings = new ArchiveSettings([
            'company_id' => 1,
            'archive_after_days' => 0,
            'retention_years' => 21,
            'auto_archive_enabled' => true,
        ]);

        verify($settings->validate())->false();
        verify($settings->errors)->arrayHasKey('archive_after_days');
        verify($settings->errors)->arrayHasKey('retention_years');

        $this->stdout('Валидация настроек архива: archive_after_days=0 и retention_years=21 вне диапазона → ошибки по обоим полям.');
    }
}
