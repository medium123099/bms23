<?php

/* Динамический sitemap: статические страницы + страницы показанных мастеров. */

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/masters.php";

header("Content-Type: application/xml; charset=UTF-8");
header("Cache-Control: public, max-age=3600");

$base  = "https://bms23.ru";
$pages = ["/", "/catalog", "/treelife-lite", "/treelife-beauty", "/treelife-vacuum", "/courses", "/about", "/masters", "/contacts", "/bms", "/delivery", "/service", "/dev"];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $p) {
    echo "  <url><loc>" . $base . $p . "</loc></url>\n";
}

try {
    tl_ensure_masters($pdo);
    foreach ($pdo->query("SELECT id FROM tl_masters WHERE status = 'shown' AND consent = 1 ORDER BY id")->fetchAll() as $r) {
        echo "  <url><loc>" . $base . "/master?id=" . (int)$r["id"] . "</loc></url>\n";
    }
} catch (Throwable $e) {
    error_log("sitemap.php: " . $e->getMessage());
}

echo "</urlset>\n";
