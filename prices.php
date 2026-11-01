<?php

/* Публичный список товаров и цен (из админки, вкладка «Каталог»). Страницы сайта подтягивают отсюда актуальные цены. */

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/schema.php";
require __DIR__ . "/config/catalog.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: public, max-age=60");

$out = [];
foreach (tl_catalog() as $slug => $p) {
    $out["treelife-" . $slug] = ["name" => $p["name"], "price" => (int)$p["price"], "page" => $p["page"]];
}
echo json_encode(["success" => true, "products" => $out], JSON_UNESCAPED_UNICODE);
