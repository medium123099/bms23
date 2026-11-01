<?php

/*
|--------------------------------------------------------------------------
| Оформление заказа из корзины.
| Цены и названия берутся ИЗ КАТАЛОГА НИЖЕ, а не от браузера —
| так нельзя подменить цену в запросе.
| Не забудьте выполнить orders.sql (создаёт таблицу orders).
|--------------------------------------------------------------------------
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

/* true — в ответе покажется настоящая причина ошибки БД (только для отладки!) */
const ORDER_DEBUG = false;

function fail($message, $field = null, $code = 200) {
    http_response_code($code);
    $out = ["success" => false, "message" => $message];
    if ($field) $out["field"] = $field;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    fail("Неверный запрос", null, 405);
}

/* ловушка для ботов: поле скрыто, человек его не заполнит */
if (trim($_POST["website"] ?? "") !== "") {
    echo json_encode(["success" => true, "order_id" => 0]);
    exit;
}

$CATALOG = tl_catalog();   // цены и названия из таблицы tl_products (редактируются в админке)

$phone   = trim($_POST["phone"] ?? "");
$comment = trim($_POST["comment"] ?? "");

/* графы заявки: фамилия, имя, отчество, email, адрес СДЭК, агент */
$det = [];
foreach (["last_name", "first_name", "middle_name", "email", "cdek", "agent"] as $k) {
    $det[$k] = trim((string)($_POST[$k] ?? ""));
}

/* старая форма с одним полем «Имя» (пока её не заменили на новую) — заказ тоже принимаем */
$legacy = !isset($_POST["first_name"]) && !isset($_POST["last_name"]) && trim($_POST["name"] ?? "") !== "";

if ($legacy) {
    $det["first_name"] = trim($_POST["name"]);
    if (!tl_name_ok($det["first_name"])) fail("Введите корректное имя", "name");
} else {
    if (!tl_name_ok($det["last_name"]))  fail("Введите фамилию", "last_name");
    if (!tl_name_ok($det["first_name"])) fail("Введите имя", "first_name");
    if ($det["middle_name"] !== "" && !tl_name_ok($det["middle_name"])) fail("Проверьте отчество", "middle_name");
    if (!tl_email_ok($det["email"]))     fail("Введите корректный email", "email");
    if (mb_strlen($det["cdek"]) < 5)     fail("Укажите адрес доставки и пункт СДЭК", "cdek");
    if (mb_strlen($det["agent"]) > 80)   fail("Слишком длинное поле «Агент»", "agent");
}

$name = tl_fio($det);

$phone = preg_replace('/\D/', '', $phone);
if (strlen($phone) === 11 && $phone[0] === "8") $phone = "7" . substr($phone, 1);
if (strlen($phone) === 10) $phone = "7" . $phone;
if (strlen($phone) !== 11 || $phone[0] !== "7") {
    fail("Введите корректный номер телефона", "phone");
}

$comment = mb_substr($comment, 0, 500);

$raw   = json_decode($_POST["items"] ?? "[]", true);
$lines = [];
$total = 0;

if (is_array($raw)) {
    foreach ($raw as $row) {
        $slug = tl_catalog_slug((string)($row["id"] ?? ""));
        $qty  = (int)($row["qty"] ?? 0);
        if ($slug === "" || $qty < 1) continue;
        $qty = min($qty, 99);
        $id  = "treelife-" . $slug;

        if (!isset($lines[$id])) {
            $lines[$id] = [
                "id"    => $id,
                "name"  => $CATALOG[$slug]["name"],
                "price" => $CATALOG[$slug]["price"],
                "qty"   => 0
            ];
        }
        $lines[$id]["qty"] = min(99, $lines[$id]["qty"] + $qty);
    }
}

if (!$lines) {
    fail("Корзина пуста");
}

foreach ($lines as $l) {
    $total += $l["price"] * $l["qty"];
}

try {

    try { tl_ensure_orders($pdo); } catch (PDOException $e) { error_log("order.php ensure: " . $e->getMessage()); }   // создаст таблицу orders, если её ещё нет

    $userId = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : null;

    $stmt = $pdo->prepare(
        "INSERT INTO tl_orders (user_id, name, phone, comment, items, total, status, created_at, stage_at)
         VALUES (?, ?, ?, ?, ?, ?, 'new', NOW(), NOW())"
    );

    $stmt->execute([
        $userId,
        $name,
        $phone,
        $comment !== "" ? $comment : null,
        json_encode(array_values($lines), JSON_UNESCAPED_UNICODE),
        $total
    ]);

    $orderId = (int)$pdo->lastInsertId();
    tl_order_set_details($pdo, $orderId, $det);
    tl_order_created($pdo, $orderId, "site", "Клиент (сайт)", "Заказ оформлен на сайте");

    echo json_encode([
        "success"  => true,
        "order_id" => $orderId
    ], JSON_UNESCAPED_UNICODE);

    // уведомление в бот MAX (после ответа клиенту, ошибки не мешают заказу)
    tl_finish_response();
    $o = tl_order_get($pdo, $orderId);
    if ($o) tl_max_notify($pdo, "🆕 Новый заказ с сайта\n\n" . tl_order_text($o), [[tl_btn("Открыть заказ", "o:v:" . $orderId)]]);
    if ($o) tl_stage_notify_staff($pdo, $o, "new");   // сотрудникам этапа «Заявка»

} catch (PDOException $e) {
    error_log("order.php: " . $e->getMessage());
    fail(
        ORDER_DEBUG ? "Ошибка БД: " . $e->getMessage()
                    : "Не удалось оформить заказ. Позвоните нам: +7 928 330-02-06",
        null, 500
    );
}