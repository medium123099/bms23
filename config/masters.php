<?php

/*
|--------------------------------------------------------------------------
| Мастера TreeLife: публичный список на сайте (masters.html).
| Публикуются только мастера, у которых включён показ И отмечено согласие
| на публикацию данных. Управление: /masters_admin (только администратор).
|--------------------------------------------------------------------------
*/

function tl_ensure_masters(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_masters (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(80)  NOT NULL,
            city        VARCHAR(60)  NOT NULL,
            specialty   VARCHAR(120) NULL,
            about       VARCHAR(400) NULL,
            contact_url VARCHAR(200) NULL,
            phone       VARCHAR(11)  NULL,
            show_phone  TINYINT(1)   NOT NULL DEFAULT 0,
            consent     TINYINT(1)   NOT NULL DEFAULT 0,
            status      VARCHAR(8)   NOT NULL DEFAULT 'hidden',
            sort_order  INT          NOT NULL DEFAULT 100,
            created_at  DATETIME     NOT NULL,
            KEY idx_masters_pub (status, consent, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/* "@name" -> ссылка на Telegram; иначе только http(s). Пусто — без ссылки. */
function tl_master_contact(string $raw): ?string
{
    $v = trim($raw);
    if ($v === "") return null;
    if ($v[0] === "@" && preg_match('/^@[A-Za-z0-9_]{4,32}$/', $v)) return "https://t.me/" . substr($v, 1);
    if (preg_match('~^https?://[^\s<>"\']{3,190}$~i', $v)) return $v;
    return "";   // "" = некорректно
}
