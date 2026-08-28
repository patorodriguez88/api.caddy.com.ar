<?php
// Disparador del worker de la cola de MercadoLibre.
// Se puede correr de dos formas:
//   - CLI (cron de cPanel):  php cron_worker.php     -> sin secret
//   - HTTP (cron externo):   ?secret=...             -> con secret
//
// Fix 2026-08-28: agregado el modo CLI para poder correrlo como cron de cPanel
// (PHP CLI no consume Entry Processes ni pasa por el WAF). El comportamiento por
// HTTP queda idéntico a antes.

require_once __DIR__ . '/Integraciones/meli_queue/MeliQueueWorker.class.php';

const CRON_WORKER_SECRET = 'k7Rw2xVq9pLm4Zts8Jyn3Bhc6Fda1Uop';

$esCli = (php_sapi_name() === 'cli');

if (!$esCli) {
    $secreto = $_POST['secret'] ?? $_GET['secret'] ?? '';
    if ($secreto !== CRON_WORKER_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => 0, 'error' => 'No autorizado']);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$handler = new auth();
$worker  = new MeliQueueWorker();
$resumen = $worker->procesarLote($handler);

echo json_encode(['ok' => 1, 'via' => $esCli ? 'cli' : 'http'] + $resumen, JSON_UNESCAPED_UNICODE) . PHP_EOL;
