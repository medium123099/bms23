<?php

session_set_cookie_params([
    "lifetime" => 60 * 60 * 24 * 30,
    "path"     => "/",
    "secure"   => true,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

require __DIR__ . "/config/database.php";

header("Content-Type: application/json; charset=UTF-8");


/*
|--------------------------------------------------------------------------
| Проверяем авторизацию
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["user_id"])) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Сначала войдите в личный кабинет"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Проверяем файл
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES["avatar"]) ||
    $_FILES["avatar"]["error"] !== UPLOAD_ERR_OK
) {

    echo json_encode([
        "success" => false,
        "message" => "Выберите фотографию"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


$file = $_FILES["avatar"];


if ($file["size"] > 5 * 1024 * 1024) {

    echo json_encode([
        "success" => false,
        "message" => "Размер фотографии не должен превышать 5 МБ"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file["tmp_name"]);


$allowedTypes = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp"
];


if (!isset($allowedTypes[$mime])) {

    echo json_encode([
        "success" => false,
        "message" => "Разрешены только JPG, PNG и WEBP"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if (!getimagesize($file["tmp_name"])) {

    echo json_encode([
        "success" => false,
        "message" => "Выбранный файл не является изображением"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


$uploadDir = __DIR__ . "/uploads/avatars/";

if (!is_dir($uploadDir)) {

    if (!mkdir($uploadDir, 0755, true)) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Не удалось создать папку для аватаров"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}


$userId    = (int)$_SESSION["user_id"];
$extension = $allowedTypes[$mime];

$fileName = "user_" . $userId . "_" . bin2hex(random_bytes(10)) . "." . $extension;

$targetPath = $uploadDir . $fileName;


if (!move_uploaded_file($file["tmp_name"], $targetPath)) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Не удалось сохранить фотографию"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


$avatarPath = "/uploads/avatars/" . $fileName;


$stmt = $pdo->prepare(
    "SELECT avatar FROM users WHERE id = ? LIMIT 1"
);

$stmt->execute([$userId]);

$oldAvatar = $stmt->fetchColumn();


$update = $pdo->prepare(
    "UPDATE users SET avatar = ? WHERE id = ?"
);

$update->execute([$avatarPath, $userId]);


$_SESSION["user_avatar"] = $avatarPath;


/*
|--------------------------------------------------------------------------
| Удаляем старую аватарку (только внутри uploads/avatars)
|--------------------------------------------------------------------------
*/

if ($oldAvatar && strpos($oldAvatar, "/uploads/avatars/") === 0) {

    $oldFilePath = __DIR__ . $oldAvatar;

    if (is_file($oldFilePath) && realpath($oldFilePath) !== realpath($targetPath)) {
        @unlink($oldFilePath);
    }
}


echo json_encode([
    "success"    => true,
    "message"    => "Аватар успешно изменён",
    "avatar"     => $avatarPath,
    "avatar_url" => $avatarPath . "?v=" . time()
], JSON_UNESCAPED_UNICODE);

exit;