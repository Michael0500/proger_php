<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\UserPreference;

/**
 * JSON API для хранения пользовательских настроек UI (таблица user_preferences).
 *
 * Actions:
 *   GET  /user-preference/get?key=...          → { success, value }
 *   POST /user-preference/save  { key, value } → { success }
 */
class UserPreferenceController extends BaseController
{
    public function beforeAction($action)
    {
        $this->enableCsrfValidation = false;
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    /** Разрешённые ключи настроек (whitelist). */
    private const ALLOWED_KEYS = [
        UserPreference::KEY_ENTRIES_TABLE_COLUMNS,
    ];

    private function userId(): ?int
    {
        $u = Yii::$app->user->identity;
        return $u ? (int)$u->id : null;
    }

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
