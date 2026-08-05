<?php
header('Content-Type: application/json');
$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
$mysqli->set_charset('utf8');

$res = $mysqli->query("SELECT * FROM Productos WHERE Codigo='183'");
$fila = $res ? $res->fetch_assoc() : null;
echo json_encode(['ok' => 1, 'fila' => $fila ?: 'NO_EXISTE'], JSON_UNESCAPED_UNICODE);
