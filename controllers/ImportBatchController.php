<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\services\ImportRollbackService;

/**
 * Standalone-страница `/imports` — пачки импорта выписок и их откат.
 *
 * Показывает строки `tds_status` (FCC12 / CAMT053 / MT950 / ED211 / ED743 / DWH
 * и ручные загрузки ASB / БНД), их состояние и доступность отката. Откат удаляет
 * вставленные пачкой строки `nostro_entries` / `nostro_balance` вместе с аудитом,
 * если в пачке ещё нет сквитованных или заархивированных записей.
 *
 * Логика отката — в {@see ImportRollbackService}.
 */
class ImportBatchController extends BaseController
{
    /** Человекочитаемые названия типов пачек. */
    const TYPE_LABELS = [
        'FCC12'   => 'FCC12',
        'CAMT053' => 'camt.053',
        'MT950'   => 'MT950',
        'ED211'   => 'ED211',
        'ED743'   => 'ED743',
        'DWH'     => 'DWH (suspend posting)',
        'ASB'     => 'Банк-клиент АСБ',
        'BND'     => 'Банк-клиент БНД',
    ];

    /**
     * Отключает CSRF для JSON API.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool Можно ли продолжать выполнение action.
     */
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * ID компании текущего пользователя.
     *
     * @return int|null
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Рендерит страницу пачек импорта.
     *
     * @return string|\yii\web\Response
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        $this->view->title = 'Импорт выписок — SmartMatch';

        // Без компании layout сам покажет заглушку «Компания не выбрана».
        $batches = $cid ? $this->buildBatchList($cid) : [];
        return $this->render('index', ['initData' => ['batches' => $batches]]);
    }

    /**
     * Возвращает актуальный список пачек (для обновления после отката).
     *
     * GET `/import-batch/list`.
     *
     * @return array
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        return ['success' => true, 'data' => $this->buildBatchList($cid)];
    }

    /**
     * Откатывает пачку импорта.
     *
     * POST `/import-batch/rollback` с параметром `id`.
     *
     * @return array
     */
    public function actionRollback(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $id = (int)Yii::$app->request->post('id');
        if (!$id) return ['success' => false, 'message' => 'Не указана пачка'];

        // Проверяем принадлежность пачки компании пользователя.
        $batch = Yii::$app->db->createCommand(
            "SELECT * FROM {{%tds_status}} WHERE id = :id",
            [':id' => $id]
        )->queryOne();
        if (!$batch || $this->effectiveCompanyId($batch) !== $cid) {
            return ['success' => false, 'message' => 'Пачка не найдена'];
        }

        $result = (new ImportRollbackService())->rollback($id, (int)Yii::$app->user->id);
        if ($result['success']) {
            $result['data'] = $this->buildBatchList($cid);
        }
        return $result;
    }

    /**
     * Собирает список пачек компании с живыми счётчиками и доступностью отката.
     *
     * @param int $cid ID компании.
     * @return array
     */
    private function buildBatchList(int $cid): array
    {
        $db = Yii::$app->db;

        $rows = $db->createCommand(
            "SELECT t.*, a.name AS account_name
               FROM {{%tds_status}} t
               LEFT JOIN {{%accounts}} a ON a.id = t.account_id
              ORDER BY t.id DESC"
        )->queryAll();

        // Живые счётчики по batch_id одним проходом.
        $entryStats = $this->indexBy($db->createCommand(
            "SELECT batch_id,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE match_id IS NOT NULL OR match_status IN ('M','I')) AS matched
               FROM {{%nostro_entries}}
              WHERE batch_id IS NOT NULL
              GROUP BY batch_id"
        )->queryAll(), 'batch_id');

        $balanceStats = $this->indexBy($db->createCommand(
            "SELECT batch_id, COUNT(*) AS total
               FROM {{%nostro_balance}}
              WHERE batch_id IS NOT NULL
              GROUP BY batch_id"
        )->queryAll(), 'batch_id');

        $archiveStats = $this->indexBy($db->createCommand(
            "SELECT batch_id, COUNT(*) AS total
               FROM {{%nostro_entries_archive}}
              WHERE batch_id IS NOT NULL
              GROUP BY batch_id"
        )->queryAll(), 'batch_id');

        $service = new ImportRollbackService();
        $result = [];

        foreach ($rows as $row) {
            if ($this->effectiveCompanyId($row) !== $cid) {
                continue;
            }

            $id = (int)$row['id'];
            $type = (string)$row['type'];
            $check = $service->canRollback($row);

            $result[] = [
                'id'              => $id,
                'type'            => $type,
                'type_label'      => self::TYPE_LABELS[$type] ?? $type,
                'date_time'       => $row['date_time'],
                'account_name'    => $row['account_name'],
                'source_label'    => $row['source_label'],
                'is_merged'       => (bool)$row['is_merged'],
                'is_rolled_back'  => (bool)$row['is_rolled_back'],
                'rolled_back_at'  => $row['rolled_back_at'],
                'imported_entries'  => $row['entries_count'] !== null ? (int)$row['entries_count'] : null,
                'imported_balances' => $row['balances_count'] !== null ? (int)$row['balances_count'] : null,
                'live_entries'    => (int)($entryStats[$id]['total'] ?? 0),
                'live_balances'   => (int)($balanceStats[$id]['total'] ?? 0),
                'matched'         => (int)($entryStats[$id]['matched'] ?? 0),
                'archived'        => (int)($archiveStats[$id]['total'] ?? 0),
                'can_rollback'    => $check['ok'],
                'reason'          => $check['reason'],
            ];
        }

        return $result;
    }

    /**
     * Эффективная компания пачки.
     *
     * Для ручных загрузок и новых merge-пачек берётся из `company_id`.
     * Для legacy-строк без `company_id` компания выводится по типу
     * (DWH → 2, остальные → 1), как в merge-командах.
     *
     * @param array $row Строка tds_status.
     * @return int
     */
    private function effectiveCompanyId(array $row): int
    {
        if ($row['company_id'] !== null && $row['company_id'] !== '') {
            return (int)$row['company_id'];
        }
        return ((string)$row['type'] === 'DWH') ? 2 : 1;
    }

    /**
     * Индексирует массив строк по значению колонки.
     *
     * @param array $rows Строки.
     * @param string $key Имя колонки-ключа.
     * @return array Карта `value => row`.
     */
    private function indexBy(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row[$key]] = $row;
        }
        return $map;
    }
}
