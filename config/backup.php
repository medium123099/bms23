<?php

/*
| Резервная копия базы без mysqldump: обычный SQL-файл (структура + данные), сжатый gzip.
| Копии лежат в config/backups/ (папка config закрыта .htaccess от прямого доступа).
| В копию входят ВСЕ таблицы базы, в том числе пользователи сайта, поэтому не выкладывайте файлы никому.
*/

function tl_backup_dir(): string
{
    $d = __DIR__ . "/backups";
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (is_dir($d) && !is_file($d . "/.htaccess")) @file_put_contents($d . "/.htaccess", "Require all denied\n");
    return $d;
}

/* Пишет SQL в открытый gz-ресурс. Возвращает число таблиц. */
function tl_backup_write(PDO $pdo, $gz): int
{
    gzwrite($gz, "-- bms23 backup " . date("Y-m-d H:i:s") . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $t = (string)$t;
        $q = "`" . str_replace("`", "``", $t) . "`";
        $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM);
        gzwrite($gz, "DROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n\n");

        $st = $pdo->query("SELECT * FROM $q");
        $batch = [];
        $cols = null;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) $cols = "(`" . implode("`,`", array_map(function ($c) { return str_replace("`", "``", $c); }, array_keys($row))) . "`)";
            $vals = [];
            foreach ($row as $v) $vals[] = $v === null ? "NULL" : $pdo->quote((string)$v);
            $batch[] = "(" . implode(",", $vals) . ")";
            if (count($batch) >= 200) {
                gzwrite($gz, "INSERT INTO $q $cols VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) gzwrite($gz, "INSERT INTO $q $cols VALUES\n" . implode(",\n", $batch) . ";\n");
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    return count($tables);
}

/* Делает копию в config/backups/, оставляет $keep последних. Возвращает [путь, размер] или бросает исключение. */
function tl_backup_make(PDO $pdo, int $keep = 14): array
{
    @set_time_limit(300);
    $dir  = tl_backup_dir();
    $file = $dir . "/bms23-" . date("Y-m-d_H-i") . ".sql.gz";
    $gz = gzopen($file, "wb6");
    if (!$gz) throw new RuntimeException("Не удалось создать файл копии (нет прав на config/backups)");
    tl_backup_write($pdo, $gz);
    gzclose($gz);

    $all = glob($dir . "/bms23-*.sql.gz") ?: [];
    sort($all);
    while (count($all) > $keep) @unlink(array_shift($all));
    return [$file, (int)filesize($file)];
}

function tl_backup_list(): array
{
    $out = [];
    foreach (glob(tl_backup_dir() . "/bms23-*.sql.gz") ?: [] as $f) $out[] = ["name" => basename($f), "size" => (int)filesize($f), "time" => (int)filemtime($f)];
    usort($out, function ($a, $b) { return $b["time"] <=> $a["time"]; });
    return $out;
}

/* Отдаёт копию на скачивание в браузер (создаёт свежую, если $name пустой). */
function tl_backup_download(PDO $pdo, string $name = ""): void
{
    if ($name === "") {
        [$path] = tl_backup_make($pdo);
    } else {
        if (!preg_match('/^bms23-[0-9_\-]+\.sql\.gz$/', $name)) { http_response_code(400); exit; }
        $path = tl_backup_dir() . "/" . $name;
        if (!is_file($path)) { http_response_code(404); exit; }
    }
    header_remove("Content-Type");
    header("Content-Type: application/gzip");
    header("Content-Disposition: attachment; filename=\"" . basename($path) . "\"");
    header("Content-Length: " . filesize($path));
    readfile($path);
    exit;
}
