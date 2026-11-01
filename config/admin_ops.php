<?php

/*
| Общие операции над заказами, отзывами и заявками.
| Их использует бот MAX. Админ-панель на сайте работает с теми же таблицами
| (tl_orders, tl_reviews, tl_callbacks), поэтому правки видны в обеих сторонах сразу.
*/

require_once __DIR__ . "/catalog.php";
require_once __DIR__ . "/pipeline.php";   // этапы заказа и права сотрудников

const TL_PHONE_PUBLIC = "+7 928 330-02-06";

/* tl_order_statuses() теперь в pipeline.php: Заявка, Счёт, Оплата, Чек, Отправка, Отправлен, Выполнен, Отменён */

function tl_money($n): string
{
    return number_format((int)$n, 0, "", " ") . " ₽";
}

function tl_phone_show(string $p): string
{
    if (!preg_match('/^7\d{10}$/', $p)) return $p !== "" ? "+" . $p : "—";
    return "+7 " . substr($p, 1, 3) . " " . substr($p, 4, 3) . "-" . substr($p, 7, 2) . "-" . substr($p, 9, 2);
}

/* "8 928 ..." / "+7928..." / "928..." -> "7928...", либо "" если номер неверный */
function tl_phone_norm(string $raw): string
{
    $p = preg_replace('/\D/', '', $raw);
    if (strlen($p) === 11 && $p[0] === "8") $p = "7" . substr($p, 1);
    if (strlen($p) === 10) $p = "7" . $p;
    return (strlen($p) === 11 && $p[0] === "7") ? $p : "";
}

function tl_name_ok(string $n): bool
{
    $l = mb_strlen($n);
    return $l >= 2 && $l <= 60 && (bool)preg_match('/^[\p{L} \-]+$/u', $n);
}

/* ---------- данные заявки по графам ---------- */

function tl_email_ok(string $e): bool
{
    return mb_strlen($e) <= 100 && (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

/* ФИО одной строкой; пустые части пропускаются */
function tl_fio(array $p): string
{
    $parts = [];
    foreach (["last_name", "first_name", "middle_name"] as $k) {
        $v = trim((string)($p[$k] ?? ""));
        if ($v !== "") $parts[] = $v;
    }
    return implode(" ", $parts);
}

/* Записывает графы заявки. Пустые значения не пишутся (в базе остаётся NULL). */
function tl_order_set_details(PDO $pdo, int $id, array $d): void
{
    $lim = ["last_name" => 60, "first_name" => 60, "middle_name" => 60, "email" => 100, "cdek" => 250, "agent" => 80];
    $set = []; $val = [];
    foreach ($lim as $k => $max) {
        $v = mb_substr(trim((string)($d[$k] ?? "")), 0, $max);
        if ($v === "") continue;
        $set[] = "$k = ?"; $val[] = $v;
    }
    if (!$set) return;
    $val[] = $id;
    $pdo->prepare("UPDATE tl_orders SET " . implode(", ", $set) . " WHERE id = ?")->execute($val);
}

/* ---------- заказы ---------- */

function tl_orders_list(PDO $pdo, string $status = "all", int $limit = 8): array
{
    $limit = max(1, min(50, $limit));
    if ($status !== "all" && isset(tl_order_statuses()[$status])) {
        $s = $pdo->prepare("SELECT * FROM tl_orders WHERE status = ? ORDER BY id DESC LIMIT $limit");
        $s->execute([$status]);
        return $s->fetchAll();
    }
    return $pdo->query("SELECT * FROM tl_orders ORDER BY id DESC LIMIT $limit")->fetchAll();
}

function tl_order_get(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare("SELECT * FROM tl_orders WHERE id = ?");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

function tl_order_text(array $o): string
{
    $st = tl_order_statuses();
    $lines = [];
    foreach (json_decode($o["items"], true) ?: [] as $it) {
        $lines[] = "• " . $it["name"] . " × " . (int)$it["qty"] . " — " . tl_money(((int)$it["price"]) * ((int)$it["qty"]));
    }
    $extra = "";
    if ((string)($o["email"] ?? "") !== "")  $extra .= "\nEmail: " . $o["email"];
    if ((string)($o["cdek"] ?? "") !== "")   $extra .= "\nАдрес СДЭК: " . $o["cdek"];
    if ((string)($o["agent"] ?? "") !== "")  $extra .= "\nАгент: " . $o["agent"];
    if (($o["buyer_type"] ?? "person") === "company") $extra .= "\nПокупатель: юрлицо";
    if ((string)($o["requisites"] ?? "") !== "") $extra .= "\nРеквизиты: " . $o["requisites"];
    if ((string)($o["invoice_no"] ?? "") !== "")  $extra .= "\nСчёт №: " . $o["invoice_no"];
    if ((string)($o["waybill_no"] ?? "") !== "")  $extra .= "\nНакладная №: " . $o["waybill_no"];
    if ((string)($o["pvz"] ?? "") !== "")         $extra .= "\nПВЗ: " . $o["pvz"];
    $stage = tl_stages()[$o["status"]] ?? null;
    $todo  = $stage ? "\n\n👉 Сейчас: " . tl_stage_action($o["status"], $o) : "";
    return "Заказ №" . $o["id"] . " · " . ($st[$o["status"]] ?? $o["status"]) . "\n"
         . date("d.m.Y H:i", strtotime($o["created_at"])) . ($o["manual"] ? " (внесён вручную)" : "") . "\n\n"
         . "Клиент: " . $o["name"] . "\n"
         . "Телефон: " . tl_phone_show((string)$o["phone"]) . $extra . "\n\n"
         . implode("\n", $lines) . "\n"
         . "Итого: " . tl_money($o["total"])
         . ((string)$o["comment"] !== "" ? "\n\nКомментарий: " . $o["comment"] : "")
         . $todo;
}

function tl_order_set_status(PDO $pdo, int $id, string $status): bool
{
    // ручная смена статуса администратором (в боте): любой статус, с записью в журнал
    $acc = tl_acc_admin("bot", "Админ (бот)");
    return tl_order_move($pdo, $id, $status, $acc, "Статус изменён вручную", [], true)["ok"];
}

/* Клиенту бота приходит сообщение при смене статуса. Ошибки отправки заказ не ломают. */
function tl_order_notify_client(PDO $pdo, array $o, string $status): void
{
    if (empty($o["max_user"]) || !function_exists("tl_max_send") || !tl_max_enabled()) return;
    $n = (int)$o["id"];
    $inv = (string)($o["invoice_no"] ?? "");
    $way = (string)($o["waybill_no"] ?? "");
    $pvz = (string)($o["pvz"] ?? "");
    $texts = [
        "invoice"   => "Заказ №" . $n . ": данные подтверждены, готовим счёт.",
        "payment"   => "По заказу №" . $n . " выставлен счёт" . ($inv !== "" && $inv !== "-" ? " №" . $inv : "") . ". После оплаты мы подтвердим поступление денег.",
        "receipt"   => "Оплата по заказу №" . $n . " получена, спасибо! Оформляем чек и документы.",
        "shipping"  => "Заказ №" . $n . " оформлен, готовим его к отправке.",
        "shipped"   => "Заказ №" . $n . " отправлен." . ($way !== "" && $way !== "-" ? "\nНакладная №" . $way : "") . ($pvz !== "" ? "\nПункт выдачи: " . $pvz : "") . "\nЕсли понадобится помощь с доставкой: " . TL_PHONE_PUBLIC,
        "done"      => "Заказ №" . $n . " выполнен. Спасибо, что выбрали TreeLife! Будем рады отзыву.",
        "cancelled" => "Заказ №" . $n . " отменён. Если это неожиданно, позвоните нам: " . TL_PHONE_PUBLIC,
    ];
    if (!isset($texts[$status])) return;
    try {
        $rows = [[tl_btn("📦 Мои заказы", "k:my")]];
        if ($status === "done") $rows = [[tl_btn("✍️ Оставить отзыв", "k:rv"), tl_btn("📦 Мои заказы", "k:my")]];
        tl_max_send((int)$o["max_user"], $texts[$status], $rows, 4);
    } catch (Throwable $e) {
        error_log("notify client: " . $e->getMessage());
    }
}

function tl_order_set_comment(PDO $pdo, int $id, string $comment): void
{
    $c = mb_substr(trim($comment), 0, 500);
    $pdo->prepare("UPDATE tl_orders SET comment = ? WHERE id = ?")->execute([$c !== "" ? $c : null, $id]);
}

function tl_order_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM tl_orders WHERE id = ?")->execute([$id]);
}

function tl_order_create(PDO $pdo, string $name, string $phone, array $lines, string $status = "new", string $comment = ""): int
{
    $total = 0;
    foreach ($lines as $l) $total += ((int)$l["qty"]) * ((int)$l["price"]);
    $pdo->prepare(
        "INSERT INTO tl_orders (user_id, name, phone, comment, items, total, status, created_at, manual, stage_at)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, NOW(), 1, NOW())"
    )->execute([
        mb_substr($name, 0, 60), $phone, $comment !== "" ? mb_substr($comment, 0, 500) : null,
        json_encode($lines, JSON_UNESCAPED_UNICODE), $total,
        isset(tl_order_statuses()[$status]) ? $status : "new",
    ]);
    $newId = (int)$pdo->lastInsertId();
    tl_order_created($pdo, $newId, "bot", "Админ (бот)", "Заказ внесён вручную");
    return $newId;
}

/* Первая запись в журнале заказа */
function tl_order_created(PDO $pdo, int $id, string $kind, string $name, string $note): void
{
    try {
        $o = tl_order_get($pdo, $id);
        tl_order_log($pdo, $id, null, $o ? (string)$o["status"] : "new", ["kind" => $kind, "name" => $name], $note);
    } catch (Throwable $e) {
        error_log("order log: " . $e->getMessage());
    }
}

/* ---------- отзывы ---------- */

function tl_review_next_pending(PDO $pdo): ?array
{
    $r = $pdo->query("SELECT * FROM tl_reviews WHERE status = 'pending' ORDER BY id ASC LIMIT 1")->fetch();
    return $r ?: null;
}

function tl_review_text(array $r): string
{
    return "Отзыв №" . $r["id"] . " · " . str_repeat("★", (int)$r["rating"]) . str_repeat("☆", 5 - (int)$r["rating"]) . "\n"
         . $r["name"] . ", " . date("d.m.Y H:i", strtotime($r["created_at"])) . "\n\n" . $r["text"];
}

function tl_review_set_status(PDO $pdo, int $id, string $status): bool
{
    if (!in_array($status, ["pending", "approved", "rejected"], true)) return false;
    $pdo->prepare("UPDATE tl_reviews SET status = ? WHERE id = ?")->execute([$status, $id]);
    return true;
}

function tl_review_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM tl_reviews WHERE id = ?")->execute([$id]);
}

/* ---------- заявки на звонок ---------- */

function tl_calls_list(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min(30, $limit));
    return $pdo->query("SELECT * FROM tl_callbacks ORDER BY id DESC LIMIT $limit")->fetchAll();
}

function tl_call_text(array $c): string
{
    return "Заявка №" . $c["id"] . " · " . date("d.m H:i", strtotime($c["created_at"])) . "\n"
         . $c["name"] . ", " . tl_phone_show((string)$c["phone"])
         . ((string)$c["topic"] !== "" ? "\nТема: " . $c["topic"] : "")
         . ((string)$c["message"] !== "" ? "\n" . $c["message"] : "");
}

function tl_call_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM tl_callbacks WHERE id = ?")->execute([$id]);
}

/* ---------- сводка ---------- */

function tl_stats(PDO $pdo): array
{
    return [
        "orders_new"   => (int)$pdo->query("SELECT COUNT(*) FROM tl_orders WHERE status = 'new'")->fetchColumn(),
        "orders_month" => (int)$pdo->query("SELECT COUNT(*) FROM tl_orders WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn(),
        "revenue_month"=> (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM tl_orders WHERE " . tl_paid_sql() . " AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn(),
        "calls"        => (int)$pdo->query("SELECT COUNT(*) FROM tl_callbacks")->fetchColumn(),
        "reviews_wait" => (int)$pdo->query("SELECT COUNT(*) FROM tl_reviews WHERE status = 'pending'")->fetchColumn(),
    ];
}


/* ---------- операции для клиентов бота ---------- */

/* Заказ от клиента из бота (не ручной). $lines: [["id","name","price","qty"], ...] */
function tl_order_create_site(PDO $pdo, string $name, string $phone, array $lines, string $comment, int $maxUser, array $details = []): int
{
    $total = 0;
    foreach ($lines as $l) $total += ((int)$l["qty"]) * ((int)$l["price"]);
    $pdo->prepare(
        "INSERT INTO tl_orders (user_id, name, phone, comment, items, total, status, created_at, manual, max_user, stage_at)
         VALUES (NULL, ?, ?, ?, ?, ?, 'new', NOW(), 0, ?, NOW())"
    )->execute([
        mb_substr($name, 0, 190), $phone, $comment !== "" ? mb_substr($comment, 0, 500) : null,
        json_encode(array_values($lines), JSON_UNESCAPED_UNICODE), $total, $maxUser,
    ]);
    $newId = (int)$pdo->lastInsertId();
    tl_order_set_details($pdo, $newId, $details);
    tl_order_created($pdo, $newId, "bot", "Клиент (бот MAX)", "Заказ оформлен клиентом");
    return $newId;
}

function tl_orders_of_user(PDO $pdo, int $maxUser, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    $s = $pdo->prepare("SELECT * FROM tl_orders WHERE max_user = ? ORDER BY id DESC LIMIT $limit");
    $s->execute([$maxUser]);
    return $s->fetchAll();
}

function tl_callback_create(PDO $pdo, string $name, string $phone, string $topic, string $message): int
{
    $pdo->prepare("INSERT INTO tl_callbacks (name, phone, topic, message, created_at) VALUES (?, ?, ?, ?, NOW())")
        ->execute([mb_substr($name, 0, 60), $phone, $topic !== "" ? mb_substr($topic, 0, 40) : null, $message !== "" ? mb_substr($message, 0, 500) : null]);
    return (int)$pdo->lastInsertId();
}

function tl_review_create(PDO $pdo, string $name, int $rating, string $text): int
{
    $pdo->prepare("INSERT INTO tl_reviews (name, rating, text, status, source, created_at) VALUES (?, ?, ?, 'pending', 'bot', NOW())")
        ->execute([mb_substr($name, 0, 60), max(1, min(5, $rating)), mb_substr($text, 0, 1000)]);
    return (int)$pdo->lastInsertId();
}

function tl_reviews_public(PDO $pdo, int $limit = 3): array
{
    $limit = max(1, min(10, $limit));
    return $pdo->query("SELECT * FROM tl_reviews WHERE status = 'approved' ORDER BY created_at DESC, id DESC LIMIT $limit")->fetchAll();
}


/* ---------- согласие на рассылку ---------- */

function tl_sub_set(PDO $pdo, int $uid, bool $on): void
{
    $pdo->prepare("INSERT IGNORE INTO tl_max_users (user_id) VALUES (?)")->execute([$uid]);
    $pdo->prepare("UPDATE tl_max_users SET subscribed = ?, sub_at = NOW() WHERE user_id = ?")->execute([$on ? 1 : 0, $uid]);
}

function tl_sub_count(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM tl_max_users WHERE subscribed = 1")->fetchColumn();
}

/* Рассылка подписчикам. Возвращает [отправлено, ошибок]. Не больше 1500 получателей за раз. */
function tl_broadcast(PDO $pdo, string $text): array
{
    @set_time_limit(600);
    ignore_user_abort(true);
    $ids = $pdo->query("SELECT user_id FROM tl_max_users WHERE subscribed = 1 ORDER BY user_id LIMIT 1500")->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0; $fail = 0;
    $rows = [[tl_btn("🛍 Каталог", "k:c"), tl_btn("🔕 Отписаться", "k:unsub")]];
    foreach ($ids as $id) {
        $r = tl_max_send((int)$id, $text, $rows, 6);
        if ($r["ok"]) $sent++; else $fail++;
        usleep(120000);   // не чаще ~8 сообщений в секунду
    }
    return [$sent, $fail];
}

/* ---------- переписка клиента с менеджером ---------- */

function tl_msg_log(PDO $pdo, int $maxUser, string $dir, string $text): void
{
    $pdo->prepare("INSERT INTO tl_max_msgs (max_user, dir, text, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$maxUser, $dir, mb_substr($text, 0, 1500)]);
}

/* Последние вопросы клиентов (по одному на клиента): [max_user, name, text, created_at, answered] */
function tl_questions_recent(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min(20, $limit));
    $rows = $pdo->query(
        "SELECT m.max_user, m.text, m.created_at, u.name,
                (SELECT COUNT(*) FROM tl_max_msgs o WHERE o.max_user = m.max_user AND o.dir = 'out' AND o.id > m.id) AS answered
         FROM tl_max_msgs m
         LEFT JOIN tl_max_users u ON u.user_id = m.max_user
         WHERE m.dir = 'in' AND m.id = (SELECT MAX(i.id) FROM tl_max_msgs i WHERE i.max_user = m.max_user AND i.dir = 'in')
         ORDER BY m.id DESC LIMIT $limit"
    )->fetchAll();
    return $rows;
}

/* ---------- привязка аккаунта сайта к аккаунту MAX ---------- */

function tl_link_code_make(PDO $pdo, int $siteUser): string
{
    $pdo->prepare("DELETE FROM tl_link_codes WHERE user_id = ? OR expires_at < NOW()")->execute([$siteUser]);
    for ($i = 0; $i < 5; $i++) {
        $code = (string)random_int(100000, 999999);
        try {
            $pdo->prepare("INSERT INTO tl_link_codes (code, user_id, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)")
                ->execute([$code, $siteUser]);
            return $code;
        } catch (PDOException $e) { /* такой код уже есть, пробуем другой */ }
    }
    throw new RuntimeException("code");
}

/* Бот получил код от клиента. true — привязали. */
function tl_link_consume(PDO $pdo, int $maxUser, string $code): bool
{
    usleep(500000);   // подбирать шестизначный код быстро не дадим
    $s = $pdo->prepare("SELECT user_id FROM tl_link_codes WHERE code = ? AND expires_at > NOW()");
    $s->execute([$code]);
    $site = $s->fetchColumn();
    if (!$site) return false;
    $pdo->prepare("REPLACE INTO tl_max_links (max_user, user_id, created_at) VALUES (?, ?, NOW())")->execute([$maxUser, (int)$site]);
    $pdo->prepare("DELETE FROM tl_link_codes WHERE code = ?")->execute([$code]);
    return true;
}

/* ---------- отчёты ---------- */

function tl_reports(PDO $pdo): array
{
    $paid = tl_paid_sql();

    // последние 12 месяцев (включая текущий)
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $k = date("Y-m", strtotime("first day of -$i month"));
        $months[$k] = ["month" => $k, "orders" => 0, "revenue" => 0, "site" => 0, "bot" => 0, "manual" => 0, "calls" => 0];
    }
    $from = array_key_first($months) . "-01";

    $q = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) n,
                SUM(CASE WHEN $paid THEN total ELSE 0 END) rev,
                SUM(CASE WHEN manual = 1 THEN 1 ELSE 0 END) n_manual,
                SUM(CASE WHEN manual = 0 AND max_user IS NOT NULL THEN 1 ELSE 0 END) n_bot,
                SUM(CASE WHEN manual = 0 AND max_user IS NULL THEN 1 ELSE 0 END) n_site
         FROM tl_orders WHERE created_at >= ? GROUP BY ym"
    );
    $q->execute([$from]);
    foreach ($q->fetchAll() as $r) {
        if (!isset($months[$r["ym"]])) continue;
        $months[$r["ym"]] = array_merge($months[$r["ym"]], [
            "orders" => (int)$r["n"], "revenue" => (int)$r["rev"],
            "site" => (int)$r["n_site"], "bot" => (int)$r["n_bot"], "manual" => (int)$r["n_manual"],
        ]);
    }
    $q = $pdo->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) n FROM tl_callbacks WHERE created_at >= ? GROUP BY ym");
    $q->execute([$from]);
    foreach ($q->fetchAll() as $r) if (isset($months[$r["ym"]])) $months[$r["ym"]]["calls"] = (int)$r["n"];

    // итоги за 12 месяцев
    $tot = $pdo->prepare(
        "SELECT COUNT(*) n, SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END) alive,
                SUM(CASE WHEN $paid THEN 1 ELSE 0 END) paid_n,
                SUM(CASE WHEN $paid THEN total ELSE 0 END) rev
         FROM tl_orders WHERE created_at >= ?"
    );
    $tot->execute([$from]);
    $t = $tot->fetch();
    $paidN = (int)$t["paid_n"];

    // источники: выручка и число заказов
    $src = [];
    $q = $pdo->prepare(
        "SELECT CASE WHEN manual = 1 THEN 'manual' WHEN max_user IS NOT NULL THEN 'bot' ELSE 'site' END src,
                COUNT(*) n, SUM(CASE WHEN $paid THEN total ELSE 0 END) rev
         FROM tl_orders WHERE created_at >= ? GROUP BY src"
    );
    $q->execute([$from]);
    foreach ($q->fetchAll() as $r) $src[$r["src"]] = ["orders" => (int)$r["n"], "revenue" => (int)$r["rev"]];
    foreach (["site", "bot", "manual"] as $k) if (!isset($src[$k])) $src[$k] = ["orders" => 0, "revenue" => 0];

    // статусы
    $status = [];
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM tl_orders GROUP BY status")->fetchAll() as $r) $status[$r["status"]] = (int)$r["n"];

    // популярные товары
    $prod = [];
    $q = $pdo->prepare("SELECT items, status FROM tl_orders WHERE created_at >= ? AND status <> 'cancelled'");
    $q->execute([$from]);
    foreach ($q->fetchAll() as $r) {
        foreach (json_decode($r["items"], true) ?: [] as $it) {
            $n = (string)($it["name"] ?? "");
            if ($n === "") continue;
            if (!isset($prod[$n])) $prod[$n] = ["name" => $n, "qty" => 0, "sum" => 0];
            $prod[$n]["qty"] += (int)($it["qty"] ?? 1);
            $prod[$n]["sum"] += (int)($it["qty"] ?? 1) * (int)($it["price"] ?? 0);
        }
    }
    usort($prod, function ($a, $b) { return $b["sum"] <=> $a["sum"]; });

    // конверсия по каналам
    $siteUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $siteBuyers  = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM tl_orders WHERE user_id IS NOT NULL AND status <> 'cancelled'")->fetchColumn();
    $botUsers    = (int)$pdo->query("SELECT COUNT(*) FROM tl_max_users")->fetchColumn();
    $botBuyers   = (int)$pdo->query("SELECT COUNT(DISTINCT max_user) FROM tl_orders WHERE max_user IS NOT NULL AND status <> 'cancelled'")->fetchColumn();
    $subs        = tl_sub_count($pdo);
    $callsAll    = (int)$pdo->query("SELECT COUNT(*) FROM tl_callbacks")->fetchColumn();
    $callsBot    = (int)$pdo->query("SELECT COUNT(*) FROM tl_callbacks WHERE topic = 'Бот MAX'")->fetchColumn();

    return [
        "months" => array_values($months),
        "totals" => [
            "orders" => (int)$t["n"], "revenue" => (int)$t["rev"], "paid" => $paidN,
            "avg_check" => $paidN ? (int)round($t["rev"] / $paidN) : 0,
            "paid_share" => (int)$t["alive"] ? (int)round(100 * $paidN / (int)$t["alive"]) : 0,
        ],
        "sources"  => $src,
        "status"   => $status,
        "products" => array_slice($prod, 0, 8),
        "funnel"   => [
            "site_users" => $siteUsers, "site_buyers" => $siteBuyers,
            "bot_users" => $botUsers, "bot_buyers" => $botBuyers, "subscribers" => $subs,
            "calls" => $callsAll, "calls_bot" => $callsBot,
        ],
    ];
}
