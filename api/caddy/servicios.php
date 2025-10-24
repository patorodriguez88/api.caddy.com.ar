<?php
require_once 'clases/respuestas.class.php';
require_once 'clases/servicios.class.php';

$_respuestas = new respuestas;
$_servicios = new servicios;

if ($_SERVER['REQUEST_METHOD'] == "GET") {
    if (isset($_GET["page"])) {
        $pagina = $_GET["page"];
        $token = $_GET["token"];
        $estado = $_GET["estado"];
        $listaServicios = $_servicios->listaServicios($pagina, $token, $estado);
        header("Content-Type: application/json");
        echo json_encode($listaServicios);
        http_response_code(200);
    } elseif (isset($_GET['id'])) {
        $codigoseguimiento = $_GET['id'];
        $token = $_GET['token'];
        $datosPaciente = $_servicios->obtenerSeguimiento($codigoseguimiento, $token);
        header("Content-Type: application/json");
        echo json_encode($datosServicios);
        http_response_code(200);
    } elseif (isset($_GET['idProveedor'])) {
        $codigoseguimiento = $_GET['idProveedor'];
        $token = $_GET['token'];
        $datosServicios = $_servicios->obtenerSeguimientoProveedor($codigoseguimiento, $token);
        header("Content-Type: application/json");
        echo json_encode($datosPaciente);
        http_response_code(200);
    }
} else if ($_SERVER['REQUEST_METHOD'] == "POST") {
    //recibimos los datos enviados
    $postBody = file_get_contents("php://input");
    //enviamos los datos al manejador
    $datosArray = $_servicios->post($postBody);
    //delvovemos una respuesta 
    header('Content-Type: application/json');

    if (isset($datosArray["result"]["error_id"])) {

        $responseCode = $datosArray["result"]["error_id"];

        http_response_code($responseCode);
    } else {

        http_response_code(200);
    }
    echo json_encode($datosArray);
} else if ($_SERVER['REQUEST_METHOD'] == "PUT") {
    //recibimos los datos enviados
    $postBody = file_get_contents("php://input");
    //enviamos datos al manejador
    $datosArray = $_servicios->put($postBody);
    //delvovemos una respuesta 
    header('Content-Type: application/json');
    if (isset($datosArray["result"]["error_id"])) {
        $responseCode = $datosArray["result"]["error_id"];
        http_response_code($responseCode);
    } else {
        http_response_code(200);
    }
    echo json_encode($datosArray);
} else if ($_SERVER['REQUEST_METHOD'] == "DELETE") {
    $headers = getallheaders();
    if (isset($headers["token"]) && isset($headers["pacienteId"])) {
        //recibimos los datos enviados por el header
        $send = [
            "token" => $headers["token"],
            "pacienteId" => $headers["pacienteId"]
        ];
        $postBody = json_encode($send);
    } else {
        //recibimos los datos enviados
        $postBody = file_get_contents("php://input");
    }
    //enviamos datos al manejador
    $datosArray = $_pacientes->delete($postBody);
    //delvovemos una respuesta 
    header('Content-Type: application/json');
    if (isset($datosArray["result"]["error_id"])) {
        $responseCode = $datosArray["result"]["error_id"];
        http_response_code($responseCode);
    } else {
        http_response_code(200);
    }
    echo json_encode($datosArray);
} else {
    header('Content-Type: application/json');
    $datosArray = $_respuestas->error_405();
    echo json_encode($datosArray);
}
