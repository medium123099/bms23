<?php

/* Публичный список мастеров (только показанные и с согласием). Без авторизации, только чтение. */

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/masters.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: public, max-age=300");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    tl_ensure_masters($pdo);
    $rows = $pdo->query(
        "SELECT name, city, specialty, about, contact_url, phone, show_phone
         FROM tl_masters WHERE status = 'shown' AND consent = 1
         ORDER BY city ASC, sort_order ASC, name ASC LIMIT 500"
    )->fetchAll();

    $out = array_map(function ($r) {
        return [
            "name"      => $r["name"],
            "city"      => $r["city"],
            "specialty" => (string)($r["specialty"] ?? ""),
            "about"     => (string)($r["about"] ?? ""),
            "contact"   => (string)($r["contact_url"] ?? ""),
            "phone"     => ($r["show_phone"] && $r["phone"]) ? (string)$r["phone"] : "",
        ];
    }, $rows);

    echo json_encode(["success" => true, "masters" => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("masters.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "masters" => []], JSON_UNESCAPED_UNICODE);
}
