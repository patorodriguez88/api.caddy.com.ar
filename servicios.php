<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1) PRIMERO: conexión
require_once __DIR__ . '/conexion/conexion.php';

// 2) LUEGO: clases de lógica
require_once __DIR__ . '/clases/Token.php';
require_once __DIR__ . '/clases/respuestas.class.php';
require_once __DIR__ . '/clases/servicios.class.php';

$_respuestas = new respuestas;
$_servicios  = new servicios;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {

    // SIEMPRE indicamos que la respuesta es JSON
    header('Content-Type: application/json; charset=utf-8');

    // 1) Obtener token (Bearer / X-Api-Token / ?token=)
    $token = Token::obtenerToken();

    if (!$token) {
        $datosArray = $_respuestas->error_400("Debe enviar token (Bearer o query ?token=)");
        http_response_code($datosArray['result']['error_id'] ?? 400);
        echo json_encode($datosArray);
        exit;
    }

    // 2) Validar token contra la BD usando la misma conexión que servicios
    $tokenData = Token::validar($token, $_servicios);

    if (!$tokenData) {
        $datosArray = $_respuestas->error_401("Token inválido o vencido");
        http_response_code($datosArray['result']['error_id'] ?? 401);
        echo json_encode($datosArray);
        exit;
    }

    // 3) Tomar NdeCliente como id de cliente origen
    $idOrigen = $tokenData['NdeCliente'] ?? null;

    if (!$idOrigen) {
        $datosArray = $_respuestas->error_401("Token sin cliente asociado");
        http_response_code($datosArray['result']['error_id'] ?? 401);
        echo json_encode($datosArray);
        exit;
    }

    // 4) Routing según parámetros
    if (isset($_GET['page'])) {

        $pagina = (int) $_GET['page'];
        $estado = $_GET['estado'] ?? null;

        // La lógica de validación de permisos está dentro de servicios.class.php
        $datosArray = $_servicios->listaServicios($pagina, $idOrigen, $estado);
    } elseif (isset($_GET['id'])) {

        // Consulta por Código de Seguimiento (id)
        $codigoseguimiento = $_GET['id'];

        $datosArray = $_servicios->obtenerSeguimiento($codigoseguimiento, $idOrigen);
    } elseif (isset($_GET['idProveedor'])) {

        // Consulta por Código de Proveedor
        $codigoseguimiento = $_GET['idProveedor'];

        $datosArray = $_servicios->obtenerSeguimientoProveedor($codigoseguimiento, $idOrigen);
    } else {
        // Sin parámetros válidos
        $datosArray = $_respuestas->error_400("Faltan parámetros para GET /servicios");
    }

    // Definimos el código HTTP *antes* del echo
    $code = 200;
    if (isset($datosArray['result']['error_id'])) {
        $code = (int) $datosArray['result']['error_id'];
    }
    http_response_code($code);

    echo json_encode($datosArray);
    exit;
} elseif ($method === 'POST') {

    // Por ahora mantenemos el comportamiento original:
    // el token se espera dentro del cuerpo JSON y se valida
    // desde servicios.class.php (método post).

    header('Content-Type: application/json; charset=utf-8');

    $postBody   = file_get_contents('php://input');
    $datosArray = $_servicios->post($postBody);

    $code = 200;
    if (isset($datosArray['result']['error_id'])) {
        $code = (int) $datosArray['result']['error_id'];
    }
    http_response_code($code);

    echo json_encode($datosArray);
    exit;
} elseif ($method === 'PUT') {

    // Igual que POST: la validación de token sigue en servicios.class.php
    header('Content-Type: application/json; charset=utf-8');

    $postBody   = file_get_contents('php://input');
    $datosArray = $_servicios->put($postBody);

    $code = 200;
    if (isset($datosArray['result']['error_id'])) {
        $code = (int) $datosArray['result']['error_id'];
    }
    http_response_code($code);

    echo json_encode($datosArray);
    exit;
} else {

    header('Content-Type: application/json; charset=utf-8');

    $datosArray = $_respuestas->error_405();
    http_response_code(405);

    echo json_encode($datosArray);
    exit;
}
