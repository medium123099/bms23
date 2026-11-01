<?php

require_once __DIR__ . "/catalog.php";
require_once __DIR__ . "/pipeline.php";

/*
|--------------------------------------------------------------------------
| Создаёт нужные таблицы, если их ещё нет (orders, reviews, callbacks).
| Подключается из order.php, reviews.php, contact.php, admin_api.php.
| Папка config закрыта .htaccess от прямого доступа — это нормально.
|--------------------------------------------------------------------------
*/

function tl_ensure_orders(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_orders (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NULL,
            name       VARCHAR(60)  NOT NULL,
            phone      VARCHAR(11)  NOT NULL,
            comment    VARCHAR(500) NULL,
            items      TEXT         NOT NULL,
            total      INT UNSIGNED NOT NULL,
            status     VARCHAR(20)  NOT NULL DEFAULT 'new',
            created_at DATETIME     NOT NULL,
            manual     TINYINT(1)   NOT NULL DEFAULT 0,
            KEY idx_orders_phone (phone),
            KEY idx_orders_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // таблица могла быть создана раньше без колонки manual
    if (!$pdo->query("SHOW COLUMNS FROM tl_orders LIKE 'manual'")->fetch()) {
        $pdo->exec("ALTER TABLE tl_orders ADD COLUMN manual TINYINT(1) NOT NULL DEFAULT 0");
    }
    // заказ из бота MAX: id пользователя MAX, чтобы он видел только свои заказы
    if (!$pdo->query("SHOW COLUMNS FROM tl_orders LIKE 'max_user'")->fetch()) {
        $pdo->exec("ALTER TABLE tl_orders ADD COLUMN max_user BIGINT NULL, ADD KEY idx_orders_maxuser (max_user)");
    }

    // этапы заказа (Заявка → Счёт → Оплата → Чек → Отправка): поля, которые заполняют сотрудники
    $have = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tl_orders")->fetchAll() as $c) $have[$c["Field"]] = true;
    $isNewPipeline = !isset($have["stage_at"]);
    $cols = [
        "buyer_type" => "VARCHAR(8) NOT NULL DEFAULT 'person'",   // person | company
        "requisites" => "VARCHAR(500) NULL",                      // реквизиты юрлица
        "invoice_no" => "VARCHAR(40) NULL",
        "waybill_no" => "VARCHAR(60) NULL",
        "pvz"        => "VARCHAR(200) NULL",
        "stage_at"   => "DATETIME NULL",                          // когда заказ перешёл на текущий этап
        // данные заявки по графам (пустые остаются NULL и нигде не показываются)
        "last_name"  => "VARCHAR(60) NULL",
        "first_name" => "VARCHAR(60) NULL",
        "middle_name"=> "VARCHAR(60) NULL",
        "email"      => "VARCHAR(100) NULL",
        "cdek"       => "VARCHAR(250) NULL",                      // адрес доставки / пункт СДЭК
        "agent"      => "VARCHAR(80) NULL",                       // агент: от кого пришёл заказ (код)
    ];
    foreach ($cols as $name => $def) {
        if (!isset($have[$name])) $pdo->exec("ALTER TABLE tl_orders ADD COLUMN $name $def");
    }
    // в name теперь лежит полное ФИО одной строкой — 60 символов может не хватить
    $nameCol = $pdo->query("SHOW COLUMNS FROM tl_orders LIKE 'name'")->fetch();
    if ($nameCol && stripos((string)$nameCol["Type"], "varchar(60)") === 0) {
        $pdo->exec("ALTER TABLE tl_orders MODIFY name VARCHAR(190) NOT NULL");
    }
    if ($isNewPipeline) {
        // один раз: старый статус «Оплачен» теперь этап «Чек» (деньги получены, чек ещё не пробит)
        $pdo->exec("UPDATE tl_orders SET status = 'receipt' WHERE status = 'paid'");
        $pdo->exec("UPDATE tl_orders SET stage_at = created_at WHERE stage_at IS NULL");
    }

    // журнал переходов: кто, когда и с какого этапа на какой перевёл заказ
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_order_log (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id    INT UNSIGNED NOT NULL,
            from_status VARCHAR(20)  NULL,
            to_status   VARCHAR(20)  NOT NULL,
            by_kind     VARCHAR(8)   NOT NULL,
            by_name     VARCHAR(80)  NULL,
            note        VARCHAR(500) NULL,
            created_at  DATETIME     NOT NULL,
            KEY idx_log_order (order_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

}

function tl_ensure_reviews(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_reviews (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(60)   NOT NULL,
            rating     TINYINT       NOT NULL,
            text       VARCHAR(1000) NOT NULL,
            status     VARCHAR(12)   NOT NULL DEFAULT 'pending',
            source     VARCHAR(10)   NOT NULL DEFAULT 'site',
            created_at DATETIME      NOT NULL,
            KEY idx_reviews_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // при первом создании кладём три отзыва, которые раньше были прописаны на главной
    if ((int)$pdo->query("SELECT COUNT(*) FROM tl_reviews")->fetchColumn() === 0) {
        $seed = $pdo->prepare(
            "INSERT INTO tl_reviews (name, rating, text, status, source, created_at)
             VALUES (?, 5, ?, 'approved', 'admin', ?)"
        );
        $seed->execute(["Марина, Краснодар",
            "Купила TreeLife Lite для дома, прошла онлайн-курс — всё понятно даже без опыта. Результат заметен уже через пару недель.",
            date("Y-m-d H:i:s", strtotime("-3 days"))]);
        $seed->execute(["Ольга, Краснодар",
            "Проходила очное обучение по TreeLife Stimulation в Краснодаре. Преподаватель подробно разобрал противопоказания и технику — работаю уверенно.",
            date("Y-m-d H:i:s", strtotime("-2 days"))]);
        $seed->execute(["Анна, Сочи",
            "TreeLife Vacuum взяла для салона — аппарат надёжный, сервис отвечает быстро. Онлайн-курс помог разобраться со всеми режимами.",
            date("Y-m-d H:i:s", strtotime("-1 days"))]);
    }

}

function tl_ensure_callbacks(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_callbacks (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(60)  NOT NULL,
            phone      VARCHAR(11)  NOT NULL,
            topic      VARCHAR(40)  NULL,
            message    VARCHAR(500) NULL,
            created_at DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/* все таблицы сразу (нужно только админ-панели) */
function tl_ensure_tables(PDO $pdo): void
{
    tl_ensure_orders($pdo);
    tl_ensure_reviews($pdo);
    tl_ensure_callbacks($pdo);
    tl_ensure_products($pdo);
    tl_ensure_max($pdo);
    tl_ensure_staff($pdo);
}

/* ---------- сотрудники: доступ к заказам по зонам ответственности ---------- */
function tl_ensure_staff(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // zones: «all» (вся база) или список этапов через запятую: new,invoice,payment,receipt,shipping
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_staff (
            id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name      VARCHAR(80)  NOT NULL,
            user_id   INT UNSIGNED NULL,
            max_user  BIGINT       NULL,
            zones     VARCHAR(80)  NOT NULL DEFAULT '',
            bind_code VARCHAR(8)   NULL,
            bind_exp  DATETIME     NULL,
            created_at DATETIME    NOT NULL,
            UNIQUE KEY uq_staff_user (user_id),
            UNIQUE KEY uq_staff_max (max_user)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/* ---------- бот MAX: администраторы и состояние диалога ---------- */
function tl_ensure_max(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_max_admins (
            user_id    BIGINT       NOT NULL PRIMARY KEY,
            name       VARCHAR(80)  NULL,
            created_at DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_max_state (
            user_id    BIGINT       NOT NULL PRIMARY KEY,
            state      VARCHAR(40)  NOT NULL,
            data       TEXT         NULL,
            updated_at DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // клиенты бота: сохранённые имя/телефон, корзина, антиспам
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_max_users (
            user_id     BIGINT       NOT NULL PRIMARY KEY,
            name        VARCHAR(60)  NULL,
            phone       VARCHAR(11)  NULL,
            cart        TEXT         NULL,
            last_review DATETIME     NULL,
            last_call   DATETIME     NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // новые поля: согласие на рассылку, время последнего изменения корзины, напоминание, вопрос менеджеру
    $cols = [
        "subscribed" => "TINYINT(1) NOT NULL DEFAULT 0",
        "sub_at"     => "DATETIME NULL",
        "cart_at"    => "DATETIME NULL",
        "remind_at"  => "DATETIME NULL",
        "last_ask"   => "DATETIME NULL",
    ];
    $have = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tl_max_users")->fetchAll() as $c) $have[$c["Field"]] = true;
    foreach ($cols as $name => $def) {
        if (!isset($have[$name])) $pdo->exec("ALTER TABLE tl_max_users ADD COLUMN $name $def");
    }

    // переписка клиента с менеджером через бота
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_max_msgs (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            max_user   BIGINT       NOT NULL,
            dir        VARCHAR(4)   NOT NULL,
            text       VARCHAR(1500) NOT NULL,
            created_at DATETIME     NOT NULL,
            KEY idx_msgs_user (max_user, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // связка аккаунта на сайте с аккаунтом MAX (личный кабинет показывает и заказы из бота)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_max_links (
            max_user   BIGINT       NOT NULL PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            created_at DATETIME     NOT NULL,
            KEY idx_links_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_link_codes (
            code       VARCHAR(8)   NOT NULL PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            expires_at DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
