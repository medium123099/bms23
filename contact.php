<?php

/*
| Заявка «Перезвоните мне» с форм на страницах /contacts и /courses.
| Сохраняется в таблицу callbacks (видна в админ-панели, вкладка «Заявки»)
| и дублируется письмом на info@bms23.ru.
*/

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/schema.php";
require __DIR__ . "/config/admin_ops.php";
require __DIR__ . "/config/max_client.php";

header("Content-Type: application/json; charset=UTF-8");

function out($ok, $message = "", $field = null, $code = 200) {
    http_response_code($code);
    $o = ["success" => $ok];
    if ($message) $o["message"] = $message;
    if ($field)   $o["field"]   = $field;
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") out(false, "Неверный запрос", null, 405);

if (trim($_POST["website"] ?? "") !== "") out(true);   // бот

$name    = trim($_POST["name"] ?? "");
$phone   = preg_replace('/\D/', '', trim($_POST["phone"] ?? ""));
$topic   = mb_substr(trim($_POST["topic"] ?? ""), 0, 40);
$message = mb_substr(trim($_POST["message"] ?? ""), 0, 500);

if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || !preg_match('/^[\p{L} \-]+$/u', $name)) {
    out(false, "Введите корректное имя", "name");
}
if (strlen($phone) === 11 && $phone[0] === "8") $phone = "7" . substr($phone, 1);
if (strlen($phone) === 10) $phone = "7" . $phone;
if (strlen($phone) !== 11 || $phone[0] !== "7") {
    out(false, "Введите корректный номер телефона", "phone");
}

try {
    try { tl_ensure_callbacks($pdo); } catch (PDOException $e) { error_log("contact.php ensure: " . $e->getMessage()); }

    $pdo->prepare(
        "INSERT INTO tl_callbacks (name, phone, topic, message, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    )->execute([$name, $phone, $topic !== "" ? $topic : null, $message !== "" ? $message : null]);

} catch (PDOException $e) {
    error_log("contact.php: " . $e->getMessage());
    out(false, "Не удалось отправить заявку. Позвоните нам: +7 928 330-02-06", null, 500);
}

// письмо — необязательно: если на хостинге mail() отключён, заявка всё равно в базе
$body = "Имя: $name\nТелефон: +$phone\nТема: $topic\nСообщение: $message\n";
@mail("info@bms23.ru", "=?UTF-8?B?" . base64_encode("Заявка с сайта bms23.ru") . "?=", $body,
      "Content-Type: text/plain; charset=UTF-8\r\nFrom: no-reply@bms23.ru");

// ответ посетителю сразу, уведомление в бот MAX уже после него
echo json_encode(["success" => true], JSON_UNESCAPED_UNICODE);
tl_finish_response();
tl_max_notify($pdo, "📞 Заявка на звонок\n" . $name . ", " . tl_phone_show($phone)
    . ($topic !== "" ? "\nТема: " . $topic : "") . ($message !== "" ? "\n" . $message : ""),
    [[tl_btn("Все заявки", "c:l")]]);
