<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\NostroEntryArchive;
use app\models\ArchiveSettings;
use app\models\Account;

/**
 * Контроллер архивирования.
 *
 * GET  /archive/list            — список архивных записей (поиск + фильтры)
 * POST /archive/run             — запустить архивирование вручную
 * POST /archive/restore         — восстановить запись из архива в nostro_entries
 * POST /archive/purge-expired   — удалить просроченные (истёк retention)
 * GET  /archive/settings        — получить настройки
 * POST /archive/settings        — сохранить настройки
 * GET  /archive/stats           — статистика (сколько в архиве, сколько ожидает)
 */
class ArchiveController extends BaseController
{
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    // ─────────────────────────────────────────────────────────────
    // GET /archive/list
    // ─────────────────────────────────────────────────────────────
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $r       = Yii::$app->request;
        $page    = max(1, (int)$r->get('page', 1));
        $limit   = min(200, max(10, (int)$r->get('limit', 50)));
        $sort    = $r->get('sort', 'archived_at');
        $dirRaw  = $r->get('dir', 'desc');
        $dir     = strtolower($dirRaw) === 'asc' ? SORT_ASC : SORT_DESC;
        $filters = json_decode($r->get('filters', '{}'), true) ?: [];

        $sortable = ['id', 'original_id', 'match_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'archived_at', 'expires_at', 'account_id'];
        if (!in_array($sort, $sortable, true)) $sort = 'archived_at';

        $q = NostroEntryArchive::find()
            ->from(['na' => NostroEntryArchive::tableName()])
            ->leftJoin(['a' => 'accounts'], 'a.id = na.account_id')
            ->where(['na.company_id' => $cid])
            ->addSelect(['na.*', 'a.name AS account_name']);

        // ── Фильтры ──────────────────────────────────────────────

        // Ностро банк / счёт
        if (!empty($filters['account_id'])) {
            $q->andWhere(['na.account_id' => (int)$filters['account_id']]);
        }
        if (!empty($filters['account_name'])) {
            $q->andWhere(['ilike', 'a.name', $filters['account_name']]);
        }

        // L/S
        if (!empty($filters['ls'])) {
            $q->andWhere(['na.ls' => $filters['ls']]);
        }

        // D/C
        if (!empty($filters['dc'])) {
            $q->andWhere(['na.dc' => $filters['dc']]);
        }

        // Валюта (одна или массив)
        if (!empty($filters['currency'])) {
            $q->andWhere(is_array($filters['currency'])
                ? ['na.currency' => $filters['currency']]
                : ['na.currency' => $filters['currency']]);
        }

        // Даты валютирования
        if (!empty($filters['value_date_from'])) {
            $q->andWhere(['>=', 'na.value_date', $filters['value_date_from']]);
        }
        if (!empty($filters['value_date_to'])) {
            $q->andWhere(['<=', 'na.value_date', $filters['value_date_to']]);
        }

        // Даты проводки
        if (!empty($filters['post_date_from'])) {
            $q->andWhere(['>=', 'na.post_date', $filters['post_date_from']]);
        }
        if (!empty($filters['post_date_to'])) {
            $q->andWhere(['<=', 'na.post_date', $filters['post_date_to']]);
        }

        // Даты архивирования
        if (!empty($filters['archived_at_from'])) {
            $q->andWhere(['>=', 'DATE(na.archived_at)', $filters['archived_at_from']]);
        }
        if (!empty($filters['archived_at_to'])) {
            $q->andWhere(['<=', 'DATE(na.archived_at)', $filters['archived_at_to']]);
        }

        // Сумма
        if (!empty($filters['amount_min'])) {
            $q->andWhere(['>=', 'na.amount', (float)$filters['amount_min']]);
        }
        if (!empty($filters['amount_max'])) {
            $q->andWhere(['<=', 'na.amount', (float)$filters['amount_max']]);
        }

        // Поиск по конкретному полю
        $searchField = $filters['search_field'] ?? '';
        $searchValue = trim($filters['search_value'] ?? '');
        $searchFields = [
            'match_id', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'other_id', 'comment',
        ];

        if ($searchValue !== '') {
            if ($searchField && in_array($searchField, $searchFields, true)) {
                // Поиск в конкретном поле
                $q->andWhere(['ilike', "na.{$searchField}", $searchValue]);
            } else {
                // Поиск по всем ID-полям
                $q->andWhere(['or',
                    ['ilike', 'na.match_id',        $searchValue],
                    ['ilike', 'na.instruction_id',  $searchValue],
                    ['ilike', 'na.end_to_end_id',   $searchValue],
                    ['ilike', 'na.transaction_id',  $searchValue],
                    ['ilike', 'na.message_id',       $searchValue],
                    ['ilike', 'na.other_id',         $searchValue],
                    ['ilike', 'na.comment',          $searchValue],
                ]);
            }
        }

        $total  = (int)(clone $q)->count('na.id');
        $offset = ($page - 1) * $limit;

        $rows = $q->orderBy(["na.{$sort}" => $dir])
            ->limit($limit)
            ->offset($offset)
            ->asArray()
            ->all();

        // Форматирование дат
        foreach ($rows as &$row) {
            if ($row['value_date'])   $row['value_date_fmt']   = date('d/m/Y', strtotime($row['value_date']));
            if ($row['post_date'])    $row['post_date_fmt']    = date('d/m/Y', strtotime($row['post_date']));
            if ($row['archived_at'])  $row['archived_at_fmt']  = date('d/m/Y H:i', strtotime($row['archived_at']));
            if ($row['expires_at'])   $row['expires_at_fmt']   = date('d/m/Y', strtotime($row['expires_at']));
        }
        unset($row);

        return [
            'success' => true,
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'pages'   => (int)ceil($total / $limit),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /archive/count
    // Сколько записей ожидает архивирования. Быстрый COUNT.
    // ─────────────────────────────────────────────────────────────
    public function actionCount(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $settings   = ArchiveSettings::getForCompany($cid);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$settings->archive_after_days} days"));

        $total = (int)NostroEntry::find()
            ->where(['company_id' => $cid, 'match_status' => NostroEntry::STATUS_MATCHED])
            ->andWhere(['is not', 'match_id', null])
            ->andWhere(['<', 'updated_at', $cutoffDate])
            ->count();

        return ['success' => true, 'total' => $total];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /archive/run-batch
    // Архивирует ОДНУ порцию (batchSize записей).
    // JS вызывает многократно пока is_finished = true.
    //
    // POST params:
    //   total_done — счётчик из предыдущего ответа (передаётся обратно)
    //   total_all  — исходное общее кол-во (для прогресс-бара)
    // ─────────────────────────────────────────────────────────────
    public function actionRunBatch(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $batchSize  = 300;
        $totalDone  = (int)Yii::$app->request->post('total_done', 0);
        $totalAll   = (int)Yii::$app->request->post('total_all',  0);

        $settings   = ArchiveSettings::getForCompany($cid);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$settings->archive_after_days} days"));
        $archivedAt = date('Y-m-d H:i:s');
        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$settings->retention_years} years"));
        $userId     = Yii::$app->user->id;
        $db         = Yii::$app->db;

        // Берём ровно $batchSize строк через чистый SQL — минимум памяти
        $rows = $db->createCommand("
            SELECT id, account_id, company_id, match_id, ls, dc, amount, currency,
                   value_date, post_date, instruction_id, end_to_end_id,
                   transaction_id, message_id, other_id, comment, source, created_at, updated_at
            FROM {{%nostro_entries}}
            WHERE company_id   = :cid
              AND match_status = 'M'
              AND match_id     IS NOT NULL
              AND updated_at   < :cutoff
            ORDER BY id ASC
            LIMIT :lim
        ", [':cid' => $cid, ':cutoff' => $cutoffDate, ':lim' => $batchSize])
            ->queryAll();

        if (empty($rows)) {
            return [
                'success'     => true,
                'archived'    => 0,
                'total_done'  => $totalDone,
                'total_all'   => $totalAll,
                'remaining'   => 0,
                'is_finished' => true,
                'message'     => "Готово! Заархивировано: {$totalDone}",
            ];
        }

        $archiveCols = [
            'original_id','account_id','company_id','match_id',
            'ls','dc','amount','currency','value_date','post_date',
            'instruction_id','end_to_end_id','transaction_id','message_id','other_id',
            'comment','source','match_status',
            'archived_at','expires_at','archived_by',
            'original_created_at','original_updated_at',
        ];

        $insertRows = [];
        $ids        = [];
        foreach ($rows as $r) {
            $insertRows[] = [
                $r['id'], $r['account_id'], $r['company_id'], $r['match_id'],
                $r['ls'], $r['dc'], $r['amount'], $r['currency'],
                $r['value_date'], $r['post_date'],
                $r['instruction_id'], $r['end_to_end_id'],
                $r['transaction_id'], $r['message_id'], $r['other_id'],
                $r['comment'], $r['source'],
                'A',
                $archivedAt, $expiresAt, $userId,
                $r['created_at'], $r['updated_at'],
            ];
            $ids[] = (int)$r['id'];
        }
        unset($rows); // сразу освобождаем

        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()
                ->batchInsert('{{%nostro_entries_archive}}', $archiveCols, $insertRows)
                ->execute();

            // PostgreSQL: DELETE WHERE id = ANY(ARRAY[...]) — один параметр, работает с любым кол-вом ID
            $db->createCommand(
                'DELETE FROM {{%nostro_entries}} WHERE id = ANY(:ids)',
                [':ids' => '{' . implode(',', $ids) . '}']
            )->execute();

            $transaction->commit();
        } catch (\Exception $ex) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка: ' . $ex->getMessage()];
        }

        unset($insertRows);

        $archived  = count($ids);
        $newDone   = $totalDone + $archived;

        // Быстрый COUNT — только цифра, без данных
        $remaining = (int)$db->createCommand("
            SELECT COUNT(*) FROM {{%nostro_entries}}
            WHERE company_id   = :cid
              AND match_status = 'M'
              AND match_id     IS NOT NULL
              AND updated_at   < :cutoff
        ", [':cid' => $cid, ':cutoff' => $cutoffDate])->queryScalar();

        $percent = $totalAll > 0 ? min(100, (int)round($newDone / $totalAll * 100)) : 0;

        return [
            'success'     => true,
            'archived'    => $archived,
            'total_done'  => $newDone,
            'total_all'   => $totalAll,
            'remaining'   => $remaining,
            'percent'     => $percent,
            'is_finished' => $remaining === 0,
            'message'     => $remaining === 0
                ? "Готово! Заархивировано: {$newDone}"
                : "Заархивировано: {$newDone} из {$totalAll}",
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /archive/restore
    // Восстановить запись из архива в nostro_entries
    // ─────────────────────────────────────────────────────────────
    public function actionRestore(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $id  = (int)Yii::$app->request->post('id');

        $archived = NostroEntryArchive::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$archived) return ['success' => false, 'message' => 'Архивная запись не найдена'];

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $entry               = new NostroEntry();
            $entry->account_id   = $archived->account_id;
            $entry->company_id   = $archived->company_id;
            $entry->match_id     = $archived->match_id;
            $entry->ls           = $archived->ls;
            $entry->dc           = $archived->dc;
            $entry->amount       = $archived->amount;
            $entry->currency     = $archived->currency;
            $entry->value_date   = $archived->value_date;
            $entry->post_date    = $archived->post_date;
            $entry->instruction_id = $archived->instruction_id;
            $entry->end_to_end_id  = $archived->end_to_end_id;
            $entry->transaction_id = $archived->transaction_id;
            $entry->message_id     = $archived->message_id;
            $entry->other_id       = $archived->other_id;
            $entry->comment        = $archived->comment;
            $entry->source         = $archived->source;
            $entry->match_status   = NostroEntry::STATUS_MATCHED;

            if (!$entry->save()) {
                $transaction->rollBack();
                return ['success' => false, 'message' => 'Ошибка восстановления', 'errors' => $entry->errors];
            }

            $archived->delete();
            $transaction->commit();

            return ['success' => true, 'message' => 'Запись восстановлена из архива'];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // POST /archive/purge-expired
    // Удалить просроченные архивные записи (retention истёк)
    // ─────────────────────────────────────────────────────────────
    public function actionPurgeExpired(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $now     = date('Y-m-d H:i:s');
        $deleted = NostroEntryArchive::deleteAll([
            'and',
            ['company_id' => $cid],
            ['<', 'expires_at', $now],
        ]);

        return ['success' => true, 'message' => "Удалено просроченных записей: {$deleted}", 'deleted' => $deleted];
    }

    // ─────────────────────────────────────────────────────────────
    // GET /archive/settings
    // ─────────────────────────────────────────────────────────────
    public function actionSettings(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $s = ArchiveSettings::getForCompany($cid);
        return ['success' => true, 'data' => $s->toApiArray()];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /archive/settings
    // ─────────────────────────────────────────────────────────────
    public function actionSaveSettings(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $s = ArchiveSettings::getForCompany($cid);
        $p = Yii::$app->request->post();

        $s->archive_after_days   = (int)($p['archive_after_days']   ?? $s->archive_after_days);
        $s->retention_years      = (int)($p['retention_years']       ?? $s->retention_years);
        $s->auto_archive_enabled = (bool)($p['auto_archive_enabled'] ?? $s->auto_archive_enabled);
        $s->updated_by           = Yii::$app->user->id;

        if (!$s->validate()) {
            return ['success' => false, 'message' => 'Ошибка валидации', 'errors' => $s->errors];
        }
        $s->save(false);

        return ['success' => true, 'message' => 'Настройки сохранены', 'data' => $s->toApiArray()];
    }

    // ─────────────────────────────────────────────────────────────
    // GET /archive/stats
    // ─────────────────────────────────────────────────────────────
    public function actionStats(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $settings   = ArchiveSettings::getForCompany($cid);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$settings->archive_after_days} days"));
        $now        = date('Y-m-d H:i:s');

        $totalArchived = (int)NostroEntryArchive::find()
            ->where(['company_id' => $cid])
            ->count();

        $expiredCount = (int)NostroEntryArchive::find()
            ->where(['company_id' => $cid])
            ->andWhere(['<', 'expires_at', $now])
            ->count();

        $pendingCount = (int)NostroEntry::find()
            ->where(['company_id' => $cid, 'match_status' => NostroEntry::STATUS_MATCHED])
            ->andWhere(['is not', 'match_id', null])
            ->andWhere(['<', 'updated_at', $cutoffDate])
            ->count();

        return [
            'success' => true,
            'data'    => [
                'total_archived'  => $totalArchived,
                'pending_archive' => $pendingCount,
                'expired_records' => $expiredCount,
                'settings'        => $settings->toApiArray(),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // GET /archive/accounts — список счетов (для фильтра)
    // ─────────────────────────────────────────────────────────────
    public function actionAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'data' => []];

        $rows = Account::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return [
            'success' => true,
            'data'    => array_map(fn($a) => [
                'id'       => $a->id,
                'name'     => $a->name,
                'currency' => $a->currency,
            ], $rows),
        ];
    }

    /**
     * GET /archive/history?id=
     * Получить историю изменений архивной записи (по original_id)
     */
    public function actionHistory(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $id  = (int)Yii::$app->request->get('id', 0);

        $archived = NostroEntryArchive::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$archived) {
            return ['success' => false, 'message' => 'Архивная запись не найдена'];
        }

        // История хранится по original_id
        $audits = \app\models\NostroEntryAudit::find()
            ->where(['entry_id' => $archived->original_id])
            ->orderBy(['created_at' => SORT_DESC])
            ->asArray()
            ->all();

        $rows = [];
        foreach ($audits as $audit) {
            $rows[] = [
                'id'            => $audit['id'],
                'action'        => $audit['action'],
                'user_id'       => $audit['user_id'],
                'old_values'    => $audit['old_values'] ? json_decode($audit['old_values'], true) : null,
                'new_values'    => $audit['new_values'] ? json_decode($audit['new_values'], true) : null,
                'changed_field' => $audit['changed_field'],
                'reason'        => $audit['reason'],
                'created_at'    => $audit['created_at'],
            ];
        }

        return ['success' => true, 'data' => $rows];
    }
}