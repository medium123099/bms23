<?php

/*
| Разовая настройка бота MAX: проверка токена, создание таблиц, подписка вебхука.
| Открывается с ключом setup_key из config/max.php.
| ПОСЛЕ НАСТРОЙКИ ЭТОТ ФАЙЛ НУЖНО УДАЛИТЬ С СЕРВЕРА.
*/

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/schema.php";
require __DIR__ . "/config/admin_ops.php";
require __DIR__ . "/config/max_client.php";

header("Content-Type: text/html; charset=UTF-8");
header("X-Robots-Tag: noindex, nofollow");

$cfg    = tl_max_config();
$hook   = rtrim((string)$cfg["site_url"], "/") . "/max_bot.php";
$action = (string)($_POST["action"] ?? "");
$key    = (string)($_POST["key"] ?? "");
$log    = [];
$authed = false;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function show(array $r): string
{
    return ($r["ok"] ? "OK" : "ОШИБКА") . " (HTTP " . $r["code"] . ")"
         . ($r["error"] !== "" ? ": " . $r["error"] : "")
         . "\n" . json_encode($r["data"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

if ($action !== "") {
    usleep(400000);   // чтобы ключ нельзя было подбирать быстро
    if ((string)$cfg["setup_key"] === "" || !hash_equals((string)$cfg["setup_key"], $key)) {
        $log[] = "Неверный ключ.";
    } else {
        $authed = true;

        if (!tl_max_enabled()) {
            $log[] = "В config/max.php не задан token бота.";
        } elseif ($action === "check") {
            $log[] = "Бот (GET /me):\n" . show(tl_max_call("GET", "/me"));
            $log[] = "Подписки (GET /subscriptions):\n" . show(tl_max_call("GET", "/subscriptions"));
            $log[] = "Адрес вебхука: " . $hook;
            if ((string)$cfg["webhook_secret"] === "") $log[] = "ВНИМАНИЕ: webhook_secret пустой, подписка без секрета небезопасна.";
            if ((string)$cfg["admin_key"] === "")      $log[] = "ВНИМАНИЕ: admin_key пустой, команда /admin работать не будет.";

        } elseif ($action === "tables") {
            try {
                tl_ensure_tables($pdo);
                tl_ensure_max($pdo);
                $log[] = "Таблицы созданы или уже были.";
            } catch (Throwable $e) {
                $log[] = "ОШИБКА БД: " . $e->getMessage();
            }

        } elseif ($action === "subscribe") {
            if ((string)$cfg["webhook_secret"] === "") {
                $log[] = "Сначала задайте webhook_secret в config/max.php.";
            } else {
                $log[] = "Подписка (POST /subscriptions):\n" . show(tl_max_call("POST", "/subscriptions", [], [
                    "url"          => $hook,
                    "update_types" => ["message_created", "message_callback", "bot_started"],
                    "secret"       => (string)$cfg["webhook_secret"],
                ]));
            }

        } elseif ($action === "unsubscribe") {
            $log[] = "Отписка (DELETE /subscriptions):\n" . show(tl_max_call("DELETE", "/subscriptions", ["url" => $hook]));

        } elseif ($action === "test") {
            try {
                tl_ensure_max($pdo);
                $ids = tl_max_admin_ids($pdo);
                if (!$ids) $log[] = "Администраторов бота пока нет. Напишите боту: /admin КЛЮЧ";
                foreach ($ids as $id) {
                    $log[] = "Тест для ID " . $id . ":\n" . show(tl_max_send((int)$id, "Проверка связи: бот сайта TreeLife Stimulation работает."));
                }
            } catch (Throwable $e) {
                $log[] = "ОШИБКА: " . $e->getMessage();
            }
        }

        $all = implode("\n", $log);
        if (stripos($all, "certificate") !== false || stripos($all, "SSL") !== false) {
            $log[] = "Похоже на проблему с сертификатом. Сервер MAX использует сертификат Минцифры. " .
                     "Скачайте корневой сертификат в формате .pem, положите в config/ и укажите путь в ca_file в config/max.php. " .
                     "Отключать проверку сертификата нельзя.";
        }
        if (stripos($all, "could not resolve") !== false || (stripos($all, "HTTP 404") !== false && stripos($all, "/me") !== false)) {
            $log[] = "Если хост не находится или отвечает 404, смените base_url в config/max.php на https://platform-api2.max.ru";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Настройка бота MAX</title>
  <style>
    body { font: 15px/1.5 system-ui, sans-serif; max-width: 760px; margin: 32px auto; padding: 0 16px; color: #1c2b2b; }
    h1 { font-size: 22px; }
    input[type=password] { padding: 8px; width: 100%; box-sizing: border-box; margin: 6px 0 12px; }
    button { padding: 8px 14px; margin: 0 6px 8px 0; cursor: pointer; }
    pre { background: #f2f5f5; padding: 12px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
    .warn { background: #fff4e0; padding: 10px 12px; }
  </style>
</head>
<body>
  <h1>Настройка бота MAX</h1>
  <p class="warn">После настройки удалите файл <b>max_setup.php</b> с сервера.</p>
  <p>Адрес вебхука: <b><?= h($hook) ?></b></p>

  <form method="post" autocomplete="off">
    <label>Ключ настройки (setup_key из config/max.php)</label>
    <input type="password" name="key" value="<?= h($authed ? $key : "") ?>" required>
    <button name="action" value="check">1. Проверить токен</button>
    <button name="action" value="tables">2. Создать таблицы</button>
    <button name="action" value="subscribe">3. Подписать вебхук</button>
    <button name="action" value="test">4. Тестовое сообщение</button>
    <button name="action" value="unsubscribe">Отписать вебхук</button>
  </form>

  <?php foreach ($log as $block): ?>
    <pre><?= h($block) ?></pre>
  <?php endforeach; ?>
</body>
</html>
