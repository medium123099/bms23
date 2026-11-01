<?php

/* Клиент Bot API мессенджера MAX и рассылка уведомлений администраторам. */

function tl_max_config(): array
{
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . "/max.php";
        $loaded = is_file($f) ? require $f : [];
        $c = (is_array($loaded) ? $loaded : []) + [
            "token" => "", "base_url" => "https://platform-api.max.ru",
            "webhook_secret" => "", "setup_key" => "", "admin_key" => "",
            "site_url" => "https://bms23.ru", "ca_file" => "", "cron_key" => "",
        ];
    }
    return $c;
}

function tl_max_enabled(): bool
{
    return (string)tl_max_config()["token"] !== "";
}

/* Возвращает ["ok"=>bool, "code"=>int, "error"=>string, "data"=>mixed] */
function tl_max_call(string $method, string $path, array $query = [], $body = null, int $timeout = 8): array
{
    $cfg = tl_max_config();
    $url = rtrim((string)$cfg["base_url"], "/") . $path . ($query ? "?" . http_build_query($query) : "");

    $ch = curl_init($url);
    $opt = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            "Authorization: " . $cfg["token"],      // токен без Bearer
            "Content-Type: application/json",
        ],
    ];
    if ((string)$cfg["ca_file"] !== "") $opt[CURLOPT_CAINFO] = $cfg["ca_file"];
    if ($body !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = is_string($raw) ? json_decode($raw, true) : null;
    return ["ok" => $raw !== false && $code >= 200 && $code < 300, "code" => $code, "error" => $err, "data" => $data !== null ? $data : $raw];
}

function tl_btn(string $text, string $payload): array
{
    return ["type" => "callback", "text" => $text, "payload" => $payload];
}

function tl_kb(?array $rows): array
{
    if (!$rows) return [];
    return [["type" => "inline_keyboard", "payload" => ["buttons" => $rows]]];
}

function tl_max_send(int $userId, string $text, ?array $rows = null, int $timeout = 8): array
{
    $body = ["text" => mb_substr($text, 0, 3900)];
    if ($rows) $body["attachments"] = tl_kb($rows);
    return tl_max_call("POST", "/messages", ["user_id" => $userId], $body, $timeout);
}

/* Ответ на нажатие кнопки: заменяем текст и клавиатуру исходного сообщения. */
function tl_max_answer(string $callbackId, string $text, ?array $rows = null, string $toast = ""): array
{
    $body = ["message" => ["text" => mb_substr($text, 0, 3900), "attachments" => tl_kb($rows)]];
    if ($toast !== "") $body["notification"] = $toast;
    return tl_max_call("POST", "/answers", ["callback_id" => $callbackId], $body);
}

function tl_max_admin_ids(PDO $pdo): array
{
    return array_map("intval", $pdo->query("SELECT user_id FROM tl_max_admins")->fetchAll(PDO::FETCH_COLUMN));
}

/* Уведомление всем админам бота. Никогда не бросает исключений, чтобы не ломать заказ. */
function tl_max_notify(PDO $pdo, string $text, ?array $rows = null): void
{
    if (!tl_max_enabled()) return;
    try {
        tl_ensure_max($pdo);
        foreach (tl_max_admin_ids($pdo) as $id) tl_max_send($id, $text, $rows, 4);
    } catch (Throwable $e) {
        error_log("max notify: " . $e->getMessage());
    }
}

/* Отдать ответ посетителю сайта сразу, а уведомления слать уже после. */
function tl_finish_response(): void
{
    if (function_exists("fastcgi_finish_request")) fastcgi_finish_request();
}
