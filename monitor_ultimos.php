<?php
header('Content-Type: application/json');
$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
$mysqli->set_charset('utf8');

$fh = $mysqli->query("SELECT id, topic, user_id, shipping_id, logistic_type, TIME_STAMP FROM flex_handshakes ORDER BY id DESC LIMIT 10");
$flex = [];
while ($f = $fh->fetch_assoc()) { $flex[] = $f; }

$imp = $mysqli->query("SELECT id, RazonSocial, shipments_id, Status, TimeStamp FROM Importaciones ORDER BY id DESC LIMIT 10");
$importaciones = [];
while ($i = $imp->fetch_assoc()) { $importaciones[] = $i; }

$q = $mysqli->query("SELECT id, shipments_id, status, procesado, created_at FROM MeliWebhookQueue ORDER BY id DESC LIMIT 10");
$cola = [];
while ($c = $q->fetch_assoc()) { $cola[] = $c; }

echo json_encode(['ok' => 1, 'hora_servidor_php' => date('Y-m-d H:i:s'), 'flex_handshakes' => $flex, 'importaciones' => $importaciones, 'cola' => $cola], JSON_UNESCAPED_UNICODE);
