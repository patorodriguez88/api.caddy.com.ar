<?php
header('Content-Type: application/json');
$path = __DIR__ . '/Integraciones/meli_queue/worker.log';

if (!file_exists($path)) {
    echo json_encode(['ok' => 0, 'error' => 'worker.log no existe', 'path' => $path]);
    exit;
}

$contenido = file_get_contents($path);
$tamano = filesize($path);
$modificado = date('Y-m-d H:i:s', filemtime($path));

echo json_encode([
    'ok' => 1,
    'tamano_bytes' => $tamano,
    'ultima_modificacion' => $modificado,
    'ultimas_lineas' => array_slice(explode(PHP_EOL, trim($contenido)), -20),
], JSON_UNESCAPED_UNICODE);
