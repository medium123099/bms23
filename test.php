<?php

require_once __DIR__ . '/config/database.php';

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM users");
$result = $stmt->fetch();

echo "Подключение к базе работает.<br>";
echo "Пользователей в базе: " . $result['total'];