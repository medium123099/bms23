<?php

/*
| Публичные отзывы.
|   GET  — список опубликованных отзывов
|   POST — новый отзыв (уходит на модерацию со статусом pending)
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
const REVIEWS_DEBUG = false;

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    try { tl_ensure_reviews($pdo); } catch (PDOException $e) { error_log("reviews.php ensure: " . $e->getMessage()); }

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $rows = $pdo->query(
            "SELECT id, name, rating, text, created_at
             FROM tl_reviews
             WHERE status = 'approved'
             ORDER BY created_at DESC, id DESC
             LIMIT 100"
        )->fetchAll();

        $list = array_map(function ($r) {
            return [
                "id"     => (int)$r["id"],
                "name"   => $r["name"],
                "rating" => (int)$r["rating"],
                "text"   => $r["text"],
                "date"   => date("c", strtotime($r["created_at"]))
            ];
        }, $rows);

        out(["success" => true, "reviews" => $list]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        out(["success" => false, "message" => "Неверный запрос"], 405);
    }

    // ловушка для ботов
    if (trim($_POST["website"] ?? "") !== "") {
        out(["success" => true]);
    }

    $name   = trim($_POST["name"] ?? "");
    $rating = (int)($_POST["rating"] ?? 0);
    $text   = trim($_POST["text"] ?? "");

    if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || !preg_match('/^[\p{L} \-,.]+$/u', $name)) {
        out(["success" => false, "field" => "name", "message" => "Введите корректное имя"]);
    }
    if ($rating < 1 || $rating > 5) {
        out(["success" => false, "field" => "rating", "message" => "Поставьте оценку"]);
    }
    if (mb_strlen($text) < 10) {
        out(["success" => false, "field" => "text", "message" => "Напишите хотя бы 10 символов"]);
    }
    $text = mb_substr($text, 0, 1000);

    // не чаще одного отзыва в минуту с одного браузера
    if (!empty($_SESSION["review_last"]) && time() - $_SESSION["review_last"] < 60) {
        out(["success" => false, "field" => "text", "message" => "Подождите минуту перед следующим отзывом"]);
    }

    $pdo->prepare(
        "INSERT INTO tl_reviews (name, rating, text, status, source, created_at)
         VALUES (?, ?, ?, 'pending', 'site', NOW())"
    )->execute([$name, $rating, $text]);

    $_SESSION["review_last"] = time();
    $newId = (int)$pdo->lastInsertId();

    echo json_encode(["success" => true], JSON_UNESCAPED_UNICODE);
    session_write_close();
    tl_finish_response();
    tl_max_notify($pdo, "💬 Новый отзыв на модерации\n\n" . str_repeat("★", $rating) . " " . $name . "\n" . $text,
        [[tl_btn("Модерировать", "r:n")]]);
    exit;

} catch (PDOException $e) {
    error_log("reviews.php: " . $e->getMessage());
    out(["success" => false, "message" => REVIEWS_DEBUG ? "Ошибка БД: " . $e->getMessage() : "Не удалось сохранить отзыв"], 500);
}