<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1) PRIMERO: conexión
require_once __DIR__ . '/conexion/conexion.php';

// 2) LUEGO: clases de lógica
require_once __DIR__ . '/clases/respuestas.class.php';
require_once __DIR__ . '/clases/servicios.class.php';

$_respuestas = new respuestas;
$_servicios  = new servicios;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {

    // SIEMPRE indicamos que la respuesta es JSON
    header('Content-Type: application/json; charset=utf-8');

    if (isset($_GET['page'])) {

        $pagina = $_GET['page'];
        $token  = $_GET['token']  ?? null;
        $estado = $_GET['estado'] ?? null;

        $datosArray = $_servicios->listaServicios($pagina, $token, $estado);
    } elseif (isset($_GET['id'])) {

        $codigoseguimiento = $_GET['id'];
        $token             = $_GET['token'] ?? null;

        $datosArray = $_servicios->obtenerSeguimiento($codigoseguimiento, $token);
    } elseif (isset($_GET['idProveedor'])) {

        $codigoseguimiento = $_GET['idProveedor'];
        $token             = $_GET['token'] ?? null;

        $datosArray = $_servicios->obtenerSeguimientoProveedor($codigoseguimiento, $token);
    } else {
        // Sin parámetros válidos
        $datosArray = $_respuestas->error_400();
    }

    // Definimos el código HTTP *antes* del echo
    $code = 200;
    if (isset($datosArray['result']['error_id'])) {
        $code = (int)$datosArray['result']['error_id'];
    }
    http_response_code($code);

    echo json_encode($datosArray);
    exit;
} elseif ($method === 'POST') {

    header('Content-Type: application/json; charset=utf-8');

    $postBody   = file_get_contents('php://input');
    $datosArray = $_servicios->post($postBody);

    $code = 200;
    if (isset($datosArray['result']['error_id'])) {
        $code = (int)$datosArray['result']['error_id'];
    }
    http_response_code($code);

    echo json_encode($datosArray);
    exit;
} elseif ($method === 'PUT') {

    header('Content-Type: application/json; charset=utf-8');

    $postBody   = file_get_contents('php://input');
    $datosArray = $_servicios->put($postBody);

    $code = 200;
    if (isset($datosArray['result']['error_id'])) {
        $code = (int)$datosArray['result']['error_id'];
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
