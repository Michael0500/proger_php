<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\services\MatchingService;
use app\models\MatchingRule;
use app\models\NostroEntry;
use app\models\User;

/**
 * JSON-контроллер квитования.
 *
 * Контроллер принимает запросы UI для ручного квитования, расквитования,
 * пошагового автоквитования и CRUD правил. Все операции ограничиваются
 * компанией текущего пользователя.
 */
class MatchingController extends BaseController
{
    /**
     * Отключает CSRF для JSON API квитования.
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
     * Создаёт сервис бизнес-логики квитования.
     *
     * @return MatchingService Новый экземпляр сервиса.
     */
    private function service(): MatchingService
    {
        return new MatchingService();
    }

    /**
     * Возвращает ID компании текущего пользователя.
     *
     * @return int|null ID компании или `null`, если компания не выбрана.
     */
    private function companyId(): ?int
    {
        $user = User::findOne(Yii::$app->user->id);
        return $user ? $user->company_id : null;
    }

    /**
     * Определяет секцию NRE/INV по коду компании текущего пользователя.
     *
     * @return string|null `NRE`, `INV` или `null`, если секцию определить нельзя.
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

    /**
     * Читает boolean-параметр из POST с поддержкой JSON bool и строк `0/1`.
     *
     * @param string $name Имя параметра.
     * @param bool $default Значение по умолчанию.
     * @return bool Нормализованное boolean-значение.
     */
    private function postBool(string $name, bool $default = false): bool
    {
        $value = Yii::$app->request->post($name, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    // ── Ручное квитование ─────────────────────────────────────────────

    /**
     * Выполняет ручное квитование выбранных записей.
     *
     * POST `/matching/match-manual`, body: `ids[]=...`, `section=INV|NRE`.
     *
     * @return array JSON-результат `MatchingService::matchManual()`.
     */
    public function actionMatchManual(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ids     = Yii::$app->request->post('ids');
        $section = Yii::$app->request->post('section') ?: $this->companySection();

        if (!is_array($ids) || count($ids) < 1) {
            return ['success' => false, 'message' => 'Выберите записи для квитования'];
        }
        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        // Если 1 запись — сервис проверит что сумма = 0, иначе отклонит
        if (count($ids) < 2) {
            // разрешаем пройти в сервис, он сам проверит amount = 0
        }

        return $this->service()->matchManual(array_map('intval', $ids), $section, $companyId);
    }

    /**
     * Расквитовывает группу записей по `match_id`.
     *
     * POST `/matching/unmatch`, body: `match_id=MTCH...`.
     *
     * @return array JSON-результат `MatchingService::unmatch()`.
     */
    public function actionUnmatch(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $matchId = Yii::$app->request->post('match_id');
        if (!$matchId) {
            return ['success' => false, 'message' => 'Не указан Match ID'];
        }
        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        return $this->service()->unmatch($matchId, $companyId);
    }

    /**
     * Считает суммы по выбранным записям перед ручным квитованием.
     *
     * POST `/matching/calc-summary`, body: `ids[]=...`.
     *
     * @return array JSON со сводкой или ошибкой.
     */
    public function actionCalcSummary(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ids = Yii::$app->request->post('ids');
        if (!is_array($ids)) {
            return ['success' => false, 'message' => 'Нет данных'];
        }
        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        $summary = $this->service()->calcSummary(array_map('intval', $ids), $companyId);
        return ['success' => true, 'data' => $summary];
    }

    // ── Автоквитование ────────────────────────────────────────────────

    /**
     * Запускает синхронное автоквитование.
     *
     * POST `/matching/auto-match`, body: `account_id` и `section` опциональны.
     * Метод сохранён для обратной совместимости; основной UI использует
     * пошаговый запуск с прогрессом.
     *
     * @return array JSON-результат автоквитования.
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
     * Инициализирует пошаговое автоквитование с прогрессом.
     *
     * POST `/matching/auto-match-start`. Принимает `account_id`, `section`,
     * `scope_type`, `scope_id` и возвращает `job_id` для последующих шагов.
     *
     * @return array JSON с параметрами созданного задания.
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
     * Выполняет следующий шаг пошагового автоквитования.
     *
     * POST `/matching/auto-match-step`, body: `job_id`.
     *
     * @return array JSON-прогресс задания.
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

    // ── Просмотр сквитованной группы ─────────────────────────────────

    /**
     * Возвращает записи одной группы квитования.
     *
     * GET `/matching/match-group?match_id=MTCH...`.
     *
     * @return array JSON со строками текущей компании и указанным `match_id`.
     */
    public function actionMatchGroup(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $matchId = Yii::$app->request->get('match_id');
        if (!$matchId) {
            return ['success' => false, 'message' => 'Не указан Match ID'];
        }

        $companyId = $this->companyId();
        $entries = NostroEntry::find()
            ->alias('e')
            ->select([
                'e.id', 'e.account_id', 'e.ls', 'e.dc', 'e.amount', 'e.currency',
                'e.value_date', 'e.post_date',
                'e.instruction_id', 'e.end_to_end_id', 'e.transaction_id', 'e.message_id',
                'e.match_id', 'e.match_status', 'e.comment',
                'a.name AS account_name',
            ])
            ->leftJoin('accounts a', 'a.id = e.account_id')
            ->where(['e.match_id' => $matchId, 'e.company_id' => $companyId])
            ->orderBy(['e.ls' => SORT_ASC, 'e.dc' => SORT_ASC, 'e.amount' => SORT_DESC])
            ->asArray()
            ->all();

        if (empty($entries)) {
            return ['success' => false, 'message' => 'Записи не найдены'];
        }

        return ['success' => true, 'data' => $entries, 'match_id' => $matchId];
    }

    // ── CRUD правил квитования ────────────────────────────────────────

    /**
     * Возвращает правила автоквитования текущей компании.
     *
     * GET `/matching/get-rules`.
     *
     * @return array JSON-список правил с человекочитаемыми подписями.
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
     * Создаёт или обновляет правило автоквитования.
     *
     * POST `/matching/save-rule`. Все сохраняемые правила принудительно
     * привязываются к компании текущего пользователя.
     *
     * @return array JSON-результат сохранения или ошибки валидации.
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
        $rule->match_dc             = $this->postBool('match_dc');
        $rule->match_amount         = $this->postBool('match_amount');
        $rule->match_value_date     = $this->postBool('match_value_date');
        $rule->match_instruction_id = $this->postBool('match_instruction_id');
        $rule->match_end_to_end_id  = $this->postBool('match_end_to_end_id');
        $rule->match_transaction_id = $this->postBool('match_transaction_id');
        $rule->match_message_id     = $this->postBool('match_message_id');
        $rule->cross_id_search      = $this->postBool('cross_id_search');
        $rule->is_active            = $this->postBool('is_active', true);
        $rule->priority             = (int) Yii::$app->request->post('priority', 100);
        $rule->description          = Yii::$app->request->post('description', '');

        if ($rule->save()) {
            return ['success' => true, 'message' => $id ? 'Правило обновлено' : 'Правило создано', 'id' => $rule->id];
        }

        return ['success' => false, 'errors' => $rule->errors];
    }

    /**
     * Удаляет правило автоквитования текущей компании.
     *
     * POST `/matching/delete-rule`, body: `id`.
     *
     * @return array JSON-результат удаления.
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
