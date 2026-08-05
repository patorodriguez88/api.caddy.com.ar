<?php
// Recibe un array de {NdeCliente, access_token, refresh_token, user_id} y
// actualiza dinter6_triangular.Clientes (solo clientes ya existentes).
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
    echo json_encode(['ok' => 0, 'error' => 'JSON invalido, se espera un array']);
    exit;
}

$actualizados = [];
$sinMatch = [];

foreach ($filas as $f) {
    $ndeCliente = (int)($f['NdeCliente'] ?? 0);
    if ($ndeCliente <= 0) continue;

    $accessToken = $mysqli->real_escape_string($f['access_token'] ?? '');
    $refreshToken = $mysqli->real_escape_string($f['refresh_token'] ?? '');
    $userId = (int)($f['user_id'] ?? 0);

    $mysqli->query("UPDATE Clientes
                     SET access_token='$accessToken', refresh_token='$refreshToken', user_id='$userId'
                     WHERE NdeCliente='" . $mysqli->real_escape_string($ndeCliente) . "'
                     LIMIT 1");

    if ($mysqli->affected_rows > 0) {
        $actualizados[] = ['NdeCliente' => $ndeCliente, 'nombre' => $f['nombrecliente'] ?? ''];
    } else {
        // affected_rows=0 puede ser "ya tenia ese valor" o "no existe el cliente"
        $control = $mysqli->query("SELECT id FROM Clientes WHERE NdeCliente='" . $mysqli->real_escape_string($ndeCliente) . "' LIMIT 1");
        if ($control && $control->fetch_assoc()) {
            $actualizados[] = ['NdeCliente' => $ndeCliente, 'nombre' => $f['nombrecliente'] ?? '', 'nota' => 'ya_tenia_ese_valor'];
        } else {
            $sinMatch[] = ['NdeCliente' => $ndeCliente, 'nombre' => $f['nombrecliente'] ?? ''];
        }
    }
}

echo json_encode([
    'ok' => 1,
    'total_procesados' => count($filas),
    'actualizados' => count($actualizados),
    'sin_match_en_dinter6' => count($sinMatch),
    'detalle_sin_match' => $sinMatch,
], JSON_UNESCAPED_UNICODE);
