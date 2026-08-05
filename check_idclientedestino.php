<?php
header('Content-Type: application/json');
$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
$mysqli->set_charset('utf8');

// Filas nuevas, creadas por el endpoint nuevo (ids mas altos, recientes)
$nuevas = $mysqli->query("SELECT id, RazonSocial, idClienteDestino, TimeStamp FROM Importaciones WHERE Meli=1 ORDER BY id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

// Filas viejas (mucho antes de hoy), para comparar si siempre fue asi
$viejas = $mysqli->query("SELECT id, RazonSocial, idClienteDestino, TimeStamp FROM Importaciones WHERE Meli=1 AND TimeStamp < '2026-07-01' ORDER BY id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

// Cuantas del total tienen idClienteDestino poblado vs null/vacio
$totalConValor = $mysqli->query("SELECT COUNT(*) as c FROM Importaciones WHERE Meli=1 AND idClienteDestino IS NOT NULL AND idClienteDestino <> 0")->fetch_assoc()['c'];
$totalSinValor = $mysqli->query("SELECT COUNT(*) as c FROM Importaciones WHERE Meli=1 AND (idClienteDestino IS NULL OR idClienteDestino = 0)")->fetch_assoc()['c'];

echo json_encode([
    'ok' => 1,
    'filas_nuevas' => $nuevas,
    'filas_viejas' => $viejas,
    'total_con_idClienteDestino' => (int)$totalConValor,
    'total_sin_idClienteDestino' => (int)$totalSinValor,
], JSON_UNESCAPED_UNICODE);
