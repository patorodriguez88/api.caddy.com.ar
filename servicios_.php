<?php

require_once 'clases/respuestas.class.php';
require_once 'clases/servicios.class_.php';

$_respuestas = new respuestas;
$_servicios  = new servicios;

$method = $_SERVER['REQUEST_METHOD'];

if ($method == "GET") {

    header("Content-Type: application/json");

    if (isset($_GET["page"])) {

        $pagina = $_GET["page"];
        $token  = $_GET["token"]  ?? null;
        $estado = $_GET["estado"] ?? null;

        $listaServicios = $_servicios->listaServicios($pagina, $token, $estado);
        echo json_encode($listaServicios);
        http_response_code(200);
    } elseif (isset($_GET['id'])) {

        $codigoseguimiento = $_GET['id'];
        $token             = $_GET['token'] ?? null;

        $datosServicios = $_servicios->obtenerSeguimiento($codigoseguimiento, $token);
        echo json_encode($datosServicios);
        http_response_code(200);
    } elseif (isset($_GET['idProveedor'])) {

        $codigoseguimiento = $_GET['idProveedor'];
        $token             = $_GET['token'] ?? null;

        $datosServicios = $_servicios->obtenerSeguimientoProveedor($codigoseguimiento, $token);
        echo json_encode($datosServicios);
        http_response_code(200);
    } else {

        // Sin parámetros válidos
        $datosArray = $_respuestas->error_400(); // o el que uses
        echo json_encode($datosArray);
        http_response_code($datosArray['result']['error_id'] ?? 400);
    }
} elseif ($method == "POST") {

    $postBody   = file_get_contents("php://input");
    $datosArray = $_servicios->post($postBody);

    header('Content-Type: application/json');

    if (isset($datosArray["result"]["error_id"])) {
        http_response_code($datosArray["result"]["error_id"]);
    } else {
        http_response_code(200);
    }

    echo json_encode($datosArray);
} elseif ($method == "PUT") {

    $postBody   = file_get_contents("php://input");
    $datosArray = $_servicios->put($postBody);

    header('Content-Type: application/json');

    if (isset($datosArray["result"]["error_id"])) {
        http_response_code($datosArray["result"]["error_id"]);
    } else {
        http_response_code(200);
    }

    echo json_encode($datosArray);
    // } elseif ($method == "DELETE") {

    //     $headers = getallheaders();

    //     if (isset($headers["token"]) && isset($headers["pacienteId"])) {
    //         $send = [
    //             "token"      => $headers["token"],
    //             "pacienteId" => $headers["pacienteId"]
    //         ];
    //         $postBody = json_encode($send);
    //     } else {
    //         $postBody = file_get_contents("php://input");
    //     }

    //     $datosArray = $_servicios->delete($postBody); // ✅ corregido

    //     header('Content-Type: application/json');

    //     if (isset($datosArray["result"]["error_id"])) {
    //         http_response_code($datosArray["result"]["error_id"]);
    //     } else {
    //         http_response_code(200);
    //     }

    //     echo json_encode($datosArray);

} else {

    header('Content-Type: application/json');
    $datosArray = $_respuestas->error_405();
    echo json_encode($datosArray);
}
