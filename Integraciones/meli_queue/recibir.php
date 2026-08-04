<?php
// Endpoint de recepción "fast-ack" para los webhooks de Meli reenviados por
// notificaciones_ml.v2 (Api-sistemacaddy.com.ar-). No procesa nada: solo
// encola el payload y responde. El procesamiento pesado (el que hoy hace
// webhook_ml.class.php) lo hace worker.php de forma asincrónica.

require_once __DIR__ . '/../../conexion/conexion.php';

class MeliQueueReceiver extends conexion
{
    public function encolar(string $rawJson): array
    {
        $datos = json_decode($rawJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => 0, 'error' => 'JSON inválido'];
        }

        $shipmentsId = isset($datos['shipments_id']) ? parent::escapar($datos['shipments_id']) : '';
        $status      = isset($datos['status']) ? parent::escapar($datos['status']) : '';
        $substatus   = isset($datos['substatus']) ? parent::escapar($datos['substatus']) : '';
        $rawEscaped  = parent::escapar($rawJson);

        $query = "INSERT INTO MeliWebhookQueue (raw_payload, shipments_id, status, substatus)
                  VALUES ('$rawEscaped', '$shipmentsId', '$status', '$substatus')";

        $id = parent::nonQueryId($query);

        parent::logMeli('MELI_QUEUE_ENCOLADO', [
            'id' => $id,
            'shipments_id' => $shipmentsId,
            'status' => $status,
        ]);

        return ['ok' => 1, 'queued_id' => $id];
    }
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => 0, 'error' => 'Method not allowed']);
    exit;
}

$postBody = file_get_contents('php://input');

$receiver = new MeliQueueReceiver();
$resultado = $receiver->encolar($postBody);

http_response_code($resultado['ok'] === 1 ? 200 : 400);
echo json_encode($resultado);
