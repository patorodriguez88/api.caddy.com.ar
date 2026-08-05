<?php
// Recibe un array de filas de Importaciones (n455735_caddy) y para cada una:
// - si ya existe una fila con ese shipments_id en dinter6_triangular, devuelve su id
// - si no existe, la inserta (escapada correctamente) y devuelve el id nuevo
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
$filas = json_decode($postBody, true);

if (!is_array($filas)) {
    echo json_encode(['ok' => 0, 'error' => 'JSON invalido, se espera un array de filas']);
    exit;
}

$columnas = ['Fecha', 'RazonSocial', 'NCliente', 'TipoDeComprobante', 'NumeroComprobante', 'Cantidad', 'Precio', 'Total', 'ClienteDestino', 'idClienteDestino', 'DocumentoDestino', 'DomicilioDestino', 'LocalidadDestino', 'CodigoSeguimiento', 'NumeroVenta', 'DomicilioOrigen', 'LocalidadOrigen', 'Usuario', 'Cargado', 'FormaDePago', 'EntregaEn', 'Eliminado', 'Observaciones', 'Transportista', 'Recorrido', 'ProvinciaDestino', 'ProvinciaOrigen', 'Kilometros', 'TimeStamp', 'Hora', 'idProveedor', 'FechaEntrega', 'Cobranza', 'Retirado', 'ValorDeclarado', 'Telefono', 'Celular', 'Length', 'Width', 'Height', 'Weight', 'cpdestino', 'dni_destino', 'mail_destino', 'Flex', 'Meli', 'Status', 'order_id', 'logistic_type', 'shipments_id', 'date_created', 'estimated_delivery_time', 'tracking_method', 'agency_description', 'description'];

$resultados = [];

foreach ($filas as $item) {
    $n455735Id = $item['id'] ?? null;
    $shippingId = $item['shipments_id'] ?? null;

    if (!$shippingId) {
        $resultados[] = ['n455735_id' => $n455735Id, 'ok' => 0, 'error' => 'sin_shipments_id'];
        continue;
    }

    $control = $mysqli->query("SELECT id FROM Importaciones WHERE shipments_id='" . $mysqli->real_escape_string($shippingId) . "' LIMIT 1");
    $existente = $control ? $control->fetch_assoc() : null;

    if ($existente) {
        $resultados[] = [
            'n455735_id' => $n455735Id,
            'shipments_id' => $shippingId,
            'ok' => 1,
            'accion' => 'ya_existia',
            'dinter_id' => (int)$existente['id'],
        ];
        continue;
    }

    $valores = [];
    foreach ($columnas as $col) {
        $val = $item[$col] ?? null;
        $valores[] = $val === null ? 'NULL' : "'" . $mysqli->real_escape_string((string)$val) . "'";
    }

    $sql = "INSERT INTO Importaciones (`" . implode('`,`', $columnas) . "`) VALUES (" . implode(',', $valores) . ")";

    if (!$mysqli->query($sql)) {
        $resultados[] = ['n455735_id' => $n455735Id, 'shipments_id' => $shippingId, 'ok' => 0, 'error' => $mysqli->error];
        continue;
    }

    $resultados[] = [
        'n455735_id' => $n455735Id,
        'shipments_id' => $shippingId,
        'ok' => 1,
        'accion' => 'insertado',
        'dinter_id' => $mysqli->insert_id,
    ];
}

echo json_encode(['ok' => 1, 'resultados' => $resultados], JSON_UNESCAPED_UNICODE);
