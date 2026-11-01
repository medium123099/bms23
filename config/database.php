<?php

$host = "localhost";
$dbname = "host1590477_shop";
$username = "host1590477";
$password = "Safonov73";


try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );


    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );


    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );


} catch(PDOException $e){

    die("Ошибка подключения к базе данных");

}