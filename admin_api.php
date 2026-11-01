<?php

/*
| API админ-панели (только для role = admin).
|   GET  — отдаёт заказы, отзывы, клиентов и заявки
|   POST — применяет изменения: {"ops":[{op:add|upd|del, kind:order|review|call, item|id}]}
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
require __DIR__ . "/config/backup.php";

header("Content-Type: application/json; charset=UTF-8");

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- доступ: роль берём из БД, а не из сессии ---------- */

$isAdmin = false;
if (!empty($_SESSION["user_id"])) {
    $s = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $s->execute([$_SESSION["user_id"]]);
    $isAdmin = ($s->fetchColumn() === "admin");
}
if (!$isAdmin) out(["success" => false, "message" => "Доступ запрещён"], 403);
$adminAcc = tl_site_access($pdo, (int)$_SESSION["user_id"]) ?: tl_acc_admin("site", "Админ");

// защита от CSRF: такой заголовок нельзя отправить с чужого сайта без разрешения
if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "fetch") {
    out(["success" => false, "message" => "Неверный запрос"], 400);
}

$STATUS_ORDER  = array_keys(tl_order_statuses());   // Заявка, Счёт, Оплата, Чек, Отправка, Отправлен, Выполнен, Отменён
$STATUS_REVIEW = ["pending", "approved", "rejected"];

function iso_to_sql($iso) {
    $t = strtotime((string)$iso);
    return date("Y-m-d H:i:s", $t ?: time());
}

try {
    try { tl_ensure_tables($pdo); } catch (PDOException $e) { error_log("admin_api.php ensure: " . $e->getMessage()); }

    /* ---------- новые разделы: каталог, отчёты, рассылка, копии ---------- */
    $ga = (string)($_GET["action"] ?? "");

    if ($_SERVER["REQUEST_METHOD"] === "GET" && $ga !== "") {
        if ($ga === "products") {
            $list = [];
            foreach (tl_catalog(true) as $slug => $p) $list[] = ["slug" => $slug] + $p;
            out(["success" => true, "products" => $list]);
        }
        if ($ga === "reports") {
            out(["success" => true, "report" => tl_reports($pdo)]);
        }
        if ($ga === "mailing") {
            out(["success" => true, "subscribers" => tl_sub_count($pdo), "bot_enabled" => tl_max_enabled(),
                 "questions" => array_map(function ($r) {
                     return ["user" => (string)$r["max_user"], "name" => (string)$r["name"], "text" => $r["text"],
                             "date" => date("c", strtotime($r["created_at"])), "answered" => (int)$r["answered"] > 0];
                 }, tl_questions_recent($pdo, 10))]);
        }
        if ($ga === "backups") {
            out(["success" => true, "files" => tl_backup_list()]);
        }
        if ($ga === "backup_dl") {
            tl_backup_download($pdo, (string)($_GET["name"] ?? ""));
        }
        out(["success" => false, "message" => "Неизвестное действие"], 400);
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $body0 = json_decode(file_get_contents("php://input"), true);
        $pa = is_array($body0) ? (string)($body0["action"] ?? "") : "";

        if ($pa === "product_save") {
            $it   = is_array($body0["item"] ?? null) ? $body0["item"] : [];
            $slug = strtolower(trim((string)($it["slug"] ?? "")));
            $name = mb_substr(trim((string)($it["name"] ?? "")), 0, 100);
            $price = (int)($it["price"] ?? -1);
            $page = trim((string)($it["page"] ?? ""));
            if (!preg_match('/^[a-z0-9]{2,20}$/', $slug)) out(["success" => false, "message" => "Код товара: 2–20 латинских букв и цифр"]);
            if (mb_strlen($name) < 2)                     out(["success" => false, "message" => "Введите название"]);
            if ($price < 0 || $price > 10000000)          out(["success" => false, "message" => "Цена от 0 до 10 000 000"]);
            if ($page !== "" && !preg_match('/^[a-z0-9\-\/]{1,100}$/', $page)) out(["success" => false, "message" => "Адрес страницы: латиница, цифры и дефис"]);
            $exists = isset(tl_catalog(true)[$slug]);
            if (!empty($it["is_new"]) && $exists) out(["success" => false, "message" => "Товар с таким кодом уже есть"]);
            $pdo->prepare(
                "INSERT INTO tl_products (slug, name, price, descr, page, active, sort) VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price), descr = VALUES(descr),
                                         page = VALUES(page), active = VALUES(active), sort = VALUES(sort)"
            )->execute([$slug, $name, $price, mb_substr(trim((string)($it["desc"] ?? "")), 0, 600) ?: null,
                        $page !== "" ? $page : null, !empty($it["active"]) ? 1 : 0, (int)($it["sort"] ?? 100)]);
            out(["success" => true]);
        }
        if ($pa === "product_delete") {
            $slug = strtolower(trim((string)($body0["slug"] ?? "")));
            $pdo->prepare("DELETE FROM tl_products WHERE slug = ?")->execute([$slug]);
            out(["success" => true]);
        }
        if ($pa === "broadcast" || $pa === "broadcast_test") {
            $text = trim((string)($body0["text"] ?? ""));
            if (mb_strlen($text) < 5 || mb_strlen($text) > 1500) out(["success" => false, "message" => "Текст: от 5 до 1500 символов"]);
            if (!tl_max_enabled()) out(["success" => false, "message" => "Бот MAX не настроен (нет токена)"]);
            if ($pa === "broadcast_test") {
                $n = 0;
                foreach (tl_max_admin_ids($pdo) as $id) { if (tl_max_send($id, "Проверка рассылки:\n\n" . $text, [[tl_btn("🛍 Каталог", "k:c"), tl_btn("🔕 Отписаться", "k:unsub")]], 6)["ok"]) $n++; }
                out(["success" => true, "sent" => $n, "failed" => 0]);
            }
            if (empty($body0["confirm"])) out(["success" => false, "message" => "Нужно подтверждение"]);
            [$sent, $fail] = tl_broadcast($pdo, $text);
            out(["success" => true, "sent" => $sent, "failed" => $fail]);
        }
        if ($pa === "backup_make") {
            try {
                [$f, $size] = tl_backup_make($pdo);
                out(["success" => true, "name" => basename($f), "size" => $size]);
            } catch (Throwable $e) {
                error_log("backup: " . $e->getMessage());
                out(["success" => false, "message" => "Не удалось создать копию: " . $e->getMessage()], 500);
            }
        }
    }

    /* ---------- чтение ---------- */
    if ($_SERVER["REQUEST_METHOD"] === "GET") {

        $orders = array_map(function ($r) {
            return [
                "id"       => (string)$r["id"],
                "num"      => (int)$r["id"],
                "date"     => date("c", strtotime($r["created_at"])),
                "customer" => $r["name"],
                "phone"    => $r["phone"] !== "" ? "+" . $r["phone"] : "",
                "items"    => json_decode($r["items"], true) ?: [],
                "total"    => (int)$r["total"],
                "status"   => $r["status"],
                "comment"  => $r["comment"] ?? "",
                "manual"   => (bool)$r["manual"],
                "buyer"    => $r["buyer_type"] ?? "person",
                "invoiceNo"=> $r["invoice_no"] ?? "",
                "waybillNo"=> $r["waybill_no"] ?? "",
                "email"    => $r["email"] ?? "",
                "cdek"     => $r["cdek"] ?? "",
                "agent"    => $r["agent"] ?? ""
            ];
        }, $pdo->query("SELECT * FROM tl_orders ORDER BY id DESC LIMIT 2000")->fetchAll());

        $reviews = array_map(function ($r) {
            return [
                "id"     => (string)$r["id"],
                "name"   => $r["name"],
                "rating" => (int)$r["rating"],
                "text"   => $r["text"],
                "date"   => date("c", strtotime($r["created_at"])),
                "status" => $r["status"],
                "source" => $r["source"]
            ];
        }, $pdo->query("SELECT * FROM tl_reviews ORDER BY id DESC LIMIT 2000")->fetchAll());

        $users = array_map(function ($r) {
            return [
                "name"      => $r["name"],
                "phone"     => "+" . $r["phone"],
                "createdAt" => $r["created_at"] ? date("c", strtotime($r["created_at"])) : null
            ];
        }, $pdo->query("SELECT name, phone, created_at FROM users ORDER BY id DESC LIMIT 5000")->fetchAll());

        $calls = array_map(function ($r) {
            return [
                "id"      => (string)$r["id"],
                "name"    => $r["name"],
                "phone"   => "+" . $r["phone"],
                "topic"   => $r["topic"] ?? "",
                "message" => $r["message"] ?? "",
                "date"    => date("c", strtotime($r["created_at"]))
            ];
        }, $pdo->query("SELECT * FROM tl_callbacks ORDER BY id DESC LIMIT 1000")->fetchAll());

        out(["success" => true, "orders" => $orders, "reviews" => $reviews, "users" => $users, "calls" => $calls]);
    }

    /* ---------- запись ---------- */
    if ($_SERVER["REQUEST_METHOD"] !== "POST") out(["success" => false], 405);

    $body = json_decode(file_get_contents("php://input"), true);
    $ops  = is_array($body["ops"] ?? null) ? $body["ops"] : [];

    $notify = [];   // [заказ до изменения, новый статус]: клиентам бота уйдёт сообщение после ответа
    $pdo->beginTransaction();

    foreach ($ops as $op) {
        $kind = $op["kind"] ?? "";
        $act  = $op["op"] ?? "";
        $item = is_array($op["item"] ?? null) ? $op["item"] : [];
        $id   = (int)($op["id"] ?? ($item["id"] ?? 0));

        /* ----- заказы ----- */
        if ($kind === "order") {

            if ($act === "del" && $id) {
                $pdo->prepare("DELETE FROM tl_orders WHERE id = ?")->execute([$id]);

            } elseif ($act === "upd" && $id) {
                $status = in_array($item["status"] ?? "", $STATUS_ORDER, true) ? $item["status"] : "new";
                $before = tl_order_get($pdo, $id);
                if (!$before) continue;
                $pdo->prepare("UPDATE tl_orders SET comment = ? WHERE id = ?")
                    ->execute([mb_substr(trim($item["comment"] ?? ""), 0, 500) ?: null, $id]);
                // смена статуса идёт через общий модуль: запись в журнал и уведомления сотрудникам и клиенту
                if ($before["status"] !== $status) {
                    $r = tl_order_move($pdo, $id, $status, $adminAcc, "Статус изменён в админ-панели", [], true, false);
                    if ($r["ok"]) $notify[] = [$r["order"], $status];
                }

            } elseif ($act === "add") {
                $name  = mb_substr(trim($item["customer"] ?? ""), 0, 60);
                $phone = preg_replace('/\D/', '', $item["phone"] ?? "");
                if (strlen($phone) !== 11) $phone = "";

                $lines = []; $total = 0;
                foreach (($item["items"] ?? []) as $it) {
                    $q = max(1, min(999, (int)($it["qty"] ?? 1)));
                    $p = max(0, (int)($it["price"] ?? 0));
                    $n = mb_substr(trim($it["name"] ?? ""), 0, 100);
                    if ($n === "") continue;
                    $lines[] = ["name" => $n, "qty" => $q, "price" => $p];
                    $total  += $q * $p;
                }
                if ($name === "" || !$lines) continue;

                $status = in_array($item["status"] ?? "", $STATUS_ORDER, true) ? $item["status"] : "new";

                $pdo->prepare(
                    "INSERT INTO tl_orders (user_id, name, phone, comment, items, total, status, created_at, manual, stage_at)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 1, NOW())"
                )->execute([
                    $name, $phone,
                    mb_substr(trim($item["comment"] ?? ""), 0, 500) ?: null,
                    json_encode($lines, JSON_UNESCAPED_UNICODE),
                    $total, $status, iso_to_sql($item["date"] ?? "")
                ]);
                tl_order_created($pdo, (int)$pdo->lastInsertId(), "site", $adminAcc["name"] ?: "Админ", "Заказ внесён вручную в админ-панели");
            }

        /* ----- отзывы ----- */
        } elseif ($kind === "review") {

            if ($act === "del" && $id) {
                $pdo->prepare("DELETE FROM tl_reviews WHERE id = ?")->execute([$id]);

            } elseif ($act === "upd" && $id) {
                $status = in_array($item["status"] ?? "", $STATUS_REVIEW, true) ? $item["status"] : "pending";
                $text   = mb_substr(trim($item["text"] ?? ""), 0, 1000);
                if (mb_strlen($text) < 10) continue;
                $pdo->prepare("UPDATE tl_reviews SET status = ?, text = ? WHERE id = ?")
                    ->execute([$status, $text, $id]);

            } elseif ($act === "add") {
                $name   = mb_substr(trim($item["name"] ?? ""), 0, 60);
                $rating = max(1, min(5, (int)($item["rating"] ?? 5)));
                $text   = mb_substr(trim($item["text"] ?? ""), 0, 1000);
                if (mb_strlen($name) < 2 || mb_strlen($text) < 10) continue;
                $status = in_array($item["status"] ?? "", $STATUS_REVIEW, true) ? $item["status"] : "approved";
                $pdo->prepare(
                    "INSERT INTO tl_reviews (name, rating, text, status, source, created_at)
                     VALUES (?, ?, ?, ?, 'admin', ?)"
                )->execute([$name, $rating, $text, $status, iso_to_sql($item["date"] ?? "")]);
            }

        /* ----- заявки на звонок ----- */
        } elseif ($kind === "call" && $act === "del" && $id) {
            $pdo->prepare("DELETE FROM tl_callbacks WHERE id = ?")->execute([$id]);
        }
    }

    $pdo->commit();

    http_response_code(200);
    echo json_encode(["success" => true], JSON_UNESCAPED_UNICODE);
    tl_finish_response();
    foreach ($notify as [$o, $st]) tl_order_after_move($pdo, $o, $st);   // клиенту и сотрудникам нового этапа
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("admin_api.php: " . $e->getMessage());
    out(["success" => false, "message" => "Ошибка базы данных"], 500);
}