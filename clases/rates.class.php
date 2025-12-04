<?php
require_once "conexion/conexion.php";
require_once "clases/Token.php";
require_once "respuestas.class.php";

date_default_timezone_set('America/Argentina/Cordoba');

/**
 * Cotizaciones (GET), ahora usando Token centralizado (Bearer / ?token=)
 * y listo para PHP 8.
 */
class RatesV2 extends conexion
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

        // Etiqueta de servicio
        $this->servicio_label = isset($p['servicio']) ? 'Retiro y Entrega' : 'Solo Entrega';

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
        $usuarioId     = (int)($tokenInfo['UsuarioId'] ?? 0);
        $datos_cliente = $this->clienteOrigen($usuarioId);

        $price_label = (int)round($precioVenta);
        $total_label = (int)round($total);

        $id_quote = $this->insert_quote(
            $datos_cliente[0]['id']            ?? 0,
            $datos_cliente[0]['nombrecliente'] ?? '',
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

        $respuesta = $_resp->response;
        $respuesta['result'] = [
            'Id'              => $id_quote,
            'Servicio'        => $this->servicio_label,
            'Fecha_Entrega'   => $send_date,
            'Localidad'       => $citydestination,
            'Distancia'       => $distance_label,
            'Cantidad'        => $this->cantidad,
            'Valor_Declarado' => $valorDec,
            'Titulo'          => $price[0]['Titulo'],
            'Tarifa'          => $price_label,
            'Seguro'          => (int)round($surePrice),
            'Total'           => $total_label,
            'Codigo'          => $codigo,
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

    public function clienteOrigen($usuarioId)
    {
        $q1 = "SELECT NdeCliente FROM usuarios WHERE id = '" . $usuarioId . "'";
        $r1 = parent::obtenerDatos($q1);
        $nde = ($r1 && isset($r1[0]['NdeCliente'])) ? $r1[0]['NdeCliente'] : 0;
        $q2 = "SELECT nombrecliente,id,Direccion FROM Clientes WHERE id = '" . $nde . "'";
        return parent::obtenerDatos($q2);
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

    // Ya no lo usamos para cotizar, pero lo dejo por si lo reutilizás en otro lado
    private function buscarToken()
    {
        $q = "SELECT TokenId,UsuarioId,Estado FROM usuarios_token
          WHERE Token = '" . $this->token . "' AND Estado = 'Activo'";
        $resp = parent::obtenerDatos($q);
        return $resp ? $resp : 0;
    }
}
