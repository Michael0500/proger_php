<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use app\models\Company;
use app\models\NostroEntry;
use app\models\MatchingRule;
use app\services\MatchingService;

/**
 * Консольная команда автоквитования.
 *
 * Использование:
 *   php yii auto-match/run                           — все компании
 *   php yii auto-match/run --company=1               — конкретная компания
 *   php yii auto-match/run --company=1 --account=5   — конкретный счёт
 *   php yii auto-match/status --company=1             — показать статистику без запуска
 *
 * Cron (каждый час):
 *   0 * * * * /path/to/php /path/to/yii auto-match/run >> /var/log/smartmatch-automatch.log 2>&1
 */
class AutoMatchController extends Controller
{
    /** @var int|null ID компании */
    public $company;

    /** @var int|null ID счёта (работает только с --company) */
    public $account;

    /** @var bool Вывести SQL без выполнения */
    public $debug = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['company', 'account', 'debug']);
    }

    /**
     * Запустить автоквитование.
     */
    public function actionRun(): int
    {
        $this->stdout("=== SmartMatch Auto-Match: " . date('Y-m-d H:i:s') . " ===\n", Console::BOLD);

        $companies = $this->getCompanies();
        if (empty($companies)) {
            $this->stderr("Компании не найдены.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $service      = new MatchingService();
        $grandTotal   = 0;
        $grandErrors  = 0;

        foreach ($companies as $company) {
            $this->stdout("\n");
            $this->stdout("┌─ Компания: {$company->name} (ID: {$company->id})\n", Console::FG_CYAN);

            // Считаем незаквитованные записи
            $query = NostroEntry::find()
                ->where([
                    'company_id'   => $company->id,
                    'match_status' => NostroEntry::STATUS_UNMATCHED,
                ]);
            if ($this->account) {
                $query->andWhere(['account_id' => (int)$this->account]);
            }
            $unmatchedBefore = (int)$query->count();

            $this->stdout("│  Незаквитованных записей: {$unmatchedBefore}\n");

            if ($unmatchedBefore === 0) {
                $this->stdout("│  Нет записей для квитования — пропускаем.\n");
                $this->stdout("└─\n");
                continue;
            }

            // Проверяем правила
            $rulesCount = MatchingRule::find()
                ->where(['company_id' => $company->id, 'is_active' => true])
                ->count();

            if ($rulesCount == 0) {
                $this->stdout("│  Нет активных правил — пропускаем.\n", Console::FG_YELLOW);
                $this->stdout("└─\n");
                continue;
            }

            $this->stdout("│  Активных правил: {$rulesCount}\n");
            $this->stdout("│\n");

            $accountId = $this->account ? (int)$this->account : null;
            $ruleErrors = 0;

            // Режим отладки: вывести SQL и выйти
            if ($this->debug) {
                $this->printDebugSql($company->id, $accountId);
                $this->stdout("└─\n");
                continue;
            }

            // Запускаем с callback прогресса
            $result = $service->autoMatch($company->id, $accountId, function (
                int $ruleIndex, string $ruleName, int $matchedInRule, int $totalMatched
            ) use (&$ruleErrors) {
                $icon = $matchedInRule > 0 ? '✓' : '·';
                $color = $matchedInRule > 0 ? Console::FG_GREEN : Console::FG_GREY;
                $this->stdout("│  {$icon} Правило #{$ruleIndex}: ", $color);
                $this->stdout("{$ruleName}");
                $this->stdout(" → пар: {$matchedInRule}", Console::BOLD);
                $this->stdout(" (итого: {$totalMatched})\n");
            });

            $matched = $result['matched'] ?? 0;
            $grandTotal += $matched;

            // Считаем оставшиеся
            $unmatchedAfter = (int)NostroEntry::find()
                ->where([
                    'company_id'   => $company->id,
                    'match_status' => NostroEntry::STATUS_UNMATCHED,
                ])
                ->count();

            $this->stdout("│\n");
            $this->stdout("│  Результат: сквитовано пар: {$matched}\n", Console::BOLD);
            $this->stdout("│  Осталось незаквитованных: {$unmatchedAfter}\n");

            if (!empty($result['message']) && strpos($result['message'], 'Ошибки') !== false) {
                $this->stderr("│  ⚠ " . $result['message'] . "\n", Console::FG_RED);
                $grandErrors++;
            }

            $this->stdout("└─\n");
        }

        $this->stdout("\n");
        $this->stdout("=== Итого: сквитовано пар: {$grandTotal}", Console::BOLD);
        if ($grandErrors > 0) {
            $this->stdout(", компаний с ошибками: {$grandErrors}", Console::FG_RED);
        }
        $this->stdout(" ===\n");

        return ExitCode::OK;
    }

    /**
     * Показать статистику незаквитованных записей.
     */
    public function actionStatus(): int
    {
        $this->stdout("=== SmartMatch Matching Status: " . date('Y-m-d H:i:s') . " ===\n", Console::BOLD);

        $companies = $this->getCompanies();
        if (empty($companies)) {
            $this->stderr("Компании не найдены.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        foreach ($companies as $company) {
            $this->stdout("\nКомпания: {$company->name} (ID: {$company->id})\n", Console::FG_CYAN);

            $db = Yii::$app->db;

            // Статистика по статусам
            $stats = $db->createCommand(
                'SELECT match_status, COUNT(*) AS cnt
                 FROM {{%nostro_entries}}
                 WHERE company_id = :cid
                 GROUP BY match_status
                 ORDER BY match_status',
                [':cid' => $company->id]
            )->queryAll();

            $statusLabels = [
                'U' => 'Незаквитованные',
                'M' => 'Сквитованные',
                'I' => 'Игнорируемые',
            ];

            $total = 0;
            foreach ($stats as $row) {
                $label = $statusLabels[$row['match_status']] ?? $row['match_status'];
                $this->stdout("  {$label}: {$row['cnt']}\n");
                $total += (int)$row['cnt'];
            }
            $this->stdout("  Всего: {$total}\n");

            // Количество правил
            $rulesActive = MatchingRule::find()
                ->where(['company_id' => $company->id, 'is_active' => true])
                ->count();
            $rulesTotal = MatchingRule::find()
                ->where(['company_id' => $company->id])
                ->count();

            $this->stdout("  Правил: {$rulesActive} активных / {$rulesTotal} всего\n");
        }

        return ExitCode::OK;
    }

    /**
     * Вывести SQL для каждого правила без выполнения.
     */
    private function printDebugSql(int $companyId, ?int $accountId): void
    {
        $rules = MatchingRule::find()
            ->where(['company_id' => $companyId, 'is_active' => true])
            ->orderBy(['priority' => SORT_ASC])
            ->all();

        if (empty($rules)) {
            $this->stdout("│  Нет активных правил.\n", Console::FG_YELLOW);
            return;
        }

        $service = new MatchingService();
        foreach ($rules as $rule) {
            $this->stdout("│\n│  === Правило: {$rule->name} (ID: {$rule->id}) ===\n", Console::FG_CYAN);
            $sql = $service->buildDebugSql($rule, $companyId, $accountId);
            if ($sql === null) {
                $this->stdout("│  ⚠ buildJoinConditions вернул пустой массив — нет условий для JOIN\n", Console::FG_RED);
            } else {
                $this->stdout($sql . "\n");
            }
        }
    }

    /**
     * Получить список компаний для обработки.
     */
    private function getCompanies(): array
    {
        if ($this->company) {
            $company = Company::findOne((int)$this->company);
            return $company ? [$company] : [];
        }

        return Company::find()->orderBy(['id' => SORT_ASC])->all();
    }
}
