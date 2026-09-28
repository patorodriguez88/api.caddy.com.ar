<?php
// GET /api/tarifas/cotizar - cotizacion LEGACY, identica a www.caddy.com.ar/api/tarifas/cotizar.
// La usa VENEX. Ver clases/rates_legacy.class.php. Plazo para dejar www: 1/12/2026.
require_once __DIR__ . '/../clases/respuestas.class.php';
require_once __DIR__ . '/../clases/rates_legacy.class.php';

$_respuestas = new respuestas();
$rates       = new RatesLegacy();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode($_respuestas->error_405());
    exit;
}

// Compat PHP 5.6 (sin operador ??)
$token = isset($_GET['token']) ? $_GET['token'] : null;
$cp    = isset($_GET['cp']) ? $_GET['cp'] : (isset($_GET['CodigoPostal']) ? $_GET['CodigoPostal'] : null);

$length = isset($_GET['length']) ? $_GET['length'] : null;
$width  = isset($_GET['width'])  ? $_GET['width']  : null;
$height = isset($_GET['height']) ? $_GET['height'] : null;
$weight = isset($_GET['weight']) ? $_GET['weight'] : null;

$params = array(
    'token'          => $token,
    'cp'             => $cp,
    'length'         => $length,
    'width'          => $width,
    'height'         => $height,
    'weight'         => $weight,
    'localidad'      => isset($_GET['localidad']) ? $_GET['localidad'] : '',
    'servicio'       => isset($_GET['servicio']) ? (int)$_GET['servicio'] : 1,
    'cantidad'       => isset($_GET['cantidad']) ? (int)$_GET['cantidad'] : 1,
    'valorDeclarado' => isset($_GET['valorDeclarado']) ? (float)$_GET['valorDeclarado'] : 0.0,
    'flex'           => isset($_GET['flex']) ? (int)$_GET['flex'] : 0,
);

list($status, $body) = $rates->cotizarGet($params);

http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
