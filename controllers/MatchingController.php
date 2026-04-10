<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\services\MatchingService;
use app\models\MatchingRule;
use app\models\NostroEntry;
use app\models\User;

/**
 * Контроллер квитования.
 * Все методы возвращают JSON.
 */
class MatchingController extends BaseController
{
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    private function service(): MatchingService
    {
        return new MatchingService();
    }

    private function companyId(): ?int
    {
        $user = User::findOne(Yii::$app->user->id);
        return $user ? $user->company_id : null;
    }

    /**
     * Определить секцию (NRE/INV) по коду компании текущего пользователя.
     */
    private function companySection(): ?string
    {
        $user = User::findOne(Yii::$app->user->id);
        if (!$user || !$user->company_id) {
            return null;
        }
        $company = \app\models\Company::findOne($user->company_id);
        if (!$company) {
            return null;
        }
        $code = strtoupper($company->code);
        return in_array($code, ['NRE', 'INV']) ? $code : null;
    }

    // ── Ручное квитование ─────────────────────────────────────────────

    /**
     * POST /matching/match-manual
     * body: ids[]=1&ids[]=2&...  section=INV|NRE (опционально)
     */
    public function actionMatchManual(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ids     = Yii::$app->request->post('ids');
        $section = Yii::$app->request->post('section') ?: $this->companySection();

        if (!is_array($ids) || count($ids) < 1) {
            return ['success' => false, 'message' => 'Выберите записи для квитования'];
        }

        // Если 1 запись — сервис проверит что сумма = 0, иначе отклонит
        if (count($ids) < 2) {
            // разрешаем пройти в сервис, он сам проверит amount = 0
        }

        return $this->service()->matchManual(array_map('intval', $ids), $section);
    }

    /**
     * POST /matching/unmatch
     * body: match_id=MTCHxxxxxxxx
     */
    public function actionUnmatch(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $matchId = Yii::$app->request->post('match_id');
        if (!$matchId) {
            return ['success' => false, 'message' => 'Не указан Match ID'];
        }

        return $this->service()->unmatch($matchId);
    }

    /**
     * POST /matching/calc-summary
     * body: ids[]=1&ids[]=2
     * Подсчёт сумм для выбранных записей (перед квитованием)
     */
    public function actionCalcSummary(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ids = Yii::$app->request->post('ids');
        if (!is_array($ids)) {
            return ['success' => false, 'message' => 'Нет данных'];
        }

        $summary = $this->service()->calcSummary(array_map('intval', $ids));
        return ['success' => true, 'data' => $summary];
    }

    // ── Автоквитование ────────────────────────────────────────────────

    /**
     * POST /matching/auto-match
     * body: account_id=X (опционально)
     * Синхронный запуск (обратная совместимость).
     */
    public function actionAutoMatch(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        $accountId = Yii::$app->request->post('account_id')
            ? (int) Yii::$app->request->post('account_id')
            : null;

        $section = Yii::$app->request->post('section') ?: $this->companySection();

        return $this->service()->autoMatch($companyId, $accountId, null, $section);
    }

    /**
     * POST /matching/auto-match-start
     * Инициализация пошагового автоквитования с прогрессом.
     * body: account_id=X (опционально), section=NRE|INV (опционально, по умолчанию из компании)
     * Возвращает job_id и количество правил.
     */
    public function actionAutoMatchStart(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        $accountId = Yii::$app->request->post('account_id')
            ? (int) Yii::$app->request->post('account_id')
            : null;

        $section   = Yii::$app->request->post('section') ?: $this->companySection();
        $scopeType = Yii::$app->request->post('scope_type') ?: 'all';
        $scopeId   = Yii::$app->request->post('scope_id') ? (int) Yii::$app->request->post('scope_id') : null;

        return $this->service()->autoMatchStart($companyId, $accountId, $section, $scopeType, $scopeId);
    }

    /**
     * POST /matching/auto-match-step
     * Выполнить следующее правило автоквитования.
     * body: job_id=xxx
     */
    public function actionAutoMatchStep(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $jobId = Yii::$app->request->post('job_id');
        if (!$jobId) {
            return ['success' => false, 'message' => 'Не указан job_id'];
        }

        return $this->service()->autoMatchStep($jobId);
    }

    // ── CRUD правил квитования ────────────────────────────────────────

    /**
     * GET /matching/get-rules
     */
    public function actionGetRules(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        $rules = MatchingRule::find()
            ->where(['company_id' => $companyId])
            ->orderBy(['priority' => SORT_ASC, 'section' => SORT_ASC])
            ->all();

        $data = array_map(function (MatchingRule $r) {
            return [
                'id'                   => $r->id,
                'name'                 => $r->name,
                'section'              => $r->section,
                'pair_type'            => $r->pair_type,
                'pair_type_label'      => MatchingRule::pairTypeList()[$r->pair_type] ?? $r->pair_type,
                'match_dc'             => (bool) $r->match_dc,
                'match_amount'         => (bool) $r->match_amount,
                'match_value_date'     => (bool) $r->match_value_date,
                'match_instruction_id' => (bool) $r->match_instruction_id,
                'match_end_to_end_id'  => (bool) $r->match_end_to_end_id,
                'match_transaction_id' => (bool) $r->match_transaction_id,
                'match_message_id'     => (bool) $r->match_message_id,
                'cross_id_search'      => (bool) $r->cross_id_search,
                'is_active'            => (bool) $r->is_active,
                'priority'             => (int) $r->priority,
                'description'          => $r->description,
                'conditions_summary'   => $r->getConditionsSummary(),
            ];
        }, $rules);

        return ['success' => true, 'data' => $data];
    }

    /**
     * POST /matching/save-rule
     * Создать или обновить правило
     */
    public function actionSaveRule(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        $id = (int) Yii::$app->request->post('id');

        $rule = $id ? MatchingRule::findOne(['id' => $id, 'company_id' => $companyId]) : new MatchingRule();
        if (!$rule) {
            return ['success' => false, 'message' => 'Правило не найдено'];
        }

        $rule->company_id           = $companyId;
        $rule->name                 = Yii::$app->request->post('name');
        $rule->section              = Yii::$app->request->post('section');
        $rule->pair_type            = Yii::$app->request->post('pair_type', 'LS');
        $rule->match_dc             = (bool) Yii::$app->request->post('match_dc');
        $rule->match_amount         = (bool) Yii::$app->request->post('match_amount');
        $rule->match_value_date     = (bool) Yii::$app->request->post('match_value_date');
        $rule->match_instruction_id = (bool) Yii::$app->request->post('match_instruction_id');
        $rule->match_end_to_end_id  = (bool) Yii::$app->request->post('match_end_to_end_id');
        $rule->match_transaction_id = (bool) Yii::$app->request->post('match_transaction_id');
        $rule->match_message_id     = (bool) Yii::$app->request->post('match_message_id');
        $rule->cross_id_search      = (bool) Yii::$app->request->post('cross_id_search');
        $rule->is_active            = (bool) Yii::$app->request->post('is_active', true);
        $rule->priority             = (int) Yii::$app->request->post('priority', 100);
        $rule->description          = Yii::$app->request->post('description', '');

        if ($rule->save()) {
            return ['success' => true, 'message' => $id ? 'Правило обновлено' : 'Правило создано', 'id' => $rule->id];
        }

        return ['success' => false, 'errors' => $rule->errors];
    }

    /**
     * POST /matching/delete-rule
     */
    public function actionDeleteRule(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        $id        = (int) Yii::$app->request->post('id');
        $rule      = MatchingRule::findOne(['id' => $id, 'company_id' => $companyId]);

        if (!$rule) {
            return ['success' => false, 'message' => 'Правило не найдено'];
        }

        $rule->delete();
        return ['success' => true, 'message' => 'Правило удалено'];
    }
}