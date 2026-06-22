<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use yii\web\UploadedFile;
use app\models\NostroBalance;
use app\models\NostroBalanceAudit;
use app\models\NostroEntry;
use app\models\NostroEntryAudit;
use app\models\Account;
use app\components\parsers\BndCamtParser;
use app\components\parsers\AsbTextParser;

/**
 * JSON-контроллер страницы балансов Ностро.
 *
 * Управляет ручным вводом, импортом BND/ASB, подтверждением ошибок,
 * историей аудита и списком остатков. Все действия с данными выполняются
 * только в компании текущего пользователя.
 */
class NostroBalanceController extends BaseController
{
    /**
     * Отключает CSRF для API балансов.
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
     * Рендерит страницу баланса по всем ностро-банкам (без сайдбара, с фильтром Select2).
     *
     * @return string HTML страницы `views/nostro-balance/page.php`.
     */
    public function actionPage()
    {
        $this->view->title = 'Баланс по всем ностро-банкам';
        return $this->render('page');
    }

    /**
     * Рендерит главную страницу баланса с сайдбаром категорий и ностро-банков.
     *
     * GET `/balance`. Выбор ностро-банка в сайдбаре фильтрует таблицу
     * `nostro_balance` по `accounts.pool_id`. Без выбранного банка показывает
     * пустое состояние.
     *
     * @return string|\yii\web\Response HTML-страница или redirect на выбор компании.
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию.');
            return $this->redirect(['/site/index']);
        }
        $this->view->title = 'Баланс';
        return $this->render('index');
    }

    /**
     * Возвращает ID компании текущего пользователя.
     *
     * @return int|null ID компании или `null`.
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Проверяет принадлежность счёта текущей компании.
     *
     * @param int $accountId ID счёта.
     * @param int $cid ID компании.
     * @return bool Счёт существует в компании.
     */
    private function accountBelongsToCompany(int $accountId, int $cid): bool
    {
        return Account::find()->where(['id' => $accountId, 'company_id' => $cid])->exists();
    }

    /**
     * Возвращает постраничный список балансовых записей.
     *
     * GET `/nostro-balance/list`. Поддерживает фильтры по L/S, валюте,
     * разделу, источнику, статусу, ностро-банку, счёту, номеру выписки и датам.
     *
     * @return array JSON с данными, total, page, limit и pages.
     */
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
            ->leftJoin(['ap' => 'account_pools'], 'ap.id = a.pool_id')
            ->where(['nb.company_id' => $cid])
            ->addSelect(['nb.*', 'a.name AS account_name', 'ap.name AS pool_name']);

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
                $row['value_date_fmt'] = date('d.m.Y', strtotime($row['value_date']));
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

    /**
     * Создаёт балансовую запись вручную.
     *
     * POST `/nostro-balance/create`. Перед сохранением запускает проверки
     * качества баланса и пишет аудит с причиной "Ручной ввод".
     *
     * @return array JSON с созданной записью или ошибками валидации.
     */
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $p  = Yii::$app->request->post();
        $accountId = (int)($p['account_id'] ?? 0);
        if (!$accountId || !$this->accountBelongsToCompany($accountId, $cid)) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

        $m  = new NostroBalance();
        $this->fillModel($m, $p, $cid);
        $m->source = NostroBalance::SOURCE_MANUAL;

        // Валидация бизнес-правил
        $settings = $this->getValidationSettings();
        $m->runValidations($settings);

        if (!$m->validate() || !$m->save(false)) {
            return ['success' => false, 'message' => $this->firstModelError($m->errors) ?: 'Ошибка сохранения', 'errors' => $m->errors];
        }

        NostroBalanceAudit::log($m->id, NostroBalanceAudit::ACTION_IMPORT, null, $m->toApiArray(), 'Ручной ввод');

        return ['success' => true, 'message' => 'Запись создана', 'data' => $m->toApiArray()];
    }

    /**
     * Обновляет балансовую запись.
     *
     * POST `/nostro-balance/update`. Сохраняет старый снимок, запускает
     * проверки качества и пишет событие аудита `edit`.
     *
     * @return array JSON с обновлённой записью или ошибкой.
     */
    public function actionUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();

        $id = (int)Yii::$app->request->post('id');
        $m  = NostroBalance::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];

        $oldValues = $m->toApiArray();
        $p = Yii::$app->request->post();
        $accountId = (int)($p['account_id'] ?? $m->account_id);
        if (!$accountId || !$this->accountBelongsToCompany($accountId, $cid)) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

        $this->fillModel($m, $p, $cid);

        $settings = $this->getValidationSettings();
        $m->runValidations($settings);

        if (!$m->validate() || !$m->save(false)) {
            return ['success' => false, 'message' => $this->firstModelError($m->errors) ?: 'Ошибка сохранения', 'errors' => $m->errors];
        }

        NostroBalanceAudit::log($m->id, NostroBalanceAudit::ACTION_EDIT, $oldValues, $m->toApiArray(), $p['reason'] ?? null);

        return ['success' => true, 'message' => 'Запись обновлена', 'data' => $m->toApiArray()];
    }

    /**
     * Подтверждает ошибочную балансовую запись вручную.
     *
     * POST `/nostro-balance/confirm`. Требует причину подтверждения,
     * переводит статус в `confirmed` и пишет аудит `confirm`.
     *
     * @return array JSON-результат подтверждения.
     */
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

    /**
     * Удаляет балансовую запись текущей компании.
     *
     * @return array JSON-результат удаления.
     */
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

    /**
     * Возвращает историю аудита балансовой записи.
     *
     * GET `/nostro-balance/history?id=`.
     *
     * @return array JSON со списком событий аудита и пользователями.
     */
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

    /**
     * Импортирует балансы из XML-файла БНД/camt.
     *
     * POST `/nostro-balance/import-bnd`. Сохраняет upload во временный файл
     * `runtime/uploads`, парсит его и удаляет после обработки.
     *
     * @return array JSON-итог импорта.
     */
    public function actionImportBnd(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $accountId = (int)Yii::$app->request->post('account_id');
        $section   = Yii::$app->request->post('section', NostroBalance::SECTION_NRE);

        if (!$accountId) return ['success' => false, 'message' => 'Укажите счёт'];
        if (!$this->accountBelongsToCompany($accountId, $cid)) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

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

        return $this->saveImportRows($rows, $parser->getErrors(), $cid, $parser->getEntryRows());
    }

    /**
     * Импортирует балансы из текстового файла АСБ.
     *
     * POST `/nostro-balance/import-asb`. Сохраняет upload во временный файл,
     * парсит его и удаляет после обработки.
     *
     * @return array JSON-итог импорта.
     */
    public function actionImportAsb(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $accountId = (int)Yii::$app->request->post('account_id');
        $section   = Yii::$app->request->post('section', NostroBalance::SECTION_NRE);

        if (!$accountId) return ['success' => false, 'message' => 'Укажите счёт'];
        if (!$this->accountBelongsToCompany($accountId, $cid)) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

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

        return $this->saveImportRows($rows, $parser->getErrors(), $cid, $parser->getEntryRows());
    }

    /**
     * Возвращает счета и ностро-банки для выпадающих списков баланса.
     *
     * @return array JSON со списком счетов и пулов текущей компании.
     */
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

    /**
     * Заполняет модель баланса данными запроса.
     *
     * Метод нормализует денежные значения как строки и не сохраняет модель.
     *
     * @param NostroBalance $m Заполняемая модель.
     * @param array $p Данные POST.
     * @param int $cid ID компании.
     * @return void
     */
    private function fillModel(NostroBalance $m, array $p, int $cid): void
    {
        $m->company_id        = $cid;
        $m->account_id        = (int)($p['account_id'] ?? 0);
        $m->ls_type           = $p['ls_type']           ?? NostroBalance::LS_LEDGER;
        $m->statement_number  = ($p['statement_number'] ?? '') ?: null;
        $m->currency          = strtoupper(trim($p['currency'] ?? ($m->currency ?: '')));
        $m->value_date        = $p['value_date']         ?? null;
        $m->opening_balance   = $this->normalizeDecimalInput($p['opening_balance'] ?? '0');
        $m->opening_dc        = $p['opening_dc']         ?? NostroBalance::DC_CREDIT;
        $m->closing_balance   = $this->normalizeDecimalInput($p['closing_balance'] ?? '0');
        $m->closing_dc        = $p['closing_dc']         ?? NostroBalance::DC_CREDIT;
        $m->section           = $p['section']            ?? NostroBalance::SECTION_NRE;
        $m->comment           = ($p['comment']           ?? '') ?: null;
    }

    /**
     * Нормализует пользовательский ввод балансовой суммы.
     *
     * Поддерживает знак минуса, пробелы, запятую как десятичный разделитель
     * и разделители тысяч без приведения к `float`.
     *
     * @param mixed $value Исходное значение из request или парсера.
     * @return string Нормализованная decimal-строка.
     */
    private function normalizeDecimalInput($value): string
    {
        $s = trim((string)$value);
        if ($s === '') {
            return '';
        }

        $sign = '';
        if (strpos($s, '-') === 0) {
            $sign = '-';
            $s = substr($s, 1);
        }

        $s = preg_replace('/\s+/u', '', $s);
        $hasDot = strpos($s, '.') !== false;
        $hasComma = strpos($s, ',') !== false;

        if ($hasDot && $hasComma) {
            $lastDot = strrpos($s, '.');
            $lastComma = strrpos($s, ',');
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $pos = strrpos($s, ',');
                $s = str_replace(',', '', substr($s, 0, $pos)) . '.' . substr($s, $pos + 1);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            $commaCount = substr_count($s, ',');
            $afterLast = substr($s, strrpos($s, ',') + 1);
            if ($commaCount === 1 && strlen($afterLast) <= 2) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        }

        if (strpos($s, '.') === 0) {
            $s = '0' . $s;
        }

        return $sign . $s;
    }

    /**
     * Возвращает первую ошибку валидации модели.
     *
     * @param array $errors Массив ошибок Yii `Model::$errors`.
     * @return string|null Текст первой ошибки или `null`.
     */
    private function firstModelError(array $errors): ?string
    {
        foreach ($errors as $messages) {
            if (!empty($messages[0])) {
                return $messages[0];
            }
        }
        return null;
    }

    /**
     * Сохраняет строки, полученные из файлового импорта.
     *
     * Для каждой строки создаётся `NostroBalance`, запускаются проверки
     * качества, сохраняется запись и пишется аудит `import`.
     *
     * @param array $rows Нормализованные строки балансов парсера.
     * @param array $parseErrors Ошибки парсинга, которые нужно вернуть в UI.
     * @param int $cid ID компании.
     * @param array $entryRows Нормализованные строки выверки парсера.
     * @return array JSON-совместимый итог импорта.
     */
    private function saveImportRows(array $rows, array $parseErrors, int $cid, array $entryRows = []): array
    {
        $settings = $this->getValidationSettings();
        $saved       = 0;
        $entrySaved  = 0;
        $errors      = 0;
        $dbErrors    = [];
        $transaction = Yii::$app->db->beginTransaction();

        try {
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
                    $dbErrors[] = ['balance' => $m->errors];
                }
            }

            foreach ($entryRows as $rowData) {
                $entry             = new NostroEntry();
                $entry->company_id = $cid;
                $entry->skipAudit  = true;

                foreach ($rowData as $key => $val) {
                    if ($entry->hasAttribute($key)) {
                        $entry->$key = $val;
                    }
                }

                if (!$entry->validate()) {
                    $dbErrors[] = ['entry' => $entry->errors];
                    continue;
                }

                if ($entry->save(false)) {
                    NostroEntryAudit::log(
                        $entry->id,
                        NostroEntryAudit::ACTION_CREATE,
                        null,
                        $entry->getAttributes(),
                        null,
                        null,
                        'Импорт из файла'
                    );
                    $entrySaved++;
                } else {
                    $dbErrors[] = ['entry' => $entry->errors];
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Yii::error('Balance file import failed: ' . $e->getMessage(), __METHOD__);

            return [
                'success'      => false,
                'saved'        => 0,
                'entries_saved' => 0,
                'errors'       => 0,
                'parse_errors' => $parseErrors,
                'db_errors'    => [['exception' => $e->getMessage()]],
                'message'      => 'Ошибка импорта файла',
            ];
        }

        $message = "Импортировано: {$saved}, с ошибками валидации: {$errors}";
        if (!empty($entryRows)) {
            $message = "Импортировано балансов: {$saved}, строк выверки: {$entrySaved}, балансов с ошибками валидации: {$errors}";
        }

        return [
            'success'      => true,
            'saved'        => $saved,
            'entries_saved' => $entrySaved,
            'errors'       => $errors,
            'parse_errors' => $parseErrors,
            'db_errors'    => $dbErrors,
            'message'      => $message,
        ];
    }

    /**
     * Возвращает настройки проверок баланса.
     *
     * Читает `Yii::$app->params['validation']`, а при отсутствии параметров
     * использует дефолтные значения.
     *
     * @return array Настройки `enable_sequence_check`, `enable_balance_check`, `balance_tolerance`.
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
