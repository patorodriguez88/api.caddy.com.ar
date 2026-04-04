<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
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
            return array(
                'ok' => 0,
                'msg' => 'no existe transcliente'
            );
        }

        $t = $TRANS[0];

        // =========================================
        // 3. SYNC STATUS TRANSCLIENTES
        // =========================================
        $statusActualTC = isset($t['status']) ? $t['status'] : '';

        if ($statusActualTC != $status) {
            $QUERY_UPDATE_TC = "UPDATE TransClientes 
                                SET status='$status' 
                                WHERE id='" . parent::escapar($this->valorTC($t, array('id'), 0)) . "' 
                                LIMIT 1";

            parent::nonQuery($QUERY_UPDATE_TC);
        }

        // =========================================
        // 4. SI YA EXISTE "ENTREGADO AL CLIENTE", ACTUALIZAR MELI
        //    EXCEPTO SI ES buyer_rescheduled
        // =========================================
        $idTransClientes = $this->valorTC($t, array('id'), 0);
        $seguimientoEntregado = $this->buscarSeguimientoEntregado($idTransClientes);

        if ($seguimientoEntregado && $substatus != 'buyer_rescheduled') {
            $idSeguimiento = $seguimientoEntregado['id'];

            $qUpdateMeli = "UPDATE Seguimiento 
                            SET status_meli='$status',
                                substatus_meli='$substatus',
                                TimeStamp=NOW()
                            WHERE id='" . parent::escapar($idSeguimiento) . "'
                            LIMIT 1";

            $updateMeli = parent::nonQuery($qUpdateMeli);

            parent::logMeli('SEGUIMIENTO_ENTREGADO_ACTUALIZADO_MELI', array(
                'idSeguimiento' => $idSeguimiento,
                'shipping_id' => $shipping_id,
                'status' => $status,
                'substatus' => $substatus,
                'updated' => $updateMeli
            ));

            return array(
                'ok' => 1,
                'msg' => 'seguimiento entregado actualizado con estado meli',
                'shipping_id' => $shipping_id,
                'status' => $status,
                'substatus' => $substatus,
                'updated' => $SQL_UPDATE
            );
        }

        // =========================================
        // 5. MAPEAR ESTADO
        // =========================================
        $slug = $this->mapearEstado($status, $substatus);

        if (!$slug) {
            parent::logMeli('ESTADO_NO_MAPEADO', array(
                'status' => $status,
                'substatus' => $substatus
            ));

            return array(
                'ok' => 1,
                'msg' => 'estado no mapeado',
                'shipping_id' => $shipping_id,
                'status' => $status,
                'substatus' => $substatus,
                'updated' => $SQL_UPDATE
            );
        }

        // =========================================
        // 6. OBTENER STATE_ID
        // =========================================
        $estadoDB = $this->obtenerEstadoPorSlug($slug);

        if (!$estadoDB) {
            parent::logMeli('SLUG_NO_EXISTE', $slug);

            return array(
                'ok' => 0,
                'msg' => 'slug no existe'
            );
        }

        $state_id = $estadoDB['id'];
        $estado_nombre = $estadoDB['Estado'];

        // =========================================
        // 7. EVITAR DUPLICADO EXACTO
        // =========================================
        $observacion = $this->armarObservacion($status, $substatus);

        $qExiste = "SELECT id FROM Seguimiento 
                    WHERE idTransClientes='" . parent::escapar($idTransClientes) . "' 
                    AND Eliminado=0
                    AND state_id='" . parent::escapar($state_id) . "'
                    AND Observaciones='" . parent::escapar($observacion) . "'
                    LIMIT 1";

        $existe = parent::obtenerDatos($qExiste);

        if ($existe) {
            return array(
                'ok' => 1,
                'msg' => 'ya existe seguimiento',
                'shipping_id' => $shipping_id,
                'status' => $status,
                'substatus' => $substatus,
                'updated' => $SQL_UPDATE
            );
        }

        // =========================================
        // 🔴 MELI DICE ENTREGADO → FORZAR LÓGICA INTERNA
        // =========================================
        if ($status == 'delivered') {

            $idTransClientes = $this->valorTC($t, array('id'), 0);
            $codigoSeguimiento = $this->valorTC($t, array('CodigoSeguimiento', 'Seguimiento'), '');
            $recorrido = $this->valorTC($t, array('Recorrido'), 0);

            // 1. TRANSCLIENTES → marcar entregado
            $qTC = "UPDATE TransClientes 
            SET Entregado = 1,
                status = 'delivered'
            WHERE id='" . parent::escapar($idTransClientes) . "' 
            LIMIT 1";
            parent::nonQuery($qTC);

            // 2. HOJA DE RUTA → cerrar recorrido
            $qHR = "UPDATE HojaDeRuta 
            SET Estado = 'Cerrado'
            WHERE Seguimiento='" . parent::escapar($codigoSeguimiento) . "'";
            parent::nonQuery($qHR);

            // 3. VER SI YA EXISTE ENTREGADO EN SEGUIMIENTO
            $seguimientoEntregado = $this->buscarSeguimientoEntregado($idTransClientes);

            if ($seguimientoEntregado) {

                // SOLO ACTUALIZA MELI
                $idSeguimiento = $seguimientoEntregado['id'];

                $qUpdate = "UPDATE Seguimiento 
                    SET status_meli='$status',
                        substatus_meli='$substatus',
                        TimeStamp=NOW()
                    WHERE id='" . parent::escapar($idSeguimiento) . "' 
                    LIMIT 1";

                parent::nonQuery($qUpdate);

                parent::logMeli('DELIVERED_UPDATE_EXISTENTE', $shipping_id);

                return array(
                    'ok' => 1,
                    'msg' => 'entregado actualizado',
                    'shipping_id' => $shipping_id
                );
            }

            // SI NO EXISTE → INSERTAR ENTREGADO
            $slug = 'delivered';
        }

        // =========================================
        // 8. DATOS AUXILIARES
        // =========================================
        $numeroDeOrden = $this->valorTC($t, array('NumeroDeOrden', 'NumerodeOrden', 'Numerodeorden'), 0);
        $numeroDeOrden = parent::escapar($numeroDeOrden);

        $entregado = ($slug == 'delivered') ? 1 : 0;

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
        $Chofer = $ChoferData && isset($ChoferData[0]['Usuario']) ? $ChoferData[0]['Usuario'] : 'WEBHOOK_MELI';

        // =========================================
        // 9. INSERT SEGUIMIENTO
        // =========================================
        $fecha = date('Y-m-d');
        $hora  = date('H:i:s');

        $codigoSeguimiento = $this->valorTC($t, array('CodigoSeguimiento', 'Seguimiento'), '');
        $idCliente = $this->valorTC($t, array('idCliente', 'NCliente', 'idOrigen', 'Cliente'), 0);
        $retirado = $this->valorTC($t, array('Retirado'), 0);
        $visitas = $this->valorTC($t, array('Visitas'), 0);
        $recorrido = $this->valorTC($t, array('Recorrido'), 0);
        $devuelto = $this->valorTC($t, array('Devuelto'), 0);

        $insert = "INSERT INTO Seguimiento (
            Fecha, Hora, Usuario, Sucursal,
            CodigoSeguimiento, Observaciones,
            Entregado, Estado,
            idCliente, Retirado,
            Visitas, idTransClientes,
            TimeStamp, Recorrido,
            Devuelto, Webhook,
            state_id, status, NumerodeOrden, Estado_id,
            status_meli, substatus_meli
        ) VALUES (
            '$fecha',
            '$hora',
            '" . parent::escapar($Chofer) . "',
            'MELI',
            '" . parent::escapar($codigoSeguimiento) . "',
            '" . parent::escapar($observacion) . "',
            '$entregado',
            '" . parent::escapar($estado_nombre) . "',
            '" . parent::escapar($idCliente) . "',
            '" . parent::escapar($retirado) . "',
            '" . parent::escapar($visitas) . "',
            '" . parent::escapar($idTransClientes) . "',
            NOW(),
            '" . parent::escapar($recorrido) . "',
            '" . parent::escapar($devuelto) . "',
            1,
            '" . parent::escapar($state_id) . "',
            '$status',
            '$numeroDeOrden',
            '" . parent::escapar($state_id) . "',
            '$status',
            '$substatus'
        )";

        parent::logMeli('DEBUG_INSERT_SEGUIMIENTO_SQL', $insert);

        $insert_result = parent::nonQuery($insert);

        parent::logMeli('SEGUIMIENTO_INSERTADO', array(
            'shipping_id' => $shipping_id,
            'state_id' => $state_id,
            'estado' => $estado_nombre,
            'resultado' => $insert_result
        ));

        return array(
            'ok' => 1,
            'shipping_id' => $shipping_id,
            'status' => $status,
            'substatus' => $substatus,
            'updated' => $SQL_UPDATE,
            'seguimiento_insertado' => $insert_result
        );
    }

    private function valorTC($row, $keys, $default)
    {
        $i = 0;
        $count = count($keys);

        while ($i < $count) {
            if (isset($row[$keys[$i]])) {
                return $row[$keys[$i]];
            }
            $i++;
        }

        return $default;
    }

    private function obtenerEstadoPorSlug($slug)
    {
        $qEstado = "SELECT id, Estado 
                    FROM Estados 
                    WHERE slug='" . parent::escapar($slug) . "' 
                    LIMIT 1";

        $estadoDB = parent::obtenerDatos($qEstado);

        if (!$estadoDB) {
            return false;
        }

        return $estadoDB[0];
    }

    private function buscarSeguimientoEntregado($idTransClientes)
    {
        $estadoEntregado = $this->obtenerEstadoPorSlug('delivered');
        $stateIdEntregado = $estadoEntregado ? $estadoEntregado['id'] : 0;

        $q = "SELECT id 
              FROM Seguimiento 
              WHERE idTransClientes='" . parent::escapar($idTransClientes) . "'
              AND Eliminado=0
              AND (
                    state_id='" . parent::escapar($stateIdEntregado) . "'
                    OR Estado='Entregado al Cliente'
                  )
              ORDER BY id DESC
              LIMIT 1";

        $data = parent::obtenerDatos($q);

        if (!$data) {
            return false;
        }

        return $data[0];
    }

    private function armarObservacion($status, $substatus)
    {
        if ($substatus == 'buyer_rescheduled') {
            return 'WEBHOOK_MELI (cliente reprogramó entrega)';
        }

        return 'WEBHOOK_MELI (' . $status . ' / ' . $substatus . ')';
    }

    private function mapearEstado($status, $substatus)
    {
        if ($substatus == 'buyer_rescheduled') {
            return '1st_visit_fail';
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
