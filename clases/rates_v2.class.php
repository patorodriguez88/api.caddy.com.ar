<?php
require_once "conexion/conexion.php";
require_once "respuestas.class.php";
require_once "pricing.class.php";
date_default_timezone_set('America/Argentina/Cordoba');

/**
 * GET-only de cotizaciones, compatible con PHP 5.6.
 * Mantiene tu lógica (token, FLEX, dimensiones, seguro, distancia, inserción en Cotizaciones).
 */
class RatesV2 extends conexion
{

    // Sin typed properties (compat 5.6)
    private $token = '';
    private $cp = '';
    private $length = 0.0;
    private $width  = 0.0;
    private $height = 0.0;
    private $weight = 0.0;

    private $localidad = '';
    private $servicio = 1;        // 1=Retiro y Entrega, 3=FLEX
    private $cantidad = 1;
    private $valorDeclarado = 0.0;
    private $flex = 0;

    private $servicio_label = 'Solo Entrega';
    private $valorDeclaradoMinimo = 0;

    public function cotizarGet($p)
    {
        $_resp = new respuestas();

        // Validación básica
        if (!isset($p['token']) || $p['token'] === '') {
            return array(401, $_resp->error_401());
        }
        foreach (array('cp', 'length', 'width', 'height', 'weight') as $k) {
            if (!isset($p[$k]) || $p[$k] === '') {
                return array(400, $_resp->error_400('Falta parámetro: ' . $k));
            }
        }

        // Normalizar entrada
        $this->token  = trim((string)$p['token']);
        $this->cp     = trim((string)$p['cp']);
        $this->length = (float)$p['length'];
        $this->width  = (float)$p['width'];
        $this->height = (float)$p['height'];
        $this->weight = (float)$p['weight'];

        $this->localidad      = isset($p['localidad']) ? (string)$p['localidad'] : '';
        $this->servicio       = isset($p['servicio']) ? (int)$p['servicio'] : 1;
        $this->cantidad       = isset($p['cantidad']) ? max(1, (int)$p['cantidad']) : 1;
        $this->valorDeclarado = isset($p['valorDeclarado']) ? (float)$p['valorDeclarado'] : 0.0;
        $this->flex           = isset($p['flex']) ? (int)$p['flex'] : 0;

        // Token válido?
        $tokenInfo = $this->buscarToken();
        if (!$tokenInfo) {
            return array(401, $_resp->error_401('El Token que envió es inválido o ha caducado'));
        }

        // Servicio label (igual a tu código)
        $this->servicio_label = isset($p['servicio']) ? 'Retiro y Entrega' : 'Solo Entrega';

        // Seguro mínimo
        $seguroMin = $this->sure();
        if (!$seguroMin) return array(400, $_resp->error_400('No se pudo obtener monto mínimo de seguro'));
        $this->valorDeclaradoMinimo = (int)$seguroMin[0]['Valor'];

        $esFlex = ($this->servicio === 3) || ($this->flex === 1);

        // Normalización de CP capital
        $cpEval = $this->cp;
        if ($this->cp >= '5000' && $this->cp <= '5023') {
            $cpEval = '5000';
        }

        // FLEX en capital -> tarifa fija
        if ($esFlex && ($this->cp >= '5000' && $this->cp <= '5023')) {

            $precio = $this->rate_flex();

            // if ($this->isErrorPrecio($precio)) {
            //     return array(400, $_resp->error_400('Error en la obtención de precio FLEX'));
            // }
            if ($precio === 4 || $this->isErrorPrecio($precio)) {
                return array(400, $_resp->error_400('Error en la obtención de precio FLEX'));
            }

            return $this->armarRespuestaOk($precio, $tokenInfo, true);
        }

        // NO FLEX (o FLEX fuera capital) -> validar dimensiones
        $dim = $this->calc_dim($this->length, $this->width, $this->height, $this->weight);
        if ($dim == 0) {
            return array(400, $_resp->error_400('Faltan datos del paquete'));
        }

        // Volumen fuera del rango cotizable en un solo bulto -> mensaje claro.
        $maxVol = $this->maxVolumenBulto();
        if ($maxVol > 0 && $dim > $maxVol) {
            return array(400, $_resp->error_400(
                'El volumen del bulto (' . round($dim) . ' cm3 = largo x ancho x alto) supera el maximo '
                . 'cotizable en un solo bulto (' . round($maxVol) . ' cm3). Divida el envio en varios '
                . 'bultos o use POST /rates_v3.'
            ));
        }

        // $precio = $this->rate($cpEval, $this->length, $this->width, $this->height, $this->weight);
        // if ($this->isErrorPrecio($precio)) {
        //     if ($precio === 4) return array(400, $_resp->error_400('Error en localidad'));
        //     return array(400, $_resp->error_400('Error en la obtención de precio'));
        // }
        $precio = $this->rate($cpEval, $this->length, $this->width, $this->height, $this->weight);

        // Si rate() devuelve código 4 → CP/localidad no encontrada
        if ($precio === 4) {
            return array(400, $_resp->error_400('Código postal no encontrado o sin tarifa configurada'));
        }

        // Cualquier otra cosa rara → error genérico de precio
        if ($this->isErrorPrecio($precio)) {
            return array(400, $_resp->error_400('Error en la obtención de precio'));
        }

        // OK
        return $this->armarRespuestaOk($precio, $tokenInfo, ($this->cp >= '5000' && $this->cp <= '5023'));
    }

    private function armarRespuestaOk($price, $tokenInfo, $esCapital)
    {
        $_resp = new respuestas();

        $sure_porc = isset($price[0]['Seguro']) ? (float)$price[0]['Seguro'] : 0.0;
        $valorDec  = $this->valorDeclarado;
        $surePrice = 0;

        if ($valorDec <= 0 || $valorDec <= $this->valorDeclaradoMinimo) {
            $valorDec  = $this->valorDeclaradoMinimo;
            $surePrice = 0;
        } else {
            $valorDec  = round($valorDec);
            $surePrice = round($valorDec) * $sure_porc / 100.0;
        }

        $km = (int)round($price[0]['Kilometros']);
        $distance_label = ($km === 500) ? 'Más de 50 km.' : ('Hasta ' . $km . ' km.');

        $precioVenta = (float)$price[0]['PrecioVenta'];
        $cant = max(1, (int)$this->cantidad);
        $tarifaTotal = Pricing::totalConDescuento(array_fill(0, $cant, $precioVenta));
        $total = $tarifaTotal + $surePrice;

        if ($esCapital) {
            $citydestination = 'Cordoba Capital';
            $hora = (int)date('G');
            $fecha = ($hora > 11) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
            $send_date = $this->get_nombre_dia($fecha);
            $codigo = isset($price[0]['Codigo']) ? $price[0]['Codigo'] : '';
        } else {
            $dateRow = $this->date_send($this->cp);
            $send_date = isset($dateRow[0]['DiaSalida']) ? $dateRow[0]['DiaSalida'] : $this->get_nombre_dia(date('Y-m-d'));
            $codigo = isset($dateRow[0]['Codigo']) ? $dateRow[0]['Codigo'] : (isset($price[0]['Codigo']) ? $price[0]['Codigo'] : '');
            $citydestination = $this->localidad;
            if ($citydestination === '' && isset($dateRow[0]['Localidad'])) {
                $citydestination = $dateRow[0]['Localidad'];
            }
        }

        $datos_cliente = $this->clienteOrigen($tokenInfo[0]['UsuarioId']);

        $price_label = (int)round($precioVenta);
        $total_label = (int)round($total);

        $id_quote = $this->insert_quote(
            isset($datos_cliente[0]['id']) ? $datos_cliente[0]['id'] : 0,
            isset($datos_cliente[0]['nombrecliente']) ? $datos_cliente[0]['nombrecliente'] : '',
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
        $respuesta['result'] = array(
            'Id'              => $id_quote,
            'Servicio'        => $this->servicio_label,
            'Fecha_Entrega'   => $send_date,
            'Localidad'       => $citydestination,
            'Distancia'       => $distance_label,
            'Cantidad'        => $this->cantidad,
            'Valor_Declarado' => $valorDec,
            'Titulo'          => $price[0]['Titulo'],
            'Tarifa'          => $price_label,
            'Seguro'          => $surePrice,
            'Total'           => $total_label,
            'Codigo'          => $price[0]['Codigo']
        );

        return array(200, $respuesta);
    }

    /* ===== Helpers basados en tu lógica ===== */

    private function isErrorPrecio($resp)
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
        if (!isset($row['id']) || !$row['id']) {
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

    public function get_nombre_dia($fecha)
    {
        $fechats = strtotime($fecha);
        switch (date('w', $fechats)) {
            case 0:
                return 'Domingo';
            case 1:
                return 'Lunes';
            case 2:
                return 'Martes';
            case 3:
                return 'Miércoles';
            case 4:
                return 'Jueves';
            case 5:
                return 'Viernes';
            case 6:
                return 'Sábado';
        }
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
        $Total = Pricing::totalConDescuento(array_fill(0, max(1, (int)$this->cantidad), (float)$precio));

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

    // Volumen máximo (cm³) que la grilla contempla en un solo bulto.
    // Si la consulta falla devuelve 0 y el llamador omite el chequeo.
    public function maxVolumenBulto()
    {
        $resp = parent::obtenerDatos("SELECT MAX(m3) AS m FROM Productos WHERE Grupo='Web'");
        return ($resp && isset($resp[0]['m'])) ? (float)$resp[0]['m'] : 0.0;
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

    private function buscarToken()
    {
        $q = "SELECT TokenId,UsuarioId,Estado FROM usuarios_token
          WHERE Token = '" . parent::escapar($this->token) . "' AND Estado = 'Activo'";
        $resp = parent::obtenerDatos($q);
        return $resp ? $resp : 0;
    }
}
