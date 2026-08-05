<?php
// Diagnóstico temporal: confirma si existe TransClientes para shipments_id
// puntuales en dinter6_triangular. Borrar después de usarlo.

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

    $tc = $mysqli->query("SELECT id, CodigoSeguimiento, Eliminado, Entregado, Retirado, status, shipments_id
                           FROM TransClientes
                           WHERE shipments_id='" . $mysqli->real_escape_string($id) . "'
                           LIMIT 1");
    $filaTC = $tc ? $tc->fetch_assoc() : null;

    $pv = $mysqli->query("SELECT id, shipments_id, Status
                           FROM PreVenta
                           WHERE shipments_id='" . $mysqli->real_escape_string($id) . "'
                           LIMIT 1");
    $filaPV = $pv ? $pv->fetch_assoc() : null;

    $out[$id] = [
        'PreVenta' => $filaPV ?: 'NO_EXISTE',
        'TransClientes' => $filaTC ?: 'NO_EXISTE',
    ];
}

echo json_encode(['ok' => 1, 'detalle' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
