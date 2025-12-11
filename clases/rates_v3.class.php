<?php

/**
 * Nuevo método unificado de cotización.
 *
 * - Si recibe 'bultos' => cotiza múltiples bultos (dimensión y valor declarado agregados).
 * - Si NO recibe 'bultos' => delega en cotizarGet() (modo legacy GET).
 *
 * Formato esperado con bultos:
 * [
 * 'cp' => '5023',
 * 'localidad' => 'Villa Allende',
 * 'servicio' => 0,
 * 'flex' => 0,
 * 'bultos' => [
 * ['length'=>0.4,'width'=>0.3,'height'=>0.2,'weight'=>2.5,'valorDeclarado'=>10000],
 * ['length'=>0.5,'width'=>0.3,'height'=>0.25,'weight'=>3.2,'valorDeclarado'=>15000]
 * ]
 * ]
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once "conexion/conexion.php";
require_once "token.class.php";
require_once "respuestas.class.php";

date_default_timezone_set('America/Argentina/Cordoba');

/**
 * Cotizaciones (GET), ahora usando Token centralizado (Bearer / ?token=)
 * y listo para PHP 8.
 */

class Rates extends conexion
{
    private string $cp = '';
    private float  $length = 0.0;
    private float  $width  = 0.0;
    private float  $height = 0.0;
    private float  $weight = 0.0;

    private string $localidad = '';
    private int    $servicio  = 1;   // 1=Retiro y Entrega, 3=FLEX
    private int    $cantidad  = 1;
    private float  $valorDeclarado = 0.0;
    private int    $flex = 0;

    private string $servicio_label = 'Solo Entrega';
    private int    $valorDeclaradoMinimo = 0;

    /**
     * Recibe los parámetros ya parseados (normalmente $_GET)
     * y devuelve [http_code, array_respuesta]
     */
    public function cotizarGet(array $p): array
    {
        $_resp = new respuestas();

        /* ==========================
         * 1) TOKEN (Bearer o ?token=)
         * ========================== */
        $token = Token::obtenerToken();

        // Backwards compatible: si viene en $p['token'], lo usamos como último recurso
        if (!$token && !empty($p['token'])) {
            $token = trim((string)$p['token']);
        }

        if (!$token) {
            return [
                401,
                $_resp->error_401('Debe enviar token (Bearer o query "token")')
            ];
        }

        // Validar token contra la BD (usa la conexión de esta clase)
        $tokenInfo = Token::validar($token, $this);
        if (!$tokenInfo) {
            return [
                401,
                $_resp->error_401('El Token que envió es inválido o ha caducado')
            ];
        }

        /* ==========================
         * 2) Validar parámetros básicos
         * ========================== */
        foreach (['cp', 'length', 'width', 'height', 'weight'] as $k) {
            if (!isset($p[$k]) || $p[$k] === '') {
                return [
                    400,
                    $_resp->error_400('Falta parámetro: ' . $k)
                ];
            }
        }

        /* ==========================
         * 3) Normalizar entrada
         * ========================== */
        $this->cp     = trim((string)$p['cp']);
        $this->length = (float)$p['length'];
        $this->width  = (float)$p['width'];
        $this->height = (float)$p['height'];
        $this->weight = (float)$p['weight'];

        $this->localidad      = isset($p['localidad'])      ? (string)$p['localidad']      : '';
        $this->servicio       = isset($p['servicio'])       ? (int)$p['servicio']          : 1;
        $this->cantidad       = isset($p['cantidad'])       ? max(1, (int)$p['cantidad'])  : 1;
        $this->valorDeclarado = isset($p['valorDeclarado']) ? (float)$p['valorDeclarado']  : 0.0;
        $this->flex           = isset($p['flex'])           ? (int)$p['flex']              : 0;

        // Etiqueta de servicio según código
        if (isset($p['servicio'])) {
            $this->servicio = (int)$p['servicio'];
        } else {
            $this->servicio = 1; // por defecto
        }

        if ($this->servicio === 1) {
            $this->servicio_label = 'Retiro y Entrega';
        } elseif ($this->servicio === 3) {
            $this->servicio_label = 'Retiro y Entrega (Flex)';
        } else {
            $this->servicio_label = 'Solo Entrega';
        }
        /* ==========================
         * 4) Seguro mínimo
         * ========================== */
        $seguroMin = $this->sure();
        if (!$seguroMin) {
            return [
                400,
                $_resp->error_400('No se pudo obtener monto mínimo de seguro')
            ];
        }
        $this->valorDeclaradoMinimo = (int)$seguroMin[0]['Valor'];

        $esFlex = ($this->servicio === 3) || ($this->flex === 1);

        // Normalización de CP capital
        $cpEval = $this->cp;
        if ($this->cp >= '5000' && $this->cp <= '5023') {
            $cpEval = '5000';
        }

        /* ==========================
         * 5) FLEX en capital -> tarifa fija
         * ========================== */
        if ($esFlex && ($this->cp >= '5000' && $this->cp <= '5023')) {

            $precio = $this->rate_flex();

            // Si rate_flex devuelve 4 o un array inválido → error
            if ($precio === 4 || $this->isErrorPrecio($precio)) {
                return [
                    400,
                    $_resp->error_400('Error en la obtención de precio FLEX')
                ];
            }

            return $this->armarRespuestaOk($precio, $tokenInfo, true);
        }

        /* ==========================
         * 6) NO FLEX (o FLEX fuera capital) → validar dimensiones
         * ========================== */
        $dim = $this->calc_dim($this->length, $this->width, $this->height, $this->weight);
        if ($dim == 0) {
            return [
                400,
                $_resp->error_400('Faltan datos del paquete')
            ];
        }

        // Tarifa general
        $precio = $this->rate($cpEval, $this->length, $this->width, $this->height, $this->weight);

        // Código 4 → CP/localidad no encontrada
        if ($precio === 4) {
            return [
                400,
                $_resp->error_400('Código postal no encontrado o sin tarifa configurada')
            ];
        }

        // Cualquier otra cosa rara → error genérico de precio
        if ($this->isErrorPrecio($precio)) {
            return [
                400,
                $_resp->error_400('Error en la obtención de precio')
            ];
        }

        // OK
        $esCapital = ($this->cp >= '5000' && $this->cp <= '5023');
        return $this->armarRespuestaOk($precio, $tokenInfo, $esCapital);
    }

    /**
     * Arma respuesta exitosa y registra en Cotizaciones
     */
    private function armarRespuestaOk(array $price, array $tokenInfo, bool $esCapital): array
    {
        $_resp = new respuestas();

        $sure_porc = isset($price[0]['Seguro']) ? (float)$price[0]['Seguro'] : 0.0;
        $valorDec  = $this->valorDeclarado;
        $surePrice = 0.0;

        if ($valorDec <= 0 || $valorDec <= $this->valorDeclaradoMinimo) {
            $valorDec  = $this->valorDeclaradoMinimo;
            $surePrice = 0.0;
        } else {
            $valorDec  = (float)round($valorDec);
            $surePrice = $valorDec * $sure_porc / 100.0;
        }

        $km = (int)round($price[0]['Kilometros']);
        $distance_label = ($km === 500) ? 'Más de 50 km.' : ('Hasta ' . $km . ' km.');

        $precioVenta = (float)$price[0]['PrecioVenta'];
        $total       = ($this->cantidad * $precioVenta) + $surePrice;

        // Labels redondeados (siempre)
        $price_label = (int)round($precioVenta);
        $total_label = (int)round($total);

        if ($esCapital) {
            $citydestination = 'Cordoba Capital';
            $hora  = (int)date('G');
            $fecha = ($hora > 11) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
            $send_date = $this->get_nombre_dia($fecha);
            $codigo    = $price[0]['Codigo'] ?? '';
        } else {
            $dateRow   = $this->date_send($this->cp);
            $send_date = $dateRow[0]['DiaSalida'] ?? $this->get_nombre_dia(date('Y-m-d'));
            $codigo    = $dateRow[0]['Codigo'] ?? ($price[0]['Codigo'] ?? '');
            $citydestination = $this->localidad;
            if ($citydestination === '' && isset($dateRow[0]['Localidad'])) {
                $citydestination = $dateRow[0]['Localidad'];
            }
        }

        // Cliente origen según UsuarioId del token
        $usuarioId = (int)($tokenInfo['UsuarioId'] ?? 0);
        $cliente   = $this->clienteOrigen($usuarioId);

        // Por defecto: no insertamos nada
        $id_quote = 0;

        if ($cliente !== null) {
            $clienteId   = (int)($cliente['id'] ?? 0);
            $clienteName = (string)($cliente['nombrecliente'] ?? '');

            // Solo insertamos si tenemos id y nombre
            if ($clienteId > 0 && $clienteName !== '') {
                $id_quote = $this->insert_quote(
                    $clienteId,
                    $clienteName,
                    $price[0]['Titulo'],
                    $price_label,
                    $citydestination,
                    $this->length,
                    $this->width,
                    $this->height,
                    $this->weight,
                    $km,
                    $send_date
                );
            }
        }

        $tarifaTotal = (float)$this->cantidad * $precioVenta;

        $tarifa_label = (int)round($tarifaTotal);
        $seguro_label = (int)round($surePrice);
        $total_label  = (int)round($tarifaTotal + $surePrice);

        $respuesta = $_resp->response;
        $respuesta['result'] = [
            'Id'                    => $id_quote,
            'Servicio'              => $this->servicio_label,
            'Fecha_Entrega'         => $send_date,
            'Localidad'             => $citydestination,
            'Distancia'             => $distance_label,
            'Cantidad'              => $this->cantidad,
            'Valor_Declarado_Total' => (int)round($valorDec),   // si querés dejar claro que es total
            'Titulo'                => $price[0]['Titulo'],

            // 🔹 Aquí la clave:
            'Tarifa'                => $tarifa_label,   // TOTAL logística (sin seguro)
            'Seguro'                => $seguro_label,   // TOTAL seguro
            'Total'                 => $total_label,    // Tarifa + Seguro

            'Codigo'                => $codigo,
        ];

        return [200, $respuesta];
    }

    /* ===== Helpers basados en tu lógica ===== */

    private function isErrorPrecio($resp): bool
    {
        // Si no es array (ej: 4, 0, false, null) → error
        if (!is_array($resp)) {
            return true;
        }

        // Si no tiene índice 0 → error
        if (!isset($resp[0]) || !is_array($resp[0])) {
            return true;
        }

        $row = $resp[0];

        // Sin id → error
        if (empty($row['id'])) {
            return true;
        }

        // Sin PrecioVenta → error
        if (!isset($row['PrecioVenta']) || $row['PrecioVenta'] === null) {
            return true;
        }

        // PrecioVenta <= 0 → lo consideramos sin tarifa válida
        if ((float)$row['PrecioVenta'] <= 0) {
            return true;
        }

        return false;
    }

    public function get_nombre_dia(string $fecha): string
    {
        $fechats = strtotime($fecha);
        return match (date('w', $fechats)) {
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            default => 'Desconocido',
        };
    }

    public function calc_dim($length, $width, $height, $weight)
    {
        if ($length !== '' && $width !== '' && $height !== '') {
            return (float)$length * (float)$width * (float)$height;
        }
        return 0;
    }

    public function insert_quote($id, $nombre, $price_title, $precio, $citydestination, $length, $width, $height, $weight, $distance, $send_date)
    {
        $date  = date('Y-m-d');
        $Total = $this->cantidad * $precio;

        $sqlstr = "INSERT INTO `Cotizaciones`(`Fecha`,`RazonSocial`, `NCliente`, `Cantidad`,`Precio`,`Total`,
             `LocalidadDestino`,`Ancho`, `Alto`, `Largo`, `Peso`,`Tarifa`,`EntregaEn`,`Kilometros`,`FechaEntrega`) 
             VALUES ('" . $date . "','" . $nombre . "','" . $id . "','" . $this->cantidad . "','" . $precio . "','" . $Total . "',
             '" . $citydestination . "','" . $width . "','" . $height . "','" . $length . "','" . $weight . "','" . $price_title . "',
             'Domicilio','" . $distance . "','" . $send_date . "')";
        $resp = parent::nonQueryId($sqlstr);
        return $resp ? $resp : 0;
    }

    public function sure()
    {
        $query = "SELECT Valor FROM Variables WHERE Nombre='MontoMinimoSeguro'";
        $resp  = parent::obtenerDatos($query);
        return $resp ? $resp : 0;
    }

    public function rate_flex()
    {
        $query = "SELECT id,Titulo,PrecioVenta,Kilometros,Seguro,Codigo FROM Productos WHERE Codigo='183'";
        $resp  = parent::obtenerDatos($query);
        return $resp ? $resp : 4;
    }

    public function rate($codigopostal, $length, $width, $height, $weight)
    {
        if ($codigopostal >= '5000' && $codigopostal <= '5023') {
            $codigopostal = '5000';
        }

        $query_dist = "SELECT Km, Localidad FROM Localidades WHERE Cp='" . $codigopostal . "'";
        $resp_dist  = parent::obtenerDatos($query_dist);
        if (!$resp_dist || !isset($resp_dist[0]['Km'])) return 4;

        $dist = $resp_dist[0]['Km'];
        $dim  = (float)$length * (float)$width * (float)$height;

        $query = "SELECT id,Titulo,MIN(PrecioVenta) AS PrecioVenta,Kilometros,Seguro,Codigo
              FROM Productos
              WHERE Grupo='Web' AND m3>='" . $dim . "' AND Kilometros>='" . $dist . "'";
        $resp = parent::obtenerDatos($query);
        return $resp ? $resp : 4;
    }

    public function clienteOrigen(int $usuarioId): ?array
    {
        // 1) Buscamos NdeCliente del usuario
        $q1 = "SELECT NdeCliente 
           FROM usuarios 
           WHERE id = '" . $usuarioId . "' 
             AND Estado = 'Activo' 
             AND ACTIVO = 1 
             AND NIVEL = 4";
        $r1 = parent::obtenerDatos($q1);

        // Si no hay fila o no tiene NdeCliente -> no hay cliente asociado
        if (!$r1 || empty($r1[0]['NdeCliente'])) {
            return null;
        }

        $nde = $r1[0]['NdeCliente'];

        // 2) Buscamos el cliente origen real
        $q2 = "SELECT id, nombrecliente, Direccion 
           FROM Clientes 
           WHERE id = '" . $nde . "'";
        $r2 = parent::obtenerDatos($q2);

        if (!$r2 || empty($r2[0]['id'])) {
            return null;
        }

        // Devolvemos UNA sola fila
        return $r2[0];
    }

    public function date_send($codigopostal)
    {
        $Localidad = $codigopostal;
        if ($codigopostal >= '5000' && $codigopostal <= '5023') {
            $Localidad = '5000';
        }
        $query = "SELECT DiaSalida,Localidad, Cp AS Codigo FROM Localidades WHERE Cp = '" . $Localidad . "'";
        return parent::obtenerDatos($query);
    }


    public function cotizar(array $p): array
    {
        $_resp = new respuestas();

        // Si no hay bultos, usamos el flujo clásico (GET compat)
        if (!isset($p['bultos']) || !is_array($p['bultos']) || count($p['bultos']) === 0) {
            return $this->cotizarGet($p);
        }

        /* ==========================
    * 1) TOKEN (Bearer o ?token=)
    * ========================== */
        $token = Token::obtenerToken();

        // Backwards compatible: si viene en $p['token'], lo usamos como último recurso
        if (!$token && !empty($p['token'])) {
            $token = trim((string)$p['token']);
        }

        if (!$token) {
            return [
                401,
                $_resp->error_401('Debe enviar token (Bearer o query "token")')
            ];
        }

        // Validar token contra la BD (usa la conexión de esta clase)
        $tokenInfo = Token::validar($token, $this);
        if (!$tokenInfo) {
            return [
                401,
                $_resp->error_401('El Token que envió es inválido o ha caducado')
            ];
        }

        /* ==========================
    * 2) Validar parámetros básicos
    * ========================== */
        if (!isset($p['cp']) || $p['cp'] === '') {
            return [
                400,
                $_resp->error_400('Falta parámetro: cp')
            ];
        }

        $bultos = $p['bultos'];
        if (!is_array($bultos) || count($bultos) === 0) {
            return [
                400,
                $_resp->error_400('Debe enviar al menos un bulto')
            ];
        }

        // Validar campos por bulto
        $totalVolumen = 0.0;
        $totalPeso = 0.0;
        $totalValorDec = 0.0;

        foreach ($bultos as $idx => $b) {
            $idxMsg = ' (bulto índice ' . $idx . ')';

            foreach (['length', 'width', 'height', 'weight'] as $k) {
                if (!isset($b[$k]) || $b[$k] === '') {
                    return [
                        400,
                        $_resp->error_400('Falta parámetro: ' . $k . $idxMsg)
                    ];
                }
            }

            $l = (float)$b['length'];
            $w = (float)$b['width'];
            $h = (float)$b['height'];
            $peso = (float)$b['weight'];

            $totalVolumen += $this->calc_dim($l, $w, $h, $peso);
            $totalPeso += $peso;
            $totalValorDec += isset($b['valorDeclarado']) ? (float)$b['valorDeclarado'] : 0.0;
        }

        /* ==========================
    * 3) Normalizar entrada agregada
    * ========================== */
        $this->cp = trim((string)$p['cp']);
        $this->localidad = isset($p['localidad']) ? (string)$p['localidad'] : '';
        $this->servicio = isset($p['servicio']) ? (int)$p['servicio'] : 1;
        $this->flex = isset($p['flex']) ? (int)$p['flex'] : 0;

        // Cantidad = cantidad de bultos enviados
        $this->cantidad = count($bultos);
        $this->valorDeclarado = $totalValorDec;

        // Representamos volumen total como largo = volumen, ancho=1, alto=1
        // (la tarifa usa m3, así que lo importante es el producto)
        $this->length = $totalVolumen;
        $this->width = 1.0;
        $this->height = 1.0;
        $this->weight = $totalPeso;

        // Etiqueta de servicio según código (igual que en cotizarGet)
        if ($this->servicio === 1) {
            $this->servicio_label = 'Retiro y Entrega';
        } elseif ($this->servicio === 3) {
            $this->servicio_label = 'Retiro y Entrega (Flex)';
        } else {
            $this->servicio_label = 'Solo Entrega';
        }

        /* ==========================
    * 4) Seguro mínimo
    * ========================== */
        $seguroMin = $this->sure();
        if (!$seguroMin) {
            return [
                400,
                $_resp->error_400('No se pudo obtener monto mínimo de seguro')
            ];
        }
        $this->valorDeclaradoMinimo = (int)$seguroMin[0]['Valor'];

        $esFlex = ($this->servicio === 3) || ($this->flex === 1);

        // Normalización de CP capital
        $cpEval = $this->cp;
        if ($this->cp >= '5000' && $this->cp <= '5023') {
            $cpEval = '5000';
        }

        /*==========================* 5) FLEX en capital -> tarifa fija
        * ========================== */
        if ($esFlex && ($this->cp >= '5000' && $this->cp <= '5023')) {

            $precio = $this->rate_flex();

            if ($precio === 4 || $this->isErrorPrecio($precio)) {
                return [
                    400,
                    $_resp->error_400('Error en la obtención de precio FLEX')
                ];
            }

            return $this->armarRespuestaOk($precio, $tokenInfo, true);
        }

        /* ==========================
            * 6) NO FLEX (o FLEX fuera capital) → validar volumen total
            * ========================== */
        $dim = $this->calc_dim($this->length, $this->width, $this->height, $this->weight);
        if ($dim == 0) {
            return [
                400,
                $_resp->error_400('Faltan datos del paquete (volumen total 0)')
            ];
        }

        // Tarifa general usando volumen total
        $precio = $this->rate($cpEval, $this->length, $this->width, $this->height, $this->weight);

        if ($precio === 4) {
            return [
                400,
                $_resp->error_400('Código postal no encontrado o sin tarifa configurada')
            ];
        }

        if ($this->isErrorPrecio($precio)) {
            return [
                400,
                $_resp->error_400('Error en la obtención de precio')
            ];
        }

        $esCapital = ($this->cp >= '5000' && $this->cp <= '5023');
        return $this->armarRespuestaOk($precio, $tokenInfo, $esCapital);
    }
}
