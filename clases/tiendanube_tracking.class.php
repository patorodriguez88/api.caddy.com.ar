<?php

/**
 * Estados de envío hacia Tienda Nube (homologación de shipping, puntos 8 y 9 del guion).
 *
 * Los pedidos de TN entran por el webhook order/packed y al aceptarlos en Preventa se les asigna
 * nuestro CodigoSeguimiento (PreVenta.TipoDeComprobante='API_TIENDANUBE', NumeroComprobante =
 * id de la orden) y se marcan como despachados con el link de seguimiento (sistema
 * TiendaNube/api_tn.php). Desde ahí, cada cambio de estado que se registra en Seguimiento
 * (sistema, app de reparto, warehouse) se informa acá como "tracking event" del fulfillment
 * order de TN:
 *   POST /orders/{order_id}/fulfillment-orders/{fo_id}/tracking-events
 *
 * En vez de enganchar cada lugar que escribe Seguimiento, cron_tiendanube_tracking.php corre
 * cada pocos minutos, toma las filas nuevas de los envíos de TN y las manda. Lo informado queda
 * en TiendaNube_tracking (una fila por Seguimiento.id), así no se repite nada.
 */

require_once __DIR__ . "/../conexion/conexion.php";

date_default_timezone_set('America/Argentina/Cordoba');

class TiendanubeTracking extends conexion
{
    /** Solo se informan estados registrados desde acá (no se reenvía el historial). */
    const DESDE = '2026-10-06';
    const BATCH = 40;
    const TIME_BUDGET = 20; // segundos

    /** Estado de Seguimiento -> estado de TN (los que no están acá no se informan: son internos). */
    public static function estadoTn(string $estado, string $observaciones): ?string
    {
        switch ($estado) {
            case 'En Origen':
            case 'Colectado del Cliente':
            case 'Retirado del Cliente':
            case 'Validado en Warehouse':
                return 'received_by_post_office';
            case 'En Transito':
                // "Cargado en la Hoja de Ruta" = subió a la camioneta del reparto.
                return stripos($observaciones, 'Hoja de Ruta') !== false ? 'out_for_delivery' : 'in_transit';
            case 'Entregado al Cliente':
                return 'delivered';
            case 'No se pudo entregar':
                return 'delivery_attempt_failed';
            case 'Devuelto al Cliente':
                return 'returned_to_sender';
        }
        return null;
    }

    private static function descripcion(string $status, string $estado): string
    {
        $textos = [
            'received_by_post_office' => 'Caddy recibió el paquete',
            'in_transit'              => 'En tránsito',
            'out_for_delivery'        => 'Salió para entrega',
            'delivered'               => 'Entregado',
            'delivery_attempt_failed' => 'No se pudo entregar',
            'returned_to_sender'      => 'Devuelto al remitente',
        ];
        return $textos[$status] ?? $estado;
    }

    public function procesar(): array
    {
        $inicio = time();
        $this->asegurarTabla();

        $estados = "'En Origen','Colectado del Cliente','Retirado del Cliente','Validado en Warehouse',"
                 . "'En Transito','Entregado al Cliente','No se pudo entregar','Devuelto al Cliente'";
        $filas = $this->obtenerDatos(
            "SELECT s.id, s.CodigoSeguimiento, s.Fecha, s.Hora, s.Estado, s.Observaciones,
                    p.NumeroComprobante AS order_id, c.user_id_tn, c.token_tiendanube
               FROM Seguimiento s
               JOIN PreVenta p ON p.CodigoSeguimiento = s.CodigoSeguimiento
                              AND p.TipoDeComprobante = 'API_TIENDANUBE' AND p.Eliminado = 0
               JOIN Clientes c ON c.id = p.NCliente
               LEFT JOIN TiendaNube_tracking t ON t.seguimiento_id = s.id
              WHERE t.id IS NULL
                AND s.Fecha >= '" . self::DESDE . "'
                AND s.Estado IN ($estados)
              ORDER BY s.Fecha, s.Hora, s.id
              LIMIT " . self::BATCH
        );

        $res = ['enviados' => 0, 'omitidos' => 0, 'errores' => 0, 'pendientes' => 0];
        foreach ($filas as $f) {
            if (time() - $inicio > self::TIME_BUDGET) {
                $res['pendientes']++;
                continue;
            }
            $status = self::estadoTn((string)$f['Estado'], (string)$f['Observaciones']);
            $orderId = (string)(int)$f['order_id'];
            $store = (string)(int)$f['user_id_tn'];
            $token = (string)$f['token_tiendanube'];

            // Mismo estado que el último informado para la orden (ej. dos "En Transito"): no se repite.
            $ultimo = $this->obtenerDatos("SELECT status, fo_id FROM TiendaNube_tracking
                                            WHERE order_id = '$orderId' AND http BETWEEN 200 AND 299
                                            ORDER BY id DESC LIMIT 1");
            if ($ultimo && $ultimo[0]['status'] === $status) {
                $this->registrar($f, $orderId, $store, $ultimo[0]['fo_id'], $status, 0, 'omitido: mismo estado que el anterior');
                $res['omitidos']++;
                continue;
            }

            $foId = $ultimo[0]['fo_id'] ?? $this->fulfillmentOrder($store, $orderId, $token);
            if (!$foId) {
                $this->registrar($f, $orderId, $store, '', $status, 404, 'sin fulfillment order en TN');
                $res['errores']++;
                continue;
            }

            $fecha = $f['Fecha'] . 'T' . ($f['Hora'] ?: '00:00:00');
            $evento = [
                'status'      => $status,
                'description' => self::descripcion($status, (string)$f['Estado']),
                'happened_at' => date('c', strtotime($fecha)),
            ];
            [$http, $body] = $this->tn('POST', "/$store/orders/$orderId/fulfillment-orders/$foId/tracking-events", $token, $evento);

            if ($http === 429) {
                // Rate limit de TN: se corta la corrida y se reintenta en la próxima.
                $res['pendientes']++;
                break;
            }
            if ($http === 0 || $http >= 500) {
                $res['pendientes']++; // error transitorio: no se registra, se reintenta
                continue;
            }
            $this->registrar($f, $orderId, $store, $foId, $status, $http, mb_substr((string)$body, 0, 500));
            $res[($http >= 200 && $http < 300) ? 'enviados' : 'errores']++;
        }
        return $res;
    }

    /** Fulfillment order de la orden que corresponde a Caddy (app 1579); si hay uno solo, ese. */
    private function fulfillmentOrder(string $store, string $orderId, string $token): ?string
    {
        [$http, $body] = $this->tn('GET', "/$store/orders/$orderId/fulfillment-orders", $token);
        $fos = $http === 200 ? json_decode((string)$body, true) : null;
        if (!is_array($fos) || !$fos) {
            return null;
        }
        foreach ($fos as $fo) {
            if ((string)($fo['shipping']['carrier']['app_id'] ?? '') === '1579') {
                return (string)$fo['id'];
            }
        }
        return count($fos) === 1 ? (string)$fos[0]['id'] : null;
    }

    private function tn(string $method, string $path, string $token, ?array $data = null): array
    {
        $ch = curl_init('https://api.tiendanube.com/v1' . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authentication: bearer ' . $token,
                'User-Agent: Caddy (1579)',
                'Content-Type: application/json',
            ],
        ];
        if ($data !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http, $body];
    }

    private function registrar(array $f, string $orderId, string $store, string $foId, ?string $status, int $http, string $respuesta): void
    {
        $this->nonQuery(
            "INSERT IGNORE INTO TiendaNube_tracking (seguimiento_id, codigo, order_id, store_id, fo_id, status, http, respuesta)
             VALUES ('" . (int)$f['id'] . "', '" . $this->escapar($f['CodigoSeguimiento']) . "', '$orderId', '$store',
                     '" . $this->escapar($foId) . "', '" . $this->escapar((string)$status) . "', '$http',
                     '" . $this->escapar($respuesta) . "')"
        );
    }

    private function asegurarTabla(): void
    {
        $this->nonQuery(
            "CREATE TABLE IF NOT EXISTS TiendaNube_tracking (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seguimiento_id INT NOT NULL,
                codigo VARCHAR(20) NOT NULL,
                order_id BIGINT NOT NULL,
                store_id BIGINT NOT NULL,
                fo_id VARCHAR(40) NOT NULL DEFAULT '',
                status VARCHAR(40) NOT NULL DEFAULT '',
                http SMALLINT NOT NULL DEFAULT 0,
                respuesta TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_seguimiento (seguimiento_id),
                KEY idx_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
