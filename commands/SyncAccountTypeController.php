<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Проставляет accounts.account_type ('L'/'S') на основании nostro_entries.ls.
 *
 * Для каждого уникального account_id в nostro_entries берём значение ls и
 * записываем его в accounts.account_type, если там пусто или отличается.
 *
 * Использование:
 *   php yii sync-account-type/run           — обновить пустые/отличающиеся
 *   php yii sync-account-type/run --force=1 — перезаписать все
 *   php yii sync-account-type/run --dry-run=1 — только показать что будет изменено
 */
class SyncAccountTypeController extends Controller
{
    /** @var bool Перезаписывать уже заполненные account_type */
    public $force = false;

    /** @var bool Не сохранять изменения, только вывести */
    public $dryRun = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['force', 'dryRun']);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), ['f' => 'force', 'd' => 'dryRun']);
    }

    public function actionRun(): int
    {
        $db = Yii::$app->db;

        $this->stdout("=== Синхронизация accounts.account_type из nostro_entries.ls ===\n");
        if ($this->force)  $this->stdout("Режим: FORCE (перезапись существующих)\n");
        if ($this->dryRun) $this->stdout("Режим: DRY-RUN (без сохранения)\n");
        $this->stdout("\n");

        // Уникальные (account_id, ls) из nostro_entries.
        // Если у одного счёта встречаются обе буквы — берём наиболее частую.
        $rows = $db->createCommand("
            SELECT account_id, ls, COUNT(*) AS cnt
            FROM nostro_entries
            WHERE account_id IS NOT NULL
              AND ls IN ('L', 'S')
            GROUP BY account_id, ls
        ")->queryAll();

        if (!$rows) {
            $this->stdout("В nostro_entries нет записей с валидным ls.\n");
            return ExitCode::OK;
        }

        // Сворачиваем в map: account_id → ls с максимальным cnt
        $best = []; // [account_id => ['ls' => 'L'|'S', 'cnt' => int]]
        foreach ($rows as $r) {
            $aid = (int)$r['account_id'];
            $cnt = (int)$r['cnt'];
            if (!isset($best[$aid]) || $best[$aid]['cnt'] < $cnt) {
                $best[$aid] = ['ls' => $r['ls'], 'cnt' => $cnt];
            }
        }

        $this->stdout("Найдено уникальных счетов в nostro_entries: " . count($best) . "\n");

        // Текущие account_type в accounts
        $ids = array_keys($best);
        $current = $db->createCommand(
            "SELECT id, name, account_type FROM accounts WHERE id = ANY(:ids)",
            [':ids' => '{' . implode(',', $ids) . '}']
        )->queryAll();

        $currentMap = [];
        foreach ($current as $c) {
            $currentMap[(int)$c['id']] = $c;
        }

        $toUpdate = [];
        $skipped  = 0;
        $missing  = 0;

        foreach ($best as $aid => $info) {
            if (!isset($currentMap[$aid])) {
                $missing++;
                continue;
            }
            $cur = $currentMap[$aid]['account_type'];
            $new = $info['ls'];

            if ($cur === $new) {
                $skipped++;
                continue;
            }
            if ($cur !== null && $cur !== '' && !$this->force) {
                $skipped++;
                continue;
            }

            $toUpdate[] = [
                'id'   => $aid,
                'name' => $currentMap[$aid]['name'],
                'old'  => $cur,
                'new'  => $new,
            ];
        }

        $this->stdout("К обновлению: " . count($toUpdate) . "\n");
        $this->stdout("Пропущено (уже верно / заполнено без --force): {$skipped}\n");
        if ($missing > 0) {
            $this->stdout("Не найдены в accounts: {$missing}\n");
        }
        $this->stdout("\n");

        if (!$toUpdate) {
            $this->stdout("Нечего обновлять.\n");
            return ExitCode::OK;
        }

        foreach ($toUpdate as $u) {
            $oldStr = $u['old'] === null || $u['old'] === '' ? '(пусто)' : $u['old'];
            $this->stdout(sprintf("  #%d  %-40s  %s → %s\n", $u['id'], $u['name'], $oldStr, $u['new']));
        }
        $this->stdout("\n");

        if ($this->dryRun) {
            $this->stdout("DRY-RUN: изменения не сохранены.\n");
            return ExitCode::OK;
        }

        // Применяем обновления — группируем по новому значению и делаем массовый UPDATE
        $byLs = ['L' => [], 'S' => []];
        foreach ($toUpdate as $u) {
            $byLs[$u['new']][] = (int)$u['id'];
        }

        $transaction = $db->beginTransaction();
        try {
            $updated = 0;
            foreach ($byLs as $ls => $idList) {
                if (!$idList) continue;
                $n = $db->createCommand()->update(
                    'accounts',
                    ['account_type' => $ls],
                    ['id' => $idList]
                )->execute();
                $updated += $n;
            }
            $transaction->commit();

            $this->stdout("Готово. Обновлено счетов: {$updated}\n", \yii\helpers\Console::FG_GREEN);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr("Ошибка: " . $e->getMessage() . "\n", \yii\helpers\Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
