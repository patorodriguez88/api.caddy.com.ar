<?php

namespace CaddyApi;

use PDO;

class Db
{
    public static function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo) return $pdo;

        $host = 'localhost';
        $db = 'tu_db';
        $user = 'tu_user';
        $pass = 'tu_pass';
        $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }
}
