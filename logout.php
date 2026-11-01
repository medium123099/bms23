<?php

/*
|--------------------------------------------------------------------------
| Те же параметры сессии, что и в остальных файлах
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


/*
|--------------------------------------------------------------------------
| Если был "запомнить меня" — чистим токен в базе, иначе он
| останется рабочим и check_auth.php снова залогинит пользователя
| несмотря на выход.
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["user_id"])) {

    $stmt = $pdo->prepare(
        "UPDATE users
         SET remember_token_hash = NULL, remember_expires = NULL
         WHERE id = ?"
    );

    $stmt->execute([$_SESSION["user_id"]]);
}


/*
|--------------------------------------------------------------------------
| Удаляем remember_token cookie
|--------------------------------------------------------------------------
*/

if (isset($_COOKIE["remember_token"])) {
    setcookie("remember_token", "", time() - 3600, "/");
    unset($_COOKIE["remember_token"]);
}


/*
|--------------------------------------------------------------------------
| Уничтожаем сессию
|--------------------------------------------------------------------------
*/

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();


echo json_encode([
    "success" => true,
    "message" => "Вы вышли из аккаунта"
], JSON_UNESCAPED_UNICODE);

exit;