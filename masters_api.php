<?php

/* Управление списком мастеров (только администратор сайта). */

session_set_cookie_params([
    "lifetime" => 60 * 60 * 24 * 30,
    "path"     => "/",
    "secure"   => true,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/masters.php";
require_once __DIR__ . "/config/admin_ops.php";

header("Content-Type: application/json; charset=UTF-8");

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = false;
if (!empty($_SESSION["user_id"])) {
    $s = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $s->execute([$_SESSION["user_id"]]);
    $isAdmin = ($s->fetchColumn() === "admin");
}
if (!$isAdmin) out(["success" => false, "message" => "Доступ запрещён"], 403);

if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "fetch") {
    out(["success" => false, "message" => "Неверный запрос"], 400);
}

try {
    tl_ensure_masters($pdo);
    $b = json_decode(file_get_contents("php://input"), true);
    if (!is_array($b)) $b = [];
    $action = (string)($b["action"] ?? "list");

    if ($action === "list") {
        $rows = $pdo->query("SELECT * FROM tl_masters ORDER BY city ASC, sort_order ASC, name ASC LIMIT 1000")->fetchAll();
        out(["success" => true, "masters" => array_map(function ($r) {
            return [
                "id" => (int)$r["id"], "name" => $r["name"], "city" => $r["city"],
                "specialty" => (string)($r["specialty"] ?? ""), "about" => (string)($r["about"] ?? ""),
                "contact" => (string)($r["contact_url"] ?? ""), "phone" => (string)($r["phone"] ?? ""),
                "show_phone" => (bool)$r["show_phone"], "consent" => (bool)$r["consent"],
                "status" => $r["status"], "sort" => (int)$r["sort_order"],
            ];
        }, $rows)]);
    }

    if ($action === "delete") {
        $pdo->prepare("DELETE FROM tl_masters WHERE id = ?")->execute([(int)($b["id"] ?? 0)]);
        out(["success" => true]);
    }

    if ($action === "save") {
        $id      = (int)($b["id"] ?? 0);
        $name    = mb_substr(trim((string)($b["name"] ?? "")), 0, 80);
        $city    = mb_substr(trim((string)($b["city"] ?? "")), 0, 60);
        $spec    = mb_substr(trim((string)($b["specialty"] ?? "")), 0, 120);
        $about   = mb_substr(trim((string)($b["about"] ?? "")), 0, 400);
        $contact = tl_master_contact((string)($b["contact"] ?? ""));
        $phoneRaw = trim((string)($b["phone"] ?? ""));
        $phone   = $phoneRaw !== "" ? tl_phone_norm($phoneRaw) : "";
        $showPh  = !empty($b["show_phone"]) ? 1 : 0;
        $consent = !empty($b["consent"]) ? 1 : 0;
        $status  = ($b["status"] ?? "hidden") === "shown" ? "shown" : "hidden";
        $sort    = max(0, min(9999, (int)($b["sort"] ?? 100)));

        if (mb_strlen($name) < 2) out(["success" => false, "message" => "Введите имя мастера"]);
        if (mb_strlen($city) < 2) out(["success" => false, "message" => "Введите город"]);
        if ($contact === "")      out(["success" => false, "message" => "Ссылка: нужен адрес с https:// или @имя в Telegram"]);
        if ($phoneRaw !== "" && $phone === "") out(["success" => false, "message" => "Телефон указан неверно"]);
        if ($showPh && $phone === "") out(["success" => false, "message" => "Чтобы показывать телефон, укажите его"]);
        if ($status === "shown" && !$consent) out(["success" => false, "message" => "Показывать на сайте можно только при согласии мастера на публикацию"]);

        $vals = [$name, $city, $spec !== "" ? $spec : null, $about !== "" ? $about : null, $contact,
                 $phone !== "" ? $phone : null, $showPh, $consent, $status, $sort];
        if ($id > 0) {
            $vals[] = $id;
            $pdo->prepare("UPDATE tl_masters SET name=?, city=?, specialty=?, about=?, contact_url=?, phone=?, show_phone=?, consent=?, status=?, sort_order=? WHERE id=?")->execute($vals);
        } else {
            $pdo->prepare("INSERT INTO tl_masters (name, city, specialty, about, contact_url, phone, show_phone, consent, status, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")->execute($vals);
            $id = (int)$pdo->lastInsertId();
        }
        out(["success" => true, "id" => $id]);
    }

    out(["success" => false, "message" => "Неизвестное действие"], 400);
} catch (Throwable $e) {
    error_log("masters_api.php: " . $e->getMessage());
    out(["success" => false, "message" => "Ошибка сервера"], 500);
}
