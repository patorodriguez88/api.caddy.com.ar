<?php

/**
 * Cotización para el checkout de Tienda Nube (carrier "Caddy. Yo lo llevo!").
 *
 * Tienda Nube le pega al callback_url registrado en cada tienda
 * (POST /api/tiendanube) con el carrito y espera {"rates": [...]}.
 *
 * Portado 1:1 de la API vieja (www.caddy.com.ar/api/tiendanube + rates.tn),
 * que se da de baja al redirigir www.caddy.com.ar. Mismas reglas de precio:
 * - Solo Córdoba (CPA con X, o 5000-5299 / 5800-5999). Fuera de eso: sin tarifas.
 * - Capital (5000-5023) = tarifa FLEX (Productos.Codigo 183).
 * - Resto = grilla Web por volumen y km (Productos Grupo='Web' x Localidades.Km).
 * - Seguro si el valor declarado supera MontoMinimoSeguro.
 * - Por cantidad de unidades: +50% de la tarifa desde la 3ra.
 *
 * Diferencias con la vieja: no pasa por HTTP ni por el token fijo (inactivo)
 * de "El constructor SRL"; el cliente se resuelve por store_id
 * (Clientes.user_id_tn). La cotización vuelve a guardarse en Cotizaciones
 * (la vieja insertaba una columna Seguro que no existe y fallaba siempre).
 */

require_once __DIR__ . "/../conexion/conexion.php";

date_default_timezone_set('America/Argentina/Cordoba');

class TiendanubeRates extends conexion
{
    private const CODIGO_FLEX = '183';

    /**
     * @param array $in body del callback de TN (ya decodificado)
     * @return array ['rates' => [...]] listo para devolver a TN
     */
    public function cotizar(array $in): array
    {
        $root  = (isset($in['rate']) && is_array($in['rate'])) ? $in['rate'] : $in;
        $dest  = is_array($root['destination'] ?? null) ? $root['destination'] : [];
        $items = is_array($root['items'] ?? null) ? $root['items'] : [];
        $storeId = (int)($root['store_id'] ?? 0);

        [$cp, $esCordoba] = self::parseCpCordoba((string)($dest['postal_code'] ?? ''));
        if (!$esCordoba || !is_int($cp)) {
            $this->log('SIN_COBERTURA', $storeId, ['cp' => $dest['postal_code'] ?? '']);
            return ['rates' => []];
        }

        $cliente = $this->clientePorTienda($storeId);
        if (!$cliente) {
            $this->log('TIENDA_DESCONOCIDA', $storeId, []);
            return ['rates' => []];
        }

        // Totales del carrito: la vieja arma una caja cúbica con el volumen total
        $cantidad = 0;
        $valor    = 0.0;
        $gramos   = 0;
        $volumen  = 0.0; // cm³
        foreach ($items as $it) {
            $q    = isset($it['quantity']) ? (int)$it['quantity'] : 1;
            $dims = is_array($it['dimensions'] ?? null) ? $it['dimensions'] : [];
            $w = isset($dims['width'])  ? (float)$dims['width']  : 10;
            $h = isset($dims['height']) ? (float)$dims['height'] : 10;
            $d = isset($dims['depth'])  ? (float)$dims['depth']  : 10;

            $cantidad += $q;
            $valor    += (float)($it['price'] ?? 0) * $q;
            $gramos   += (int)($it['grams'] ?? 0) * $q;
            $volumen  += ($w * $h * $d) * $q;
        }
        $lado = (int)ceil(pow(max(1, $volumen), 1 / 3));
        $peso = max(0.1, $gramos / 1000);

        $esCapital = ($cp >= 5000 && $cp <= 5023);
        $price = $esCapital ? $this->tarifaFlex() : $this->tarifaGrilla($cp, $lado * $lado * $lado);
        if (!$price || (int)$price['id'] <= 0) {
            $this->log('SIN_PRECIO', $storeId, ['cp' => $cp, 'lado' => $lado]);
            return ['rates' => []];
        }

        // Seguro: solo si el valor declarado supera el mínimo
        $minimoSeguro = $this->minimoSeguro();
        $seguro = ($valor > $minimoSeguro) ? round($valor) * (float)$price['Seguro'] / 100 : 0;

        // La vieja calculaba la tarifa con Cantidad=1 y después aplicaba el +50%
        // por unidad sobre el TOTAL (tarifa + seguro). Se respeta tal cual.
        $totalUnitario = round((float)$price['PrecioVenta'] + $seguro);
        if ($totalUnitario <= 0) {
            $this->log('TOTAL_CERO', $storeId, ['codigo' => $price['Codigo']]);
            return ['rates' => []];
        }
        $precioFinal = self::porCantidad($totalUnitario, max(1, $cantidad));

        if ($esCapital) {
            $localidad = 'Cordoba Capital';
            $fecha = ((int)date('G') > 11) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
            $diaEntrega = self::nombreDia($fecha);
        } else {
            $loc = $this->localidadPorCp($cp);
            $localidad  = (string)($dest['locality'] ?? '') ?: ($loc['Localidad'] ?? '');
            $diaEntrega = $loc['DiaSalida'] ?? '';
        }

        $ahora   = date('Y-m-d\TH:i:sO');
        $entrega = $diaEntrega ? self::proximaEntrega($diaEntrega, $esCapital) : $ahora;

        $this->guardarCotizacion($cliente, $price, $precioFinal, $seguro, $localidad, $lado, $peso, substr($entrega, 0, 10), $cantidad);

        $rate = [
            'name'              => 'Caddy. ' . $price['Titulo'],
            'code'              => 'Simple', // tiene que coincidir con la opción activa del carrier en TN
            'price'             => $precioFinal,
            'price_merchant'    => $precioFinal,
            'currency'          => 'ARS',
            'type'              => 'ship',
            'min_delivery_date' => $ahora,
            'max_delivery_date' => $entrega,
            'phone_required'    => true,
            'reference'         => $price['Titulo'],
        ];

        $this->log('OK', $storeId, ['cp' => $cp, 'cant' => $cantidad, 'precio' => $precioFinal, 'tarifa' => $price['Titulo']]);
        return ['rates' => [$rate]];
    }

    /** +50% de la tarifa por cada unidad desde la 3ra */
    public static function porCantidad(float $base, int $cantidad): float
    {
        if ($cantidad <= 2) {
            return $base;
        }
        return $base + ($base * 0.5 * ($cantidad - 2));
    }

    /**
     * CP base de 4 dígitos + si es Córdoba.
     * CPA (X5000ABC): Córdoba si la letra es X. Numérico: 5000-5299 y 5800-5999
     * (evita 53xx La Rioja, 54xx San Juan, 55-57xx Mendoza/San Luis).
     */
    public static function parseCpCordoba(string $cpRaw): array
    {
        $up = strtoupper(trim($cpRaw));
        if ($up === '') {
            return [null, false];
        }
        if (preg_match('/^[A-Z]\d{4}[A-Z0-9]{3}$/', $up)) {
            return [(int)substr($up, 1, 4), $up[0] === 'X'];
        }
        $digits = preg_replace('/\D+/', '', $up);
        if (strlen($digits) < 4) {
            return [null, false];
        }
        $base = (int)substr($digits, 0, 4);
        return [$base, ($base >= 5000 && $base <= 5299) || ($base >= 5800 && $base <= 5999)];
    }

    public static function nombreDia(string $fecha): string
    {
        return ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][(int)date('w', strtotime($fecha))];
    }

    /** Fecha ISO de la próxima ocurrencia del día de salida (hoy solo si es Capital) */
    public static function proximaEntrega(string $dia, bool $esCapital): string
    {
        $en = [
            'Lunes' => 'Monday', 'Martes' => 'Tuesday', 'Miércoles' => 'Wednesday', 'Jueves' => 'Thursday',
            'Viernes' => 'Friday', 'Sábado' => 'Saturday', 'Domingo' => 'Sunday',
        ][$dia] ?? null;
        if (!$en) {
            return date('Y-m-d\TH:i:sO');
        }
        if ($en === date('l') && $esCapital) {
            return date('Y-m-d\TH:i:sO');
        }
        return date('Y-m-d\TH:i:sO', strtotime("next $en", strtotime(date('Y-m-d'))));
    }

    /** Hay 2 tiendas con dos clientes cargados: se prefiere el que tiene el carrier creado */
    private function clientePorTienda(int $storeId): ?array
    {
        if ($storeId <= 0) {
            return null;
        }
        $r = parent::obtenerDatos(
            "SELECT id, nombrecliente FROM Clientes WHERE user_id_tn = '" . $storeId . "'
             ORDER BY (carrier_id_tn IS NULL OR carrier_id_tn IN ('', '0')), id LIMIT 1"
        );
        return $r[0] ?? null;
    }

    private function tarifaFlex(): ?array
    {
        $r = parent::obtenerDatos("SELECT id, Titulo, PrecioVenta, Kilometros, Seguro, Codigo FROM Productos WHERE Codigo='" . self::CODIGO_FLEX . "'");
        return $r[0] ?? null;
    }

    private function tarifaGrilla(int $cp, float $volumen): ?array
    {
        $loc = $this->localidadPorCp($cp);
        if (!$loc || $loc['Km'] === null) {
            return null;
        }
        $r = parent::obtenerDatos(
            "SELECT id, Titulo, PrecioVenta, Kilometros, Seguro, Codigo FROM Productos
             WHERE Grupo='Web' AND m3 >= '" . $volumen . "' AND Kilometros >= '" . (float)$loc['Km'] . "'
             ORDER BY PrecioVenta ASC LIMIT 1"
        );
        return $r[0] ?? null;
    }

    private function localidadPorCp(int $cp): ?array
    {
        $r = parent::obtenerDatos("SELECT Km, Localidad, DiaSalida FROM Localidades WHERE Cp = '" . $cp . "' LIMIT 1");
        return $r[0] ?? null;
    }

    private function minimoSeguro(): float
    {
        $r = parent::obtenerDatos("SELECT Valor FROM Variables WHERE Nombre='MontoMinimoSeguro'");
        return (float)($r[0]['Valor'] ?? 0);
    }

    /** Nunca debe romper la cotización: si el INSERT falla, TN igual recibe la tarifa */
    private function guardarCotizacion(array $cliente, array $price, float $total, float $seguro, string $localidad, int $lado, float $peso, string $fechaEntrega, int $cantidad): void
    {
        try {
            parent::nonQueryId(
                "INSERT INTO Cotizaciones (Fecha, RazonSocial, NCliente, Cantidad, Precio, Total,
                   LocalidadDestino, Ancho, Alto, Largo, Peso, Tarifa, EntregaEn, Kilometros, FechaEntrega, Observaciones)
                 VALUES ('" . date('Y-m-d') . "', '" . $this->escapar($cliente['nombrecliente']) . "', '" . (int)$cliente['id'] . "',
                   '" . $cantidad . "', '" . ($total - $seguro) . "', '" . $total . "',
                   '" . $this->escapar($localidad) . "', '" . $lado . "', '" . $lado . "', '" . $lado . "', '" . $peso . "',
                   '" . $this->escapar($price['Titulo']) . "', 'Domicilio', '" . round((float)$price['Kilometros']) . "',
                   '" . $this->escapar($fechaEntrega) . "', 'Tienda Nube')"
            );
        } catch (Throwable $e) {
            $this->log('ERROR_COTIZACION', 0, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log fuera del document root (/home/dinter6/logs), sin datos personales:
     * la vieja guardaba el carrito completo (nombres, direcciones, teléfonos)
     * en un .log descargable públicamente.
     */
    private function log(string $evento, int $storeId, array $datos): void
    {
        $dir = dirname(__DIR__, 3) . '/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $linea = '[' . date('Y-m-d H:i:s') . "] $evento store=$storeId " . json_encode($datos, JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . '/tiendanube_rates.log', $linea . PHP_EOL, FILE_APPEND);
    }
}
