<?php
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
