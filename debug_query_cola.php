<?php
header('Content-Type: application/json');
$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
$mysqli->set_charset('utf8');

$sql = "SELECT id, raw_payload, procesado, intentos FROM MeliWebhookQueue WHERE procesado = 0 AND intentos < 5 ORDER BY id ASC LIMIT 20";
$res = $mysqli->query($sql);

echo json_encode([
    'ok' => 1,
    'sql' => $sql,
    'num_rows_reportado' => $res ? $res->num_rows : 'QUERY_FALLO',
    'error_mysqli' => $mysqli->error,
    'filas' => $res ? $res->fetch_all(MYSQLI_ASSOC) : [],
], JSON_UNESCAPED_UNICODE);
