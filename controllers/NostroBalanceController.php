<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use yii\web\UploadedFile;
use app\models\NostroBalance;
use app\models\NostroBalanceAudit;
use app\models\Account;
use app\components\parsers\BndCamtParser;
use app\components\parsers\AsbTextParser;

class NostroBalanceController extends BaseController
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
    // GET /nostro-balance/list
    // ─────────────────────────────────────────────────────────────
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $r       = Yii::$app->request;
        $page    = max(1, (int)$r->get('page', 1));
        $limit   = min(200, max(10, (int)$r->get('limit', 50)));
        $sort    = $r->get('sort', 'value_date');
        $dirRaw  = $r->get('dir', 'desc');
        $dir     = strtolower($dirRaw) === 'asc' ? SORT_ASC : SORT_DESC;
        $filters = json_decode($r->get('filters', '{}'), true) ?: [];

        $sortable = ['id', 'ls_type', 'statement_number', 'currency', 'value_date',
            'opening_balance', 'closing_balance', 'section', 'source', 'status'];
        if (!in_array($sort, $sortable, true)) $sort = 'value_date';

        $q = NostroBalance::find()
            ->from(['nb' => NostroBalance::tableName()])
            ->leftJoin(['a' => 'accounts'], 'a.id = nb.account_id')
            ->where(['nb.company_id' => $cid])
            ->addSelect(['nb.*', 'a.name AS account_name']);

        // Фильтры
        foreach (['ls_type', 'currency', 'section', 'source', 'status'] as $f) {
            if (!empty($filters[$f])) {
                // Поддержка массива (мультивыбор)
                $q->andWhere(is_array($filters[$f])
                    ? ['nb.' . $f => $filters[$f]]
                    : ['nb.' . $f => $filters[$f]]);
            }
        }
        if (!empty($filters['pool_id'])) {
            $q->andWhere(['a.pool_id' => (int)$filters['pool_id']]);
        }
        if (!empty($filters['account_id'])) {
            $q->andWhere(['nb.account_id' => (int)$filters['account_id']]);
        }
        if (!empty($filters['statement_number'])) {
            $q->andWhere(['ilike', 'nb.statement_number', $filters['statement_number']]);
        }
        if (!empty($filters['value_date_from'])) {
            $q->andWhere(['>=', 'nb.value_date', $filters['value_date_from']]);
        }
        if (!empty($filters['value_date_to'])) {
            $q->andWhere(['<=', 'nb.value_date', $filters['value_date_to']]);
        }

        $total  = (int)(clone $q)->count('nb.id');
        $offset = ($page - 1) * $limit;

        $rows = $q->orderBy(["nb.{$sort}" => $dir])
            ->limit($limit)
            ->offset($offset)
            ->asArray()
            ->all();

        // Форматируем даты для вывода
        foreach ($rows as &$row) {
            if ($row['value_date']) {
                $row['value_date_fmt'] = date('d/m/Y', strtotime($row['value_date']));
            }
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
    // POST /nostro-balance/create  — ручной ввод
    // ─────────────────────────────────────────────────────────────
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $p  = Yii::$app->request->post();
        $m  = new NostroBalance();
        $this->fillModel($m, $p, $cid);
        $m->source = NostroBalance::SOURCE_MANUAL;

        // Валидация бизнес-правил
        $settings = $this->getValidationSettings();
        $m->runValidations($settings);

        if (!$m->validate() || !$m->save(false)) {
            return ['success' => false, 'message' => 'Ошибка сохранения', 'errors' => $m->errors];
        }

        NostroBalanceAudit::log($m->id, NostroBalanceAudit::ACTION_IMPORT, null, $m->toApiArray(), 'Ручной ввод');

        return ['success' => true, 'message' => 'Запись создана', 'data' => $m->toApiArray()];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /nostro-balance/update
    // ─────────────────────────────────────────────────────────────
    public function actionUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id = (int)Yii::$app->request->post('id');
        $m  = NostroBalance::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];

        $oldValues = $m->toApiArray();
        $p = Yii::$app->request->post();
        $this->fillModel($m, $p, $cid);

        $settings = $this->getValidationSettings();
        $m->runValidations($settings);

        if (!$m->validate() || !$m->save(false)) {
            return ['success' => false, 'errors' => $m->errors];
        }

        NostroBalanceAudit::log($m->id, NostroBalanceAudit::ACTION_EDIT, $oldValues, $m->toApiArray(), $p['reason'] ?? null);

        return ['success' => true, 'message' => 'Запись обновлена', 'data' => $m->toApiArray()];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /nostro-balance/confirm  — подтвердить ошибочную запись
    // ─────────────────────────────────────────────────────────────
    public function actionConfirm(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id     = (int)Yii::$app->request->post('id');
        $reason = trim(Yii::$app->request->post('reason', ''));

        if (!$reason) {
            return ['success' => false, 'message' => 'Укажите причину корректировки'];
        }

        $m = NostroBalance::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];

        $oldValues   = $m->toApiArray();
        $m->status   = NostroBalance::STATUS_CONFIRMED;
        $m->comment  = mb_substr($reason, 0, 255);
        $m->save(false);

        NostroBalanceAudit::log($m->id, NostroBalanceAudit::ACTION_CONFIRM, $oldValues, $m->toApiArray(), $reason);

        return ['success' => true, 'message' => 'Запись подтверждена', 'data' => $m->toApiArray()];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /nostro-balance/delete
    // ─────────────────────────────────────────────────────────────
    public function actionDelete(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id = (int)Yii::$app->request->post('id');
        $m  = NostroBalance::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];

        $m->delete();
        return ['success' => true, 'message' => 'Запись удалена'];
    }

    // ─────────────────────────────────────────────────────────────
    // GET /nostro-balance/history?id=
    // ─────────────────────────────────────────────────────────────
    public function actionHistory(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id = (int)Yii::$app->request->get('id');
        $m  = NostroBalance::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];

        $audits = NostroBalanceAudit::find()
            ->where(['balance_id' => $id])
            ->orderBy(['created_at' => SORT_DESC])
            ->asArray()
            ->all();

        // Кэш пользователей
        $userIds = array_unique(array_filter(array_column($audits, 'user_id')));
        $users   = [];
        if (!empty($userIds)) {
            $userRows = \app\models\User::find()
                ->select(['id', 'username', 'email'])
                ->where(['id' => $userIds])
                ->asArray()
                ->all();
            foreach ($userRows as $u) {
                $users[$u['id']] = $u['username'] ?: $u['email'];
            }
        }

        $rows = [];
        foreach ($audits as $audit) {
            $rows[] = [
                'id'         => $audit['id'],
                'action'     => $audit['action'],
                'user_id'    => $audit['user_id'],
                'username'   => $users[$audit['user_id']] ?? ('User #' . $audit['user_id']),
                'old_values' => $audit['old_values'] ? json_decode($audit['old_values'], true) : null,
                'new_values' => $audit['new_values'] ? json_decode($audit['new_values'], true) : null,
                'reason'     => $audit['reason'],
                'created_at' => $audit['created_at'],
            ];
        }

        return ['success' => true, 'data' => $rows];
    }

    // ─────────────────────────────────────────────────────────────
    // POST /nostro-balance/import-bnd  — загрузка XML (БНД)
    // ─────────────────────────────────────────────────────────────
    public function actionImportBnd(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $accountId = (int)Yii::$app->request->post('account_id');
        $section   = Yii::$app->request->post('section', NostroBalance::SECTION_NRE);

        if (!$accountId) return ['success' => false, 'message' => 'Укажите счёт'];

        $file = UploadedFile::getInstanceByName('file');
        if (!$file) return ['success' => false, 'message' => 'Файл не выбран'];

        $tmpPath = Yii::getAlias('@runtime/uploads/') . uniqid('bnd_', true) . '.xml';
        @mkdir(dirname($tmpPath), 0777, true);

        if (!$file->saveAs($tmpPath)) {
            return ['success' => false, 'message' => 'Не удалось сохранить файл'];
        }

        $parser = new BndCamtParser();
        $rows   = $parser->parse($tmpPath, $accountId, $section);
        @unlink($tmpPath);

        return $this->saveImportRows($rows, $parser->getErrors(), $cid);
    }

    // ─────────────────────────────────────────────────────────────
    // POST /nostro-balance/import-asb  — загрузка текст (АСБ)
    // ─────────────────────────────────────────────────────────────
    public function actionImportAsb(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $accountId = (int)Yii::$app->request->post('account_id');
        $section   = Yii::$app->request->post('section', NostroBalance::SECTION_NRE);

        if (!$accountId) return ['success' => false, 'message' => 'Укажите счёт'];

        $file = UploadedFile::getInstanceByName('file');
        if (!$file) return ['success' => false, 'message' => 'Файл не выбран'];

        $tmpPath = Yii::getAlias('@runtime/uploads/') . uniqid('asb_', true) . '.txt';
        @mkdir(dirname($tmpPath), 0777, true);

        if (!$file->saveAs($tmpPath)) {
            return ['success' => false, 'message' => 'Не удалось сохранить файл'];
        }

        $parser = new AsbTextParser();
        $rows   = $parser->parse($tmpPath, $accountId, $section);
        @unlink($tmpPath);

        return $this->saveImportRows($rows, $parser->getErrors(), $cid);
    }

    // ─────────────────────────────────────────────────────────────
    // GET /nostro-balance/accounts  — список счетов для dropdown
    // ─────────────────────────────────────────────────────────────
    public function actionAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $accounts = Account::find()
            ->where(['company_id' => $cid])
            ->select(['id', 'name', 'pool_id'])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        // Список ностро-банков
        $pools = \app\models\AccountPool::find()
            ->where(['company_id' => $cid])
            ->select(['id', 'name'])
            ->orderBy(['name' => SORT_ASC])
            ->asArray()
            ->all();

        return ['success' => true, 'data' => $accounts, 'pools' => $pools];
    }

    // ─────────────────────────────────────────────────────────────
    // Приватные хелперы
    // ─────────────────────────────────────────────────────────────

    private function fillModel(NostroBalance $m, array $p, int $cid): void
    {
        $m->company_id        = $cid;
        $m->account_id        = (int)($p['account_id'] ?? 0);
        $m->ls_type           = $p['ls_type']           ?? NostroBalance::LS_LEDGER;
        $m->statement_number  = ($p['statement_number'] ?? '') ?: null;
        $m->currency          = strtoupper($p['currency'] ?? 'RUB');
        $m->value_date        = $p['value_date']         ?? null;
        $m->opening_balance   = (float)str_replace(',', '.', $p['opening_balance'] ?? '0');
        $m->opening_dc        = $p['opening_dc']         ?? NostroBalance::DC_CREDIT;
        $m->closing_balance   = (float)str_replace(',', '.', $p['closing_balance'] ?? '0');
        $m->closing_dc        = $p['closing_dc']         ?? NostroBalance::DC_CREDIT;
        $m->section           = $p['section']            ?? NostroBalance::SECTION_NRE;
        $m->comment           = ($p['comment']           ?? '') ?: null;
    }

    private function saveImportRows(array $rows, array $parseErrors, int $cid): array
    {
        $settings = $this->getValidationSettings();
        $saved    = 0;
        $errors   = 0;
        $dbErrors = [];

        foreach ($rows as $rowData) {
            $m             = new NostroBalance();
            $m->company_id = $cid;

            // Убираем служебное поле
            unset($rowData['_acct_string']);

            foreach ($rowData as $key => $val) {
                if ($m->hasAttribute($key)) {
                    $m->$key = $val;
                }
            }

            $m->runValidations($settings);

            if ($m->save(false)) {
                NostroBalanceAudit::log($m->id, NostroBalanceAudit::ACTION_IMPORT, null, $m->toApiArray(), 'Импорт из файла');
                $saved++;
                if ($m->status === NostroBalance::STATUS_ERROR) {
                    $errors++;
                }
            } else {
                $dbErrors[] = $m->errors;
            }
        }

        return [
            'success'      => true,
            'saved'        => $saved,
            'errors'       => $errors,
            'parse_errors' => $parseErrors,
            'db_errors'    => $dbErrors,
            'message'      => "Импортировано: {$saved}, с ошибками валидации: {$errors}",
        ];
    }

    /**
     * Настройки валидации из params или defaults
     */
    private function getValidationSettings(): array
    {
        $params = Yii::$app->params;
        return [
            'enable_sequence_check' => $params['validation']['enable_sequence_check'] ?? true,
            'enable_balance_check'  => $params['validation']['enable_balance_check']  ?? true,
            'balance_tolerance'     => $params['validation']['balance_tolerance']     ?? 0.01,
        ];
    }
}