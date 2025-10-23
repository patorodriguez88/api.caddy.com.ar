<?php
require __DIR__ . '/../vendor/autoload.php';

use CaddyApi\Db;
use CaddyApi\Helpers;
use CaddyApi\AuthService;

Helpers::cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$in = Helpers::json();
$rt = trim($in['refresh_token'] ?? '');
if ($rt === '' || !str_contains($rt, '|')) {
    http_response_code(400);
    echo json_encode(['error' => 'refresh_token inválido']);
    exit;
}

[$selector] = explode('|', $rt, 2);
AuthService::logout(Db::pdo(), $selector);
echo json_encode(['ok' => true]);
