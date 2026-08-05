<?php
header('Content-Type: application/json');
$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
$mysqli->set_charset('utf8');

$totales = $mysqli->query("SELECT procesado, COUNT(*) as cant FROM MeliWebhookQueue GROUP BY procesado")->fetch_all(MYSQLI_ASSOC);

$masReciente = $mysqli->query("SELECT id, shipments_id, procesado, resultado, created_at, processed_at FROM MeliWebhookQueue WHERE procesado=1 ORDER BY id DESC LIMIT 3")->fetch_all(MYSQLI_ASSOC);

$masViejoPendiente = $mysqli->query("SELECT id, shipments_id, created_at FROM MeliWebhookQueue WHERE procesado=0 ORDER BY id ASC LIMIT 3")->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'ok' => 1,
    'totales_por_estado' => $totales,
    'ultimos_procesados' => $masReciente,
    'pendientes_mas_viejos' => $masViejoPendiente,
], JSON_UNESCAPED_UNICODE);
