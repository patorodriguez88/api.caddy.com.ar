<?php
// Disparador del envío de webhooks pendientes.
// Se puede correr de dos formas:
//   - CLI (cron de cPanel):  php cron_webhooks.php          -> sin secret
//   - HTTP (cron externo):   ?secret=...                    -> con secret
//
// Consume Webhook_notifications, que sistema.caddy.com.ar encola (en cambiarRecorrido(),
// Funciones/Funciones.php) cuando un cambio de estado amerita avisar a un cliente, según
// Estados.Webhook/Notificacion_origen/Notificacion_destino. El envío en sí vive acá porque
// es un evento de API saliente hacia un partner externo (Wepoint, etc.), no una operación
// interna del sistema de logística.
//
// IMPORTANTE (fix 2026-08-28): esta corrida está ACOTADA a propósito.
//   - Toma como máximo BATCH filas por invocación (antes: todas, sin LIMIT).
//   - Corta por presupuesto de tiempo (TIME_BUDGET) para no dejar el proceso PHP
//     colgado y hacer que se apilen invocaciones (fue lo que saturó Entry Processes).
//   - ACTUALIZA la fila original (Send, Response, Stop) en vez de INSERTAR una fila
//     nueva por intento (el INSERT hacía crecer la tabla y reintentar para siempre).
//   - Un envío se marca Stop=1 cuando responde 200 o cuando agotó los reintentos
//     (Send > MAX_SEND) o cuando el cliente no tiene endpoint activo.
//   - Por CLI se saltea el gate del secret; por HTTP se exige igual que antes.

require_once __DIR__ . '/conexion/conexion.php';

const CRON_WEBHOOKS_SECRET = '94ea72d2db0d4a82e654a3152dd5a67e';

const BATCH        = 50;   // filas por invocación
const TIME_BUDGET  = 20;   // segundos de trabajo antes de cortar y dejar el resto para la próxima
const MAX_SEND     = 8;    // reintentos antes de rendirse (coincide con el filtro Send<=8)
const CURL_CONNECT = 5;    // segundos para conectar
const CURL_TOTAL   = 8;    // segundos totales por request

$esCli = (php_sapi_name() === 'cli');

if (!$esCli) {
    $secreto = $_POST['secret'] ?? $_GET['secret'] ?? '';
    if ($secreto !== CRON_WEBHOOKS_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => 0, 'error' => 'No autorizado']);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

@set_time_limit(TIME_BUDGET + 15);

$db = new conexion();
$t0 = microtime(true);

$pendientes = $db->obtenerDatos(
    "SELECT id, idCliente, idCaddy, idProveedor, Estado, Fecha, Hora, State, Send
       FROM Webhook_notifications
      WHERE Send <= " . MAX_SEND . " AND Response <> 200 AND Stop = 0
      ORDER BY id DESC
      LIMIT " . BATCH
);

$procesados   = 0;
$entregados   = 0;
$fallidos     = 0;
$rendidos     = 0;
$sin_endpoint = 0;
$corte_tiempo = false;

// Cache de endpoints por cliente para no repetir la query dentro del loop
$endpoints = [];

foreach ($pendientes as $row) {
    if (microtime(true) - $t0 > TIME_BUDGET) {
        $corte_tiempo = true;
        break;
    }

    $id        = (int)$row['id'];
    $idCliente = (int)$row['idCliente'];
    $payload   = $row['State']; // JSON real a enviar, armado por el productor
    $sendNuevo = (int)$row['Send'] + 1;

    // --- endpoint del cliente ---
    if (!array_key_exists($idCliente, $endpoints)) {
        $wh = $db->obtenerDatos(
            "SELECT Endpoint, Token FROM Webhook
              WHERE idCliente = '" . $db->escapar($idCliente) . "' AND Activo = 1
              LIMIT 1"
        );
        $endpoints[$idCliente] = !empty($wh) ? $wh[0] : null;
    }
    $conf = $endpoints[$idCliente];

    if ($conf === null) {
        // Sin endpoint activo: no tiene sentido reintentar. Se frena.
        $db->nonQuery(
            "UPDATE Webhook_notifications
                SET Stop = 1, User = 'cron-sin-endpoint'
              WHERE id = " . $id
        );
        $sin_endpoint++;
        $procesados++;
        continue;
    }

    $servidor = $conf['Endpoint'];
    $token    = $conf['Token'];

    // --- envío ---
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $servidor,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT,
        CURLOPT_TIMEOUT        => CURL_TOTAL,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'x-caddy-webhook-token: ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($curl);
    $response = curl_errno($curl) ? 0 : (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    // --- resultado: se ACTUALIZA la fila original, no se inserta una nueva ---
    $entregado = ($response === 200);
    $agotado   = ($sendNuevo > MAX_SEND);
    $stop      = ($entregado || $agotado) ? 1 : 0;

    $db->nonQuery(
        "UPDATE Webhook_notifications
            SET Send     = " . $sendNuevo . ",
                Response  = '" . $db->escapar($response) . "',
                Servidor  = '" . $db->escapar($servidor) . "',
                User      = 'cron',
                Stop      = " . $stop . "
          WHERE id = " . $id
    );

    if ($entregado)      $entregados++;
    elseif ($agotado)    $rendidos++;
    else                 $fallidos++;

    $procesados++;
}

// Cuántas quedan para próximas corridas
$restRow = $db->obtenerDatos(
    "SELECT COUNT(*) AS n
       FROM Webhook_notifications
      WHERE Send <= " . MAX_SEND . " AND Response <> 200 AND Stop = 0"
);
$restantes = isset($restRow[0]['n']) ? (int)$restRow[0]['n'] : null;

echo json_encode([
    'ok'           => 1,
    'via'          => $esCli ? 'cli' : 'http',
    'procesados'   => $procesados,
    'entregados'   => $entregados,
    'fallidos'     => $fallidos,
    'rendidos'     => $rendidos,
    'sin_endpoint' => $sin_endpoint,
    'restantes'    => $restantes,
    'corte_tiempo' => $corte_tiempo,
    'duracion_s'   => round(microtime(true) - $t0, 2),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
