<?php

/*
| Вебхук бота MAX для управления сайтом.
| Работает с теми же таблицами, что и admin.html, поэтому правки видны в обеих сторонах.
| Администраторам доступно управление (регистрация: /admin КЛЮЧ), остальным — магазин: каталог, заказ, звонок, отзывы.
*/

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/schema.php";
require __DIR__ . "/config/admin_ops.php";
require __DIR__ . "/config/max_client.php";
require __DIR__ . "/config/max_shop.php";

header("Content-Type: application/json; charset=UTF-8");

$cfg = tl_max_config();

if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); exit; }

$secret = (string)$cfg["webhook_secret"];
$given  = (string)($_SERVER["HTTP_X_MAX_BOT_API_SECRET"] ?? "");
if ($secret === "" || !hash_equals($secret, $given)) { http_response_code(403); exit; }

$update = json_decode(file_get_contents("php://input"), true);
if (!is_array($update)) { http_response_code(400); exit; }

// MAX ждёт быстрый 200, всё остальное делаем после ответа
http_response_code(200);
echo "{}";
tl_finish_response();
ignore_user_abort(true);

try {
    tl_ensure_tables($pdo);
    tl_ensure_max($pdo);
    tl_bot_handle($pdo, $update);
} catch (Throwable $e) {
    error_log("max_bot: " . $e->getMessage());
}
exit;

/* =========================== состояние диалога =========================== */

function tl_state_get(PDO $pdo, int $uid): array
{
    $s = $pdo->prepare("SELECT state, data FROM tl_max_state WHERE user_id = ? AND updated_at > (NOW() - INTERVAL 1 HOUR)");
    $s->execute([$uid]);
    $r = $s->fetch();
    return $r ? [$r["state"], json_decode((string)$r["data"], true) ?: []] : ["", []];
}

function tl_state_set(PDO $pdo, int $uid, string $state, array $data = []): void
{
    $pdo->prepare(
        "REPLACE INTO tl_max_state (user_id, state, data, updated_at) VALUES (?, ?, ?, NOW())"
    )->execute([$uid, $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function tl_state_clear(PDO $pdo, int $uid): void
{
    $pdo->prepare("DELETE FROM tl_max_state WHERE user_id = ?")->execute([$uid]);
}

/* =========================== экраны: [текст, кнопки] =========================== */

function scr_menu(PDO $pdo, ?array $acc = null): array
{
    $acc = $acc ?: tl_acc_admin("bot");
    if (!$acc["admin"]) return scr_staff_menu($pdo, $acc);

    $s  = tl_stats($pdo);
    $c  = tl_stage_counts($pdo, $acc);
    $st = tl_stages();
    $line = [];
    foreach ($st as $k => $v) $line[] = $v["label"] . ": " . $c[$k];
    $text = "Управление сайтом TreeLife\n\n"
          . "Заказы по этапам\n" . implode(" · ", $line) . "\n\n"
          . "Заказов за месяц: " . $s["orders_month"] . ", выручка " . tl_money($s["revenue_month"]) . "\n"
          . "Заявок на звонок: " . $s["calls"] . "\n"
          . "Отзывов на модерации: " . $s["reviews_wait"];
    $kb = [[tl_btn("📦 Все заказы", "o:l:all")]];
    $row = [];
    foreach ($st as $k => $v) {
        $row[] = tl_btn($v["label"] . " (" . $c[$k] . ")", "o:l:" . $k);
        if (count($row) === 3) { $kb[] = $row; $row = []; }
    }
    if ($row) $kb[] = $row;
    $kb[] = [tl_btn("📞 Заявки (" . $s["calls"] . ")", "c:l"), tl_btn("💬 Отзывы (" . $s["reviews_wait"] . ")", "r:n")];
    $kb[] = [tl_btn("➕ Новый заказ", "n:s"), tl_btn("🔄 Обновить", "m")];
    $kb[] = [tl_btn("💬 Вопросы клиентов", "q:l"), tl_btn("🛍 Витрина", "k:m")];
    return [$text, $kb];
}

/* Меню сотрудника: только его этапы (или вся база) */
function scr_staff_menu(PDO $pdo, array $acc): array
{
    $c  = tl_stage_counts($pdo, $acc);
    $st = tl_stages();
    $text = "Рабочее место: заказы\n" . tl_acc_label($acc) . "\n\nЖдут действия:";
    $kb = [[tl_btn($acc["all"] ? "📦 Все заказы" : "📦 Мои заказы", "o:l:all")]];
    $row = [];
    foreach ($acc["zones"] as $z) {
        $text .= "\n• " . $st[$z]["label"] . ": " . $st[$z]["action"] . " — " . $c[$z];
        $row[] = tl_btn($st[$z]["label"] . " (" . $c[$z] . ")", "o:l:" . $z);
        if (count($row) === 2) { $kb[] = $row; $row = []; }
    }
    if ($row) $kb[] = $row;
    $kb[] = [tl_btn("🔄 Обновить", "m"), tl_btn("🛍 Витрина", "k:m")];
    return [$text, $kb];
}

function scr_orders(PDO $pdo, string $filter, ?array $acc = null): array
{
    $acc  = $acc ?: tl_acc_admin("bot");
    $st   = tl_order_statuses();
    $rows = tl_orders_visible($pdo, $acc, $filter, 8);
    $title = $filter === "all" ? ($acc["all"] ? "Последние заказы" : "Заказы на ваших этапах") : "Заказы: " . ($st[$filter] ?? $filter);
    $kb = [];
    foreach ($rows as $o) {
        $kb[] = [tl_btn("№" . $o["id"] . " · " . mb_substr($o["name"], 0, 18) . " · " . tl_money($o["total"]) . " · " . ($st[$o["status"]] ?? ""), "o:v:" . $o["id"])];
    }
    $f = [tl_btn(($filter === "all" ? "• " : "") . "Все", "o:l:all")];
    foreach ($acc["zones"] as $z) $f[] = tl_btn(($z === $filter ? "• " : "") . tl_stages()[$z]["label"], "o:l:" . $z);
    foreach (array_chunk($f, 3) as $chunk) $kb[] = $chunk;
    $kb[] = [tl_btn("← Меню", "m")];
    return [$rows ? $title . " (нажмите на заказ):" : $title . ": пусто", $kb];
}

function scr_order(PDO $pdo, int $id, string $note = "", ?array $acc = null): array
{
    $acc = $acc ?: tl_acc_admin("bot");
    $o = tl_order_get($pdo, $id);
    if (!$o || !tl_acc_can_view($acc, $o)) return ["Заказ не найден или недоступен вам.", [[tl_btn("← К заказам", "o:l:all"), tl_btn("Меню", "m")]]];

    $stages = tl_stages();
    $kb = [];
    $stage = $stages[$o["status"]] ?? null;
    if ($stage) {
        $kb[] = [tl_btn($stage["btn"], "o:a:" . $id)];
    } elseif ($o["status"] === "shipped") {
        $kb[] = [tl_btn("✅ Заказ выполнен", "o:a:" . $id)];
    }
    $row = [];
    $prev = tl_stage_prev($o["status"]);
    if ($prev && $o["status"] !== "cancelled") $row[] = tl_btn("↩ Вернуть: " . (tl_order_statuses()[$prev] ?? $prev), "o:b:" . $id);
    if ($stage) $row[] = tl_btn(($o["buyer_type"] ?? "person") === "company" ? "👤 Сделать физлицом" : "🏢 Сделать юрлицом", "o:t:" . $id);
    if ($row) $kb[] = $row;
    $row = [tl_btn("✏️ Комментарий", "o:c:" . $id)];
    if ($stage) $row[] = tl_btn("🧾 Реквизиты", "o:pv:" . $id . ":requisites");
    $kb[] = $row;
    if ($o["status"] === "shipping") $kb[] = [tl_btn("📍 Указать ПВЗ", "o:pv:" . $id . ":pvz")];
    $row = [tl_btn("📜 История", "o:h:" . $id)];
    if (tl_acc_can_cancel($acc, $o) && !in_array($o["status"], ["cancelled", "done"], true)) $row[] = tl_btn("✖ Отменить заказ", "o:x:" . $id);
    $kb[] = $row;
    if ($acc["admin"]) $kb[] = [tl_btn("⚙️ Статус вручную", "o:sm:" . $id), tl_btn("🗑 Удалить", "o:d:" . $id)];
    $kb[] = [tl_btn("← К заказам", "o:l:" . ($stage ? $o["status"] : "all")), tl_btn("Меню", "m")];
    return [($note !== "" ? $note . "\n\n" : "") . tl_order_text($o), $kb];
}

/* Журнал переходов заказа */
function scr_order_history(PDO $pdo, int $id, array $acc): array
{
    $o = tl_order_get($pdo, $id);
    if (!$o || !tl_acc_can_view($acc, $o)) return ["Заказ не найден или недоступен вам.", [[tl_btn("← Меню", "m")]]];
    $st = tl_order_statuses();
    $text = "История заказа №" . $id . "\n";
    $rows = tl_order_history($pdo, $id, 12);
    if (!$rows) $text .= "\nЗаписей пока нет.";
    foreach ($rows as $r) {
        $move = $r["from_status"] === null ? "создан" : ($r["from_status"] === $r["to_status"] ? "правка" : ($st[$r["from_status"]] ?? $r["from_status"]) . " → " . ($st[$r["to_status"]] ?? $r["to_status"]));
        $text .= "\n" . date("d.m H:i", strtotime($r["created_at"])) . " · " . ($r["by_name"] ?: "—") . ": " . $move . ($r["note"] ? " (" . $r["note"] . ")" : "");
    }
    return [$text, [[tl_btn("← К заказу", "o:v:" . $id), tl_btn("Меню", "m")]]];
}

function scr_review(PDO $pdo, string $note = ""): array
{
    $r = tl_review_next_pending($pdo);
    if (!$r) return [($note !== "" ? $note . "\n\n" : "") . "Отзывов на модерации нет.", [[tl_btn("← Меню", "m")]]];
    return [($note !== "" ? $note . "\n\n" : "") . tl_review_text($r), [
        [tl_btn("✅ Опубликовать", "r:a:" . $r["id"]), tl_btn("❌ Отклонить", "r:r:" . $r["id"])],
        [tl_btn("🗑 Удалить", "r:d:" . $r["id"]), tl_btn("← Меню", "m")],
    ]];
}

function scr_questions(PDO $pdo, string $note = ""): array
{
    $pre  = $note !== "" ? $note . "\n\n" : "";
    $rows = tl_questions_recent($pdo, 6);
    if (!$rows) return [$pre . "Вопросов от клиентов пока нет.", [[tl_btn("← Меню", "m")]]];
    $text = $pre . "Вопросы клиентов (по одному на клиента, последние):\n"; $kb = [];
    foreach ($rows as $r) {
        $who = trim((string)$r["name"]) !== "" ? $r["name"] : "клиент " . $r["max_user"];
        $text .= "\n" . ((int)$r["answered"] ? "✅ " : "❗ ") . $who . ", " . date("d.m H:i", strtotime($r["created_at"])) . "\n" . mb_substr($r["text"], 0, 160) . "\n";
        $kb[] = [tl_btn(((int)$r["answered"] ? "Ответить ещё: " : "Ответить: ") . mb_substr($who, 0, 20), "q:r:" . $r["max_user"])];
    }
    $kb[] = [tl_btn("← Меню", "m")];
    return [$text, $kb];
}

function scr_calls(PDO $pdo): array
{
    $rows = tl_calls_list($pdo, 6);
    if (!$rows) return ["Заявок на звонок нет.", [[tl_btn("← Меню", "m")]]];
    $text = "Последние заявки:\n"; $kb = [];
    foreach ($rows as $c) {
        $text .= "\n" . tl_call_text($c) . "\n";
        $kb[] = [tl_btn("✔ Обработана №" . $c["id"] . " (удалить)", "c:d:" . $c["id"])];
    }
    $kb[] = [tl_btn("← Меню", "m")];
    return [$text, $kb];
}

/* =========================== обработка =========================== */

function tl_bot_handle(PDO $pdo, array $u): void
{
    $type = (string)($u["update_type"] ?? "");

    if ($type === "message_callback") {
        $cb  = $u["callback"] ?? [];
        $uid = (int)($cb["user"]["user_id"] ?? 0);
        $cid = (string)($cb["callback_id"] ?? "");
        if (!$uid || $cid === "") return;
        $payload = (string)($cb["payload"] ?? "");
        $acc     = tl_bot_access($pdo, $uid);     // администратор, сотрудник по зонам или null (обычный клиент)
        $isAdmin = $acc !== null;
        // кнопки магазина доступны всем; остальное только администраторам и сотрудникам
        if (strpos($payload, "k:") === 0 || !$isAdmin) {
            if (strpos($payload, "k:") !== 0) $payload = "k:m";
            [$text, $kb, $toast] = tl_shop_callback($pdo, $uid, $isAdmin, $payload);
        } else {
            [$text, $kb, $toast] = tl_bot_callback($pdo, $uid, $payload, $acc);
        }
        tl_max_answer($cid, $text, $kb, $toast);
        return;
    }

    if ($type === "bot_started") {
        $uid = (int)($u["user"]["user_id"] ?? 0);
        if (!$uid) return;
        $acc = tl_bot_access($pdo, $uid);
        if ($acc) { [$t, $k] = scr_menu($pdo, $acc); }
        else { [$t, $k] = shop_menu(false, $pdo, $uid); }
        tl_max_send($uid, $t, $k);
        return;
    }

    if ($type === "message_created") {
        $msg = $u["message"] ?? [];
        if (!empty($msg["sender"]["is_bot"])) return;
        $uid  = (int)($msg["sender"]["user_id"] ?? 0);
        $text = trim((string)($msg["body"]["text"] ?? ""));
        if (!$uid) return;
        tl_bot_message($pdo, $uid, $text, (string)($msg["sender"]["name"] ?? ($msg["sender"]["first_name"] ?? "")));
    }
}

function tl_max_is_admin(PDO $pdo, int $uid): bool
{
    $s = $pdo->prepare("SELECT 1 FROM tl_max_admins WHERE user_id = ?");
    $s->execute([$uid]);
    return (bool)$s->fetchColumn();
}

function tl_bot_message(PDO $pdo, int $uid, string $text, string $senderName): void
{
    $cfg = tl_max_config();

    // регистрация администратора
    if (preg_match('~^/admin\s+(\S+)$~u', $text, $m)) {
        usleep(600000);   // не даём быстро подбирать ключ
        $key = (string)$cfg["admin_key"];
        if ($key !== "" && hash_equals($key, $m[1])) {
            $pdo->prepare("REPLACE INTO tl_max_admins (user_id, name, created_at) VALUES (?, ?, NOW())")
                ->execute([$uid, mb_substr($senderName, 0, 80)]);
            $acc0 = tl_acc_admin("bot", $senderName);
            [$t, $k] = scr_menu($pdo, $acc0);
            tl_max_send($uid, "Готово, вы администратор. Новые заказы, заявки и отзывы будут приходить сюда.\n\n" . $t, $k);
        } else {
            tl_max_send($uid, "Неверный ключ.");
        }
        return;
    }

    // подключение сотрудника: код выдаёт администратор на странице «Заказы по этапам»
    if (preg_match('~^/staff\s+(\d{6})$~u', $text, $m)) {
        usleep(600000);   // не даём быстро подбирать код
        $row = tl_staff_bind_bot($pdo, $m[1], $uid, $senderName);
        if ($row) {
            $acc = tl_bot_access($pdo, $uid);
            [$t, $k] = scr_menu($pdo, $acc);
            tl_max_send($uid, "Готово, вы подключены как сотрудник. " . tl_acc_label($acc) . ".\nЗаказы вашего этапа будут приходить сюда.\n\n" . $t, $k);
        } else {
            tl_max_send($uid, "Код неверный или устарел. Попросите администратора создать новый.");
        }
        return;
    }

    $acc     = tl_bot_access($pdo, $uid);
    $isAdmin = $acc !== null;
    [$st0] = tl_state_get($pdo, $uid);

    // клиентские диалоги (и любые сообщения обычных пользователей)
    if (!$isAdmin || strpos($st0, "k_") === 0) {
        tl_shop_message($pdo, $uid, $isAdmin, $text, $senderName);
        return;
    }

    if (in_array(mb_strtolower($text), ["/start", "/menu", "меню", "/cancel", "отмена"], true)) {
        tl_state_clear($pdo, $uid);
        [$t, $k] = scr_menu($pdo, $acc);
        tl_max_send($uid, $t, $k);
        return;
    }

    [$state, $data] = tl_state_get($pdo, $uid);

    /* ----- этапы заказа: ввод номера счёта / накладной, реквизитов, ПВЗ, причины возврата ----- */
    if (preg_match('~^pf:(\d+):(invoice_no|waybill_no)$~', $state, $m)) {
        $id = (int)$m[1]; $o = tl_order_get($pdo, $id);
        $val = ($text === "-") ? "-" : $text;
        $r = $o ? tl_order_move($pdo, $id, (string)tl_stage_next($o["status"]), $acc, "", [$m[2] => $val]) : ["ok" => false, "msg" => "Заказ не найден"];
        tl_state_clear($pdo, $uid);
        [$t, $k] = scr_after_move($pdo, $id, $r, $acc);
        tl_max_send($uid, $t, $k);
        return;
    }
    if (preg_match('~^pv:(\d+):(requisites|pvz)$~', $state, $m)) {
        $id = (int)$m[1]; $o = tl_order_get($pdo, $id);
        $r = ($o && tl_acc_can_view($acc, $o)) ? tl_order_move($pdo, $id, (string)$o["status"], $acc, "", [$m[2] => ($text === "-" ? "" : $text)]) : ["ok" => false];
        tl_state_clear($pdo, $uid);
        [$t, $k] = scr_order($pdo, $id, !empty($r["ok"]) ? "Сохранено." : "Не удалось сохранить.", $acc);
        tl_max_send($uid, $t, $k);
        return;
    }
    if (preg_match('~^pb:(\d+)$~', $state, $m)) {
        $id = (int)$m[1]; $o = tl_order_get($pdo, $id);
        $r = $o ? tl_order_move($pdo, $id, (string)tl_stage_prev($o["status"]), $acc, "Возврат: " . $text) : ["ok" => false, "msg" => "Заказ не найден"];
        tl_state_clear($pdo, $uid);
        [$t, $k] = scr_after_move($pdo, $id, $r, $acc);
        tl_max_send($uid, $t, $k);
        return;
    }

    if ($state === "n_name") {
        if (!tl_name_ok($text)) { tl_max_send($uid, "Имя: 2–60 символов, только буквы. Введите ещё раз или /cancel."); return; }
        tl_state_set($pdo, $uid, "n_phone", ["name" => $text]);
        tl_max_send($uid, "Телефон клиента (например, 8 928 330-02-06):");
        return;
    }

    if ($state === "n_phone") {
        $p = tl_phone_norm($text);
        if ($p === "") { tl_max_send($uid, "Не похоже на российский номер. Введите ещё раз или /cancel."); return; }
        $data["phone"] = $p;
        tl_state_set($pdo, $uid, "n_prod", $data);
        $kb = [];
        foreach (tl_catalog() as $k => $it) $kb[] = [tl_btn($it["name"] . " — " . tl_money($it["price"]), "n:p:" . $k)];
        $kb[] = [tl_btn("Отмена", "m")];
        tl_max_send($uid, "Что заказал клиент?", $kb);
        return;
    }

    if (strpos($state, "qa:") === 0) {
        $to = (int)substr($state, 3);
        if (mb_strlen($text) < 1) { tl_max_send($uid, "Напишите ответ текстом или /cancel."); return; }
        $r = tl_max_send($to, "Ответ менеджера:\n\n" . $text, [[tl_btn("💬 Задать ещё вопрос", "k:ask"), tl_btn("← Меню", "k:m")]]);
        tl_state_clear($pdo, $uid);
        if ($r["ok"]) {
            tl_msg_log($pdo, $to, "out", $text);
            [$t, $k] = scr_questions($pdo, "Ответ отправлен клиенту.");
        } else {
            [$t, $k] = scr_questions($pdo, "Не удалось отправить: клиент мог заблокировать бота. Позвоните по номеру из вопроса.");
        }
        tl_max_send($uid, $t, $k);
        return;
    }

    if (strpos($state, "oc:") === 0) {
        $id = (int)substr($state, 3);
        $o  = tl_order_get($pdo, $id);
        if ($o && tl_acc_can_view($acc, $o)) {
            tl_order_set_comment($pdo, $id, $text === "-" ? "" : $text);
            tl_order_log($pdo, $id, (string)$o["status"], (string)$o["status"], $acc, "Комментарий: " . ($text === "-" ? "(очищен)" : mb_substr($text, 0, 200)));
        }
        tl_state_clear($pdo, $uid);
        [$t, $k] = scr_order($pdo, $id, "Комментарий сохранён.", $acc);
        tl_max_send($uid, $t, $k);
        return;
    }

    [$t, $k] = scr_menu($pdo, $acc);
    tl_max_send($uid, $t, $k);
}

/* Экран после перехода заказа на другой этап (или сообщение об ошибке) */
function scr_after_move(PDO $pdo, int $id, array $r, array $acc): array
{
    if (empty($r["ok"])) {
        $msg = (string)($r["msg"] ?? "Не удалось выполнить");
        return scr_order($pdo, $id, "⚠️ " . $msg, $acc);
    }
    $o = $r["order"];
    $lab = tl_order_statuses()[$o["status"]] ?? $o["status"];
    $note = $r["from"] !== $r["to"] ? "✅ Заказ №" . $id . " переведён на этап «" . $lab . "»." : "Сохранено.";
    if (tl_acc_can_view($acc, $o)) return scr_order($pdo, $id, $note, $acc);
    // заказ ушёл из зоны сотрудника: возвращаем к списку его этапа
    $back = $r["from"] ?? "all";
    return [$note, [[tl_btn("← К списку", "o:l:" . (in_array($back, $acc["zones"], true) ? $back : "all")), tl_btn("Меню", "m")]]];
}

/* Возвращает [текст, кнопки, всплывающее уведомление] */
function tl_bot_callback(PDO $pdo, int $uid, string $payload, array $acc): array
{
    $p = explode(":", $payload);
    $a = $p[0] ?? "";

    if ($a === "m") {
        tl_state_clear($pdo, $uid);
        [$t, $k] = scr_menu($pdo, $acc);
        return [$t, $k, ""];
    }

    // сотрудник по зонам работает только с заказами; звонки, отзывы, вопросы, рассылка — у администратора
    if (!$acc["admin"] && $a !== "o") {
        [$t, $k] = scr_menu($pdo, $acc);
        return [$t, $k, ""];
    }

    if ($a === "o") {
        $act = $p[1] ?? "";
        $id  = (int)($p[2] ?? 0);

        if ($act === "l") { [$t, $k] = scr_orders($pdo, (string)($p[2] ?? "all"), $acc); return [$t, $k, ""]; }

        // все остальные действия — над конкретным заказом, и только если он в зоне доступа
        $o = tl_order_get($pdo, $id);
        if (!$o || !tl_acc_can_view($acc, $o)) {
            [$t, $k] = scr_orders($pdo, "all", $acc);
            return ["Заказ не найден или недоступен вам.\n\n" . $t, $k, ""];
        }

        if ($act === "v") { [$t, $k] = scr_order($pdo, $id, "", $acc); return [$t, $k, ""]; }
        if ($act === "h") { [$t, $k] = scr_order_history($pdo, $id, $acc); return [$t, $k, ""]; }

        // этап выполнен: переводим на следующий
        if ($act === "a") {
            $to = tl_stage_next($o["status"]);
            if (!$to || $o["status"] === "cancelled") return [tl_order_text($o), [[tl_btn("← К заказу", "o:v:" . $id)]], "Следующего этапа нет"];
            $r = tl_order_move($pdo, $id, $to, $acc);
            if (!empty($r["need"])) {
                tl_state_set($pdo, $uid, "pf:" . $id . ":" . $r["need"]);
                return [$r["msg"] . "\n\nЗаказ №" . $id, [[tl_btn("Отмена", "o:v:" . $id)]], ""];
            }
            [$t, $k] = scr_after_move($pdo, $id, $r, $acc);
            return [$t, $k, !empty($r["ok"]) ? "Этап выполнен" : "Не вышло"];
        }
        // вернуть на предыдущий этап (с причиной)
        if ($act === "b") {
            if (!tl_stage_prev($o["status"])) return [tl_order_text($o), [[tl_btn("← К заказу", "o:v:" . $id)]], "Назад некуда"];
            tl_state_set($pdo, $uid, "pb:" . $id);
            return ["Почему возвращаете заказ №" . $id . " на этап «" . (tl_order_statuses()[tl_stage_prev($o["status"])] ?? "") . "»? Напишите причину одним сообщением.", [[tl_btn("Отмена", "o:v:" . $id)]], ""];
        }
        // отмена
        if ($act === "x") {
            if (!tl_acc_can_cancel($acc, $o)) return [tl_order_text($o), [[tl_btn("← К заказу", "o:v:" . $id)]], "Нет прав на отмену"];
            return ["Отменить заказ №" . $id . "? Клиент из бота получит уведомление.", [[tl_btn("Да, отменить", "o:xy:" . $id), tl_btn("Нет", "o:v:" . $id)]], ""];
        }
        if ($act === "xy") {
            $r = tl_order_move($pdo, $id, "cancelled", $acc, "Заказ отменён");
            [$t, $k] = scr_after_move($pdo, $id, $r, $acc);
            return [$t, $k, !empty($r["ok"]) ? "Заказ отменён" : "Не вышло"];
        }
        // физлицо / юрлицо
        if ($act === "t") {
            $new = ($o["buyer_type"] ?? "person") === "company" ? "person" : "company";
            $r = tl_order_move($pdo, $id, (string)$o["status"], $acc, "Покупатель: " . ($new === "company" ? "юрлицо" : "физлицо"), ["buyer_type" => $new]);
            [$t, $k] = scr_order($pdo, $id, !empty($r["ok"]) ? "Тип покупателя изменён." : "Не удалось изменить.", $acc);
            return [$t, $k, ""];
        }
        // реквизиты юрлица / ПВЗ
        if ($act === "pv") {
            $field = (string)($p[3] ?? "");
            if (!in_array($field, ["requisites", "pvz"], true)) return [tl_order_text($o), [[tl_btn("← К заказу", "o:v:" . $id)]], ""];
            tl_state_set($pdo, $uid, "pv:" . $id . ":" . $field);
            $what = $field === "pvz" ? "адрес пункта выдачи (ПВЗ)" : "реквизиты покупателя (название, ИНН, КПП, адрес)";
            return ["Заказ №" . $id . ": напишите " . $what . " одним сообщением (или «-», чтобы очистить).", [[tl_btn("Отмена", "o:v:" . $id)]], ""];
        }
        if ($act === "c") {
            tl_state_set($pdo, $uid, "oc:" . $id);
            return ["Напишите комментарий к заказу №" . $id . " одним сообщением (или «-», чтобы очистить).",
                    [[tl_btn("Отмена", "o:v:" . $id)]], ""];
        }

        // дальше — только администратор
        if (!$acc["admin"]) return [tl_order_text($o), [[tl_btn("← К заказу", "o:v:" . $id)]], "Только для администратора"];

        if ($act === "sm") {
            $kb = [];
            foreach (tl_order_statuses() as $k => $label) {
                if ($k === $o["status"]) continue;
                $kb[] = [tl_btn("→ " . $label, "o:s:" . $id . ":" . $k)];
            }
            $kb[] = [tl_btn("← К заказу", "o:v:" . $id)];
            return ["Заказ №" . $id . ": выберите статус вручную (в обход этапов).", $kb, ""];
        }
        if ($act === "s") {
            $ok = tl_order_set_status($pdo, $id, (string)($p[3] ?? ""));
            [$t, $k] = scr_order($pdo, $id, "", $acc);
            return [$t, $k, $ok ? "Статус изменён" : "Неверный статус"];
        }
        if ($act === "d") {
            return ["Удалить заказ №" . $id . " без возможности восстановления?",
                    [[tl_btn("Да, удалить", "o:dy:" . $id), tl_btn("Нет", "o:v:" . $id)]], ""];
        }
        if ($act === "dy") {
            tl_order_delete($pdo, $id);
            [$t, $k] = scr_orders($pdo, "all", $acc);
            return [$t, $k, "Заказ удалён"];
        }
    }

    if ($a === "q") {
        $act = $p[1] ?? "";
        if ($act === "r") {
            $to = (int)($p[2] ?? 0);
            tl_state_set($pdo, $uid, "qa:" . $to);
            $last = $pdo->prepare("SELECT text FROM tl_max_msgs WHERE max_user = ? AND dir = 'in' ORDER BY id DESC LIMIT 1");
            $last->execute([$to]);
            return ["Ответ клиенту. Его вопрос:\n\n" . mb_substr((string)$last->fetchColumn(), 0, 600) . "\n\nНапишите ответ одним сообщением, бот отправит его клиенту.", [[tl_btn("Отмена", "q:l")]], ""];
        }
        [$t, $k] = scr_questions($pdo);
        return [$t, $k, ""];
    }

    if ($a === "c") {
        if (($p[1] ?? "") === "d") tl_call_delete($pdo, (int)($p[2] ?? 0));
        [$t, $k] = scr_calls($pdo);
        return [$t, $k, ($p[1] ?? "") === "d" ? "Заявка удалена" : ""];
    }

    if ($a === "r") {
        $act = $p[1] ?? "";
        $id  = (int)($p[2] ?? 0);
        $toast = "";
        if ($act === "a") { tl_review_set_status($pdo, $id, "approved"); $toast = "Опубликован"; }
        if ($act === "r") { tl_review_set_status($pdo, $id, "rejected"); $toast = "Отклонён"; }
        if ($act === "d") { tl_review_delete($pdo, $id);                 $toast = "Удалён"; }
        [$t, $k] = scr_review($pdo);
        return [$t, $k, $toast];
    }

    if ($a === "n") {
        $act = $p[1] ?? "";
        if ($act === "s") {
            tl_state_set($pdo, $uid, "n_name");
            return ["Новый заказ. Введите имя клиента:", [[tl_btn("Отмена", "m")]], ""];
        }
        if ($act === "p") {
            [$state, $data] = tl_state_get($pdo, $uid);
            $cat = tl_catalog();
            $key = (string)($p[2] ?? "");
            if ($state !== "n_prod" || !isset($cat[$key]) || empty($data["name"]) || empty($data["phone"])) {
                [$t, $k] = scr_menu($pdo, $acc);
                return ["Диалог устарел, начните заново.\n\n" . $t, $k, ""];
            }
            $it = $cat[$key];
            $id = tl_order_create($pdo, $data["name"], $data["phone"], [["name" => $it["name"], "qty" => 1, "price" => $it["price"]]]);
            tl_state_clear($pdo, $uid);
            [$t, $k] = scr_order($pdo, $id, "Заказ создан.", $acc);
            return [$t, $k, "Создано"];
        }
    }

    [$t, $k] = scr_menu($pdo, $acc);
    return [$t, $k, ""];
}
