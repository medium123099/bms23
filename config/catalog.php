<?php

/*
| Каталог аппаратов. Хранится в таблице tl_products и редактируется в admin.html (вкладка «Каталог»).
| Если таблицы ещё нет или база недоступна, берутся значения по умолчанию ниже.
| Ключ товара (slug) — латиница и цифры; для сайта id в корзине = "treelife-" + slug.
*/

function tl_catalog_defaults(): array
{
    return [
        "lite"   => ["name" => "TreeLife Lite",        "price" => 50000,
                     "desc" => "Упрощённая версия аппарата биомеханической стимуляции лица и шейно-воротниковой зоны.",
                     "page" => "treelife-lite",   "active" => 1, "sort" => 10],
        "beauty" => ["name" => "TreeLife Stimulation", "price" => 65000,
                     "desc" => "Аппарат биомеханической стимуляции лица и тела. Для кабинета и для дома.",
                     "page" => "treelife-beauty", "active" => 1, "sort" => 20],
        "vacuum" => ["name" => "TreeLife Vacuum",      "price" => 100000,
                     "desc" => "Универсальный аппарат вибровакуумного массажа и биомеханической стимуляции для коррекции фигуры и лица.",
                     "page" => "treelife-vacuum", "active" => 1, "sort" => 30],
    ];
}

function tl_ensure_products(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tl_products (
            slug       VARCHAR(20)  NOT NULL PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            price      INT UNSIGNED NOT NULL DEFAULT 0,
            descr      VARCHAR(600) NULL,
            page       VARCHAR(100) NULL,
            active     TINYINT(1)   NOT NULL DEFAULT 1,
            sort       INT          NOT NULL DEFAULT 100
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ((int)$pdo->query("SELECT COUNT(*) FROM tl_products")->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO tl_products (slug, name, price, descr, page, active, sort) VALUES (?,?,?,?,?,?,?)");
        foreach (tl_catalog_defaults() as $slug => $p) {
            $ins->execute([$slug, $p["name"], $p["price"], $p["desc"], $p["page"], $p["active"], $p["sort"]]);
        }
    }
}

/* [slug => [name, price, desc, page, active, sort]]. $all = true — вместе со скрытыми товарами */
function tl_catalog(bool $all = false): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = tl_catalog_defaults();
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                tl_ensure_products($pdo);
                $rows = $pdo->query("SELECT * FROM tl_products ORDER BY sort, slug")->fetchAll();
                if ($rows) {
                    $cache = [];
                    foreach ($rows as $r) {
                        $cache[$r["slug"]] = [
                            "name" => $r["name"], "price" => (int)$r["price"], "desc" => (string)$r["descr"],
                            "page" => (string)$r["page"], "active" => (int)$r["active"], "sort" => (int)$r["sort"],
                        ];
                    }
                }
            } catch (Throwable $e) {
                error_log("catalog: " . $e->getMessage());
            }
        }
    }
    if ($all) return $cache;
    return array_filter($cache, function ($p) { return !empty($p["active"]); });
}

/* id из корзины сайта ("treelife-lite") или slug ("lite") -> slug или "" */
function tl_catalog_slug(string $id): string
{
    $id = preg_replace('/^treelife-/', '', trim($id));
    return isset(tl_catalog()[$id]) ? $id : "";
}
