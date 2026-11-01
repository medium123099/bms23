<?php

/*
| Клиентская часть бота MAX: каталог, корзина, заказ, звонок, отзывы, справка.
| Доступна всем пользователям. Все экраны возвращают [текст, кнопки].
*/

const TL_SITE = "https://bms23.ru";
const TL_PHONE = "+7 928 330-02-06";

/* ---------- профиль и корзина ---------- */

function tl_user(PDO $pdo, int $uid): array
{
    $pdo->prepare("INSERT IGNORE INTO tl_max_users (user_id) VALUES (?)")->execute([$uid]);
    $s = $pdo->prepare("SELECT * FROM tl_max_users WHERE user_id = ?");
    $s->execute([$uid]);
    return $s->fetch();
}

function tl_cart_get(PDO $pdo, int $uid): array
{
    $u = tl_user($pdo, $uid);
    $c = json_decode((string)$u["cart"], true);
    $cat = tl_catalog();
    $out = [];
    foreach (is_array($c) ? $c : [] as $k => $q) if (isset($cat[$k]) && (int)$q > 0) $out[$k] = min(9, (int)$q);
    return $out;
}

function tl_cart_save(PDO $pdo, int $uid, array $cart): void
{
    tl_user($pdo, $uid);
    $pdo->prepare("UPDATE tl_max_users SET cart = ?, cart_at = ? WHERE user_id = ?")
        ->execute([json_encode($cart), $cart ? date("Y-m-d H:i:s") : null, $uid]);
}

function tl_cart_total(array $cart): int
{
    $cat = tl_catalog(); $t = 0;
    foreach ($cart as $k => $q) $t += $cat[$k]["price"] * $q;
    return $t;
}

function tl_cart_count(array $cart): int { return array_sum($cart); }

function tl_cart_lines(array $cart): array
{
    $cat = tl_catalog(); $l = [];
    foreach ($cart as $k => $q) $l[] = ["id" => "treelife-" . $k, "name" => $cat[$k]["name"], "price" => $cat[$k]["price"], "qty" => $q];
    return $l;
}

function tl_save_contact(PDO $pdo, int $uid, string $name, string $phone): void
{
    tl_user($pdo, $uid);
    $pdo->prepare("UPDATE tl_max_users SET name = ?, phone = ? WHERE user_id = ?")->execute([$name, $phone, $uid]);
}

/* не чаще раза в минуту: $col = last_review | last_call */
function tl_rate_ok(PDO $pdo, int $uid, string $col, int $seconds = 60): bool
{
    if (!in_array($col, ["last_review", "last_call", "last_ask"], true)) return false;
    $u = tl_user($pdo, $uid);
    return empty($u[$col]) || time() - strtotime($u[$col]) >= $seconds;
}

function tl_rate_mark(PDO $pdo, int $uid, string $col): void
{
    if (!in_array($col, ["last_review", "last_call", "last_ask"], true)) return;
    $pdo->prepare("UPDATE tl_max_users SET $col = NOW() WHERE user_id = ?")->execute([$uid]);
}

/* ---------- экраны ---------- */

function shop_menu(bool $isAdmin, PDO $pdo, int $uid): array
{
    $n = tl_cart_count(tl_cart_get($pdo, $uid));
    $sub = !empty(tl_user($pdo, $uid)["subscribed"]);
    $kb = [
        [tl_btn("🛍 Каталог", "k:c"), tl_btn("🛒 Корзина" . ($n ? " (" . $n . ")" : ""), "k:cart")],
        [tl_btn("📦 Мои заказы", "k:my"), tl_btn("📞 Заказать звонок", "k:call")],
        [tl_btn("🎓 Обучение", "k:i:courses"), tl_btn("🚚 Доставка и оплата", "k:i:delivery")],
        [tl_btn("⭐ Отзывы", "k:rl"), tl_btn("✍️ Оставить отзыв", "k:rv")],
        [tl_btn("📍 Контакты", "k:i:contacts"), tl_btn("💬 Написать менеджеру", "k:ask")],
        [tl_btn($sub ? "🔕 Отписаться от новостей" : "🔔 Новости и акции", $sub ? "k:unsub" : "k:sub"), tl_btn("🔗 Привязать сайт", "k:link")],
    ];
    if ($isAdmin) $kb[] = [tl_btn("← Админ-меню", "m")];
    return ["Здравствуйте! Это бот TreeLife Stimulation: аппараты биомеханической стимуляции для лица и тела и обучение работе на них.\n\nЧто вас интересует?", $kb];
}

function shop_catalog(): array
{
    $text = "Аппараты TreeLife:\n"; $kb = [];
    foreach (tl_catalog() as $k => $it) {
        $text .= "\n• " . $it["name"] . " — " . tl_money($it["price"]);
        $kb[] = [tl_btn($it["name"] . " · " . tl_money($it["price"]), "k:p:" . $k)];
    }
    $kb[] = [tl_btn("🛒 Корзина", "k:cart"), tl_btn("← Меню", "k:m")];
    return [$text . "\n\nВыберите аппарат, чтобы узнать подробнее.", $kb];
}

function shop_product(string $key): array
{
    $cat = tl_catalog();
    if (!isset($cat[$key])) return shop_catalog();
    $it = $cat[$key];
    return [$it["name"] . "\n" . tl_money($it["price"]) . "\n\n" . $it["desc"] . ($it["page"] !== "" ? "\n\nПодробнее на сайте: " . TL_SITE . "/" . $it["page"] : ""), [
        [tl_btn("➕ В корзину", "k:a:" . $key)],
        [tl_btn("← Каталог", "k:c"), tl_btn("🛒 Корзина", "k:cart")],
    ]];
}

function shop_cart(PDO $pdo, int $uid, string $note = ""): array
{
    $cart = tl_cart_get($pdo, $uid);
    $pre  = $note !== "" ? $note . "\n\n" : "";
    if (!$cart) return [$pre . "Корзина пуста.", [[tl_btn("🛍 Каталог", "k:c"), tl_btn("← Меню", "k:m")]]];
    $cat = tl_catalog(); $text = $pre . "Ваша корзина:\n\n"; $kb = [];
    foreach ($cart as $k => $q) {
        $text .= "• " . $cat[$k]["name"] . " × " . $q . " — " . tl_money($cat[$k]["price"] * $q) . "\n";
        $kb[] = [tl_btn("−", "k:x:" . $k), tl_btn($cat[$k]["name"] . " × " . $q, "k:p:" . $k), tl_btn("+", "k:a:" . $k)];
    }
    $text .= "\nИтого: " . tl_money(tl_cart_total($cart));
    $kb[] = [tl_btn("✅ Оформить заказ", "k:o")];
    $kb[] = [tl_btn("🗑 Очистить", "k:clr"), tl_btn("🛍 Каталог", "k:c")];
    return [$text, $kb];
}

function shop_orders(PDO $pdo, int $uid): array
{
    $rows = tl_orders_of_user($pdo, $uid, 5);
    if (!$rows) return ["Заказов из бота пока нет.", [[tl_btn("🛍 Каталог", "k:c"), tl_btn("← Меню", "k:m")]]];
    $st = tl_order_status_public(); $text = "Ваши последние заказы:\n";
    foreach ($rows as $o) {
        $names = [];
        foreach (json_decode($o["items"], true) ?: [] as $it) $names[] = $it["name"] . " × " . (int)$it["qty"];
        $text .= "\n№" . $o["id"] . " от " . date("d.m.Y", strtotime($o["created_at"])) . " — " . ($st[$o["status"]] ?? $o["status"])
               . "\n" . implode(", ", $names) . "\nСумма: " . tl_money($o["total"]) . "\n";
    }
    return [$text . "\nВопросы по заказу: " . TL_PHONE, [[tl_btn("← Меню", "k:m")]]];
}

function shop_reviews(PDO $pdo): array
{
    $rows = tl_reviews_public($pdo, 3);
    if (!$rows) return ["Отзывов пока нет. Будьте первым!", [[tl_btn("✍️ Оставить отзыв", "k:rv"), tl_btn("← Меню", "k:m")]]];
    $text = "Отзывы клиентов:\n";
    foreach ($rows as $r) $text .= "\n" . str_repeat("★", (int)$r["rating"]) . " " . $r["name"] . "\n" . $r["text"] . "\n";
    return [$text, [[tl_btn("✍️ Оставить отзыв", "k:rv"), tl_btn("← Меню", "k:m")]]];
}

function shop_info(string $what): array
{
    $back = [[tl_btn("📞 Заказать звонок", "k:call"), tl_btn("← Меню", "k:m")]];
    if ($what === "courses") return ["Обучение методике БМС\n\nУчим работать на аппаратах TreeLife с нуля, опыт в косметологии не нужен. Два формата, программа одна:\n• очно в Краснодаре — в кабинете, с преподавателем;\n• дистанционно по видеосвязи.\n\nПрограмма: теория (принцип метода), техника (порядок работы с аппаратом) и практика (разбор реальных случаев).\n\nЧтобы записаться, закажите звонок, мы расскажем о ближайших датах. " . TL_SITE . "/courses", $back];
    if ($what === "delivery") return ["Доставка, оплата и гарантия\n\nРаботаем в Краснодарском крае и по всей России. Способ, срок и стоимость доставки зависят от города, а способ оплаты согласуем при подтверждении заказа. Все условия вы узнаете до оплаты.\n\nГарантия: условия и срок указаны в документах на аппарат. Есть собственная сервисная служба, запчасти в наличии.\n\nПодробности: " . TL_SITE . "/delivery", $back];
    return ["Контакты\n\nТелефон: " . TL_PHONE . " (звонок по России бесплатный)\nПочта: info@bms23.ru\nАдрес: 350011, Краснодар, ул. Димитрова, 3/2, помещение 5\n\nСайт: " . TL_SITE, $back];
}

/* ---------- кнопки действий: возвращает [текст, кнопки, всплывающее уведомление] ---------- */

function tl_shop_callback(PDO $pdo, int $uid, bool $isAdmin, string $payload): array
{
    $p = explode(":", $payload);
    $act = $p[1] ?? "m";
    $arg = (string)($p[2] ?? "");
    $cat = tl_catalog();
    $toast = "";

    if ($act !== "ou" && $act !== "on") { /* диалоги сбрасываем при любом переходе */ }
    if (!in_array($act, ["rs", "ou", "on"], true)) tl_state_clear($pdo, $uid);

    switch ($act) {
        case "c":    [$t, $k] = shop_catalog(); break;
        case "p":    [$t, $k] = shop_product($arg); break;
        case "cart": [$t, $k] = shop_cart($pdo, $uid); break;
        case "my":   [$t, $k] = shop_orders($pdo, $uid); break;
        case "rl":   [$t, $k] = shop_reviews($pdo); break;
        case "i":    [$t, $k] = shop_info($arg); break;

        case "a":
        case "x": {
            if (!isset($cat[$arg])) { [$t, $k] = shop_catalog(); break; }
            $cart = tl_cart_get($pdo, $uid);
            $q = ($cart[$arg] ?? 0) + ($act === "a" ? 1 : -1);
            if ($q > 9) { $q = 9; $toast = "Максимум 9 шт."; }
            if ($q <= 0) unset($cart[$arg]); else $cart[$arg] = $q;
            tl_cart_save($pdo, $uid, $cart);
            [$t, $k] = shop_cart($pdo, $uid, $act === "a" ? "Добавлено: " . $cat[$arg]["name"] : "");
            break;
        }
        case "clr":
            tl_cart_save($pdo, $uid, []);
            [$t, $k] = shop_cart($pdo, $uid, "Корзина очищена.");
            break;

        /* оформление заказа */
        case "o":
        case "on":
        case "ou": {
            $cart = tl_cart_get($pdo, $uid);
            if (!$cart) { [$t, $k] = shop_cart($pdo, $uid); break; }
            tl_state_set($pdo, $uid, "k_lname", ["flow" => "order"]);
            $t = "Оформляем заказ на " . tl_money(tl_cart_total($cart)) . ". Отвечайте по одному сообщению.\n\nФамилия:";
            $k = [[tl_btn("Отмена", "k:cart")]];
            break;
        }

        /* вопрос менеджеру */
        case "ask":
            if (!tl_rate_ok($pdo, $uid, "last_ask", 20)) { [$t, $k] = shop_menu($isAdmin, $pdo, $uid); $t = "Вопрос уже отправлен, менеджер ответит здесь же. Новый можно задать через несколько секунд.\n\n" . $t; break; }
            tl_state_set($pdo, $uid, "k_ask");
            $t = "Напишите вопрос одним сообщением. Менеджер ответит вам прямо в этом чате в рабочее время.";
            $k = [[tl_btn("Отмена", "k:m")]];
            break;

        /* подписка на новости */
        case "sub":
            $t = "Новости и акции\n\nМы будем присылать сообщения об акциях, новых датах курсов и новинках, не чаще нескольких раз в месяц. Подписываясь, вы соглашаетесь получать такие сообщения от TreeLife Stimulation. Отписаться можно в любой момент кнопкой в меню или командой /stop.";
            $k = [[tl_btn("✅ Подписаться", "k:sub1")], [tl_btn("← Меню", "k:m")]];
            break;
        case "sub1":
            tl_sub_set($pdo, $uid, true);
            [$t, $k] = shop_menu($isAdmin, $pdo, $uid);
            $t = "Готово, вы подписаны. Отписаться можно в любой момент.\n\n" . $t;
            break;
        case "unsub":
            tl_sub_set($pdo, $uid, false);
            [$t, $k] = shop_menu($isAdmin, $pdo, $uid);
            $t = "Вы отписались, рассылка больше не придёт.\n\n" . $t;
            break;

        /* привязка аккаунта с сайта */
        case "link":
            tl_state_set($pdo, $uid, "k_link");
            $t = "Привязка к сайту\n\nВойдите на " . TL_SITE . ", откройте личный кабинет и нажмите «Привязать бот MAX». Сайт покажет шестизначный код. Отправьте его сюда сообщением (код действует 15 минут).\n\nПосле этого заказы из бота будут видны в вашем кабинете на сайте.";
            $k = [[tl_btn("Отмена", "k:m")]];
            break;

        /* звонок */
        case "call":
            if (!tl_rate_ok($pdo, $uid, "last_call")) { [$t, $k] = shop_menu($isAdmin, $pdo, $uid); $t = "Заявка уже отправлена, мы скоро перезвоним. Новую можно оставить через минуту.\n\n" . $t; break; }
            tl_state_set($pdo, $uid, "k_name", ["flow" => "call"]);
            $t = "Закажем звонок. Как к вам обращаться?"; $k = [[tl_btn("Отмена", "k:m")]];
            break;

        /* отзыв */
        case "rv":
            if (!tl_rate_ok($pdo, $uid, "last_review")) { [$t, $k] = shop_menu($isAdmin, $pdo, $uid); $t = "Спасибо, отзыв уже получен. Новый можно оставить через минуту.\n\n" . $t; break; }
            $t = "Ваша оценка?";
            $k = [[tl_btn("1 ★", "k:rs:1"), tl_btn("2 ★", "k:rs:2"), tl_btn("3 ★", "k:rs:3"), tl_btn("4 ★", "k:rs:4"), tl_btn("5 ★", "k:rs:5")], [tl_btn("Отмена", "k:m")]];
            break;
        case "rs": {
            $r = (int)$arg;
            if ($r < 1 || $r > 5) { [$t, $k] = shop_menu($isAdmin, $pdo, $uid); break; }
            tl_state_set($pdo, $uid, "k_name", ["flow" => "review", "rating" => $r]);
            $t = "Как вас подписать под отзывом? (имя)"; $k = [[tl_btn("Отмена", "k:m")]];
            break;
        }

        default: [$t, $k] = shop_menu($isAdmin, $pdo, $uid);
    }
    return [$t, $k, $toast];
}

/* ---------- текстовые сообщения клиента (диалоги) ---------- */

function tl_shop_message(PDO $pdo, int $uid, bool $isAdmin, string $text, string $senderName = ""): void
{
    [$state, $d] = tl_state_get($pdo, $uid);
    $low = mb_strtolower($text);

    // отписка командой
    if ($low === "/stop" || $low === "отписаться") {
        tl_sub_set($pdo, $uid, false);
        tl_state_clear($pdo, $uid);
        tl_max_send($uid, "Вы отписались, рассылка больше не придёт. Вернуться в меню: /menu");
        return;
    }
    // привязка командой: /link 123456
    if (preg_match('~^/link\s+(\d{6})$~u', $text, $m)) {
        tl_state_clear($pdo, $uid);
        tl_shop_link($pdo, $uid, $m[1]);
        return;
    }

    if (in_array($low, ["/start", "/menu", "меню", "/cancel", "отмена"], true) || $state === "") {
        tl_state_clear($pdo, $uid);
        [$t, $k] = shop_menu($isAdmin, $pdo, $uid);
        tl_max_send($uid, $t, $k);
        return;
    }

    $flow = $d["flow"] ?? "";

    if ($state === "k_link") {
        if (!preg_match('~^\d{6}$~', $text)) { tl_max_send($uid, "Нужен шестизначный код с сайта. Введите ещё раз или /cancel."); return; }
        tl_state_clear($pdo, $uid);
        tl_shop_link($pdo, $uid, $text);
        return;
    }

    if ($state === "k_ask") {
        if (mb_strlen($text) < 3) { tl_max_send($uid, "Напишите вопрос чуть подробнее или /cancel."); return; }
        $u = tl_user($pdo, $uid);
        $who = trim((string)($u["name"] ?: $senderName)) ?: "клиент";
        tl_msg_log($pdo, $uid, "in", $text);
        tl_rate_mark($pdo, $uid, "last_ask");
        tl_state_clear($pdo, $uid);
        tl_max_send($uid, "Спасибо! Вопрос передан менеджеру, ответ придёт сюда же. Срочное: " . TL_PHONE, [[tl_btn("← Меню", "k:m")]]);
        tl_max_notify($pdo, "💬 Вопрос от клиента: " . $who . ($u["phone"] ? ", " . tl_phone_show($u["phone"]) : "") . "\n\n" . mb_substr($text, 0, 1500),
            [[tl_btn("↩️ Ответить", "q:r:" . $uid)], [tl_btn("Все вопросы", "q:l")]]);
        return;
    }

    if ($state === "k_name") {
        if (!tl_name_ok($text)) { tl_max_send($uid, "Имя: 2–60 символов, только буквы. Введите ещё раз или /cancel."); return; }
        $d["name"] = $text;
        tl_state_set($pdo, $uid, "k_phone", $d);
        tl_max_send($uid, "Ваш телефон (например, 8 928 330-02-06):");
        return;
    }

    if ($state === "k_phone") {
        $ph = tl_phone_norm($text);
        if ($ph === "") { tl_max_send($uid, "Не похоже на российский номер. Введите ещё раз или /cancel."); return; }
        $d["phone"] = $ph;
        if ($flow === "review") {
            tl_state_set($pdo, $uid, "k_rtext", $d);
            tl_max_send($uid, "Напишите отзыв (от 10 символов):");
        } elseif ($flow === "call") {
            tl_state_set($pdo, $uid, "k_cmsg", $d);
            tl_max_send($uid, "Что хотите узнать? Или «-», чтобы пропустить:");
        } else {
            tl_save_contact($pdo, $uid, (string)($d["first_name"] ?? $d["name"] ?? ""), $ph);
            tl_state_set($pdo, $uid, "k_email", $d);
            tl_max_send($uid, "Email (на него придут документы и чек):");
        }
        return;
    }

    /* ---- заявка по графам: фамилия → имя → отчество → телефон → email → адрес СДЭК → агент → комментарий ---- */
    if ($state === "k_lname") {
        if (!tl_name_ok($text)) { tl_max_send($uid, "Фамилия: 2–60 символов, только буквы. Введите ещё раз или /cancel."); return; }
        $d["last_name"] = $text;
        tl_state_set($pdo, $uid, "k_fname", $d);
        tl_max_send($uid, "Имя:");
        return;
    }

    if ($state === "k_fname") {
        if (!tl_name_ok($text)) { tl_max_send($uid, "Имя: 2–60 символов, только буквы. Введите ещё раз или /cancel."); return; }
        $d["first_name"] = $text;
        tl_state_set($pdo, $uid, "k_mname", $d);
        tl_max_send($uid, "Отчество (если нет, отправьте «-»):");
        return;
    }

    if ($state === "k_mname") {
        if ($text !== "-") {
            if (!tl_name_ok($text)) { tl_max_send($uid, "Отчество: только буквы, или «-», чтобы пропустить."); return; }
            $d["middle_name"] = $text;
        }
        tl_state_set($pdo, $uid, "k_phone", $d);
        tl_max_send($uid, "Ваш телефон (например, 8 928 330-02-06):");
        return;
    }

    if ($state === "k_email") {
        if (!tl_email_ok($text)) { tl_max_send($uid, "Не похоже на email. Введите ещё раз (name@example.ru) или /cancel."); return; }
        $d["email"] = $text;
        tl_state_set($pdo, $uid, "k_cdek", $d);
        tl_max_send($uid, "Адрес доставки и пункт СДЭК (город, улица или код пункта выдачи):");
        return;
    }

    if ($state === "k_cdek") {
        if (mb_strlen($text) < 5) { tl_max_send($uid, "Напишите адрес подробнее: город и пункт СДЭК. Или /cancel."); return; }
        $d["cdek"] = mb_substr($text, 0, 250);
        tl_state_set($pdo, $uid, "k_agent", $d);
        tl_max_send($uid, "Агент: от кого вы пришли (код или имя). Если сами, отправьте «-»:");
        return;
    }

    if ($state === "k_agent") {
        if ($text !== "-") $d["agent"] = mb_substr($text, 0, 80);
        tl_state_set($pdo, $uid, "k_ocomment", $d);
        tl_max_send($uid, "Комментарий к заказу (удобное время звонка, пожелания) или «-», чтобы пропустить:");
        return;
    }

    if ($state === "k_ocomment") {
        $cart = tl_cart_get($pdo, $uid);
        if (!$cart) { [$t, $k] = shop_cart($pdo, $uid); tl_max_send($uid, $t, $k); tl_state_clear($pdo, $uid); return; }
        $comment = $text === "-" ? "" : mb_substr($text, 0, 480);
        $details = [];
        foreach (["last_name", "first_name", "middle_name", "email", "cdek", "agent"] as $kk) $details[$kk] = (string)($d[$kk] ?? "");
        $fio = tl_fio($details) ?: (string)($d["name"] ?? "");
        $id = tl_order_create_site($pdo, $fio, $d["phone"], tl_cart_lines($cart), trim("[MAX] " . $comment), $uid, $details);
        tl_cart_save($pdo, $uid, []);
        tl_state_clear($pdo, $uid);
        tl_max_send($uid, "Заказ №" . $id . " принят.\nСумма: " . tl_money(tl_cart_total($cart)) . "\n\nМы позвоним на " . tl_phone_show($d["phone"]) . ", подтвердим комплектацию, доставку и оплату. Статус можно смотреть в разделе «Мои заказы».", [[tl_btn("📦 Мои заказы", "k:my"), tl_btn("← Меню", "k:m")]]);
        $o = tl_order_get($pdo, $id);
        if ($o) tl_max_notify($pdo, "🆕 Новый заказ из бота MAX\n\n" . tl_order_text($o), [[tl_btn("Открыть заказ", "o:v:" . $id)]]);
        if ($o) tl_stage_notify_staff($pdo, $o, "new");   // сотрудникам этапа «Заявка»
        return;
    }

    if ($state === "k_cmsg") {
        $msg = $text === "-" ? "" : $text;
        tl_callback_create($pdo, $d["name"], $d["phone"], "Бот MAX", $msg);
        tl_rate_mark($pdo, $uid, "last_call");
        tl_state_clear($pdo, $uid);
        tl_max_send($uid, "Спасибо! Мы перезвоним вам в ближайшее время.", [[tl_btn("← Меню", "k:m")]]);
        tl_max_notify($pdo, "📞 Заявка на звонок из бота MAX\n" . $d["name"] . ", " . tl_phone_show($d["phone"]) . ($msg !== "" ? "\n" . $msg : ""), [[tl_btn("Все заявки", "c:l")]]);
        return;
    }

    if ($state === "k_rtext") {
        if (mb_strlen($text) < 10) { tl_max_send($uid, "Напишите хотя бы 10 символов или /cancel."); return; }
        tl_review_create($pdo, $d["name"], (int)$d["rating"], $text);
        tl_rate_mark($pdo, $uid, "last_review");
        tl_state_clear($pdo, $uid);
        tl_max_send($uid, "Спасибо за отзыв! Он появится на сайте после проверки.", [[tl_btn("← Меню", "k:m")]]);
        tl_max_notify($pdo, "💬 Новый отзыв из бота MAX\n\n" . str_repeat("★", (int)$d["rating"]) . " " . $d["name"] . "\n" . mb_substr($text, 0, 1000), [[tl_btn("Модерировать", "r:n")]]);
        return;
    }

    tl_state_clear($pdo, $uid);
    [$t, $k] = shop_menu($isAdmin, $pdo, $uid);
    tl_max_send($uid, $t, $k);
}


/* привязка кода с сайта; сообщает клиенту результат */
function tl_shop_link(PDO $pdo, int $uid, string $code): void
{
    if (tl_link_consume($pdo, $uid, $code)) {
        tl_max_send($uid, "Готово, бот привязан к вашему аккаунту на сайте. Заказы из бота теперь видны в личном кабинете.", [[tl_btn("← Меню", "k:m")]]);
    } else {
        tl_max_send($uid, "Код не подошёл или истёк. Получите новый в личном кабинете на сайте и отправьте его снова.", [[tl_btn("← Меню", "k:m")]]);
    }
}
