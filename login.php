<?php

/*
|--------------------------------------------------------------------------
| Единые настройки сессии — ОБЯЗАТЕЛЬНО такие же, как в check_auth.php,
| register.php, logout.php. Если параметры разные — сессия слетает.
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

$phone    = trim($_POST["phone"] ?? "");
$password = trim($_POST["password"] ?? "");
$remember = isset($_POST["remember"]);

/*
|--------------------------------------------------------------------------
| Нормализация телефона: приводим к формату 7XXXXXXXXXX
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

if ($password === "") {
    echo json_encode([
        "success" => false,
        "field"   => "password",
        "message" => "Введите пароль"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {

    $stmt = $pdo->prepare(
        "SELECT id, name, phone, password_hash, role, avatar
         FROM users
         WHERE phone = ?
         LIMIT 1"
    );

    $stmt->execute([$phone]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
    |----------------------------------------------------------------
    | Намеренно не говорим отдельно "телефон не найден" —
    | это позволяет перебирать номера. Единое сообщение безопаснее.
    | Если хочешь оставить раздельные — просто скажи, верну как было.
    |----------------------------------------------------------------
    */

    if (!$user || !password_verify($password, $user["password_hash"])) {
        echo json_encode([
            "success" => false,
            "field"   => "general",
            "message" => "Неверный телефон или пароль"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    session_regenerate_id(true);

    $_SESSION["user_id"]     = $user["id"];
    $_SESSION["user_name"]   = $user["name"];
    $_SESSION["user_phone"]  = $user["phone"];
    $_SESSION["user_role"]   = $user["role"];
    $_SESSION["user_avatar"] = $user["avatar"];

    if ($remember) {

        $token  = bin2hex(random_bytes(32));
        $hash   = hash("sha256", $token);
        $expire = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 30);

        $update = $pdo->prepare(
            "UPDATE users
             SET remember_token_hash = ?, remember_expires = ?
             WHERE id = ?"
        );

        $update->execute([$hash, $expire, $user["id"]]);

        setcookie(
            "remember_token",
            $token,
            [
                "expires"  => time() + 60 * 60 * 24 * 30,
                "path"     => "/",
                "secure"   => true,
                "httponly" => true,
                "samesite" => "Lax"
            ]
        );
    }

    echo json_encode([
        "success" => true,
        "user" => [
            "id"     => $user["id"],
            "name"   => $user["name"],
            "phone"  => $user["phone"],
            "avatar" => $user["avatar"],
            "role"   => $user["role"]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Ошибка базы данных"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}