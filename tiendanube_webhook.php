<?php
// Webhook de pedidos de Tienda Nube (order/packed): POST /api/tiendanube_webhook
// Es la URL que se registra en cada tienda al instalar la app (y a la que se re-apuntaron
// las tiendas que antes iban a www.sistemacaddy.com.ar). Ver clases/tiendanube_webhook.class.php.

require_once 'clases/tiendanube_webhook.class.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => 0, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$firma = $_SERVER['HTTP_X_LINKEDSTORE_HMAC_SHA256'] ?? '';

try {
    $r = (new TiendanubeWebhook())->procesar($raw, $firma);
} catch (Throwable $e) {
    error_log('tiendanube_webhook: ' . $e->getMessage());
    $r = ['code' => 500, 'body' => ['ok' => 0, 'error' => 'error_interno']];
}

http_response_code($r['code']);
echo json_encode($r['body'], JSON_UNESCAPED_UNICODE);
