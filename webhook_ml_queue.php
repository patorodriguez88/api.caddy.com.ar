<?php
// Endpoint de recepción "fast-ack" para los webhooks de Meli reenviados por
// notificaciones_ml.v2 (Api-sistemacaddy.com.ar-). No procesa nada: solo
// encola el payload y responde. El procesamiento pesado (el que hoy hace
// webhook_ml.class.php) lo hace Integraciones/meli_queue/worker.php de forma
// asincrónica, por cron.

require_once __DIR__ . '/Integraciones/meli_queue/MeliQueueReceiver.class.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => 0, 'error' => 'Method not allowed']);
    exit;
}

$postBody = file_get_contents('php://input');

$receiver = new MeliQueueReceiver();
$resultado = $receiver->encolar($postBody);

http_response_code($resultado['ok'] === 1 ? 200 : 400);
echo json_encode($resultado);
