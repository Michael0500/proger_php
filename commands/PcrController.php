<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Expression;
use yii\helpers\Console;

/**
 * Сервис СЦР (Цифровой рубль) → файл IntelliMatch PCRFIHIST.
 *
 * Асинхронный процесс выгрузки истории операций и балансов кошельков ФП из ПлЦР:
 *   1. pcr/request — отправка запроса к API СЦР (POST /api/v4/fi/wallet/balance).
 *      В ответ 200 приходит correlationId + operationId (запрос принят в работу).
 *   2. Позже СЦР дёргает наш callback (PcrCallbackController) и наполняет
 *      pcr_wallet_info / pcr_operation.
 *   3. pcr/export — сборка отдельного файла строго заданного формата на каждый correlationId.
 *   4. pcr/upload — перекладка файла по FTP.
 *   5. pcr/run — export + upload (для cron к 07:00).
 *
 * Конфигурация — params.php → ['pcr'].
 *
 * Использование:
 *   php yii pcr/request
 *   php yii pcr/request --type=balance
 *   php yii pcr/request --date-from="2025-05-27T00:00:00.000Z" --date-to="2025-05-28T00:00:00.000Z"
 *   php yii pcr/request --date=2025-05-25   # сутки 2025-05-25 в UTC+3 (from 2025-05-24T21:00Z, to 2025-05-25T21:00Z)
 *   php yii pcr/export
 *   php yii pcr/export --correlation-id=ef71d287-995f-4582-b5ec-d8585beecf45
 *   php yii pcr/export --date=2025-05-27
 *   php yii pcr/upload
 *   php yii pcr/run
 */
class PcrController extends Controller
{
    /** Ширины полей строки баланса (тег 60), в порядке следования. */
    const W_TAG       = 2;
    const W_ACCOUNT   = 25;
    const W_CCY       = 3;
    const W_DATE      = 10;
    const W_AMOUNT    = 25;
    const W_DC        = 1;
    /** Ширины специфичных полей строки операции (тег 61). */
    const W_OP_ID     = 40;
    const W_OP_TYPE   = 35;

    const TAG_BALANCE   = '60';
    const TAG_OPERATION = '61';

    /** Разделитель полей в файле IntelliMatch. */
    const SEP = '|';

    /** --type=balance|operationHistory (для request) */
    public ?string $type = null;
    /** --date-from / --date-to: ISO-границы запроса (для request) */
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    /** --wallet: override walletIdList (для request) */
    public ?string $wallet = null;
    /** --correlation-id: выбор конкретного запроса (для export) */
    public ?string $correlationId = null;
    /** --date=YYYY-MM-DD: дата выверки (request — сутки UTC+3; export — выбор по from_date_time) */
    public ?string $date = null;
    /** --out: путь выходного файла/каталога (для export) */
    public ?string $out = null;
    /** --file: путь файла для аплоада (для upload) */
    public ?string $file = null;
    /** Файлы, созданные последним actionExport() в рамках текущего процесса. */
    private array $lastExportedFiles = [];

    /**
     * Описание опций командной строки.
     *
     * @param string $actionID Идентификатор action.
     * @return array
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'type', 'dateFrom', 'dateTo', 'wallet',
            'correlationId', 'date', 'out', 'file',
        ]);
    }

    /**
     * Алиасы опций (--date-from → --dateFrom и т.п.).
     *
     * @return array
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'date-from'      => 'dateFrom',
            'date-to'        => 'dateTo',
            'correlation-id' => 'correlationId',
        ]);
    }

    /**
     * Возвращает блок конфигурации ['pcr'] из params.
     *
     * @return array
     */
    private function cfg(): array
    {
        return Yii::$app->params['pcr'] ?? [];
    }

    // =====================================================================
    //  pcr/request — отправка запроса к API СЦР
    // =====================================================================

    /**
     * Отправляет запрос баланса/истории операций к API СЦР и сохраняет
     * correlationId/operationId из 200-ответа в pcr_request.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionRequest(): int
    {
        $cfg  = $this->cfg();
        $type = $this->type ?: 'operationHistory';

        if (!in_array($type, ['balance', 'operationHistory'], true)) {
            $this->stderr("Неизвестный --type: '{$type}'. Допустимо: balance | operationHistory\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        // Период: дефолт — предыдущие календарные сутки (UTC+3 → UTC).
        [$from, $to, $reconDate] = $this->resolvePeriod();

        $wallets = $this->wallet !== null
            ? array_filter(array_map('trim', explode(',', $this->wallet)))
            : ($cfg['walletIdList'] ?? []);

        $body = [
            'requestType'          => $type,
            'dcWalletIdList'       => array_values($wallets),
            'dateFrom'             => $from,
            'dateTo'               => $to,
            'additionalParameters' => ['nodeId' => $cfg['nodeId'] ?? null],
        ];

        $this->stdout("=== PCR request: " . date('Y-m-d H:i:s') . " [{$type}] ===\n", Console::BOLD);
        $this->stdout("Период: {$from} … {$to}\n", Console::FG_GREY);

        $url = rtrim($cfg['baseUrl'] ?? '', '/') . ($cfg['balancePath'] ?? '');
        [$httpStatus, $respBody, $curlErr] = $this->httpPostJson($url, $body, $cfg);

        $resp = json_decode((string)$respBody, true);
        $ok   = $httpStatus >= 200 && $httpStatus < 300 && $curlErr === null;

        $row = [
            'request_type'   => $type,
            'wallet_id_list' => json_encode(array_values($wallets), JSON_UNESCAPED_UNICODE),
            'date_from'      => $from,
            'date_to'        => $to,
            'recon_date'     => $reconDate,
            'node_id'        => $cfg['nodeId'] ?? null,
            'correlation_id' => is_array($resp) ? ($resp['correlationId'] ?? null) : null,
            'operation_id'   => is_array($resp) ? ($resp['operationId'] ?? null) : null,
            'http_status'    => $httpStatus,
            'response_raw'   => $respBody !== null ? (string)$respBody : null,
            'status'         => $ok ? 'accepted' : 'failed',
            'error'          => $ok ? null : ($curlErr ?? ('HTTP ' . $httpStatus)),
        ];
        Yii::$app->db->createCommand()->insert('{{%pcr_request}}', $row)->execute();

        if ($ok) {
            $this->stdout("OK: correlationId={$row['correlation_id']}, operationId={$row['operation_id']}\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr("Ошибка запроса: " . ($row['error']) . "\n", Console::FG_RED);
        if ($respBody !== null) {
            $this->stderr("Ответ: " . substr((string)$respBody, 0, 500) . "\n", Console::FG_GREY);
        }
        return ExitCode::UNAVAILABLE;
    }

    /**
     * Вычисляет границы периода запроса в формате ISO `...T..:..:..000Z`
     * и дату выверки (recon_date, YYYY-MM-DD) для сохранения в pcr_request.
     *
     * Приоритет источников периода:
     *   1. --date=YYYY-MM-DD — календарные сутки этой даты в зоне UTC+3,
     *      переведённые в UTC. Например --date=2025-05-25 →
     *      dateFrom=2025-05-24T21:00:00.000Z, dateTo=2025-05-25T21:00:00.000Z,
     *      recon_date=2025-05-25.
     *   2. --date-from/--date-to — переданные ISO-границы как есть (recon_date=null).
     *   3. дефолт — предыдущие календарные сутки в UTC+3; recon_date=вчера.
     *
     * @return array{0:string,1:string,2:?string} `[dateFrom, dateTo, reconDate]`.
     */
    private function resolvePeriod(): array
    {
        $utc = new \DateTimeZone('UTC');
        $tz  = new \DateTimeZone('+0300');

        $fmt = function (\DateTime $d): string {
            return $d->format('Y-m-d\TH:i:s') . '.000Z';
        };

        // 1. Явно заданная дата выверки → сутки этой даты в UTC+3.
        if ($this->date !== null && $this->date !== '') {
            $reconDate = (new \DateTime($this->date, $tz))->format('Y-m-d');
            $from = (new \DateTime($reconDate . ' 00:00:00', $tz))->setTimezone($utc);
            $to   = (new \DateTime($reconDate . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc);

            return [$fmt($from), $fmt($to), $reconDate];
        }

        // 2. Явные ISO-границы.
        if ($this->dateFrom !== null && $this->dateTo !== null) {
            return [$this->dateFrom, $this->dateTo, null];
        }

        // 3. Дефолт — предыдущие сутки в UTC+3.
        $reconDate = (new \DateTime('yesterday', $tz))->format('Y-m-d');
        $from = new \DateTime('yesterday 00:00:00', $tz);
        $to   = new \DateTime('today 00:00:00', $tz);
        $from->setTimezone($utc);
        $to->setTimezone($utc);

        return [
            $this->dateFrom ?? $fmt($from),
            $this->dateTo ?? $fmt($to),
            $reconDate,
        ];
    }

    /**
     * Выполняет POST с JSON-телом и Basic Auth через cURL.
     *
     * @param string $url  Полный URL.
     * @param array  $body Тело запроса.
     * @param array  $cfg  Блок конфигурации pcr.
     * @return array{0:int,1:?string,2:?string} `[httpStatus, responseBody, curlError]`.
     */
    private function httpPostJson(string $url, array $body, array $cfg): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)($cfg['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => !empty($cfg['verifySsl']),
            CURLOPT_SSL_VERIFYHOST => !empty($cfg['verifySsl']) ? 2 : 0,
        ]);
        $auth = $cfg['auth'] ?? [];
        if (!empty($auth['username'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $auth['username'] . ':' . ($auth['password'] ?? ''));
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = $resp === false ? (curl_error($ch) ?: 'cURL error') : null;
        curl_close($ch);

        return [$status, $resp === false ? null : $resp, $err];
    }

    // =====================================================================
    //  pcr/export — сборка текстового файла IntelliMatch
    // =====================================================================

    /**
     * Строит отдельный текстовый файл PCRFIHIST для каждого неэкспортированного correlation_id.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionExport(): int
    {
        $db  = Yii::$app->db;
        $cfg = $this->cfg();
        $this->lastExportedFiles = [];

        $this->stdout("=== PCR export: " . date('Y-m-d H:i:s') . " ===\n", Console::BOLD);

        // Выбор reports.
        $where = [
            "wi.correlation_id IS NOT NULL",
            "wi.correlation_id <> ''",
            "EXISTS (
                SELECT 1
                  FROM {{%pcr_request}} pr
                 WHERE pr.correlation_id = wi.correlation_id
                   AND pr.status = 'accepted'
                   AND COALESCE(pr.is_exported, false) = false
            )",
        ];
        $params = [];
        if ($this->correlationId !== null) {
            $where[] = 'wi.correlation_id = :c';
            $params[':c'] = $this->correlationId;
        } elseif ($this->date !== null) {
            $where[] = 'wi.from_date_time::date = :d';
            $params[':d'] = $this->date;
        }

        $reports = $db->createCommand(
            "SELECT wi.* FROM {{%pcr_wallet_info}} wi WHERE " . implode(' AND ', $where) . " ORDER BY wi.correlation_id, wi.id",
            $params
        )->queryAll();

        if (empty($reports)) {
            $this->stdout("Нет неэкспортированных данных для экспорта.\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $account    = (string)($cfg['nostroAccount'] ?? '');
        $dcIn       = (string)($cfg['dcIn'] ?? 'D');
        $dcOut      = (string)($cfg['dcOut'] ?? 'D');
        $groups     = $this->groupReportsByCorrelationId($reports);
        $totalReports = 0;
        $totalOps = 0;

        foreach ($groups as $correlationId => $groupReports) {
            $lines   = [];
            $opCount = 0;

            foreach ($groupReports as $r) {
                $lines[] = $this->buildBalanceLine($r, $account, $dcIn, $dcOut);

                $ops = $db->createCommand(
                    "SELECT * FROM {{%pcr_operation}} WHERE wallet_info_id = :w ORDER BY id",
                    [':w' => $r['id']]
                )->queryAll();

                foreach ($ops as $op) {
                    $lines[] = $this->buildOperationLine($op);
                    $opCount++;
                }
            }

            $path    = $this->resolveOutPath($cfg, $correlationId);
            $content = implode("\r\n", $lines) . "\r\n";
            $content = $this->encode($content, $cfg);

            if (@file_put_contents($path, $content) === false) {
                $this->stderr("Не удалось записать файл: {$path}\n", Console::FG_RED);
                return ExitCode::IOERR;
            }

            $db->createCommand()->update('{{%pcr_request}}', [
                'is_exported' => true,
                'exported_at' => new Expression('CURRENT_TIMESTAMP'),
                'export_file' => $path,
            ], ['correlation_id' => $correlationId])->execute();

            $this->lastExportedFiles[] = $path;
            $totalReports += count($groupReports);
            $totalOps += $opCount;

            $this->stdout("correlationId={$correlationId}: балансов " . count($groupReports) . ", операций {$opCount}\n", Console::FG_GREEN);
            $this->stdout("Файл: {$path}\n", Console::FG_GREEN);
        }

        $this->stdout("Итого файлов: " . count($groups) . ", балансов: {$totalReports}, операций: {$totalOps}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Группирует reports по correlation_id.
     *
     * @param array $reports Строки pcr_wallet_info.
     * @return array<string,array<int,array>>
     */
    private function groupReportsByCorrelationId(array $reports): array
    {
        $groups = [];
        foreach ($reports as $report) {
            $correlationId = (string)($report['correlation_id'] ?? '');
            $groups[$correlationId][] = $report;
        }
        return $groups;
    }

    /**
     * Формирует строку баланса (тег 60).
     *
     * @param array  $r       Строка pcr_wallet_info.
     * @param string $account Константа «Счёт ностро».
     * @param string $dcIn    Дт/Кт входящего остатка.
     * @param string $dcOut   Дт/Кт исходящего остатка.
     * @return string
     */
    private function buildBalanceLine(array $r, string $account, string $dcIn, string $dcOut): string
    {
        $fields = [
            $this->padRight(self::TAG_BALANCE, self::W_TAG),
            $this->padRight($account, self::W_ACCOUNT),
            $this->padRight($this->ccy($r['current_balance_ccy'] ?? ''), self::W_CCY),
            $this->padRight($this->fmtDate($r['from_date_time'] ?? null), self::W_DATE),
            $this->padLeft($this->fmtAmount($r['opening_balance'] ?? null), self::W_AMOUNT),
            $this->padRight($dcIn, self::W_DC),
            $this->padLeft($this->fmtAmount($r['outgoing_balance'] ?? null), self::W_AMOUNT),
            $this->padRight($dcOut, self::W_DC),
        ];
        return implode(self::SEP, $fields);
    }

    /**
     * Формирует строку операции (тег 61).
     *
     * @param array $op Строка pcr_operation.
     * @return string
     */
    private function buildOperationLine(array $op): string
    {
        $opId = str_replace('-', '', (string)($op['operation_id'] ?? ''));
        $fields = [
            $this->padRight(self::TAG_OPERATION, self::W_TAG),
            $this->padRight($opId, self::W_OP_ID),
            $this->padRight($this->ccy($op['amount_ccy'] ?? ''), self::W_CCY),
            $this->padLeft($this->fmtAmount($op['amount'] ?? null), self::W_AMOUNT),
            $this->padRight($this->dc($op['credit_debit_indicator'] ?? ''), self::W_DC),
            $this->padRight((string)($op['type'] ?? ''), self::W_OP_TYPE),
        ];
        return implode(self::SEP, $fields);
    }

    /**
     * Вычисляет путь выходного файла.
     *
     * @param array $cfg Блок конфигурации pcr.
     * @return string
     */
    private function resolveOutPath(array $cfg, string $correlationId): string
    {
        $safeCorrelationId = $this->safeFilePart($correlationId);
        $prefix = (string)($cfg['filePrefix'] ?? 'PCRFIHIST');

        if ($this->out !== null) {
            $out = Yii::getAlias($this->out);
            if (is_dir($out) || pathinfo($out, PATHINFO_EXTENSION) === '') {
                if (!is_dir($out)) {
                    @mkdir($out, 0775, true);
                }
                return rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                    . $prefix . '_' . date('Ymd_His') . '_' . $safeCorrelationId . '.txt';
            }

            $dir = dirname($out);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $ext = pathinfo($out, PATHINFO_EXTENSION);
            $name = pathinfo($out, PATHINFO_FILENAME);
            return $dir . DIRECTORY_SEPARATOR . $name . '_' . $safeCorrelationId . ($ext !== '' ? '.' . $ext : '');
        }

        $dir = Yii::getAlias((string)($cfg['exportDir'] ?? '@runtime/pcr'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $prefix . '_' . date('Ymd_His') . '_' . $safeCorrelationId . '.txt';
    }

    /**
     * Делает значение correlation_id безопасным фрагментом имени файла.
     *
     * @param string $value Исходное значение.
     * @return string
     */
    private function safeFilePart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));
        $safe = trim((string)$safe, '._-');
        if ($safe === '') {
            return 'no-correlation';
        }
        return substr($safe, 0, 120);
    }

    /**
     * Преобразует кодировку содержимого файла, если задана не UTF-8.
     *
     * @param string $content Содержимое в UTF-8.
     * @param array  $cfg     Блок конфигурации pcr.
     * @return string
     */
    private function encode(string $content, array $cfg): string
    {
        $enc = strtoupper((string)($cfg['fileEncoding'] ?? 'UTF-8'));
        if ($enc === 'UTF-8' || $enc === 'UTF8' || $enc === '') {
            return $content;
        }
        return mb_convert_encoding($content, $enc, 'UTF-8');
    }

    // =====================================================================
    //  pcr/upload — перекладка файла по FTP
    // =====================================================================

    /**
     * Заливает файл по FTP. Без --file берёт последний файл из exportDir.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionUpload(): int
    {
        $cfg = $this->cfg();
        $ftp = $cfg['ftp'] ?? [];

        $path = $this->file !== null ? Yii::getAlias($this->file) : $this->latestExport($cfg);
        if ($path === null || !is_file($path)) {
            $this->stderr("Файл для загрузки не найден" . ($path ? ": {$path}" : '') . "\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }
        if (empty($ftp['host'])) {
            $this->stderr("Не задан FTP-хост (params.pcr.ftp.host)\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout("=== PCR upload: {$path} → {$ftp['host']} ===\n", Console::BOLD);

        $conn = !empty($ftp['ssl'])
            ? @ftp_ssl_connect($ftp['host'], (int)($ftp['port'] ?? 21), 30)
            : @ftp_connect($ftp['host'], (int)($ftp['port'] ?? 21), 30);
        if (!$conn) {
            $this->stderr("Не удалось подключиться к FTP {$ftp['host']}:{$ftp['port']}\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        try {
            if (!@ftp_login($conn, (string)($ftp['username'] ?? ''), (string)($ftp['password'] ?? ''))) {
                $this->stderr("FTP-авторизация не удалась\n", Console::FG_RED);
                return ExitCode::NOPERM;
            }
            if (!empty($ftp['passive'])) {
                ftp_pasv($conn, true);
            }
            $remoteDir = rtrim((string)($ftp['remoteDir'] ?? '/'), '/');
            $remote    = ($remoteDir === '' ? '' : $remoteDir . '/') . basename($path);

            if (!@ftp_put($conn, $remote, $path, FTP_BINARY)) {
                $this->stderr("Не удалось загрузить файл на FTP: {$remote}\n", Console::FG_RED);
                return ExitCode::IOERR;
            }
            $this->stdout("Загружено: {$remote}\n", Console::FG_GREEN);
            return ExitCode::OK;
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * Находит последний по времени .txt-файл в exportDir.
     *
     * @param array $cfg Блок конфигурации pcr.
     * @return string|null Путь или null.
     */
    private function latestExport(array $cfg): ?string
    {
        $dir = Yii::getAlias((string)($cfg['exportDir'] ?? '@runtime/pcr'));
        if (!is_dir($dir)) {
            return null;
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.txt');
        if (empty($files)) {
            return null;
        }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }

    // =====================================================================
    //  pcr/run — export + upload
    // =====================================================================

    /**
     * Полный цикл выгрузки: экспорт файла, затем загрузка по FTP.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionRun(): int
    {
        $rc = $this->actionExport();
        if ($rc !== ExitCode::OK) {
            return $rc;
        }
        if (empty($this->lastExportedFiles)) {
            return ExitCode::OK;
        }

        foreach ($this->lastExportedFiles as $path) {
            $this->file = $path;
            $rc = $this->actionUpload();
            if ($rc !== ExitCode::OK) {
                return $rc;
            }
        }

        return ExitCode::OK;
    }

    // =====================================================================
    //  Хелперы форматирования
    // =====================================================================

    /**
     * Конвертирует код валюты RUB → RUR (требование формата IntelliMatch).
     *
     * @param string $code Код валюты из API.
     * @return string
     */
    private function ccy(string $code): string
    {
        return strtoupper(trim($code)) === 'RUB' ? 'RUR' : strtoupper(trim($code));
    }

    /**
     * Преобразует индикатор Дт/Кт: Debit → D, Credit → C.
     *
     * @param string $indicator Значение creditDebitIndicator.
     * @return string
     */
    private function dc(string $indicator): string
    {
        $v = strtolower(trim($indicator));
        if ($v === 'debit')  return 'D';
        if ($v === 'credit') return 'C';
        return strtoupper(substr($indicator, 0, 1));
    }

    /**
     * Форматирует сумму: разделитель «.», ровно 2 знака, без разделителей тысяч.
     *
     * @param mixed $amount Сумма (string|float|null).
     * @return string
     */
    private function fmtAmount($amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        return number_format((float)$amount, 2, '.', '');
    }

    /**
     * Форматирует дату в dd/MM/YYYY.
     *
     * @param string|null $value Дата/время из БД.
     * @return string
     */
    private function fmtDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return (new \DateTime($value))->format('d/m/Y');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Дополняет строку пробелами справа до ширины (обрезает при превышении).
     *
     * @param string $s     Значение.
     * @param int    $width Целевая ширина.
     * @return string
     */
    private function padRight(string $s, int $width): string
    {
        if (mb_strlen($s) > $width) {
            $s = mb_substr($s, 0, $width);
        }
        return $s . str_repeat(' ', $width - mb_strlen($s));
    }

    /**
     * Дополняет строку пробелами слева до ширины (обрезает при превышении).
     *
     * @param string $s     Значение.
     * @param int    $width Целевая ширина.
     * @return string
     */
    private function padLeft(string $s, int $width): string
    {
        if (mb_strlen($s) > $width) {
            $s = mb_substr($s, -$width);
        }
        return str_repeat(' ', $width - mb_strlen($s)) . $s;
    }
}
