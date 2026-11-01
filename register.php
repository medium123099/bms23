<?php

/*
|--------------------------------------------------------------------------
| Те же параметры сессии, что и в login.php / check_auth.php / logout.php
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

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Неверный запрос"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$name     = trim($_POST["name"] ?? "");
$phone    = trim($_POST["phone"] ?? "");
$password = trim($_POST["password"] ?? "");


/*
|--------------------------------------------------------------------------
| Имя
|--------------------------------------------------------------------------
*/

if (mb_strlen($name) < 2) {
    echo json_encode([
        "success" => false,
        "field"   => "name",
        "message" => "Введите корректное имя"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($name) > 60) {
    echo json_encode([
        "success" => false,
        "field"   => "name",
        "message" => "Слишком длинное имя"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// разрешаем буквы (рус/лат), пробел и дефис — защита от <script> и т.п.
if (!preg_match('/^[\p{L} \-]+$/u', $name)) {
    echo json_encode([
        "success" => false,
        "field"   => "name",
        "message" => "Имя может содержать только буквы"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


/*
|--------------------------------------------------------------------------
| Телефон — раньше вообще не проверялся, можно было отправить что угодно
|--------------------------------------------------------------------------
*/

$phone = preg_replace('/\D/', '', $phone);

if (strlen($phone) === 11 && $phone[0] === "8") {
    $phone = "7" . substr($phone, 1);
}

if (strlen($phone) === 10) {
    $phone = "7" . $phone;
}

if (strlen($phone) !== 11 || $phone[0] !== "7") {
    echo json_encode([
        "success" => false,
        "field"   => "phone",
        "message" => "Введите корректный номер телефона"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


/*
|--------------------------------------------------------------------------
| Пароль
|--------------------------------------------------------------------------
*/

if (mb_strlen($password) < 6) {
    echo json_encode([
        "success" => false,
        "field"   => "password",
        "message" => "Пароль должен содержать минимум 6 символов"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


try {

    $check = $pdo->prepare(
        "SELECT id FROM users WHERE phone = ? LIMIT 1"
    );
    $check->execute([$phone]);

    if ($check->fetch()) {
        echo json_encode([
            "success" => false,
            "field"   => "phone",
            "message" => "Пользователь с таким телефоном уже существует"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare(
        "INSERT INTO users (phone, name, password_hash, role, created_at)
         VALUES (?, ?, ?, 'user', NOW())"
    );

    $insert->execute([$phone, $name, $passwordHash]);

    $userId = $pdo->lastInsertId();

    session_regenerate_id(true);

    $_SESSION["user_id"]     = $userId;
    $_SESSION["user_name"]   = $name;
    $_SESSION["user_phone"]  = $phone;
    $_SESSION["user_role"]   = "user";
    $_SESSION["user_avatar"] = null;

    echo json_encode([
        "success" => true,
        "message" => "Аккаунт создан",
        "user" => [
            "id"     => $userId,
            "name"   => $name,
            "phone"  => $phone,
            "avatar" => null,
            "role"   => "user"
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (PDOException $e) {

    /*
    | 1062 = нарушение UNIQUE-индекса: телефон уже занят. Ловим и здесь,
    | потому что проверка SELECT выше не защищает от двойного клика
    | или двух одновременных запросов — их отсекает только индекс в БД.
    */
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        echo json_encode([
            "success" => false,
            "field"   => "phone",
            "message" => "Пользователь с таким телефоном уже существует"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Не удалось создать аккаунт"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}