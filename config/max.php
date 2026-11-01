<?php
/*
| Скопируйте этот файл в config/max.php и заполните.
| Папка config закрыта .htaccess, снаружи файл не откроется.
| НЕ выкладывайте токен в чаты и репозитории.
*/
return [
    // токен бота: MAX → Чат-боты → Интеграция → Получить токен
    "token"          => "f9LHodD0cOK6zu_DPQ43oMRBdrrvPCo39oQAASrtb8rh9B2jqXpoaKSp3_Hp5iasd59OSRYcPC_NwSGWuqYd",

    // адрес API; если запросы не проходят, попробуйте https://platform-api2.max.ru
    "base_url"       => "https://platform-api.max.ru",

    // секрет вебхука: 5–256 символов, только a-z A-Z 0-9 _ -  (придумайте длинный случайный)
    "webhook_secret" => "1234099polo",

    // ключ для страницы max_setup.php (длинный случайный)
    "setup_key"      => "1234099polo",

    // ключ для команды /admin КЛЮЧ в боте: кто его знает, тот станет администратором
    "admin_key"      => "1234099polo",

    "site_url"       => "https://bms23.ru",
    "cron_key"       => "1234099polo",
    // если MAX отдаёт ошибку сертификата: путь к .pem корневого сертификата Минцифры
    "ca_file"        => "",
];
