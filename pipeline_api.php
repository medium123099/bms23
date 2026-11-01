<?php

/*
| API страницы «Заказы по этапам» (pipeline.html).
| Доступ: администратор (role = admin) или сотрудник из таблицы tl_staff.
| Сотрудник видит и двигает только заказы на своих этапах (зонах ответственности);
| сотрудник с зоной «all» работает со всей базой заказов; управление сотрудниками — только у админа.
|
|   GET                      — заказы, доступные пользователю, счётчики по этапам
|   GET  ?action=history&id= — журнал заказа
|   POST {action: advance|back|cancel|save|set_status|staff_save|staff_code|staff_delete|staff_unbind, ...}
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
require __DIR__ . "/config/max_client.php";

header("Content-Type: application/json; charset=UTF-8");

function out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    try { tl_ensure_tables($pdo); } catch (PDOException $e) { error_log("pipeline_api ensure: " . $e->getMessage()); }

    $acc = tl_site_access($pdo, (int)($_SESSION["user_id"] ?? 0));
    if (!$acc) out(["success" => false, "message" => "Доступ запрещён"], 403);

    // защита от CSRF: такой заголовок нельзя отправить с чужого сайта без разрешения
    if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "fetch") {
        out(["success" => false, "message" => "Неверный запрос"], 400);
    }

    $method = $_SERVER["REQUEST_METHOD"];

    /* ---------- чтение ---------- */
    if ($method === "GET") {

        if (($_GET["action"] ?? "") === "history") {
            $id = (int)($_GET["id"] ?? 0);
            $o  = tl_order_get($pdo, $id);
            if (!$o || !tl_acc_can_view($acc, $o)) out(["success" => false, "message" => "Заказ недоступен"], 404);
            $st = tl_order_statuses();
            out(["success" => true, "log" => array_map(function ($r) use ($st) {
                return [
                    "date" => date("c", strtotime($r["created_at"])),
                    "by"   => (string)$r["by_name"],
                    "from" => $r["from_status"] === null ? null : ($st[$r["from_status"]] ?? $r["from_status"]),
                    "to"   => $st[$r["to_status"]] ?? $r["to_status"],
                    "same" => $r["from_status"] === $r["to_status"],
                    "note" => (string)$r["note"],
                ];
            }, tl_order_history($pdo, $id, 50))]);
        }

        $orders = array_map(function ($r) use ($acc) {
            return [
                "id"         => (int)$r["id"],
                "date"       => date("c", strtotime($r["created_at"])),
                "stage_at"   => $r["stage_at"] ? date("c", strtotime($r["stage_at"])) : null,
                "name"       => $r["name"],
                "last_name"  => (string)($r["last_name"] ?? ""),
                "first_name" => (string)($r["first_name"] ?? ""),
                "middle_name"=> (string)($r["middle_name"] ?? ""),
                "email"      => (string)($r["email"] ?? ""),
                "cdek"       => (string)($r["cdek"] ?? ""),
                "agent"      => (string)($r["agent"] ?? ""),
                "phone"      => (string)$r["phone"],
                "items"      => json_decode($r["items"], true) ?: [],
                "total"      => (int)$r["total"],
                "status"     => $r["status"],
                "comment"    => (string)($r["comment"] ?? ""),
                "buyer"      => $r["buyer_type"] ?? "person",
                "requisites" => (string)($r["requisites"] ?? ""),
                "invoice_no" => (string)($r["invoice_no"] ?? ""),
                "waybill_no" => (string)($r["waybill_no"] ?? ""),
                "pvz"        => (string)($r["pvz"] ?? ""),
                "action"     => tl_stage_action((string)$r["status"], $r),
                "source"     => $r["max_user"] ? "Бот MAX" : ($r["manual"] ? "Вручную" : "Сайт"),
                "can_cancel" => tl_acc_can_cancel($acc, $r) && !in_array($r["status"], ["cancelled", "done"], true),
            ];
        }, tl_orders_visible($pdo, $acc, "all", 500));

        $stages = [];
        foreach (tl_stages() as $k => $v) {
            $stages[] = ["code" => $k, "label" => $v["label"], "action" => $v["action"], "hint" => $v["hint"],
                         "btn" => $v["btn"], "need" => $v["need"], "ask" => $v["ask"]];
        }
        $statuses = [];
        foreach (tl_order_statuses() as $k => $v) $statuses[] = ["code" => $k, "label" => $v];

        $res = [
            "success"  => true,
            "me"       => ["name" => $acc["name"], "admin" => $acc["admin"], "all" => $acc["all"], "zones" => $acc["zones"], "label" => tl_acc_label($acc)],
            "stages"   => $stages,
            "statuses" => $statuses,
            "counts"   => tl_stage_counts($pdo, $acc),
            "orders"   => $orders,
        ];

        if ($acc["admin"]) {
            $res["staff"] = array_map(function ($r) {
                $code = ($r["bind_code"] && $r["bind_exp"] && strtotime($r["bind_exp"]) > time()) ? $r["bind_code"] : "";
                return [
                    "id"    => (int)$r["id"],
                    "name"  => $r["name"],
                    "phone" => $r["phone"] ? "+" . $r["phone"] : "",
                    "site"  => $r["user_id"] !== null,
                    "bot"   => $r["max_user"] !== null,
                    "zones" => tl_zones_parse((string)$r["zones"]),
                    "code"  => $code,
                ];
            }, $pdo->query(
                "SELECT s.*, u.phone FROM tl_staff s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.id"
            )->fetchAll());
        }
        out($res);
    }

    if ($method !== "POST") out(["success" => false], 405);

    /* ---------- действия ---------- */
    $b      = json_decode(file_get_contents("php://input"), true);
    $b      = is_array($b) ? $b : [];
    $action = (string)($b["action"] ?? "");
    $id     = (int)($b["id"] ?? 0);
    $fields = is_array($b["fields"] ?? null) ? $b["fields"] : [];

    // сначала ответ человеку, потом уведомления (клиенту и сотрудникам нового этапа)
    $finish = function (array $r) use ($pdo) {
        $resp = ["success" => !empty($r["ok"]), "message" => (string)($r["msg"] ?? ""), "need" => (string)($r["need"] ?? "")];
        http_response_code(200);
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        tl_finish_response();
        if (!empty($r["ok"]) && ($r["from"] ?? null) !== ($r["to"] ?? null)) tl_order_after_move($pdo, $r["order"], (string)$r["to"]);
        exit;
    };

    if ($action === "advance") {
        $o = tl_order_get($pdo, $id);
        if (!$o) out(["success" => false, "message" => "Заказ не найден"], 404);
        $to = tl_stage_next((string)$o["status"]);
        if (!$to || $o["status"] === "cancelled") out(["success" => false, "message" => "Следующего этапа нет"]);
        $finish(tl_order_move($pdo, $id, $to, $acc, (string)($b["note"] ?? ""), $fields, false, false));
    }

    if ($action === "back") {
        $o = tl_order_get($pdo, $id);
        if (!$o) out(["success" => false, "message" => "Заказ не найден"], 404);
        $to = tl_stage_prev((string)$o["status"]);
        if (!$to) out(["success" => false, "message" => "Назад некуда"]);
        $note = trim((string)($b["note"] ?? ""));
        if ($note === "") out(["success" => false, "message" => "Напишите причину возврата"]);
        $finish(tl_order_move($pdo, $id, $to, $acc, "Возврат: " . $note, [], false, false));
    }

    if ($action === "cancel") {
        $finish(tl_order_move($pdo, $id, "cancelled", $acc, (string)($b["note"] ?? "Заказ отменён"), [], false, false));
    }

    if ($action === "save") {
        $o = tl_order_get($pdo, $id);
        if (!$o || !tl_acc_can_view($acc, $o)) out(["success" => false, "message" => "Заказ недоступен"], 404);
        if (array_key_exists("comment", $b)) {
            $newC = mb_substr(trim((string)$b["comment"]), 0, 500);
            if ($newC !== (string)($o["comment"] ?? "")) tl_order_set_comment($pdo, $id, $newC);
        }
        $r = tl_order_move($pdo, $id, (string)$o["status"], $acc, "", $fields, false, false);
        out(["success" => !empty($r["ok"]), "message" => (string)($r["msg"] ?? "")]);
    }

    /* ----- только администратор ----- */
    if (!$acc["admin"]) out(["success" => false, "message" => "Только для администратора"], 403);

    if ($action === "set_status") {
        $finish(tl_order_move($pdo, $id, (string)($b["status"] ?? ""), $acc, "Статус изменён вручную", [], true, false));
    }

    if ($action === "staff_save") {
        $name  = mb_substr(trim((string)($b["name"] ?? "")), 0, 80);
        $zones = array_values(array_unique(array_filter(
            array_map("strval", is_array($b["zones"] ?? null) ? $b["zones"] : []),
            function ($z) { return $z === "all" || isset(tl_stages()[$z]); }
        )));
        if (in_array("all", $zones, true)) $zones = ["all"];
        if (mb_strlen($name) < 2) out(["success" => false, "message" => "Введите имя сотрудника"]);
        if (!$zones)              out(["success" => false, "message" => "Выберите хотя бы один этап или «Вся база»"]);

        $userId = null;
        $rawPhone = trim((string)($b["phone"] ?? ""));
        if ($rawPhone !== "") {
            $phone = tl_phone_norm($rawPhone);
            if ($phone === "") out(["success" => false, "message" => "Не похоже на российский номер"]);
            $u = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
            $u->execute([$phone]);
            $userId = $u->fetchColumn();
            if (!$userId) out(["success" => false, "message" => "Аккаунта с таким номером нет. Сотрудник должен сначала зарегистрироваться на сайте."]);
            $userId = (int)$userId;
        }

        $csv = implode(",", $zones);
        if ($id) {
            $dup = $userId ? $pdo->prepare("SELECT id FROM tl_staff WHERE user_id = ? AND id <> ?") : null;
            if ($dup) { $dup->execute([$userId, $id]); if ($dup->fetchColumn()) out(["success" => false, "message" => "Этот аккаунт уже добавлен другому сотруднику"]); }
            $pdo->prepare("UPDATE tl_staff SET name = ?, user_id = ?, zones = ? WHERE id = ?")->execute([$name, $userId, $csv, $id]);
        } else {
            if ($userId) {
                $dup = $pdo->prepare("SELECT id FROM tl_staff WHERE user_id = ?");
                $dup->execute([$userId]);
                if ($dup->fetchColumn()) out(["success" => false, "message" => "Этот аккаунт уже добавлен"]);
            }
            $pdo->prepare("INSERT INTO tl_staff (name, user_id, zones, created_at) VALUES (?, ?, ?, NOW())")->execute([$name, $userId, $csv]);
            $id = (int)$pdo->lastInsertId();
        }
        out(["success" => true, "id" => $id]);
    }

    if ($action === "staff_code") {
        $s = $pdo->prepare("SELECT id FROM tl_staff WHERE id = ?");
        $s->execute([$id]);
        if (!$s->fetchColumn()) out(["success" => false, "message" => "Сотрудник не найден"], 404);
        out(["success" => true, "code" => tl_staff_make_code($pdo, $id)]);
    }

    if ($action === "staff_unbind") {
        $pdo->prepare("UPDATE tl_staff SET max_user = NULL, bind_code = NULL, bind_exp = NULL WHERE id = ?")->execute([$id]);
        out(["success" => true]);
    }

    if ($action === "staff_delete") {
        $pdo->prepare("DELETE FROM tl_staff WHERE id = ?")->execute([$id]);
        out(["success" => true]);
    }

    out(["success" => false, "message" => "Неизвестное действие"], 400);

} catch (Throwable $e) {
    error_log("pipeline_api.php: " . $e->getMessage());
    out(["success" => false, "message" => "Ошибка сервера"], 500);
}
