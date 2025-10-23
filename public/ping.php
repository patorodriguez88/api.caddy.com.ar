<?php
header('Content-Type: application/json');
echo json_encode([
    "ok" => true,
    "file" => __FILE__,
    "cwd"  => getcwd(),
    "server" => $_SERVER['SERVER_NAME'] ?? null
]);
