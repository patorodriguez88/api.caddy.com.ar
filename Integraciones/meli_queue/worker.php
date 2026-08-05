<?php
// Worker de la cola de webhooks de Meli, para invocacion por CLI/cron.
require_once __DIR__ . '/MeliQueueWorker.class.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Solo se ejecuta por CLI/cron.');
}

$handler = new auth();
$worker = new MeliQueueWorker();
$resumen = $worker->procesarLote($handler);

echo json_encode($resumen, JSON_PRETTY_PRINT) . PHP_EOL;
