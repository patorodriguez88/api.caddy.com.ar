<?php
// Endpoint completo para notificaciones de Meli, autosuficiente en dinter6_triangular.
// Reemplaza el flujo que hoy pasa por www.sistemacaddy.com.ar/Api/notificaciones_ml.php.

require_once __DIR__ . '/clases/webhook_ml_receiver.class.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => 0, 'error' => 'Method not allowed']);
    exit;
}

$postBody = file_get_contents('php://input');

$receiver = new WebhookMlReceiver();
$resultado = $receiver->login($postBody);

http_response_code(200);
echo json_encode($resultado);
