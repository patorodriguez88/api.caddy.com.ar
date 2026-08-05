<?php
// Disparador HTTP del worker de la cola de Meli, para usar desde un cron
// que pega a una URL (via curl/wget) en vez de invocar el CLI de PHP.

require_once __DIR__ . '/Integraciones/meli_queue/MeliQueueWorker.class.php';

const CRON_WORKER_SECRET = 'k7Rw2xVq9pLm4Zts8Jyn3Bhc6Fda1Uop';

$secreto = $_POST['secret'] ?? $_GET['secret'] ?? '';
if ($secreto !== CRON_WORKER_SECRET) {
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$handler = new auth();
$worker = new MeliQueueWorker();
$resumen = $worker->procesarLote($handler);

echo json_encode(['ok' => 1] + $resumen, JSON_UNESCAPED_UNICODE);
