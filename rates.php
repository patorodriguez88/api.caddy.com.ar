<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once 'clases/respuestas.class.php';
require_once 'clases/rates.class.php';   // 👈 O el nombre final que uses

$_respuestas = new respuestas();
$rates       = new RatesV2();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode($_respuestas->error_405(), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * NO leemos más el token acá.
 * Lo resuelve internamente RatesV2::cotizarGet()
 * con Token::obtenerToken() (Bearer o ?token=).
 */

$cp = $_GET['cp']
    ?? ($_GET['CodigoPostal'] ?? null);

$params = [
    // ya no pasamos 'token'
    'cp'             => $cp,
    'length'         => $_GET['length']         ?? null,
    'width'          => $_GET['width']          ?? null,
    'height'         => $_GET['height']         ?? null,
    'weight'         => $_GET['weight']         ?? null,
    'localidad'      => $_GET['localidad']      ?? '',
    'servicio'       => isset($_GET['servicio']) ? (int)$_GET['servicio'] : 1,
    'cantidad'       => isset($_GET['cantidad']) ? (int)$_GET['cantidad'] : 1,
    'valorDeclarado' => isset($_GET['valorDeclarado']) ? (float)$_GET['valorDeclarado'] : 0.0,
    'flex'           => isset($_GET['flex']) ? (int)$_GET['flex'] : 0,
];

[$status, $body] = $rates->cotizarGet($params);

http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
