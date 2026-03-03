<?php
require_once __DIR__ . '/clases/respuestas.class.php';
require_once __DIR__ . '/clases/warehouse.class.php';

$_respuestas = new respuestas;
$_warehouse  = new warehouse();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $postBody = file_get_contents("php://input");
    $datosArray = $_warehouse->post($postBody);

    if (isset($datosArray["result"]["error_id"])) {
        http_response_code((int)$datosArray["result"]["error_id"]);
    } else {
        http_response_code(200);
    }

    echo json_encode($datosArray, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(["result" => ["error_id" => 405, "error_msg" => "Método no permitido"]], JSON_UNESCAPED_UNICODE);
