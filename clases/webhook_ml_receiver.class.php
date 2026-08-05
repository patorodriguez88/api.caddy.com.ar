<?php
require_once __DIR__ . "/../conexion/conexion.php";
require_once __DIR__ . "/../Integraciones/meli_queue/MeliQueueReceiver.class.php";

class WebhookMlReceiver extends conexion
{
    const CLIENT_ID = '3999751492306746';
    const CLIENT_SECRET = 'w5SMpJwEFlRxuLf5H8hCAyFxutn1jrMr';

    public function login($json)
    {
        $datos = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            parent::logMeli('WEBHOOK_JSON_ERROR', array(
                'error' => json_last_error_msg(),
                'raw'   => $json
            ));
            return array('ok' => 0, 'error' => 'json_invalido');
        }

        if (!$datos || !isset($datos['resource']) || !isset($datos['user_id'])) {
            parent::logMeli('ERROR_400_LOGIN', array('motivo' => 'Faltan resource o user_id', 'datos' => $datos));
            return array('ok' => 0, 'error' => 'faltan_datos');
        }

        $resource       = $datos['resource'];
        $user_id        = $datos['user_id'];
        $_id            = $datos['_id'] ?? '';
        $topic          = $datos['topic'] ?? '';
        $application_id = $datos['application_id'] ?? '';
        $sent           = $datos['sent'] ?? null;
        $attempts       = $datos['attempts'] ?? 0;
        $received       = $datos['received'] ?? date('Y-m-d H:i:s');

        $webhook = array(
            '_id' => $_id, 'topic' => $topic, 'resource' => $resource, 'user_id' => $user_id,
            'application_id' => $application_id, 'sent' => $sent, 'attempts' => $attempts, 'received' => $received
        );

        if ($topic == 'orders_v2') {
            $resultado = $this->insertarDatosImportaciones($resource, $user_id, $webhook);

            if ($resultado === 401) {
                if ($this->controlarTokenMeli($user_id)) {
                    $resultado = $this->insertarDatosImportaciones($resource, $user_id, $webhook);
                } else {
                    return array('ok' => 0, 'error' => 'refresh_fallido');
                }
            }

            return array('ok' => 1, 'resultado' => $resultado);
        }

        if ($topic == 'shipments') {
            $resultado = $this->actualizarStatus($resource, $user_id, $webhook);

            if ($resultado === 401) {
                if ($this->controlarTokenMeli($user_id)) {
                    $resultado = $this->actualizarStatus($resource, $user_id, $webhook);
                } else {
                    return array('ok' => 0, 'error' => 'refresh_fallido');
                }
            }

            return array('ok' => 1, 'resultado' => $resultado);
        }

        parent::logMeli('TOPIC_IGNORADO', array('topic' => $topic, 'resource' => $resource, 'user_id' => $user_id));
        return array('ok' => 1, 'resultado' => 'topic_ignorado');
    }

    private function insertarFlexHandshake($webhook, $shipping_id = 0, $logistic_type = '')
    {
        $query = "INSERT IGNORE INTO `flex_handshakes`
        (`_id`, `topic`, `resource`, `user_id`, `application_id`, `sent`, `attempts`, `received`, `shipping_id`, `logistic_type`)
        VALUES (
            '" . parent::escapar($webhook['_id']) . "',
            '" . parent::escapar($webhook['topic']) . "',
            '" . parent::escapar($webhook['resource']) . "',
            '" . parent::escapar($webhook['user_id']) . "',
            '" . parent::escapar($webhook['application_id']) . "',
            '" . parent::escapar($webhook['sent']) . "',
            '" . parent::escapar($webhook['attempts']) . "',
            '" . parent::escapar($webhook['received']) . "',
            '" . parent::escapar($shipping_id) . "',
            '" . parent::escapar($logistic_type) . "'
        )";

        parent::nonQuery($query);
    }

    private function llamarMeliApi($resource, $token)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.mercadolibre.com" . $resource,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $token),
        ));
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return array('response' => $response, 'curl_error' => $curlError, 'http_code' => $httpCode);
    }

    private function insertarDatosImportaciones($resource, $user_id, $webhook)
    {
        $datosClientes = parent::obtenerDatos("SELECT NdeCliente as id, nombrecliente, access_token, user_id, refresh_token
                                                FROM Clientes WHERE user_id='" . parent::escapar($user_id) . "' LIMIT 1");

        if (!$datosClientes || !isset($datosClientes[0]['access_token']) || $datosClientes[0]['access_token'] === '') {
            parent::logMeli('IMPORTACIONES_CLIENTE_NO_ENCONTRADO', array('user_id' => $user_id));
            return 'cliente_no_encontrado';
        }

        $datoTarifa = parent::obtenerDatos("SELECT PrecioVenta FROM Productos WHERE Codigo='183' LIMIT 1");
        if (!$datoTarifa || !isset($datoTarifa[0]['PrecioVenta'])) {
            parent::logMeli('IMPORTACIONES_TARIFA_NO_ENCONTRADA');
            return 'tarifa_no_encontrada';
        }

        $token = $datosClientes[0]['access_token'];

        $orden = $this->llamarMeliApi($resource, $token);
        if ($orden['curl_error']) {
            parent::logMeli('ORDER_REQUEST_ERROR_CURL', array('resource' => $resource, 'curl_error' => $orden['curl_error']));
            return 'error_order_curl';
        }
        if ($orden['http_code'] == 401) return 401;
        if ($orden['http_code'] != 200) {
            parent::logMeli('ORDER_REQUEST_ERROR_HTTP', array('resource' => $resource, 'http_code' => $orden['http_code'], 'response' => $orden['response']));
            return 'error_order_http';
        }

        $result = json_decode($orden['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            parent::logMeli('ORDER_JSON_ERROR', array('error' => json_last_error_msg()));
            return 'order_json_error';
        }
        if (!$result || !isset($result['shipping']['id'])) {
            parent::logMeli('ORDER_SIN_SHIPPING', array('response' => $result));
            return 'orden_sin_shipping';
        }

        $shipping_id = $result['shipping']['id'];

        $shipment = $this->llamarMeliApi('/shipments/' . $shipping_id, $token);
        if ($shipment['curl_error']) {
            parent::logMeli('SHIPMENT_REQUEST_ERROR_CURL', array('shipping_id' => $shipping_id, 'curl_error' => $shipment['curl_error']));
            return 'error_shipment_curl';
        }
        if ($shipment['http_code'] == 401) return 401;
        if ($shipment['http_code'] != 200) {
            parent::logMeli('SHIPMENT_REQUEST_ERROR_HTTP', array('shipping_id' => $shipping_id, 'http_code' => $shipment['http_code']));
            return 'error_shipment_http';
        }

        $result1 = json_decode($shipment['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            parent::logMeli('SHIPMENT_JSON_ERROR', array('error' => json_last_error_msg(), 'shipping_id' => $shipping_id));
            return 'shipment_json_error';
        }
        if (!$result1 || !isset($result1['logistic_type'])) {
            parent::logMeli('SHIPMENT_INVALIDO', array('shipping_id' => $shipping_id));
            return 'shipment_invalido';
        }

        $logistic_type = $result1['logistic_type'] ?? '';
        if ($logistic_type != 'self_service') {
            return 'no_self_service';
        }

        $this->insertarFlexHandshake($webhook, $shipping_id, $logistic_type);

        $total_amount = $result['total_amount'] ?? 0;
        $name = $result1['receiver_address']['receiver_name'] ?? '';
        $address_line_raw = $result1['receiver_address']['address_line'] ?? '';
        $city = $result1['receiver_address']['city']['name'] ?? '';
        $state = $result1['receiver_address']['state']['name'] ?? '';
        $address_line = trim($address_line_raw . ', ' . $city . ', ' . $state);
        $comment = $result1['receiver_address']['comment'] ?? '';
        $status = $result1['status'] ?? '';
        $substatus = $result1['substatus'] ?? '';
        $phone = $result1['receiver_address']['receiver_phone'] ?? '';
        $zip_code = $result1['receiver_address']['zip_code'] ?? '';
        $Cantidad = 1;
        $Precio = $datoTarifa[0]['PrecioVenta'];
        $Precio_Total = $Cantidad * $Precio;
        $order_id = $result1['order_id'] ?? 0;
        $date_created = $result1['date_created'] ?? null;
        $estimated_delivery_time = $result1['shipping_option']['estimated_delivery_time']['date'] ?? '';
        $tracking_method = $result1['tracking_method'] ?? '';
        $agency_description = $result1['receiver_address']['agency']['description'] ?? '';
        $description = $result1['shipping_items'][0]['description'] ?? '';
        $Fecha = date('Y-m-d');

        $min = 5000;
        $max = 5023;
        $zip_num = (int)$zip_code;
        if (!(($min <= $zip_num) && ($zip_num <= $max))) {
            parent::logMeli('FUERA_DE_CORDOBA_FLEX', array('shipping_id' => $shipping_id, 'zip_code' => $zip_code));
            return 'fuera_de_cordoba_flex';
        }

        $control = parent::obtenerDatos("SELECT id FROM Importaciones WHERE shipments_id='" . parent::escapar($shipping_id) . "' AND Eliminado=0 LIMIT 1");
        if ($control && count($control) > 0) {
            return 'ya_existe';
        }

        $nombreCliente = $datosClientes[0]['nombrecliente'];

        $columnas = ['TipoDeComprobante', 'NumeroComprobante', 'Fecha', 'RazonSocial', 'NCliente', 'Cantidad', 'Precio', 'Total', 'ClienteDestino', 'DomicilioDestino', 'Usuario', 'Eliminado', 'LocalidadDestino', 'ProvinciaDestino', 'Observaciones', 'idProveedor', 'ValorDeclarado', 'Celular', 'Meli', 'Status', 'Substatus', 'cpdestino', 'order_id', 'logistic_type', 'shipments_id', 'date_created', 'estimated_delivery_time', 'tracking_method', 'agency_description', 'description'];

        $valoresPorColumna = [
            'TipoDeComprobante' => 'API_MELI',
            'NumeroComprobante' => '188',
            'Fecha' => $Fecha,
            'RazonSocial' => $nombreCliente,
            'NCliente' => $datosClientes[0]['id'],
            'Cantidad' => $Cantidad,
            'Precio' => $Precio,
            'Total' => $Precio_Total,
            'ClienteDestino' => $name,
            'DomicilioDestino' => $address_line,
            'Usuario' => 'WEBHOOK',
            'Eliminado' => 0,
            'LocalidadDestino' => $city,
            'ProvinciaDestino' => $state,
            'Observaciones' => $comment,
            'idProveedor' => $shipping_id,
            'ValorDeclarado' => $total_amount,
            'Celular' => $phone,
            'Meli' => 1,
            'Status' => $status,
            'Substatus' => $substatus,
            'cpdestino' => $zip_code,
            'order_id' => $order_id,
            'logistic_type' => $logistic_type,
            'shipments_id' => $shipping_id,
            'date_created' => $date_created,
            'estimated_delivery_time' => $estimated_delivery_time,
            'tracking_method' => $tracking_method,
            'agency_description' => $agency_description,
            'description' => $description,
        ];

        $valores = [];
        foreach ($columnas as $col) {
            $val = $valoresPorColumna[$col];
            $valores[] = $val === null ? 'NULL' : "'" . parent::escapar($val) . "'";
        }

        $query = "INSERT INTO Importaciones (`" . implode('`,`', $columnas) . "`) VALUES (" . implode(',', $valores) . ")";

        $insertId = parent::nonQueryId($query);
        if (!$insertId) {
            parent::logMeli('IMPORTACIONES_INSERT_ERROR', array('shipping_id' => $shipping_id));
            return 'error_insert_importaciones';
        }

        parent::logMeli('IMPORTACIONES_OK', array('shipping_id' => $shipping_id, 'insert_id' => $insertId));
        return 'ok';
    }

    private function actualizarStatus($resource, $user_id, $webhook)
    {
        $datosClientes = parent::obtenerDatos("SELECT id, nombrecliente, access_token, user_id, refresh_token
                                                FROM Clientes WHERE user_id='" . parent::escapar($user_id) . "' LIMIT 1");

        if (!$datosClientes || !isset($datosClientes[0]['access_token']) || $datosClientes[0]['access_token'] === '') {
            parent::logMeli('ACTUALIZAR_STATUS_CLIENTE_NO_ENCONTRADO', array('user_id' => $user_id));
            return 'cliente_no_encontrado';
        }

        $shipment = $this->llamarMeliApi($resource, $datosClientes[0]['access_token']);
        if ($shipment['curl_error']) {
            parent::logMeli('ACTUALIZAR_STATUS_CURL_ERROR', array('resource' => $resource, 'curl_error' => $shipment['curl_error']));
            return 'error_shipment_curl';
        }
        if ($shipment['http_code'] == 401) return 401;
        if ($shipment['http_code'] != 200) {
            parent::logMeli('ACTUALIZAR_STATUS_HTTP_ERROR', array('resource' => $resource, 'http_code' => $shipment['http_code']));
            return 'error_shipment_http';
        }

        $result = json_decode($shipment['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !$result || !isset($result['id'])) {
            parent::logMeli('ACTUALIZAR_STATUS_SHIPMENT_INVALIDO', array('response' => $result));
            return 'shipment_invalido';
        }

        $shipping_id = $result['id'];
        $status = $result['status'] ?? '';
        $substatus = $result['substatus'] ?? '';
        $logistic_type = $result['logistic_type'] ?? '';
        $tracking_method = $result['tracking_method'] ?? '';
        $agency_description = $result['receiver_address']['agency']['description'] ?? '';
        $estimated_delivery_time = $result['shipping_option']['estimated_delivery_time']['date'] ?? '';

        if ($logistic_type != 'self_service') {
            return 'no_self_service';
        }

        $this->insertarFlexHandshake($webhook, $shipping_id, $logistic_type);

        $datoStatus = parent::obtenerDatos("SELECT Status, Substatus FROM Importaciones WHERE shipments_id='" . parent::escapar($shipping_id) . "' AND Eliminado=0 LIMIT 1");

        if (!$datoStatus || !isset($datoStatus[0]['Status'])) {
            $orderIdFallback = $result['order_id'] ?? 0;
            if (!$orderIdFallback) {
                parent::logMeli('FALLBACK_SIN_ORDER_ID', array('shipping_id' => $shipping_id));
                return 'shipment_no_importado';
            }

            $resultadoFallback = $this->insertarDatosImportaciones('/orders/' . $orderIdFallback, $user_id, $webhook);

            if ($resultadoFallback === 401) return 401;
            if ($resultadoFallback != 'ok' && $resultadoFallback != 'ya_existe') {
                parent::logMeli('FALLBACK_FALLO', array('shipping_id' => $shipping_id, 'resultado' => $resultadoFallback));
                return 'shipment_no_importado';
            }

            $datoStatus = parent::obtenerDatos("SELECT Status, Substatus FROM Importaciones WHERE shipments_id='" . parent::escapar($shipping_id) . "' AND Eliminado=0 LIMIT 1");
            if (!$datoStatus || !isset($datoStatus[0]['Status'])) {
                return 'shipment_no_importado';
            }
        }

        $substatusActualBd = $datoStatus[0]['Substatus'] ?? '';

        if ($datoStatus[0]['Status'] != $status || $substatusActualBd != $substatus) {
            parent::nonQuery("UPDATE Importaciones
                               SET Status='" . parent::escapar($status) . "', Substatus='" . parent::escapar($substatus) . "'
                               WHERE shipments_id='" . parent::escapar($shipping_id) . "' AND Eliminado=0");

            $receptor = new MeliQueueReceiver();
            $payload = json_encode(array(
                'shipments_id' => (string)$shipping_id,
                'status' => (string)$status,
                'substatus' => (string)$substatus,
                'logistic_type' => (string)$logistic_type,
                'estimated_delivery_time' => (string)$estimated_delivery_time,
                'tracking_method' => (string)$tracking_method,
                'agency_description' => (string)$agency_description,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $receptor->encolar($payload);

            return $shipping_id;
        }

        return 'sin_cambios';
    }

    private function controlarTokenMeli($user_id)
    {
        $datosClientes = parent::obtenerDatos("SELECT id, access_token, user_id, refresh_token
                                                FROM Clientes WHERE user_id='" . parent::escapar($user_id) . "' LIMIT 1");

        if (!$datosClientes || !isset($datosClientes[0]['refresh_token']) || $datosClientes[0]['refresh_token'] === '') {
            parent::logMeli('CONTROLAR_TOKEN_SIN_REFRESH_TOKEN', array('user_id' => $user_id));
            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.mercadolibre.com/oauth/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'grant_type=refresh_token&client_id=' . self::CLIENT_ID . '&client_secret=' . self::CLIENT_SECRET . '&refresh_token=' . $datosClientes[0]['refresh_token'],
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        $resultado = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($resultado['access_token'])) {
            parent::logMeli('CONTROLAR_TOKEN_REFRESH_FALLIDO', array('user_id' => $user_id, 'respuesta' => $resultado));
            return false;
        }

        $update = parent::nonQuery("UPDATE Clientes
                                     SET access_token='" . parent::escapar($resultado['access_token']) . "',
                                         refresh_token='" . parent::escapar($resultado['refresh_token'] ?? $datosClientes[0]['refresh_token']) . "'
                                     WHERE user_id='" . parent::escapar($user_id) . "'");

        return $update > 0;
    }
}
