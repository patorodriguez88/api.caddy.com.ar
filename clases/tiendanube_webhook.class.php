<?php

/**
 * Webhook de pedidos de Tienda Nube (evento order/packed): POST /api/tiendanube_webhook
 *
 * Cuando la tienda marca un pedido como empaquetado, si el envío es del carrier de Caddy
 * ("Caddy. Yo lo llevo!") el pedido entra a Importaciones (Meli=1, TipoDeComprobante
 * API_TIENDANUBE) y aparece en Integraciones para aceptarlo.
 *
 * Reemplaza la cadena vieja, que pasaba por dos servidores que se dan de baja:
 *   TN -> www.sistemacaddy.com.ar/Api_php/tiendanube_webhook.php (base propia + copia de tokens)
 *      -> www.caddy.com.ar/api/notificaciones_tn (API vieja) -> Importaciones
 * Mismos datos y mismas reglas (solo envíos a domicilio y solo con nuestro carrier), pero
 * todo contra dinter6_triangular. Diferencias: valida la firma de TN si hay app secret
 * configurado, las fechas se pasan de UTC a hora de Córdoba y el texto va en UTF-8.
 */

require_once __DIR__ . "/../conexion/conexion.php";

date_default_timezone_set('America/Argentina/Cordoba');

class TiendanubeWebhook extends conexion
{
    /**
     * @param string $raw    body tal cual llegó (la firma se calcula sobre esto)
     * @param string $firma  header x-linkedstore-hmac-sha256
     * @return array{code:int, body:array}
     */
    public function procesar(string $raw, string $firma): array
    {
        $secret = self::appSecret();
        if ($secret !== null) {
            if ($firma === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $firma)) {
                $this->log('FIRMA_INVALIDA', 0, []);
                return ['code' => 401, 'body' => ['ok' => 0, 'error' => 'firma_invalida']];
            }
        }

        $datos = json_decode($raw, true);
        $storeId = (int) ($datos['store_id'] ?? 0);
        $orderId = (int) ($datos['id'] ?? 0);
        $evento = (string) ($datos['event'] ?? '');
        if ($storeId <= 0 || $orderId <= 0) {
            $this->log('DATOS_INVALIDOS', $storeId, ['raw' => mb_substr($raw, 0, 300)]);
            return ['code' => 400, 'body' => ['ok' => 0, 'error' => 'faltan_datos']];
        }
        if ($secret === null) {
            $this->log('SIN_VALIDAR_FIRMA', $storeId, ['motivo' => 'falta conexion/tiendanube_config.php']);
        }
        if ($evento !== 'order/packed') {
            $this->log('EVENTO_IGNORADO', $storeId, ['evento' => $evento, 'orden' => $orderId]);
            return ['code' => 200, 'body' => ['ok' => 1, 'ignorado' => 'evento']];
        }

        if ($this->obtenerDatos("SELECT id FROM Importaciones WHERE shipments_id = '" . $orderId . "' AND Eliminado = 0 LIMIT 1")) {
            $this->log('DUPLICADO', $storeId, ['orden' => $orderId]);
            return ['code' => 200, 'body' => ['ok' => 1, 'ignorado' => 'duplicado']];
        }

        $cliente = $this->clientePorTienda($storeId);
        if (!$cliente || ($cliente['token_tiendanube'] ?? '') === '') {
            $this->log('TIENDA_DESCONOCIDA', $storeId, ['orden' => $orderId]);
            return ['code' => 200, 'body' => ['ok' => 0, 'error' => 'tienda_desconocida']];
        }

        [$http, $orden] = $this->traerOrden($storeId, $orderId, $cliente['token_tiendanube']);
        if (!is_array($orden) || empty($orden['id'])) {
            $this->log('ORDEN_NO_DISPONIBLE', $storeId, ['orden' => $orderId, 'http' => $http]);
            // 5xx: TN reintenta el webhook más tarde.
            return ['code' => 503, 'body' => ['ok' => 0, 'error' => 'orden_no_disponible']];
        }

        // Solo envíos a domicilio con el carrier de Caddy (retiros y otros carriers no entran).
        if (($orden['shipping_pickup_type'] ?? '') !== 'ship') {
            $this->log('NO_ES_ENVIO', $storeId, ['orden' => $orderId, 'tipo' => $orden['shipping_pickup_type'] ?? '']);
            return ['code' => 200, 'body' => ['ok' => 1, 'ignorado' => 'retiro']];
        }
        if (($orden['shipping'] ?? '') !== 'api_' . $cliente['carrier_id_tn']) {
            $this->log('OTRO_CARRIER', $storeId, ['orden' => $orderId, 'shipping' => $orden['shipping'] ?? '']);
            return ['code' => 200, 'body' => ['ok' => 1, 'ignorado' => 'otro_carrier']];
        }

        $id = $this->insertar($cliente, $orden);
        if (!$id) {
            $this->log('ERROR_INSERT', $storeId, ['orden' => $orderId]);
            return ['code' => 500, 'body' => ['ok' => 0, 'error' => 'no_se_pudo_guardar']];
        }
        $this->log('OK', $storeId, ['orden' => $orderId, 'importacion' => $id, 'cliente' => $cliente['id']]);
        return ['code' => 200, 'body' => ['ok' => 1, 'id' => $id]];
    }

    /** App secret de la app de TN (conexion/tiendanube_config.php, solo en el servidor). */
    public static function appSecret(): ?string
    {
        $archivo = __DIR__ . '/../conexion/tiendanube_config.php';
        if (!is_file($archivo)) {
            return null;
        }
        $cfg = require $archivo;
        $s = trim((string) ($cfg['app_secret'] ?? ''));
        return $s !== '' ? $s : null;
    }

    /** Mismo criterio que la cotización: si hay dos clientes con la misma tienda, el que tiene carrier. */
    private function clientePorTienda(int $storeId): ?array
    {
        $r = $this->obtenerDatos(
            "SELECT id, nombrecliente, token_tiendanube, carrier_id_tn FROM Clientes
              WHERE user_id_tn = '" . $storeId . "' AND Eliminado = 0
              ORDER BY (carrier_id_tn IS NULL OR carrier_id_tn IN ('', '0')), id LIMIT 1"
        );
        return $r[0] ?? null;
    }

    private function traerOrden(int $storeId, int $orderId, string $token): array
    {
        $ch = curl_init("https://api.tiendanube.com/v1/$storeId/orders/$orderId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authentication: bearer ' . $token,
                'User-Agent: Caddy (1579)',
            ],
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http, $http === 200 ? json_decode((string) $resp, true) : null];
    }

    /** UTC de TN ("2026-10-03T16:32:36+0000") -> hora de Córdoba. */
    private static function fechaLocal(?string $iso, string $formato): string
    {
        $t = $iso ? strtotime($iso) : false;
        return $t ? date($formato, $t) : '';
    }

    private function insertar(array $cliente, array $o): int
    {
        $dir = $o['shipping_address'] ?? [];
        $cantidad = 0;
        foreach (($o['products'] ?? []) as $p) {
            $cantidad += (int) ($p['quantity'] ?? 0);
        }
        $entregaEstimada = is_numeric($o['shipping_max_days'] ?? null)
            ? date('Y-m-d', strtotime('+' . (int) $o['shipping_max_days'] . ' day'))
            : null;

        $cols = [
            'TipoDeComprobante'       => 'API_TIENDANUBE',
            'NumeroComprobante'       => (string) $o['id'],
            'Fecha'                   => self::fechaLocal($o['created_at'] ?? null, 'Y-m-d') ?: date('Y-m-d'),
            'Hora'                    => self::fechaLocal($o['created_at'] ?? null, 'H:i:s'),
            'RazonSocial'             => $cliente['nombrecliente'],
            'NCliente'                => (string) $cliente['id'],
            'Cantidad'                => (string) $cantidad,
            'Precio'                  => (string) (float) ($o['shipping_cost_owner'] ?? 0),
            'Total'                   => (string) (float) ($o['shipping_cost_owner'] ?? 0),
            'ClienteDestino'          => (string) ($dir['name'] ?? ''),
            'DomicilioDestino'        => trim(($dir['address'] ?? '') . ' ' . ($dir['number'] ?? '') . ' ' . ($dir['floor'] ?? '')),
            'Usuario'                 => 'API_TIENDANUBE',
            'Eliminado'               => '0',
            'LocalidadDestino'        => (string) ($dir['locality'] ?? ''),
            'ProvinciaDestino'        => (string) ($dir['province'] ?? ''),
            'Observaciones'           => (string) ($o['note'] ?? ''),
            'idProveedor'             => (string) $o['id'],
            'ValorDeclarado'          => (string) (float) ($o['total'] ?? 0),
            'Celular'                 => mb_substr((string) ($o['customer']['phone'] ?? ($dir['phone'] ?? '')), 0, 20),
            'Meli'                    => '1',
            'Status'                  => mb_substr((string) ($o['shipping_status'] ?? ''), 0, 20),
            'cpdestino'               => (string) ($dir['zipcode'] ?? ''),
            'order_id'                => (string) $o['id'],
            'logistic_type'           => 'ship',
            'shipments_id'            => (string) $o['id'],
            'date_created'            => self::fechaLocal($o['created_at'] ?? null, 'Y-m-d H:i:s'),
            'estimated_delivery_time' => $entregaEstimada,
            'tracking_method'         => '',
            'agency_description'      => 'API_TIENDANUBE',
            'description'             => (string) ($o['products'][0]['name'] ?? ''),
        ];

        $vals = [];
        foreach ($cols as $col => $v) {
            $esFecha = in_array($col, ['date_created', 'estimated_delivery_time'], true);
            $vals[] = ($esFecha && ($v === null || $v === '')) ? 'NULL' : "'" . $this->escapar((string) $v) . "'";
        }
        $sql = "INSERT INTO Importaciones (`" . implode('`,`', array_keys($cols)) . "`) VALUES (" . implode(',', $vals) . ")";
        return (int) $this->nonQueryId($sql);
    }

    private function log(string $evento, int $storeId, array $datos): void
    {
        $dir = dirname(__DIR__, 3) . '/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $linea = '[' . date('Y-m-d H:i:s') . "] $evento store=$storeId " . json_encode($datos, JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . '/tiendanube_webhook.log', $linea . PHP_EOL, FILE_APPEND);
    }
}
