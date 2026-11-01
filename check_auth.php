<?php

/*
|--------------------------------------------------------------------------
| Те же самые параметры сессии, что и в login.php / register.php / logout.php.
| Это критично — если где-то параметры отличаются, PHP может выдать
| другую сессию или не найти куку вовсе, и пользователя выкидывает.
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


/*
|--------------------------------------------------------------------------
| Если есть активная сессия — отдаём пользователя сразу
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["user_id"])) {

    $stmt = $pdo->prepare(
        "SELECT id, name, phone, avatar, role
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->execute([$_SESSION["user_id"]]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode([
            "success" => true,
            "auth"    => true,
            "user"    => $user
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
    | Сессия есть, а пользователя в базе уже нет (удалили аккаунт) —
    | подчищаем протухшую сессию.
    */
    session_destroy();
}


/*
|--------------------------------------------------------------------------
| Проверяем "запомнить меня" по cookie remember_token
|--------------------------------------------------------------------------
*/

if (isset($_COOKIE["remember_token"])) {

    $tokenHash = hash("sha256", $_COOKIE["remember_token"]);

    $stmt = $pdo->prepare(
        "SELECT id, name, phone, avatar, role
         FROM users
         WHERE remember_token_hash = ?
         AND remember_expires > NOW()
         LIMIT 1"
    );

    $stmt->execute([$tokenHash]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {

        session_regenerate_id(true);

        $_SESSION["user_id"]     = $user["id"];
        $_SESSION["user_name"]   = $user["name"];
        $_SESSION["user_phone"]  = $user["phone"];
        $_SESSION["user_role"]   = $user["role"];
        $_SESSION["user_avatar"] = $user["avatar"];

        echo json_encode([
            "success" => true,
            "auth"    => true,
            "user"    => $user
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
    | Токен есть, но невалидный/просрочен — удаляем битую куку,
    | чтобы браузер не слал её каждый раз впустую.
    */
    setcookie("remember_token", "", time() - 3600, "/");
}


echo json_encode([
    "success" => true,
    "auth"    => false
], JSON_UNESCAPED_UNICODE);