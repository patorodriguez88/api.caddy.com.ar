<?php
// Diagnóstico temporal: para un shipments_id dado, busca TODAS las filas en
// dinter6_triangular.Importaciones que le correspondan (por shipments_id),
// para comparar contra el idCaddy que dice n455735_caddy. Borrar después.

header('Content-Type: application/json');

$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8');

$shippingId = $_GET['shipping_id'] ?? '';
$idCaddyReportado = $_GET['id_caddy'] ?? '';

$out = [];

// 1) Qué hay realmente en dinter6 para ese shipments_id (puede haber duplicados)
$res = $mysqli->query("SELECT id, RazonSocial, shipments_id, Cargado, Status
                        FROM Importaciones
                        WHERE shipments_id='" . $mysqli->real_escape_string($shippingId) . "'");
$filasReales = [];
while ($f = $res->fetch_assoc()) { $filasReales[] = $f; }
$out['filas_reales_para_este_shipping_id'] = $filasReales;

// 2) Qué hay en el id que n455735 dice que le corresponde
if ($idCaddyReportado !== '') {
    $res2 = $mysqli->query("SELECT id, RazonSocial, shipments_id, Cargado, Status
                             FROM Importaciones
                             WHERE id='" . $mysqli->real_escape_string($idCaddyReportado) . "'
                             LIMIT 1");
    $out['fila_en_el_idCaddy_reportado'] = $res2 ? ($res2->fetch_assoc() ?: 'NO_EXISTE') : 'ERROR';
}

echo json_encode(['ok' => 1, 'detalle' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
