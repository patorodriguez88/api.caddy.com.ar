<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Argentina/Cordoba');

// ===== Helpers de fecha y tarifa =====
function translateDay($dayInSpanish)
{
    $days = [
        'Lunes' => 'Monday',
        'Martes' => 'Tuesday',
        'Miércoles' => 'Wednesday',
        'Jueves' => 'Thursday',
        'Viernes' => 'Friday',
        'Sábado' => 'Saturday',
        'Domingo' => 'Sunday'
    ];
    return $days[$dayInSpanish] ?? null;
}
function calcularTarifa($tarifaBase, $cantidad)
{
    if ($cantidad <= 2) return (float)$tarifaBase;
    // +50% por cada unidad extra a partir de 3
    return (float)$tarifaBase + ((float)$tarifaBase * 0.5 * ($cantidad - 2));
}
function getNextDeliveryDate($dayInSpanish, $postal_code)
{
    $dayOfWeek = translateDay($dayInSpanish);
    if (!$dayOfWeek) return date('Y-m-d\TH:i:sO'); // fallback ahora

    $currentDate = date('Y-m-d');
    $currentDayOfWeek = date('l');

    if ($dayOfWeek === $currentDayOfWeek) {
        // Si hoy es el día prometido:
        if (ctype_digit((string)$postal_code) && (int)$postal_code >= 5000 && (int)$postal_code <= 5023) {
            // Flex Córdoba: entrega hoy
            return date('Y-m-d\TH:i:sO');
        }
        // No flex: programar para la próxima ocurrencia (no hoy)
        return date('Y-m-d\TH:i:sO', strtotime("next $dayOfWeek", strtotime($currentDate)));
    }
    // Programar para la próxima ocurrencia del día
    return date('Y-m-d\TH:i:sO', strtotime("next $dayOfWeek", strtotime($currentDate)));
}

// ===== Log sencillo (opcional, muy útil) =====
$LOG = __DIR__ . '/tiendanube_rates.log';
function tnlog($label, $data)
{
    global $LOG;
    file_put_contents($LOG, '[' . date('Y-m-d H:i:s') . "] $label: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)) . PHP_EOL, FILE_APPEND);
}

// ===== Leer y normalizar input de TN =====
$raw = file_get_contents('php://input');
tnlog('INPUT_RAW', $raw);
$in = json_decode($raw, true) ?: [];
$root = (isset($in['rate']) && is_array($in['rate'])) ? $in['rate'] : $in;

$dest = $root['destination'] ?? [];
$items = $root['items'] ?? [];
$postal_code = (string)($dest['postal_code'] ?? '');
$totalQty = 0;
$totalPrice = 0;
$totalWeight = 0;
$totalW = 0;
$totalH = 0;
$totalD = 0;

foreach ($items as $it) {
    $q = (int)($it['quantity'] ?? 1);
    $dims = $it['dimensions'] ?? [];
    $w = (float)($dims['width']  ?? 10);
    $h = (float)($dims['height'] ?? 10);
    $d = (float)($dims['depth']  ?? 10);
    $g = (int)  ($it['grams']    ?? 0);
    $p = (float)($it['price']    ?? 0);

    $totalQty   += $q;
    $totalPrice += $p * $q;
    $totalWeight += $g * $q;
    $totalW     += $w * $q;
    $totalH     += $h * $q;
    $totalD     += $d * $q;
}

// Zona Flex Córdoba (5000–5023)
$esFlex = (ctype_digit($postal_code) && (int)$postal_code >= 5000 && (int)$postal_code <= 5023);

// ===== Llamado a tu API de Caddy =====

// "Token" => "24c2862db2fb1f807e3f18c9374e813e",

$payloadCaddy = [
    "Token" => "4a031caee2e91950fcfd3048e38753ac",
    "flex"  => $esFlex ? "1" : "0",
    "Destination" => [["Localidad" => "Destino", "CodigoPostal" => $postal_code]],
    "Service" => [["Cantidad" => 1, "Servicio" => 1, "ValorDeclarado" => (string)$totalPrice]],
    "Box" => [[
        "Length" => (string)max(1, $totalD),
        "Width"  => (string)max(1, $totalW),
        "Height" => (string)max(1, $totalH),
        "Weight" => (string)max(0.1, $totalWeight / 1000)
    ]]
];
tnlog('CADDY_REQ', $payloadCaddy);

$ch = curl_init('https://www.caddy.com.ar/api/rates');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payloadCaddy, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 8,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
tnlog('CADDY_HTTP', $http);
tnlog('CADDY_RESP', $resp ?: $err);

$data = json_decode($resp, true);
if (!is_array($data) || !isset($data['result'])) {
    // Sin resultado → no rates
    echo json_encode(['rates' => []]);
    exit;
}

$r = $data['result'];
$titulo         = (string)($r['Titulo'] ?? 'Caddy Envío');
$tarifaBase     = (float) ($r['Tarifa'] ?? 0);
$totalCaddy     = (float) ($r['Total']  ?? 0);
$fechaEntregaAR = (string)($r['Fecha_Entrega'] ?? ''); // “Lunes”, “Martes”, etc.

// (Opcional) aplicar tu lógica de cantidad
if ($esFlex) {
    $precioCalculado = (float)$totalCaddy; // siempre tarifa base 1 servicio
} else {
    $precioCalculado = calcularTarifa($totalCaddy, max(1, $totalQty));
}

// Fechas de promesa (usa tus helpers)
$minDate = date('Y-m-d\TH:i:sO'); // ahora
$maxDate = $fechaEntregaAR ? getNextDeliveryDate($fechaEntregaAR, $postal_code)
    : $minDate; // fallback ahora

// CLAVE: code debe coincidir con una option ACTIVA del carrier
$code = 'Simple'; // Ajustá 205 por el ID de producto ACTIVO que corresponda

$rate = [
    "name" => "Caddy. " . $titulo,
    "code" => (string)$code,
    "price" => $precioCalculado,
    "price_merchant" => $precioCalculado,
    "currency" => "ARS",
    "type" => "ship",
    "min_delivery_date" => $minDate,
    "max_delivery_date" => $maxDate,
    "phone_required" => true,
    "reference" => $titulo
];

$out = ['rates' => [$rate]];
tnlog('OUTPUT', $out);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
