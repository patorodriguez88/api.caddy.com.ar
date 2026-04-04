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

        $shipping_id = parent::escapar($datos['shipments_id']);
        $status = parent::escapar($datos['status']);
        $substatus = isset($datos['substatus']) ? parent::escapar($datos['substatus']) : '';
        $logistic_type = isset($datos['logistic_type']) ? parent::escapar($datos['logistic_type']) : '';
        $estimated_delivery_time = isset($datos['estimated_delivery_time']) ? parent::escapar($datos['estimated_delivery_time']) : '';
        $tracking_method = isset($datos['tracking_method']) ? parent::escapar($datos['tracking_method']) : '';
        $agency_description = isset($datos['agency_description']) ? parent::escapar($datos['agency_description']) : '';

        parent::logMeli('WEBHOOK_ML_RECIBIDO', $datos);

        // =========================================
        // 1. UPDATE IMPORTACIONES
        // =========================================

        $query_actualiza_status = "UPDATE Importaciones SET 
            Status='$status',
            Substatus='$substatus',
            estimated_delivery_time='$estimated_delivery_time',
            tracking_method='$tracking_method',
            agency_description='$agency_description'
            WHERE shipments_id='$shipping_id' AND Eliminado=0";

        $SQL_UPDATE = parent::nonQuery($query_actualiza_status);

        // =========================================
        // 2. BUSCAR TRANSCLIENTES
        // =========================================

        $QUERY_TC = "SELECT * FROM TransClientes 
                     WHERE Eliminado=0 
                     AND shipments_id='$shipping_id' 
                     LIMIT 1";

        $TRANS = parent::obtenerDatos($QUERY_TC);

        if (!$TRANS) {
            parent::logMeli('NO_EXISTE_TRANSCLIENTES', $shipping_id);
            return array('ok' => 0, 'msg' => 'no existe transcliente');
        }

        $t = $TRANS[0];

        // =========================================
        // 3. SYNC STATUS TRANSCLIENTES
        // =========================================

        if ($t['status'] != $status) {
            $QUERY_UPDATE_TC = "UPDATE TransClientes 
                                SET status='$status' 
                                WHERE id='" . $t['id'] . "' 
                                LIMIT 1";

            parent::nonQuery($QUERY_UPDATE_TC);
        }

        // =========================================
        // 4. MAPEAR ESTADO
        // =========================================

        $slug = $this->mapearEstado($status, $substatus);

        if (!$slug) {
            parent::logMeli('ESTADO_NO_MAPEADO', array(
                'status' => $status,
                'substatus' => $substatus
            ));
            return array('ok' => 1, 'msg' => 'estado no mapeado');
        }

        // =========================================
        // 5. OBTENER STATE_ID
        // =========================================

        $qEstado = "SELECT id, Estado 
                    FROM estados 
                    WHERE slug='$slug' 
                    LIMIT 1";

        $estadoDB = parent::obtenerDatos($qEstado);

        if (!$estadoDB) {
            parent::logMeli('SLUG_NO_EXISTE', $slug);
            return array('ok' => 0, 'msg' => 'slug no existe');
        }

        $state_id = $estadoDB[0]['id'];
        $estado_nombre = $estadoDB[0]['Estado'];

        // =========================================
        // 6. EVITAR DUPLICADOS
        // =========================================


        $qExiste = "SELECT id FROM Seguimiento 
            WHERE idTransClientes='" . $t['id'] . "' 
            AND state_id='$state_id'
            AND Observaciones='WEBHOOK_MELI ($status / $substatus)'
            LIMIT 1";
        $existe = parent::obtenerDatos($qExiste);

        if ($existe) {
            return array('ok' => 1, 'msg' => 'ya existe seguimiento');
        }
        $numeroDeOrden = isset($t['NumeroDeOrden']) ? (int)$t['NumeroDeOrden'] : 0;
        $entregado = ($slug == 'delivered') ? 1 : 0;
        //ACA VER QUE HACEMOS CUANDO MELI ME DEVUELVE returned_to_origin
        $devuelto = ($slug == 'returned_to_origin') ? 1 : parent::escapar($t['Devuelto']);

        $qChofer = "SELECT Usuario 
            FROM usuarios 
            WHERE id = (
                SELECT idUsuarioChofer 
                FROM Logistica 
                WHERE NumerodeOrden = '$numeroDeOrden' 
                AND Eliminado = 0 
                LIMIT 1
            )";

        $ChoferData = parent::obtenerDatos($qChofer);

        $Chofer = $ChoferData ? $ChoferData[0]['Usuario'] : '';



        // =========================================
        // 7. INSERT SEGUIMIENTO
        // =========================================

        $fecha = date('Y-m-d');
        $hora  = date('H:i:s');


        $insert = "INSERT INTO Seguimiento (
            Fecha, Hora, Usuario, Sucursal,
            CodigoSeguimiento, Observaciones,
            Entregado, Estado,
            idCliente, Retirado,
            Visitas, idTransClientes,
            TimeStamp, Recorrido,
            Devuelto, Webhook,
            state_id, status, NumerodeOrden, Estado_id
        ) VALUES (
            '$fecha',
            '$hora',
            '$Chofer',
            'MELI',
            '" . parent::escapar($t['CodigoSeguimiento']) . "',
            'WEBHOOK_MELI ($status / $substatus)',
            '$entregado',
            '$estado_nombre',
            '" . parent::escapar($t['idCliente']) . "',
            '" . parent::escapar($t['Retirado']) . "',
            '" . parent::escapar($t['Visitas']) . "',
            '" . parent::escapar($t['id']) . "',
            NOW(),
            '" . parent::escapar($t['Recorrido']) . "',
            '" . parent::escapar($t['Devuelto']) . "',
            1,
            '$state_id',
            '$status',
            '" . parent::escapar($numeroDeOrden) . "',
            '$state_id'
        )";

        parent::nonQuery($insert);

        parent::logMeli('SEGUIMIENTO_INSERTADO', array(
            'shipping_id' => $shipping_id,
            'state_id' => $state_id,
            'estado' => $estado_nombre
        ));

        return array(
            'ok' => 1,
            'shipping_id' => $shipping_id,
            'status' => $status,
            'substatus' => $substatus,
            'updated' => $SQL_UPDATE
        );
    }

    // =========================================
    // MAPEO MELI → SISTEMA
    // =========================================
    private function mapearEstado($status, $substatus)
    {
        // primero casos especiales por substatus
        if ($substatus == 'packed' || $substatus == 'ready_to_pack' || $substatus == 'ready_to_print' || $substatus == 'in_warehouse') {
            return 'warehouse_validated';
        }

        if ($status == 'shipped') {
            return 'last_mile';
        }

        if ($status == 'delivered') {
            return 'delivered';
        }

        if ($status == 'not_delivered' || $status == 'cancelled') {
            return '1st_visit_fail';
        }

        if ($status == 'returned') {
            return 'returned_to_origin';
        }

        return null;
    }
}
