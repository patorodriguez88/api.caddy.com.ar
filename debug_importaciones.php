<?php
// Diagnóstico temporal: estado real de Importaciones en dinter6_triangular
// para shipping_ids puntuales. Borrar después de usarlo.

header('Content-Type: application/json');

$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8');

$shippingIds = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];

$out = [];
foreach ($shippingIds as $id) {
    $id = trim($id);
    if ($id === '') continue;

    $res = $mysqli->query("SELECT id, Eliminado, Cargado, Meli, Status, Substatus, shipments_id, RazonSocial
                            FROM Importaciones
                            WHERE shipments_id='" . $mysqli->real_escape_string($id) . "'
                            LIMIT 1");
    $fila = $res ? $res->fetch_assoc() : null;
    $out[$id] = $fila ?: 'NO_EXISTE_EN_DINTER6';
}

echo json_encode(['ok' => 1, 'detalle' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
