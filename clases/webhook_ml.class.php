<?php

require_once __DIR__ . "/../conexion/conexion.php";
require_once __DIR__ . "/respuestas.class.php";

class auth extends conexion
{

    public function login($json)
    {
        $_respuestas = new respuestas;

        $datos = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $_respuestas->error_400();
        }

        if (!isset($datos["shipments_id"]) || !isset($datos["status"])) {
            return $_respuestas->error_400();
        }

        $shipping_id = isset($datos['shipments_id']) ? parent::escapar($datos['shipments_id']) : '';
        $status = isset($datos['status']) ? parent::escapar($datos['status']) : '';
        $substatus = isset($datos['substatus']) ? parent::escapar($datos['substatus']) : '';
        $logistic_type = isset($datos['logistic_type']) ? parent::escapar($datos['logistic_type']) : '';
        $estimated_delivery_time = isset($datos['estimated_delivery_time']) ? parent::escapar($datos['estimated_delivery_time']) : '';
        $tracking_method = isset($datos['tracking_method']) ? parent::escapar($datos['tracking_method']) : '';
        $agency_description = isset($datos['agency_description']) ? parent::escapar($datos['agency_description']) : '';

        // LOG recomendado
        parent::logMeli('WEBHOOK_ML_RECIBIDO', $datos);

        $query_actualiza_status = "UPDATE Importaciones SET 
            Status='$status',
            Substatus='$substatus',
            estimated_delivery_time='$estimated_delivery_time',
            tracking_method='$tracking_method',
            agency_description='$agency_description'
            WHERE shipments_id='$shipping_id' AND Eliminado=0";

        parent::logMeli('WEBHOOK_ML_UPDATE', array(
            'shipping_id' => $shipping_id,
            'status' => $status,
            'substatus' => $substatus
        ));

        $SQL_UPDATE = parent::nonQuery($query_actualiza_status);

        // Sync con TransClientes
        $QUERY_STATUS = "SELECT status FROM TransClientes WHERE Eliminado=0 AND shipments_id='$shipping_id' LIMIT 1";
        $DATO_STATUS = parent::obtenerDatos($QUERY_STATUS);

        if ($DATO_STATUS && isset($DATO_STATUS[0]['status'])) {
            if ($DATO_STATUS[0]['status'] != $status) {

                $QUERY_UPDATE_TC = "UPDATE TransClientes SET status='$status' WHERE Eliminado=0 AND shipments_id='$shipping_id' LIMIT 1";

                parent::nonQuery($QUERY_UPDATE_TC);
            }
        }

        return array(
            'ok' => 1,
            'shipping_id' => $shipping_id,
            'status' => $status,
            'substatus' => $substatus,
            'updated' => $SQL_UPDATE
        );
    }
}
