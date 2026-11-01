<?php

/*
| Личный кабинет: история заказов клиента и привязка бота MAX.
|   GET                  — заказы (с сайта, а также из привязанного бота) и статус привязки
|   POST action=link_code — шестизначный код, который нужно отправить боту (действует 15 минут)
|   POST action=unlink    — отвязать бота
| Чужие заказы увидеть нельзя: берутся только заказы этого user_id и привязанных к нему аккаунтов MAX.
*/

session_set_cookie_params([
    "lifetime" => 60 * 60 * 24 * 30,
    "path"     => "/",
    "secure"   => true,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/schema.php";
require __DIR__ . "/config/admin_ops.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION["user_id"])) out(["success" => false, "message" => "Войдите в аккаунт"], 401);
$uid = (int)$_SESSION["user_id"];

try {
    tl_ensure_orders($pdo);
    tl_ensure_max($pdo);

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "fetch") out(["success" => false, "message" => "Неверный запрос"], 400);
        $body = json_decode(file_get_contents("php://input"), true);
        $act  = is_array($body) ? (string)($body["action"] ?? "") : "";

        if ($act === "link_code") {
            out(["success" => true, "code" => tl_link_code_make($pdo, $uid), "minutes" => 15]);
        }
        if ($act === "unlink") {
            $pdo->prepare("DELETE FROM tl_max_links WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM tl_link_codes WHERE user_id = ?")->execute([$uid]);
            out(["success" => true]);
        }
        out(["success" => false, "message" => "Неизвестное действие"], 400);
    }

    $s = $pdo->prepare(
        "SELECT * FROM tl_orders
         WHERE user_id = ? OR max_user IN (SELECT max_user FROM tl_max_links WHERE user_id = ?)
         ORDER BY id DESC LIMIT 30"
    );
    $s->execute([$uid, $uid]);
    $labels = tl_order_status_public();
    $orders = array_map(function ($o) use ($labels) {
        return [
            "id"     => (int)$o["id"],
            "date"   => date("d.m.Y", strtotime($o["created_at"])),
            "status" => $o["status"],
            "label"  => $labels[$o["status"]] ?? $o["status"],
            "items"  => json_decode($o["items"], true) ?: [],
            "total"  => (int)$o["total"],
            "source" => $o["max_user"] ? "Бот MAX" : "Сайт",
        ];
    }, $s->fetchAll());

    $l = $pdo->prepare("SELECT COUNT(*) FROM tl_max_links WHERE user_id = ?");
    $l->execute([$uid]);

    out(["success" => true, "orders" => $orders, "linked" => (int)$l->fetchColumn() > 0]);

} catch (Throwable $e) {
    error_log("cabinet.php: " . $e->getMessage());
    out(["success" => false, "message" => "Не удалось загрузить данные"], 500);
}
