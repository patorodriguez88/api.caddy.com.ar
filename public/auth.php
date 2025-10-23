<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require __DIR__ . '/../vendor/autoload.php';

use CaddyApi\Db;
use CaddyApi\Jwt;
use CaddyApi\Helpers;
use CaddyApi\AuthService;

Helpers::cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$in = Helpers::json();
$user = trim($in['usuario'] ?? '');
$pass = (string)($in['password'] ?? '');

if ($user === '' || $pass === '') {
    http_response_code(400);
    echo json_encode(['error' => 'usuario y password son requeridos']);
    exit;
}

$pdo = Db::pdo();
$row = AuthService::findUserByUsuario($pdo, $user);

// Respuesta uniforme
if (!$row || (string)$row['Estado'] !== 'Activo' || empty($row['password_hash']) || !password_verify($pass, $row['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuario o contraseña inválidos']);
    exit;
}

// OK → Access (15m) + Refresh (30d rotativo)
$access  = Jwt::issueAccessToken((int)$row['id']);

$refresh = AuthService::issueRefresh($pdo, (int)$row['id']);

echo json_encode([
    'access_token'  => $access,
    'token_type'    => 'Bearer',
    'expires_in'    => 900,
    'refresh_token' => $refresh['selector'] . '|' . $refresh['token'],
    'refresh_expires_at' => $refresh['expires_at']
]);
