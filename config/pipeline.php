<?php

/*
| Этапы заказа и права сотрудников.
| Общий модуль: им пользуются и сайт (pipeline_api.php, admin_api.php), и бот MAX (max_bot.php),
| поэтому правила одинаковы везде.
|
| Путь заказа:  Заявка → Счёт → Оплата → Чек → Отправка → Отправлен → Выполнен   (+ Отменён)
| Заказ «стоит» на этапе, пока сотрудник не выполнит действие этого этапа.
|
| Права:
|   администратор (role = admin на сайте или /admin в боте) — всё;
|   сотрудник с зонами «Счёт, Оплата…» — видит и двигает только заказы на своих этапах;
|   сотрудник с зоной «all» — видит всю базу заказов и двигает любой этап, но не удаляет заказы
|   и не управляет сотрудниками.
*/

/* ---------- этапы ---------- */

function tl_stages(): array
{
    return [
        "new" => [
            "label"  => "Заявка",
            "action" => "Подтвердить данные",
            "hint"   => "Свяжитесь с клиентом: сверьте имя, телефон и состав заказа. Укажите, физлицо или юрлицо (для юрлица внесите реквизиты).",
            "btn"    => "✅ Данные подтверждены",
            "need"   => "", "ask" => "",
        ],
        "invoice" => [
            "label"  => "Счёт",
            "action" => "Выставить счёт и отправить клиенту",
            "hint"   => "Выставьте счёт, отправьте клиенту и впишите номер счёта.",
            "btn"    => "✅ Счёт отправлен",
            "need"   => "invoice_no", "ask" => "Введите номер счёта (или «-», если номера нет):",
        ],
        "payment" => [
            "label"  => "Оплата",
            "action" => "Проверить поступление денег",
            "hint"   => "Сверьте поступление с банком. Если денег нет, верните заказ на этап «Счёт» или напишите клиенту.",
            "btn"    => "✅ Деньги поступили",
            "need"   => "", "ask" => "",
        ],
        "receipt" => [
            "label"  => "Чек",
            "action" => "Пробить чек или составить документ для юрлица",
            "hint"   => "Физлицо — пробейте кассовый чек. Юрлицо — составьте закрывающие документы по реквизитам заказа.",
            "btn"    => "✅ Чек / документ готов",
            "need"   => "", "ask" => "",
        ],
        "shipping" => [
            "label"  => "Отправка",
            "action" => "Создать накладную и отправить в ПВЗ",
            "hint"   => "Создайте накладную, передайте заказ в пункт выдачи и впишите номер накладной (и адрес ПВЗ).",
            "btn"    => "✅ Отправлено",
            "need"   => "waybill_no", "ask" => "Введите номер накладной (или «-», если номера нет):",
        ],
    ];
}

/* Все статусы заказа: этапы + итоговые */
function tl_order_statuses(): array
{
    $out = [];
    foreach (tl_stages() as $k => $s) $out[$k] = $s["label"];
    $out["shipped"]   = "Отправлен";
    $out["done"]      = "Выполнен";
    $out["cancelled"] = "Отменён";
    return $out;
}

/* Подписи для клиента (в личном кабинете и в боте) */
function tl_order_status_public(): array
{
    return [
        "new"       => "Заявка принята",
        "invoice"   => "Готовим счёт",
        "payment"   => "Ждём оплату",
        "receipt"   => "Оплачен, оформляем документы",
        "shipping"  => "Готовим к отправке",
        "shipped"   => "Отправлен",
        "done"      => "Выполнен",
        "cancelled" => "Отменён",
    ];
}

/* Статусы, при которых деньги уже получены (для выручки и отчётов) */
function tl_paid_statuses(): array { return ["receipt", "shipping", "shipped", "done"]; }
function tl_paid_sql(): string { return "status IN ('receipt','shipping','shipped','done')"; }

function tl_stage_codes(): array { return array_keys(tl_stages()); }

function tl_stage_next(string $s): ?string
{
    if ($s === "shipped") return "done";
    $c = tl_stage_codes();
    $i = array_search($s, $c, true);
    if ($i === false) return null;
    return $c[$i + 1] ?? "shipped";
}

function tl_stage_prev(string $s): ?string
{
    if ($s === "shipped") return "shipping";
    if ($s === "done")    return "shipped";
    $c = tl_stage_codes();
    $i = array_search($s, $c, true);
    return ($i !== false && $i > 0) ? $c[$i - 1] : null;
}

function tl_stage_action(string $stage, array $o): string
{
    $st = tl_stages();
    if (!isset($st[$stage])) return "";
    if ($stage === "receipt") {
        return ($o["buyer_type"] ?? "person") === "company"
            ? "Составить документ для юрлица (реквизиты в заказе)"
            : "Пробить кассовый чек";
    }
    return $st[$stage]["action"];
}

function tl_buyer_label(array $o): string
{
    return ($o["buyer_type"] ?? "person") === "company" ? "Юрлицо" : "Физлицо";
}

/* ---------- права ---------- */

/* acc: ["admin"=>bool, "all"=>bool (вся база), "zones"=>[этапы], "name"=>string, "kind"=>"site"|"bot"] */

function tl_zones_parse(string $s): array
{
    $out = [];
    $stages = tl_stages();
    foreach (explode(",", $s) as $z) {
        $z = trim($z);
        if ($z === "all" || isset($stages[$z])) $out[] = $z;
    }
    return array_values(array_unique($out));
}

function tl_acc_admin(string $kind, string $name = ""): array
{
    return ["admin" => true, "all" => true, "zones" => tl_stage_codes(), "name" => $name, "kind" => $kind, "staff_id" => 0];
}

function tl_acc_staff(array $row, string $kind): array
{
    $z   = tl_zones_parse((string)$row["zones"]);
    $all = in_array("all", $z, true);
    return [
        "admin" => false, "all" => $all,
        "zones" => $all ? tl_stage_codes() : array_values(array_filter($z, function ($x) { return $x !== "all"; })),
        "name"  => (string)$row["name"], "kind" => $kind, "staff_id" => (int)$row["id"],
    ];
}

function tl_acc_can_view(array $acc, array $o): bool
{
    return $acc["all"] || in_array($o["status"], $acc["zones"], true);
}

function tl_acc_can_cancel(array $acc, array $o): bool
{
    if (!tl_acc_can_view($acc, $o)) return false;
    return $acc["admin"] || $acc["all"] || in_array("new", $acc["zones"], true);
}

/* Статусы, заказы в которых человек видит */
function tl_acc_statuses(array $acc): array
{
    return $acc["all"] ? array_keys(tl_order_statuses()) : $acc["zones"];
}

function tl_acc_label(array $acc): string
{
    if ($acc["admin"]) return "Администратор: полный доступ";
    if ($acc["all"])   return "Вся база заказов";
    $st = tl_stages();
    return "Этапы: " . implode(", ", array_map(function ($z) use ($st) { return $st[$z]["label"]; }, $acc["zones"]));
}

function tl_staff_by_user(PDO $pdo, int $userId): ?array
{
    $s = $pdo->prepare("SELECT * FROM tl_staff WHERE user_id = ? LIMIT 1");
    $s->execute([$userId]);
    return $s->fetch() ?: null;
}

function tl_staff_by_max(PDO $pdo, int $maxUser): ?array
{
    $s = $pdo->prepare("SELECT * FROM tl_staff WHERE max_user = ? LIMIT 1");
    $s->execute([$maxUser]);
    return $s->fetch() ?: null;
}

/* Доступ на сайте. Роль админа берём из БД, а не из сессии, чтобы права отзывались сразу. */
function tl_site_access(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) return null;
    $s = $pdo->prepare("SELECT name, role FROM users WHERE id = ? LIMIT 1");
    $s->execute([$userId]);
    $u = $s->fetch();
    if (!$u) return null;
    if ($u["role"] === "admin") return tl_acc_admin("site", (string)$u["name"]);
    $row = tl_staff_by_user($pdo, $userId);
    if (!$row) return null;
    $acc = tl_acc_staff($row, "site");
    if (!$acc["zones"]) return null;
    return $acc;
}

/* Доступ в боте MAX: администратор (/admin) или сотрудник (/staff КОД). */
function tl_bot_access(PDO $pdo, int $uid): ?array
{
    $s = $pdo->prepare("SELECT name FROM tl_max_admins WHERE user_id = ?");
    $s->execute([$uid]);
    $a = $s->fetch();
    if ($a) return tl_acc_admin("bot", (string)$a["name"]);
    $row = tl_staff_by_max($pdo, $uid);
    if (!$row) return null;
    $acc = tl_acc_staff($row, "bot");
    return $acc["zones"] ? $acc : null;
}

/* Код для привязки бота: сотрудник отправляет боту «/staff 123456». Действует 24 часа. */
function tl_staff_make_code(PDO $pdo, int $staffId): string
{
    $code = (string)random_int(100000, 999999);
    $pdo->prepare("UPDATE tl_staff SET bind_code = ?, bind_exp = (NOW() + INTERVAL 24 HOUR) WHERE id = ?")
        ->execute([$code, $staffId]);
    return $code;
}

function tl_staff_bind_bot(PDO $pdo, string $code, int $maxUser, string $name): ?array
{
    $s = $pdo->prepare("SELECT * FROM tl_staff WHERE bind_code = ? AND bind_exp > NOW() LIMIT 1");
    $s->execute([$code]);
    $row = $s->fetch();
    if (!$row) return null;
    // этот аккаунт MAX мог быть привязан к другой карточке — освобождаем
    $pdo->prepare("UPDATE tl_staff SET max_user = NULL WHERE max_user = ? AND id <> ?")->execute([$maxUser, $row["id"]]);
    $pdo->prepare("UPDATE tl_staff SET max_user = ?, bind_code = NULL, bind_exp = NULL WHERE id = ?")->execute([$maxUser, $row["id"]]);
    $row["max_user"] = $maxUser;
    return $row;
}

/* ---------- поля заказа, которые заполняют сотрудники ---------- */

function tl_order_fields_clean(array $f): array
{
    $out = [];
    if (isset($f["buyer_type"])) $out["buyer_type"] = $f["buyer_type"] === "company" ? "company" : "person";
    $lim = ["requisites" => 500, "invoice_no" => 40, "waybill_no" => 60, "pvz" => 200];
    foreach ($lim as $k => $n) {
        if (!array_key_exists($k, $f)) continue;
        $v = mb_substr(trim((string)$f[$k]), 0, $n);
        $out[$k] = $v !== "" ? $v : null;
    }
    // графы заявки: пустое значение очищает поле
    foreach (["last_name" => 60, "first_name" => 60, "middle_name" => 60, "email" => 100, "cdek" => 250, "agent" => 80] as $k => $n) {
        if (!array_key_exists($k, $f)) continue;
        $v = mb_substr(trim((string)$f[$k]), 0, $n);
        if ($k === "email" && $v !== "" && !tl_email_ok($v)) continue;   // кривой email не сохраняем
        $out[$k] = $v !== "" ? $v : null;
    }
    // ФИО одной строкой собирается из граф, если их присылают
    if (isset($f["last_name"]) || isset($f["first_name"]) || isset($f["middle_name"])) {
        $fio = tl_fio($f);
        if ($fio !== "") $out["name"] = mb_substr($fio, 0, 190);
    } elseif (isset($f["name"])) {
        $v = mb_substr(trim((string)$f["name"]), 0, 190);
        if ($v !== "") $out["name"] = $v;
    }
    if (isset($f["phone"])) {
        $p = tl_phone_norm((string)$f["phone"]);
        if ($p !== "") $out["phone"] = $p;
    }
    return $out;
}

function tl_order_log(PDO $pdo, int $orderId, ?string $from, string $to, array $acc, string $note = ""): void
{
    $pdo->prepare(
        "INSERT INTO tl_order_log (order_id, from_status, to_status, by_kind, by_name, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    )->execute([
        $orderId, $from, $to, (string)($acc["kind"] ?? "system"),
        mb_substr((string)($acc["name"] ?? ""), 0, 80),
        mb_substr(trim($note), 0, 500) ?: null,
    ]);
}

function tl_order_history(PDO $pdo, int $orderId, int $limit = 30): array
{
    $s = $pdo->prepare("SELECT * FROM tl_order_log WHERE order_id = ? ORDER BY id DESC LIMIT " . max(1, min(100, $limit)));
    $s->execute([$orderId]);
    return $s->fetchAll();
}

/*
| Главная операция: перевести заказ на другой этап (или сохранить поля).
| $acc    — кто делает (см. tl_site_access / tl_bot_access);
| $force  — только для администратора: любой статус вручную, без проверки соседних этапов и обязательных полей.
| Возвращает ["ok"=>bool, "msg"=>string, "need"=>поле, которое надо ввести, "order"=>заказ после изменения].
*/
function tl_order_move(PDO $pdo, int $id, string $to, array $acc, string $note = "", array $fields = [], bool $force = false, bool $notify = true): array
{
    $o = tl_order_get($pdo, $id);
    if (!$o) return ["ok" => false, "msg" => "Заказ не найден"];
    if (!tl_acc_can_view($acc, $o)) return ["ok" => false, "msg" => "Нет доступа к этому заказу"];

    $from   = (string)$o["status"];
    $labels = tl_order_statuses();
    if (!isset($labels[$to])) return ["ok" => false, "msg" => "Неизвестный этап"];

    $clean = tl_order_fields_clean($fields);

    if ($to !== $from) {
        if ($force) {
            if (!$acc["admin"]) return ["ok" => false, "msg" => "Свободная смена статуса доступна только администратору"];
        } else {
            if ($to === "cancelled") {
                if (!tl_acc_can_cancel($acc, $o)) return ["ok" => false, "msg" => "Отменять заказы может администратор или сотрудник этапа «Заявка»"];
            } elseif ($to !== tl_stage_next($from) && $to !== tl_stage_prev($from)) {
                return ["ok" => false, "msg" => "Можно перейти только на соседний этап"];
            }
            if ($to === tl_stage_next($from)) {
                $need = tl_stages()[$from]["need"] ?? "";
                if ($need !== "") {
                    $have = array_key_exists($need, $clean) ? $clean[$need] : ($o[$need] ?? null);
                    if (trim((string)$have) === "") {
                        return ["ok" => false, "need" => $need, "msg" => tl_stages()[$from]["ask"]];
                    }
                }
            }
        }
    }

    $sets = []; $vals = [];
    foreach ($clean as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
    if ($to !== $from) { $sets[] = "status = ?"; $vals[] = $to; $sets[] = "stage_at = NOW()"; }
    if ($sets) {
        $vals[] = $id;
        $pdo->prepare("UPDATE tl_orders SET " . implode(", ", $sets) . " WHERE id = ?")->execute($vals);
    }

    if ($to !== $from) {
        tl_order_log($pdo, $id, $from, $to, $acc, $note);
    } elseif ($clean || trim($note) !== "") {
        tl_order_log($pdo, $id, $from, $from, $acc, trim($note) !== "" ? $note : "Изменены данные заказа");
    }

    $new = tl_order_get($pdo, $id);
    if ($notify && $to !== $from) tl_order_after_move($pdo, $new, $to);
    return ["ok" => true, "msg" => "", "order" => $new, "from" => $from, "to" => $to];
}

/* Уведомления после перехода: клиенту (если он из бота) и сотрудникам нового этапа. */
function tl_order_after_move(PDO $pdo, array $o, string $to): void
{
    tl_order_notify_client($pdo, $o, $to);
    tl_stage_notify_staff($pdo, $o, $to);
}

/* Сотрудникам бота, у которых в зоне этот этап, приходит «заказ ждёт вас». Администраторам не дублируем. */
function tl_stage_notify_staff(PDO $pdo, array $o, string $stage): void
{
    if (!function_exists("tl_max_enabled") || !tl_max_enabled() || !isset(tl_stages()[$stage])) return;
    try {
        tl_ensure_staff($pdo);
        $admins = function_exists("tl_max_admin_ids") ? tl_max_admin_ids($pdo) : [];
        $label  = tl_stages()[$stage]["label"];
        $text   = "📌 Заказ №" . $o["id"] . " ждёт этапа «" . $label . "»\n" . tl_stage_action($stage, $o) . "\n\n" . tl_order_text($o);
        foreach ($pdo->query("SELECT max_user, zones FROM tl_staff WHERE max_user IS NOT NULL")->fetchAll() as $r) {
            $z = tl_zones_parse((string)$r["zones"]);
            if (!in_array("all", $z, true) && !in_array($stage, $z, true)) continue;
            if (in_array((int)$r["max_user"], $admins, true)) continue;
            tl_max_send((int)$r["max_user"], $text, [[tl_btn("Открыть заказ", "o:v:" . $o["id"])]], 4);
        }
    } catch (Throwable $e) {
        error_log("stage notify: " . $e->getMessage());
    }
}

/* Сколько заказов на каждом этапе (только те, что видит acc) */
function tl_stage_counts(PDO $pdo, array $acc): array
{
    $counts = [];
    foreach (tl_order_statuses() as $k => $_) $counts[$k] = 0;
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM tl_orders GROUP BY status")->fetchAll() as $r) {
        if (isset($counts[$r["status"]]) && ($acc["all"] || in_array($r["status"], $acc["zones"], true))) $counts[$r["status"]] = (int)$r["n"];
    }
    return $counts;
}

/* Список заказов, видимых acc (опционально — только на одном этапе) */
function tl_orders_visible(PDO $pdo, array $acc, string $only = "all", int $limit = 300): array
{
    $limit = max(1, min(2000, $limit));
    $statuses = tl_acc_statuses($acc);
    if ($only !== "all") {
        if (!in_array($only, $statuses, true)) return [];
        $statuses = [$only];
    }
    $in = implode(",", array_fill(0, count($statuses), "?"));
    $s = $pdo->prepare("SELECT * FROM tl_orders WHERE status IN ($in) ORDER BY id DESC LIMIT $limit");
    $s->execute($statuses);
    return $s->fetchAll();
}
