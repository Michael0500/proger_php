<?php

use app\models\Account;
use app\models\AccountPool;
use app\models\ArchiveSettings;
use app\models\Category;
use app\models\Company;
use app\models\MatchingRule;
use app\models\NostroBalance;
use app\models\NostroBalanceArchive;
use app\models\NostroEntry;
use app\models\NostroEntryArchive;
use app\models\User;

/**
 * Тестовый класс `SmartMatchTestHelper`.
 *
 * Проверяет поведение соответствующего участка SmartMatch в рамках Codeception suite.
 */
final class SmartMatchTestHelper
{
    /**
     * Выполняет тестовый сценарий: reset database.
     * @return void
     */
    public static function resetDatabase(): void
    {
        $db = Yii::$app->db;
        $tables = [
            'suspend_posting',
            'user_preferences',
            'nostro_entry_audit',
            'nostro_entries_archive',
            'nostro_entries',
            'nostro_balance_audit',
            'nostro_balance_archive',
            'nostro_balance',
            'archive_settings',
            'matching_rules',
            'accounts',
            'account_pools',
            'group_filters',
            'groups',
            'categories',
            'user',
            'company',
        ];

        $existing = [];
        foreach ($tables as $table) {
            if ($db->schema->getTableSchema($table, true) !== null) {
                $existing[] = $db->quoteTableName($table);
            }
        }

        if ($existing) {
            $db->createCommand('TRUNCATE TABLE ' . implode(', ', $existing) . ' RESTART IDENTITY CASCADE')->execute();
        }

        $hasMatchSequence = (bool)$db->createCommand(
            "SELECT 1 FROM pg_class WHERE relkind = 'S' AND relname = 'match_id_seq'"
        )->queryScalar();
        if ($hasMatchSequence) {
            $db->createCommand('ALTER SEQUENCE match_id_seq RESTART WITH 1')->execute();
        }

        Yii::$app->user->logout(false);
        Yii::$app->cache->flush();
    }

    /**
     * Выполняет тестовый сценарий: create company.
     *
     * @return void
     */
    public static function createCompany(array $attributes = []): Company
    {
        $suffix = self::suffix();
        $company = new Company(array_merge([
            'name' => 'Тестовая компания ' . $suffix,
            'code' => 'T' . strtoupper(substr($suffix, 0, 5)),
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
        $company->save(false);
        return $company;
    }

    /**
     * Выполняет тестовый сценарий: create user.
     *
     * @return void
     */
    public static function createUser(?int $companyId = null, array $attributes = []): User
    {
        $suffix = self::suffix();

        // Пользователи в тестах авторизуются через cookie/session helper.
        // Открытый пароль не является частью тестовых данных.
        unset($attributes['password']);

        $defaults = [
            'username' => 'user_' . $suffix,
            'email' => 'user_' . $suffix . '@example.test',
            'status' => User::STATUS_ACTIVE,
            'company_id' => $companyId,
        ];

        if (User::hasTableColumn('auth_key')) {
            $defaults['auth_key'] = Yii::$app->security->generateRandomString(32);
        }
        if (User::hasTableColumn('password_hash')) {
            $defaults['password_hash'] = self::cookieOnlyPasswordHash();
        }

        $user = new User(array_merge($defaults, $attributes));
        $user->save(false);
        return $user;
    }

    /**
     * Выполняет тестовый сценарий: create category.
     *
     * @return void
     */
    public static function createCategory(int $companyId, array $attributes = []): Category
    {
        $category = new Category(array_merge([
            'company_id' => $companyId,
            'name' => 'Категория ' . self::suffix(),
            'description' => null,
        ], $attributes));
        $category->save(false);
        return $category;
    }

    /**
     * Выполняет тестовый сценарий: create pool.
     *
     * @return void
     */
    public static function createPool(int $companyId, array $attributes = []): AccountPool
    {
        $pool = new AccountPool(array_merge([
            'company_id' => $companyId,
            'category_id' => null,
            'name' => 'Ностро-банк ' . self::suffix(),
            'description' => null,
        ], $attributes));
        $pool->save(false);
        return $pool;
    }

    /**
     * Выполняет тестовый сценарий: create account.
     *
     * @return void
     */
    public static function createAccount(int $companyId, int $poolId, array $attributes = []): Account
    {
        $db = Yii::$app->db;
        $now = date('Y-m-d H:i:s');
        $db->createCommand()->insert(Account::tableName(), array_merge([
            'company_id' => $companyId,
            'pool_id' => $poolId,
            'name' => 'ACC-' . self::suffix(),
            'currency' => 'RUB',
            'account_type' => NostroBalance::LS_LEDGER,
            'country' => 'RU',
            'load_barsgl' => false,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'updated_by' => null,
            'load_status' => 'L',
            'date_close' => null,
            'is_suspense' => false,
            'date_open' => '2026-01-01 00:00:00',
        ], $attributes))->execute();

        return Account::findOne((int)$db->getLastInsertID('accounts_id_seq'));
    }

    /**
     * Выполняет тестовый сценарий: create entry.
     *
     * @return void
     */
    public static function createEntry(array $attributes = [], bool $skipAudit = true): NostroEntry
    {
        $entry = new NostroEntry(array_merge([
            'account_id' => null,
            'company_id' => null,
            'posting_id' => null,
            'match_id' => null,
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
            'currency' => 'RUB',
            'value_date' => '2026-01-10',
            'post_date' => '2026-01-10',
            'instruction_id' => null,
            'end_to_end_id' => null,
            'transaction_id' => null,
            'message_id' => null,
            'other_id' => null,
            'comment' => null,
            'source' => 'TEST',
            'match_status' => NostroEntry::STATUS_UNMATCHED,
            'matched_at' => null,
            'branch_code' => null,
        ], $attributes));
        $entry->skipAudit = $skipAudit;
        if (!$entry->save(false)) {
            throw new \RuntimeException('Не удалось сохранить тестовую запись nostro_entries.');
        }
        return $entry;
    }

    /**
     * Выполняет тестовый сценарий: create rule.
     *
     * @return void
     */
    public static function createRule(int $companyId, array $attributes = []): MatchingRule
    {
        $rule = new MatchingRule(array_merge([
            'company_id' => $companyId,
            'name' => 'Правило ' . self::suffix(),
            'section' => MatchingRule::SECTION_NRE,
            'pair_type' => MatchingRule::PAIR_LS,
            'match_dc' => true,
            'match_amount' => true,
            'match_value_date' => true,
            'match_instruction_id' => false,
            'match_end_to_end_id' => false,
            'match_transaction_id' => false,
            'match_message_id' => false,
            'cross_id_search' => false,
            'is_active' => true,
            'priority' => 100,
            'description' => null,
        ], $attributes));
        $rule->save(false);
        return $rule;
    }

    /**
     * Выполняет тестовый сценарий: create balance.
     *
     * @return void
     */
    public static function createBalance(array $attributes = []): NostroBalance
    {
        $balance = new NostroBalance(array_merge([
            'company_id' => null,
            'account_id' => null,
            'ls_type' => NostroBalance::LS_LEDGER,
            'statement_number' => null,
            'currency' => 'RUB',
            'value_date' => '2026-01-10',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '100.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
            'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
            'comment' => null,
            'branch_code' => null,
        ], $attributes));
        $balance->save(false);
        return $balance;
    }

    /**
     * Выполняет тестовый сценарий: create archive settings.
     *
     * @return void
     */
    public static function createArchiveSettings(int $companyId, array $attributes = []): ArchiveSettings
    {
        $settings = new ArchiveSettings(array_merge([
            'company_id' => $companyId,
            'archive_after_days' => ArchiveSettings::DEFAULT_ARCHIVE_AFTER_DAYS,
            'retention_years' => ArchiveSettings::DEFAULT_RETENTION_YEARS,
            'auto_archive_enabled' => true,
            'updated_by' => null,
        ], $attributes));
        $settings->save(false);
        return $settings;
    }

    /**
     * Выполняет тестовый сценарий: create archived entry.
     *
     * @return void
     */
    public static function createArchivedEntry(array $attributes = []): NostroEntryArchive
    {
        $archive = new NostroEntryArchive(array_merge([
            'original_id' => 1,
            'account_id' => null,
            'company_id' => null,
            'match_id' => 'MTCH00000001',
            'ls' => NostroEntry::LS_LEDGER,
            'dc' => NostroEntry::DC_DEBIT,
            'amount' => '100.00',
            'currency' => 'RUB',
            'value_date' => '2026-01-10',
            'post_date' => '2026-01-10',
            'instruction_id' => null,
            'end_to_end_id' => null,
            'transaction_id' => null,
            'message_id' => null,
            'other_id' => null,
            'comment' => null,
            'source' => 'TEST',
            'match_status' => NostroEntryArchive::STATUS_ARCHIVED,
            'matched_at' => '2026-01-11 10:00:00',
            'archived_at' => '2026-01-12 10:00:00',
            'expires_at' => '2031-01-12 10:00:00',
            'archived_by' => null,
            'original_created_at' => '2026-01-10 10:00:00',
            'original_updated_at' => '2026-01-11 10:00:00',
        ], $attributes));
        $archive->save(false);
        return $archive;
    }

    /**
     * Выполняет тестовый сценарий: create archived balance.
     *
     * @return void
     */
    public static function createArchivedBalance(array $attributes = []): NostroBalanceArchive
    {
        $archive = new NostroBalanceArchive(array_merge([
            'original_id' => 1,
            'company_id' => null,
            'account_id' => null,
            'ls_type' => NostroBalance::LS_LEDGER,
            'statement_number' => null,
            'currency' => 'RUB',
            'value_date' => '2026-01-10',
            'opening_balance' => '0.00',
            'opening_dc' => NostroBalance::DC_CREDIT,
            'closing_balance' => '100.00',
            'closing_dc' => NostroBalance::DC_CREDIT,
            'section' => NostroBalance::SECTION_NRE,
            'source' => NostroBalance::SOURCE_MANUAL,
            'status' => NostroBalance::STATUS_NORMAL,
            'comment' => null,
            'branch_code' => null,
            'archived_at' => '2026-01-12 10:00:00',
            'expires_at' => '2031-01-12 10:00:00',
            'archived_by' => null,
            'original_created_at' => '2026-01-10 10:00:00',
            'original_updated_at' => '2026-01-11 10:00:00',
        ], $attributes));
        $archive->save(false);
        return $archive;
    }

    /**
     * Выполняет тестовый сценарий: create company pool account.
     * @return void
     */
    public static function createCompanyPoolAccount(): array
    {
        $company = self::createCompany();
        $pool = self::createPool((int)$company->id);
        $account = self::createAccount((int)$company->id, (int)$pool->id);

        return [$company, $pool, $account];
    }

    /**
     * Выполняет тестовый сценарий: suffix.
     * @return void
     */
    private static function suffix(): string
    {
        return strtolower(bin2hex(random_bytes(4)));
    }

    /**
     * Возвращает технический hash для legacy NOT NULL поля `password_hash`.
     *
     * @return string Hash неизвестного одноразового значения.
     */
    private static function cookieOnlyPasswordHash(): string
    {
        static $hash = null;

        if ($hash === null) {
            $hash = Yii::$app->security->generatePasswordHash(Yii::$app->security->generateRandomString(32));
        }

        return $hash;
    }
}
