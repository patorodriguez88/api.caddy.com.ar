<?php
header('Content-Type: application/json');
$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
$mysqli->set_charset('utf8');

$res = $mysqli->query("SELECT * FROM flex_handshakes ORDER BY id DESC LIMIT 5");
$filas = [];
while ($f = $res->fetch_assoc()) { $filas[] = $f; }
echo json_encode(['ok' => 1, 'filas' => $filas], JSON_UNESCAPED_UNICODE);
