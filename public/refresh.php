<?php
require __DIR__ . '/../vendor/autoload.php';

use CaddyApi\Db;
use CaddyApi\Jwt;
use CaddyApi\Helpers;
use CaddyApi\AuthService;
use Firebase\JWT\ExpiredException;

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

[$selector, $token] = explode('|', $rt, 2);
$pdo = Db::pdo();

$new = AuthService::rotateRefresh($pdo, $selector, $token);
if (!$new) {
    http_response_code(401);
    echo json_encode(['error' => 'refresh inválido/expirado']);
    exit;
}

// emitimos nuevo access
// Nota: si querés scopes distintos, podés guardarlos por user y cargarlos aquí
$access = Jwt::issueAccessToken((int)($pdo->query("SELECT user_id FROM auth_refresh_tokens WHERE selector=" . $pdo->quote($new['selector']))->fetchColumn()));

echo json_encode([
    'access_token'  => $access,
    'token_type'    => 'Bearer',
    'expires_in'    => 900,
    'refresh_token' => $new['selector'] . '|' . $new['token'],
    'refresh_expires_at' => $new['expires_at']
]);
