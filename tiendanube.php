<?php
// Callback de cotización de Tienda Nube: POST /api/tiendanube
// Es la URL que se registra como callback_url del carrier "Caddy. Yo lo llevo!"
// en cada tienda. Sin token: la tienda se identifica por store_id.
// Ante cualquier problema se devuelve {"rates": []} con 200 (TN simplemente no muestra a Caddy).

require_once 'clases/tiendanube_rates.class.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['rates' => [], 'error' => 'Method not allowed']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);

try {
    $out = (new TiendanubeRates())->cotizar(is_array($in) ? $in : []);
} catch (Throwable $e) {
    error_log('tiendanube rates: ' . $e->getMessage());
    $out = ['rates' => []];
}

http_response_code(200);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
