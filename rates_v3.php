<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'clases/respuestas.class.php';
require_once 'clases/rates_v3.class.php';

$_respuestas = new respuestas();
$rates       = new Rates();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); // respuesta dinámica: que ningún proxy la cachee

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    $r = $_respuestas->error_405();
    $r['result']['metodos_permitidos'] = ['GET', 'POST'];
    $r['result']['hint'] = 'GET /rates_v3 = modo simple (N bultos idénticos, ?cantidad=N o ?bultos=N). '
        . 'POST /rates_v3 = modo multibulto, body JSON con un arreglo "bultos" de medidas independientes.';
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * IMPORTANTE:
 * El token ya lo resuelve internamente la clase Rates
 * (por header Bearer o por ?token=).
 */

if ($method === 'GET') {
    // MODO SIMPLE: 1 tipo de bulto, N cantidad (idénticos)
    $cp = $_GET['cp'] ?? ($_GET['CodigoPostal'] ?? null);

    // 'cantidad' = cantidad de bultos idénticos. Alias: 'bultos' (escalar en modo GET).
    $cantidad = isset($_GET['cantidad']) ? (int)$_GET['cantidad']
              : (isset($_GET['bultos']) ? (int)$_GET['bultos'] : 1);

    $baseBulto = [
        'length'         => isset($_GET['length'])         ? (float)$_GET['length']         : null,
        'width'          => isset($_GET['width'])          ? (float)$_GET['width']          : null,
        'height'         => isset($_GET['height'])         ? (float)$_GET['height']         : null,
        'weight'         => isset($_GET['weight'])         ? (float)$_GET['weight']         : null,
        'valorDeclarado' => isset($_GET['valorDeclarado']) ? (float)$_GET['valorDeclarado'] : 0.0,
    ];

    $bultos = array_fill(0, max($cantidad, 1), $baseBulto);

    $params = [
        'cp'        => $cp,
        'localidad' => $_GET['localidad']      ?? '',
        'servicio'  => isset($_GET['servicio']) ? (int)$_GET['servicio'] : 1,
        'flex'      => isset($_GET['flex'])     ? (int)$_GET['flex']     : 0,
        'bultos'    => $bultos,
    ];
} else {
    // POST: MODO PRO, múltiples bultos con medidas/valor independientes
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode($_respuestas->error_400("JSON inválido"), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cp = $data['cp'] ?? ($data['CodigoPostal'] ?? null);

    $params = [
        'cp'        => $cp,
        'localidad' => $data['localidad'] ?? '',
        'servicio'  => isset($data['servicio']) ? (int)$data['servicio'] : 1,
        'flex'      => isset($data['flex'])     ? (int)$data['flex']     : 0,
        'bultos'    => $data['bultos'] ?? [],
    ];

    if (empty($params['bultos'])) {
        http_response_code(400);
        echo json_encode($_respuestas->error_400("Debe enviar al menos un bulto"), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Llamamos a la lógica común de cotización
[$status, $body] = $rates->cotizar($params);

http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
