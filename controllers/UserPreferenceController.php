<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\UserPreference;

/**
 * JSON API пользовательских настроек UI.
 *
 * Настройки хранятся в `user_preferences` как JSONB и доступны только для
 * текущего пользователя. Ключи проходят whitelist, чтобы API не превратился
 * в произвольное хранилище.
 */
class UserPreferenceController extends BaseController
{
    /**
     * Отключает CSRF и сразу выставляет JSON-формат ответа.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool Можно ли продолжать выполнение action.
     */
    public function beforeAction($action)
    {
        $this->enableCsrfValidation = false;
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    /** Разрешённые ключи настроек (whitelist). */
    private const ALLOWED_KEYS = [
        UserPreference::KEY_ENTRIES_TABLE_COLUMNS,
        UserPreference::KEY_BALANCE_TABLE_COLUMNS,
        UserPreference::KEY_ARCHIVE_TABLE_COLUMNS,
        UserPreference::KEY_BALANCE_ARCHIVE_TABLE_COLUMNS,
    ];

    /**
     * Возвращает ID текущего пользователя.
     *
     * @return int|null ID пользователя или `null`, если пользователь не авторизован.
     */
    private function userId(): ?int
    {
        $u = Yii::$app->user->identity;
        return $u ? (int)$u->id : null;
    }

    /**
     * Возвращает значение пользовательской настройки.
     *
     * GET `/user-preference/get?key=...`.
     *
     * @return array JSON с `value` или ошибкой whitelist/авторизации.
     */
    public function actionGet()
    {
        $uid = $this->userId();
        if (!$uid) return ['success' => false, 'message' => 'Не авторизован'];

        $key = (string) Yii::$app->request->get('key', '');
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            return ['success' => false, 'message' => 'Неизвестный ключ'];
        }

        $value = UserPreference::getValue($uid, $key, null);
        return ['success' => true, 'value' => $value];
    }

    /**
     * Сохраняет значение пользовательской настройки.
     *
     * POST `/user-preference/save`, body: `key`, `value`. Значение передаётся
     * в модель как PHP-структура, чтобы PostgreSQL JSONB не получил
     * double-encoded строку.
     *
     * @return array JSON-результат сохранения.
     */
    public function actionSave()
    {
        $uid = $this->userId();
        if (!$uid) return ['success' => false, 'message' => 'Не авторизован'];

        $key   = (string) Yii::$app->request->post('key', '');
        $value = Yii::$app->request->post('value', null);

        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            return ['success' => false, 'message' => 'Неизвестный ключ'];
        }
        if ($value === null) {
            return ['success' => false, 'message' => 'Пустое значение'];
        }

        UserPreference::setValue($uid, $key, $value);
        return ['success' => true];
    }
}
