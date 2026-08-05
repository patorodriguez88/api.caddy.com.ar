<?php
// Inserta una fila de Importaciones (copiada de n455735_caddy) directo en
// dinter6_triangular, para reparar huerfanos por el bug de idCaddy cruzado.
// Borrar despues de usar.

header('Content-Type: application/json');

$config = json_decode(file_get_contents(__DIR__ . '/conexion/config'), true)['conexion'];
$mysqli = new mysqli($config['server'], $config['user'], $config['password'], $config['database'], (int)$config['port']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8');

$postBody = file_get_contents('php://input');
$item = json_decode($postBody, true);

if (!$item) {
    echo json_encode(['ok' => 0, 'error' => 'JSON invalido']);
    exit;
}

// Chequeo de seguridad: no insertar si ya existe ese shipments_id en destino
$control = $mysqli->query("SELECT id FROM Importaciones WHERE shipments_id='" . $mysqli->real_escape_string($item['shipments_id']) . "' LIMIT 1");
if ($control && $control->fetch_assoc()) {
    echo json_encode(['ok' => 0, 'error' => 'ya_existe_en_destino']);
    exit;
}

$columnas = ['Fecha', 'RazonSocial', 'NCliente', 'TipoDeComprobante', 'NumeroComprobante', 'Cantidad', 'Precio', 'Total', 'ClienteDestino', 'idClienteDestino', 'DocumentoDestino', 'DomicilioDestino', 'LocalidadDestino', 'CodigoSeguimiento', 'NumeroVenta', 'DomicilioOrigen', 'LocalidadOrigen', 'Usuario', 'Cargado', 'FormaDePago', 'EntregaEn', 'Eliminado', 'Observaciones', 'Transportista', 'Recorrido', 'ProvinciaDestino', 'ProvinciaOrigen', 'Kilometros', 'TimeStamp', 'Hora', 'idProveedor', 'FechaEntrega', 'Cobranza', 'Retirado', 'ValorDeclarado', 'Telefono', 'Celular', 'Length', 'Width', 'Height', 'Weight', 'cpdestino', 'dni_destino', 'mail_destino', 'Flex', 'Meli', 'Status', 'order_id', 'logistic_type', 'shipments_id', 'date_created', 'estimated_delivery_time', 'tracking_method', 'agency_description', 'description'];

$valores = [];
foreach ($columnas as $col) {
    $val = $item[$col] ?? null;
    $valores[] = $val === null ? 'NULL' : "'" . $mysqli->real_escape_string((string)$val) . "'";
}

$sql = "INSERT INTO Importaciones (`" . implode('`,`', $columnas) . "`) VALUES (" . implode(',', $valores) . ")";

if (!$mysqli->query($sql)) {
    echo json_encode(['ok' => 0, 'error' => $mysqli->error]);
    exit;
}

echo json_encode(['ok' => 1, 'nuevo_id' => $mysqli->insert_id]);
