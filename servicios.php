
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/conexion/conexion.php';
require_once __DIR__ . '/clases/respuestas.class.php';
require_once __DIR__ . '/clases/servicios.class.php';
require_once __DIR__ . '/clases/token.class.php';

$_respuestas = new respuestas();
$_servicios  = new servicios();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {

    header('Content-Type: application/json; charset=utf-8');

    // 1) Obtener token (Authorization / X-Api-Token / ?token=)
    $token = Token::obtenerToken();

    if (!$token && isset($_GET['token'])) {
        $token = trim($_GET['token']);
    }

    if (!$token) {
        $datosArray = $_respuestas->error_401('Debe enviar token (Bearer o query "token")');
        http_response_code(401);
        echo json_encode($datosArray);
        exit;
    }

    // 2) Validar token
    $tokenData = Token::validar($token, $_servicios);

    if (!$tokenData) {
        $datosArray = $_respuestas->error_401('El token enviado es inválido o ha caducado');
        http_response_code(401);
        echo json_encode($datosArray);
        exit;
    }

    // 3) Obtener idClienteOrigen (cliente dueño) a partir del UsuarioId
    $usuarioId = (int)($tokenData['UsuarioId'] ?? 0);

    if ($usuarioId <= 0) {
        $datosArray = $_respuestas->error_401('Token sin usuario asociado');
        http_response_code(401);
        echo json_encode($datosArray);
        exit;
    }

    // Este método ya lo tenés en servicios.class.php
    $clienteOrigen = $_servicios->clienteOrigen($usuarioId);

    if (!$clienteOrigen || empty($clienteOrigen[0]['id'])) {
        $datosArray = $_respuestas->error_401(
            'El usuario no tiene un cliente asignado (NdeCliente). No es posible consultar envíos.'
        );
        http_response_code(401);
        echo json_encode($datosArray);
        exit;
    }

    $idClienteOrigen = (int)$clienteOrigen[0]['id'];

    // 4) Router de parámetros: page / id / idProveedor
    if (isset($_GET['page'])) {

        $pagina = (int)$_GET['page'];
        $estado = isset($_GET['estado']) ? $_GET['estado'] : '0'; // Ej: 0 = no entregado, 1 = entregado

        $datosArray = $_servicios->listaServicios($pagina, $idClienteOrigen, $estado);
    } elseif (isset($_GET['codigo'])) {

        $codigoSeguimiento = $_GET['codigo'];
        $datosArray        = $_servicios->obtenerSeguimiento($codigoSeguimiento, $idClienteOrigen);
    } elseif (isset($_GET['idProveedor'])) {

        $idProveedor = $_GET['idProveedor'];
        $datosArray  = $_servicios->obtenerSeguimientoProveedor($idProveedor, $idClienteOrigen);
    } else {

        $datosArray = $_respuestas->error_400('Faltan parámetros para GET /servicios');
    }

    // 5) Código HTTP según error_id si existe
    $code = 200;
    if (isset($datosArray['result']['error_id'])) {
        $code = (int)$datosArray['result']['error_id'];
    }

    http_response_code($code);
    echo json_encode($datosArray, JSON_UNESCAPED_UNICODE);
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
