<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * Публичный приёмник callback от СЦР (Цифровой рубль).
 *
 * СЦР асинхронно отправляет сюда тело FIWalletInfo после запроса
 * (см. commands/PcrController::actionRequest). Эндпоинт НЕ требует
 * пользовательской сессии — авторизация через собственный Basic Auth
 * (params.pcr.callbackAuth). Маршрут задан в config/web.php urlManager
 * (POST /api/v4/fi/callback/wallet/FIWalletInfo) и добавлен в whitelist
 * app\components\AccessControl.
 */
class PcrCallbackController extends Controller
{
    /**
     * Отключает CSRF для API-эндпоинта.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool
     */
    public function beforeAction($action)
    {
        $this->enableCsrfValidation = false;
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    /**
     * Принимает тело FIWalletInfo, идемпотентно сохраняет сырой callback и
     * нормализует reports[] в pcr_wallet_info / pcr_operation.
     *
     * @return array Ответ-подтверждение.
     */
    public function actionWalletInfo(): array
    {
        if (!$this->checkAuth()) {
            Yii::$app->response->statusCode = 401;
            Yii::$app->response->headers->set('WWW-Authenticate', 'Basic realm="pcr-callback"');
            return ['status' => 'error', 'message' => 'Unauthorized'];
        }

        $req = Yii::$app->request;
        if (!$req->isPost) {
            Yii::$app->response->statusCode = 405;
            return ['status' => 'error', 'message' => 'Method Not Allowed'];
        }

        $payload = $req->getBodyParams();
        if (empty($payload) || !is_array($payload)) {
            Yii::$app->response->statusCode = 400;
            return ['status' => 'error', 'message' => 'Empty or invalid JSON body'];
        }

        $operationId = $payload['operationId'] ?? null;
        $partId      = $payload['partId'] ?? null;
        $db          = Yii::$app->db;

        // Идемпотентность: тот же (operationId, partId) уже обработан.
        $exists = $db->createCommand(
            "SELECT id FROM {{%pcr_callback}}
              WHERE operation_id IS NOT DISTINCT FROM :op
                AND part_id IS NOT DISTINCT FROM :part
              LIMIT 1",
            [':op' => $operationId, ':part' => $partId]
        )->queryScalar();

        if ($exists !== false && $exists !== null) {
            return ['status' => 'accepted', 'duplicate' => true];
        }

        $tx = $db->beginTransaction();
        try {
            $db->createCommand()->insert('{{%pcr_callback}}', [
                'correlation_id'             => $payload['correlationId'] ?? null,
                'operation_id'               => $operationId,
                'part_no'                    => isset($payload['partNo']) ? (int)$payload['partNo'] : null,
                'part_quantity'              => isset($payload['partQuantity']) ? (int)$payload['partQuantity'] : null,
                'part_id'                    => $partId,
                'message_creation_date_time' => $this->ts($payload['messageCreationDateTime'] ?? null),
                'payload'                    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->execute();
            $callbackId = (int)$db->getLastInsertID('pcr_callback_id_seq');

            $reports   = $payload['reports'] ?? [];
            $wiCount   = 0;
            $opCount   = 0;

            foreach ($reports as $rep) {
                if (!is_array($rep)) {
                    continue;
                }
                $bi = $rep['balanceInfo'] ?? [];

                $db->createCommand()->insert('{{%pcr_wallet_info}}', [
                    'callback_id'              => $callbackId,
                    'correlation_id'           => $payload['correlationId'] ?? null,
                    'operation_id'             => $operationId,
                    'fi_wallet_id'             => $rep['fiWalletId'] ?? null,
                    'dc_account_number'        => $rep['dcAccountNumber'] ?? null,
                    'total_amount'             => $bi['totalAmount'] ?? null,
                    'total_amount_ccy'         => $bi['totalAmountCcy'] ?? null,
                    'total_blocked_amount'     => $bi['totalBlockedAmount'] ?? null,
                    'total_blocked_amount_ccy' => $bi['totalBlockedAmountCcy'] ?? null,
                    'current_balance'          => $bi['currentBalance'] ?? null,
                    'current_balance_ccy'      => $bi['currentBalanceCcy'] ?? null,
                    'opening_balance'          => $rep['openingBalance'] ?? null,
                    'opening_balance_ccy'      => $rep['openingBalanceCcy'] ?? null,
                    'outgoing_balance'         => $rep['outgoingBalance'] ?? null,
                    'outgoing_balance_ccy'     => $rep['outgoingBalanceCcy'] ?? null,
                    'total_amount_debit'       => $rep['totalAmountDebit'] ?? null,
                    'total_amount_debit_ccy'   => $rep['totalAmountDebitCcy'] ?? null,
                    'total_amount_credit'      => $rep['totalAmountCredit'] ?? null,
                    'total_amount_credit_ccy'  => $rep['totalAmountCreditCcy'] ?? null,
                    'wallet_status'            => $rep['walletStatus'] ?? null,
                    'from_date_time'           => $this->ts($rep['fromDateTime'] ?? null),
                    'to_date_time'             => $this->ts($rep['toDateTime'] ?? null),
                ])->execute();
                $walletInfoId = (int)$db->getLastInsertID('pcr_wallet_info_id_seq');
                $wiCount++;

                foreach (($rep['operationsInformation'] ?? []) as $op) {
                    if (!is_array($op)) {
                        continue;
                    }
                    $db->createCommand()->insert('{{%pcr_operation}}', [
                        'wallet_info_id'         => $walletInfoId,
                        'operation_id'           => $op['operationId'] ?? null,
                        'type'                   => $op['type'] ?? null,
                        'amount'                 => $op['amount'] ?? null,
                        'amount_ccy'             => $op['amountCcy'] ?? null,
                        'credit_debit_indicator' => $op['creditDebitIndicator'] ?? null,
                        'settlement_date_time'   => $this->ts($op['settlementDateTime'] ?? null),
                        'other_details'          => isset($op['otherDetails'])
                            ? json_encode($op['otherDetails'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : null,
                    ])->execute();
                    $opCount++;
                }
            }

            $tx->commit();
            return ['status' => 'accepted', 'reports' => $wiCount, 'operations' => $opCount];
        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error('PCR callback error: ' . $e->getMessage(), __METHOD__);
            Yii::$app->response->statusCode = 500;
            return ['status' => 'error', 'message' => 'Internal error'];
        }
    }

    /**
     * Проверяет Basic Auth входящего callback против params.pcr.callbackAuth.
     *
     * Управляется флагом `params.pcr.callbackAuth.enabled`:
     *   - false (по умолчанию) — проверка пропускается (удобно для локального теста);
     *   - true — требуется совпадение username/password из конфига.
     *
     * @return bool
     */
    private function checkAuth(): bool
    {
        $cfg = Yii::$app->params['pcr']['callbackAuth'] ?? [];

        if (empty($cfg['enabled'])) {
            return true;
        }

        $user = (string)($cfg['username'] ?? '');
        $pass = (string)($cfg['password'] ?? '');

        [$gotUser, $gotPass] = Yii::$app->request->getAuthCredentials();
        return hash_equals($user, (string)$gotUser) && hash_equals($pass, (string)$gotPass);
    }

    /**
     * Нормализует ISO-дату/время к строке, пригодной для timestamp-колонки.
     *
     * @param string|null $value Значение из payload.
     * @return string|null
     */
    private function ts(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
