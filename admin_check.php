<?php

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

$isAdmin = false;

if (!empty($_SESSION["user_id"])) {
    // роль берём из базы, а не из сессии — так права отзываются сразу
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION["user_id"]]);
    $isAdmin = ($stmt->fetchColumn() === "admin");
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Доступ запрещён"], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(["success" => true], JSON_UNESCAPED_UNICODE);