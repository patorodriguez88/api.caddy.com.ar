<?php
// Disparador HTTP del envío de webhooks pendientes, para usar desde un cron externo
// (cron-job.org). Mismo patrón que cron_worker.php: secret + no-cache.
//
// Consume Webhook_notifications, que sistema.caddy.com.ar encola (en cambiarRecorrido(),
// Funciones/Funciones.php) cuando un cambio de estado amerita avisar a un cliente, según
// Estados.Webhook/Notificacion_origen/Notificacion_destino. El envío en sí vive acá porque
// es un evento de API saliente hacia un partner externo (Wepoint, etc.), no una operación
// interna del sistema de logística.

require_once __DIR__ . '/conexion/conexion.php';

const CRON_WEBHOOKS_SECRET = '94ea72d2db0d4a82e654a3152dd5a67e';

$secreto = $_POST['secret'] ?? $_GET['secret'] ?? '';
if ($secreto !== CRON_WEBHOOKS_SECRET) {
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$db = new conexion();

$pendientes = $db->obtenerDatos(
    "SELECT * FROM Webhook_notifications WHERE Send<=8 AND Response<>200 AND Stop=0"
);

$procesados = 0;
$entregados = 0;

foreach ($pendientes as $row) {
    $idCliente   = (int)$row['idCliente'];
    $idCaddy     = $row['idCaddy'];
    $idProveedor = $row['idProveedor'];
    $estado      = $row['Estado'];
    $fecha       = $row['Fecha'];
    $hora        = $row['Hora'];
    $payload     = $row['State']; // JSON real a enviar, armado por el productor
    $send        = (int)$row['Send'] + 1;

    $webhookRows = $db->obtenerDatos(
        "SELECT * FROM Webhook WHERE idCliente='" . $db->escapar($idCliente) . "' AND Activo=1 LIMIT 1"
    );
    if (empty($webhookRows)) {
        continue;
    }
    $servidor = $webhookRows[0]['Endpoint'];
    $token = $webhookRows[0]['Token'];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $servidor,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'x-caddy-webhook-token: ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($curl);

    $response = 0;
    if (!curl_errno($curl)) {
        $response = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    }
    curl_close($curl);

    $db->nonQuery(
        "INSERT INTO Webhook_notifications
            (idCliente, idCaddy, idProveedor, Servidor, State, Estado, Fecha, Hora, User, Response, Send, Stop)
        VALUES (
            '" . $db->escapar($idCliente) . "',
            '" . $db->escapar($idCaddy) . "',
            '" . $db->escapar($idProveedor) . "',
            '" . $db->escapar($servidor) . "',
            '" . $db->escapar($payload) . "',
            '" . $db->escapar($estado) . "',
            '" . $db->escapar($fecha) . "',
            '" . $db->escapar($hora) . "',
            'cron',
            '" . $db->escapar($response) . "',
            '" . $db->escapar($send) . "',
            0
        )"
    );

    if ($response === 200) {
        $db->nonQuery("UPDATE Webhook_notifications SET Stop=1 WHERE id='" . (int)$row['id'] . "'");
        $entregados++;
    }

    $procesados++;
}

echo json_encode(['ok' => 1, 'procesados' => $procesados, 'entregados' => $entregados], JSON_UNESCAPED_UNICODE);
