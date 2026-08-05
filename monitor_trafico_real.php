<?php
// Diagnostico temporal: muestra los ultimos flex_handshakes y el estado de
// la cola MeliWebhookQueue, para confirmar que el trafico real de Meli
// esta llegando al endpoint nuevo. Borrar despues de usar.
header('Content-Type: application/json');
$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
$mysqli->set_charset('utf8');

$desde = $_GET['desde'] ?? date('Y-m-d H:i:s', strtotime('-10 minutes'));

$fh = $mysqli->query("SELECT id, topic, user_id, shipping_id, logistic_type, TIME_STAMP
                       FROM flex_handshakes
                       WHERE TIME_STAMP >= '" . $mysqli->real_escape_string($desde) . "'
                       ORDER BY id DESC");
$flexHandshakes = [];
while ($f = $fh->fetch_assoc()) { $flexHandshakes[] = $f; }

$q = $mysqli->query("SELECT id, shipments_id, status, procesado, created_at
                      FROM MeliWebhookQueue
                      WHERE created_at >= '" . $mysqli->real_escape_string($desde) . "'
                      ORDER BY id DESC");
$cola = [];
while ($c = $q->fetch_assoc()) { $cola[] = $c; }

echo json_encode([
    'ok' => 1,
    'desde' => $desde,
    'total_flex_handshakes_nuevos' => count($flexHandshakes),
    'flex_handshakes' => $flexHandshakes,
    'total_cola_nuevos' => count($cola),
    'cola' => $cola,
], JSON_UNESCAPED_UNICODE);
