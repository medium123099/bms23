<?php

/*
| Задачи по расписанию (раз в час):
|   • резервная копия базы (не чаще раза в сутки, хранится 14 последних);
|   • напоминание клиенту бота о брошенной корзине (один раз, через сутки, днём).
| Запуск в cron хостинга любым способом:
|   php /путь/к/сайту/cron.php
|   или по ссылке  https://bms23.ru/cron.php?key=ВАШ_cron_key   (ключ из config/max.php)
*/

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/schema.php";
require __DIR__ . "/config/admin_ops.php";
require __DIR__ . "/config/max_client.php";
require __DIR__ . "/config/max_shop.php";
require __DIR__ . "/config/backup.php";

$cli = PHP_SAPI === "cli";
if (!$cli) {
    header("Content-Type: text/plain; charset=UTF-8");
    header("X-Robots-Tag: noindex, nofollow");
    $key = (string)tl_max_config()["cron_key"];
    if ($key === "" || !hash_equals($key, (string)($_GET["key"] ?? ""))) { http_response_code(403); echo "forbidden"; exit; }
}

$task = $cli ? (string)($argv[1] ?? "all") : (string)($_GET["task"] ?? "all");
@set_time_limit(300);

try {
    tl_ensure_tables($pdo);
} catch (Throwable $e) {
    error_log("cron ensure: " . $e->getMessage());
}

/* ---------- резервная копия ---------- */
if ($task === "all" || $task === "backup") {
    $files = tl_backup_list();
    $fresh = $files && (time() - $files[0]["time"]) < 23 * 3600;
    if ($fresh && empty($_GET["force"])) {
        echo "backup: свежая копия уже есть (" . $files[0]["name"] . ")\n";
    } else {
        try {
            [$f, $size] = tl_backup_make($pdo);
            echo "backup: ok " . basename($f) . " " . round($size / 1024) . " KB\n";
        } catch (Throwable $e) {
            error_log("cron backup: " . $e->getMessage());
            echo "backup: ОШИБКА " . $e->getMessage() . "\n";
            tl_max_notify($pdo, "⚠️ Не удалось сделать резервную копию базы сайта: " . $e->getMessage());
        }
    }
}

/* ---------- напоминание о корзине ---------- */
if ($task === "all" || $task === "remind") {
    $h = (int)date("G");
    if (!tl_max_enabled()) {
        echo "remind: бот не настроен\n";
    } elseif ($h < 9 || $h >= 20) {
        echo "remind: ночь, отправим днём\n";
    } else {
        $ids = $pdo->query(
            "SELECT user_id FROM tl_max_users
             WHERE cart IS NOT NULL AND cart NOT IN ('', '[]', '{}')
               AND cart_at IS NOT NULL
               AND cart_at < (NOW() - INTERVAL 24 HOUR) AND cart_at > (NOW() - INTERVAL 7 DAY)
               AND (remind_at IS NULL OR remind_at < cart_at)
             LIMIT 100"
        )->fetchAll(PDO::FETCH_COLUMN);

        $sent = 0;
        foreach ($ids as $uid) {
            $uid  = (int)$uid;
            $cart = tl_cart_get($pdo, $uid);
            $pdo->prepare("UPDATE tl_max_users SET remind_at = NOW() WHERE user_id = ?")->execute([$uid]);
            if (!$cart) continue;
            $cat = tl_catalog();
            $names = [];
            foreach ($cart as $k => $q) $names[] = $cat[$k]["name"] . " × " . $q;
            $r = tl_max_send($uid, "В вашей корзине остались аппараты:\n" . implode("\n", $names) . "\nИтого: " . tl_money(tl_cart_total($cart)) . "\n\nОформить заказ или задать вопрос менеджеру?",
                [[tl_btn("🛒 Открыть корзину", "k:cart")], [tl_btn("💬 Задать вопрос", "k:ask")]], 6);
            if ($r["ok"]) $sent++;
            usleep(150000);
        }
        echo "remind: отправлено $sent из " . count($ids) . "\n";
    }
}

echo "done\n";
