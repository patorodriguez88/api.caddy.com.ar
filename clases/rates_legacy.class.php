<?php
/**
 * Cotización LEGACY: copia de RatesV3 de la API vieja (www.caddy.com.ar/api/tarifas/cotizar)
 * para que los clientes que la usan (VENEX) puedan pasar a api.caddy.com.ar/api/tarifas/cotizar
 * sin ningún cambio de precio ni de formato de respuesta. Verificado contra la vieja.
 *
 * NO es el cotizador actual (ese es clases/rates.class.php, /api/rates): difiere en el
 * seguro con valor declarado bajo, en el campo Tarifa con varios bultos y en servicio=3
 * sin flex=1. Unificarlos es una decisión comercial, no técnica.
 *
 * Cambios respecto del original (sin efecto en las respuestas):
 * - rutas de require; clase renombrada a RatesLegacy;
 * - token y textos escapados en SQL (antes iban crudos);
 * - si el INSERT de Cotizaciones falla, la cotización sale igual (en PHP 5.6 fallaba en
 *   silencio; en PHP 8 mysqli tira excepción y cortaba la respuesta).
 */
require_once __DIR__ . "/../conexion/conexion.php";
require_once __DIR__ . "/respuestas.class.php";
date_default_timezone_set('America/Argentina/Cordoba');

/**
 * GET-only de cotizaciones, compatible con PHP 5.6.
 * - Si es FLEX (servicio=3 o flex=1): NO exige dimensiones, NO las usa.
 * - Si NO es FLEX: exige dimensiones, calcula m3 y busca tarifa por distancia.
 * - Corrige SQL: nada de MIN(PrecioVenta) mezclando columnas.
 */
class RatesLegacy extends conexion
{
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
    private function tarifaPorCantidad($precioUnitario, $cantidad)
    {
        $cantidad = (int)$cantidad;
        $precioUnitario = (float)$precioUnitario;

        if ($cantidad <= 1) {
            return $precioUnitario;         // 1 x 100%
        }

        if ($cantidad == 2) {
            return $precioUnitario;         // 2° bonificado => pagás 1
        }

        // desde 3: 1 tarifa + 50% por cada extra desde el tercero
        $factor = 1 + 0.5 * ($cantidad - 2);
        return $precioUnitario * $factor;
    }
    private function obtenerMaxM3Web()
    {
        $q = "SELECT MAX(m3) AS max_m3 FROM Productos WHERE Grupo='Web'";
        $r = parent::obtenerDatos($q);
        if ($r && isset($r[0]['max_m3']) && $r[0]['max_m3'] !== null) {
            return (float)$r[0]['max_m3'];
        }
        return 0.0;
    }
    public function cotizarGet($p)
    {
        $_resp = new respuestas();

        // =========================
        // Validación mínima
        // =========================
        if (!isset($p['token']) || $p['token'] === '') {
            return array(401, $_resp->error_401());
        }
        if (!isset($p['cp']) || $p['cp'] === '') {
            return array(400, $_resp->error_400('Falta parámetro: cp'));
        }

        // =========================
        // Normalizar lo necesario para decidir FLEX
        // =========================
        $this->token    = trim((string)$p['token']);
        $this->cp       = trim((string)$p['cp']);
        $this->servicio = isset($p['servicio']) ? (int)$p['servicio'] : 1;
        $this->flex     = isset($p['flex']) ? (int)$p['flex'] : 0;

        $esFlex = ($this->servicio === 3) || ($this->flex === 1);
        // =========================
        // Regla FLEX: CP válido solo Córdoba Capital
        // =========================
        if ($esFlex) {
            if ($this->cp < '5000' || $this->cp > '5023') {
                return array(
                    400,
                    $_resp->error_400('Zona Flex mal definida. El código postal debe ser de Córdoba Capital (5000-5023)')
                );
            }
        }
        // Si NO es FLEX, exigir dimensiones
        if (!$esFlex) {
            foreach (array('length', 'width', 'height', 'weight') as $k) {
                if (!isset($p[$k]) || $p[$k] === '') {
                    return array(400, $_resp->error_400('Falta parámetro: ' . $k));
                }
            }
        }

        // =========================
        // Normalizar el resto
        // =========================
        // Para FLEX puede venir sin dimensiones => 0
        $this->length = isset($p['length']) ? (float)$p['length'] : 0.0;
        $this->width  = isset($p['width'])  ? (float)$p['width']  : 0.0;
        $this->height = isset($p['height']) ? (float)$p['height'] : 0.0;
        $this->weight = isset($p['weight']) ? (float)$p['weight'] : 0.0;

        $this->localidad      = isset($p['localidad']) ? (string)$p['localidad'] : '';
        $this->cantidad       = isset($p['cantidad']) ? max(1, (int)$p['cantidad']) : 1;
        $this->valorDeclarado = isset($p['valorDeclarado']) ? (float)$p['valorDeclarado'] : 0.0;

        // Label servicio (ahora por valor real)
        $this->servicio_label = ($this->servicio === 1) ? 'Retiro y Entrega' : 'Solo Entrega';
        if ($esFlex) {
            // Si querés mostrar FLEX explícito, podés cambiarlo a 'FLEX'
            // $this->servicio_label = 'FLEX';
            $this->servicio_label = 'Retiro y Entrega';
        }

        // Token válido?
        $tokenInfo = $this->buscarToken();
        if (!$tokenInfo) {
            return array(401, $_resp->error_401('El Token que envió es inválido o ha caducado'));
        }

        // Seguro mínimo
        $seguroMin = $this->sure();
        if (!$seguroMin) {
            return array(400, $_resp->error_400('No se pudo obtener monto mínimo de seguro'));
        }
        $this->valorDeclaradoMinimo = (int)$seguroMin[0]['Valor'];

        // Normalización CP capital (para consultas a Localidades, etc.)
        $esCapital = ($this->cp >= '5000' && $this->cp <= '5023');
        $cpEval = $this->cp;
        if ($esCapital) {
            $cpEval = '5000';
        }

        // =========================
        // Si es FLEX: NO usamos dimensiones
        // =========================
        if ($esFlex) {
            $precio = $this->rate_flex();

            if ($precio === 4 || $this->isErrorPrecio($precio)) {
                return array(400, $_resp->error_400('Error en la obtención de precio FLEX'));
            }

            return $this->armarRespuestaOk($precio, $tokenInfo, $esCapital);
        }

        // =========================
        // NO FLEX: validar dimensiones
        // =========================
        $dim = $this->calc_dim($this->length, $this->width, $this->height, $this->weight);
        if ($dim == 0) {
            return array(400, $_resp->error_400('Faltan datos del paquete'));
        }

        $precio = $this->rate($cpEval, $this->length, $this->width, $this->height, $this->weight);

        // if ($precio === 4) {
        //     return array(400, $_resp->error_400('Código postal no encontrado o sin tarifa configurada'));
        // }
        if (is_array($precio) && isset($precio['error']) && $precio['error'] == 4) {

            if ($precio['motivo'] === 'CP_NO_ENCONTRADO') {
                return array(400, $_resp->error_400('Código postal no encontrado'));
            }

            if ($precio['motivo'] === 'VOLUMEN_EXCEDE_MAXIMO') {
                return array(400, $_resp->error_400(
                    'Dimensiones exceden el máximo permitido. Volumen=' . $precio['dim'] . ' Máximo=' . $precio['max_m3']
                ));
            }

            if ($precio['motivo'] === 'SIN_TARIFA_PARA_DISTANCIA') {
                return array(400, $_resp->error_400(
                    'Sin tarifa para la distancia. Distancia=' . $precio['dist'] . ' Volumen=' . $precio['dim']
                ));
            }

            return array(400, $_resp->error_400('No se pudo cotizar'));
        }

        // (por compatibilidad con tu lógica vieja si alguien devuelve 4 “puro”)
        if ($precio === 4) {
            return array(400, $_resp->error_400('No se pudo cotizar'));
        }

        if ($this->isErrorPrecio($precio)) {
            return array(400, $_resp->error_400('Error en la obtención de precio'));
        }

        return $this->armarRespuestaOk($precio, $tokenInfo, $esCapital);
    }

    // private function armarRespuestaOk($price, $tokenInfo, $esCapital)
    // {
    //     $_resp = new respuestas();

    //     $sure_porc = isset($price[0]['Seguro']) ? (float)$price[0]['Seguro'] : 0.0;

    //     // Seguro: mínimo + % si supera mínimo
    //     $valorDec  = $this->valorDeclarado;
    //     $surePrice = 0;

    //     if ($valorDec <= 0 || $valorDec <= $this->valorDeclaradoMinimo) {
    //         $valorDec  = $this->valorDeclaradoMinimo;
    //         $surePrice = 0;
    //     } else {
    //         $valorDec  = round($valorDec);

    //         // Guard-rail anti “100%”
    //         if ($sure_porc > 10) { // umbral razonable
    //             error_log("Seguro porc inválido detectado. Codigo=" . $price[0]['Codigo'] . " Seguro=" . $sure_porc);
    //             $sure_porc = 0;
    //         }

    //         $surePrice = round($valorDec) * $sure_porc / 100.0;
    //     }

    //     $km = (int)round($price[0]['Kilometros']);
    //     $distance_label = ($km === 500) ? 'Más de 50 km.' : ('Hasta ' . $km . ' km.');

    //     // $precioVenta = (float)$price[0]['PrecioVenta'];
    //     // $total = ($this->cantidad * $precioVenta) + $surePrice;
    //     $precioVenta = (float)$price[0]['PrecioVenta'];

    //     // Tarifa según cantidad (bonificación + 50% desde el 3ro)
    //     $tarifaSinSeguro = $this->tarifaPorCantidad($precioVenta, $this->cantidad);

    //     $total = $tarifaSinSeguro + $surePrice;

    //     if ($esCapital) {
    //         $citydestination = 'Cordoba Capital';
    //         $hora = (int)date('G');
    //         $fecha = ($hora > 11) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
    //         $send_date = $this->get_nombre_dia($fecha);
    //         $codigo = isset($price[0]['Codigo']) ? $price[0]['Codigo'] : '';
    //     } else {
    //         $dateRow = $this->date_send($this->cp);
    //         $send_date = isset($dateRow[0]['DiaSalida']) ? $dateRow[0]['DiaSalida'] : $this->get_nombre_dia(date('Y-m-d'));
    //         $codigo = isset($dateRow[0]['Codigo']) ? $dateRow[0]['Codigo'] : (isset($price[0]['Codigo']) ? $price[0]['Codigo'] : '');

    //         $citydestination = $this->localidad;
    //         if ($citydestination === '' && isset($dateRow[0]['Localidad'])) {
    //             $citydestination = $dateRow[0]['Localidad'];
    //         }
    //     }

    //     $datos_cliente = $this->clienteOrigen($tokenInfo[0]['UsuarioId']);

    //     $clienteSure = 0;
    //     if ($datos_cliente && isset($datos_cliente[0]['Sure'])) {
    //         $clienteSure = (float)$datos_cliente[0]['Sure'];
    //     }


    //     $price_label = (int)round($precioVenta);
    //     $total_label = (int)round($total);

    //     $id_quote = $this->insert_quote(
    //         isset($datos_cliente[0]['id']) ? $datos_cliente[0]['id'] : 0,
    //         isset($datos_cliente[0]['nombrecliente']) ? $datos_cliente[0]['nombrecliente'] : '',
    //         $price[0]['Titulo'],
    //         $price_label,
    //         $citydestination,
    //         $this->length,
    //         $this->width,
    //         $this->height,
    //         $this->weight,
    //         $km,
    //         $send_date
    //     );

    //     $respuesta = $_resp->response;
    //     $respuesta['result'] = array(
    //         'Id'              => $id_quote,
    //         'Servicio'        => $this->servicio_label,
    //         'Fecha_Entrega'   => $send_date,
    //         'Localidad'       => $citydestination,
    //         'Distancia'       => $distance_label,
    //         'Cantidad'        => $this->cantidad,
    //         'Valor_Declarado' => (int)$valorDec,
    //         'Titulo'          => $price[0]['Titulo'],
    //         'Tarifa'          => $price_label,
    //         'Seguro'          => (int)round($surePrice),
    //         'Total'           => $total_label,
    //         'Codigo'          => $price[0]['Codigo']
    //     );

    //     return array(200, $respuesta);
    // }
    private function armarRespuestaOk($price, $tokenInfo, $esCapital)
    {
        $_resp = new respuestas();

        // =========================
        // 1) Datos del cliente + flag de seguro
        // =========================
        $datos_cliente = $this->clienteOrigen($tokenInfo[0]['UsuarioId']);

        $clienteSure = 0;
        if ($datos_cliente && isset($datos_cliente[0]['Sure'])) {
            $clienteSure = (float)$datos_cliente[0]['Sure'];
        }

        // =========================
        // 2) Datos del producto (porcentaje de seguro)
        // =========================
        $sure_porc = isset($price[0]['Seguro']) ? (float)$price[0]['Seguro'] : 0.0;

        // =========================
        // 3) Cálculo de seguro
        //    - Si clienteSure = 0 => NO seguro
        // =========================
        $valorDec  = $this->valorDeclarado;
        $surePrice = 0;

        if ($clienteSure <= 0 || $sure_porc <= 0) {
            // Cliente sin seguro (o producto sin %) => no se calcula
            $surePrice = 0;
            // opcional: normalizar valor declarado
            $valorDec = ($valorDec > 0) ? round($valorDec) : 0;
        } else {

            // Seguro: mínimo + % si supera mínimo
            if ($valorDec <= 0 || $valorDec <= $this->valorDeclaradoMinimo) {
                $valorDec  = $this->valorDeclaradoMinimo;
                $surePrice = 0;
            } else {
                $valorDec  = round($valorDec);

                // Guard-rail anti “100%”
                if ($sure_porc > 10) {
                    error_log("Seguro porc inválido detectado. Codigo=" . $price[0]['Codigo'] . " Seguro=" . $sure_porc);
                    $sure_porc = 0;
                }

                $surePrice = round($valorDec) * $sure_porc / 100.0;
            }
        }

        // =========================
        // 4) Resto tal cual lo tenías
        // =========================
        $km = (int)round($price[0]['Kilometros']);
        $distance_label = ($km === 500) ? 'Más de 50 km.' : ('Hasta ' . $km . ' km.');

        $precioVenta = (float)$price[0]['PrecioVenta'];
        $tarifaSinSeguro = $this->tarifaPorCantidad($precioVenta, $this->cantidad);
        $total = $tarifaSinSeguro + $surePrice;

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
            'Valor_Declarado' => (int)$valorDec,
            'Titulo'          => $price[0]['Titulo'],
            'Tarifa'          => $price_label,
            'Seguro'          => (int)round($surePrice),
            'Total'           => $total_label,
            'Codigo'          => $price[0]['Codigo']
        );

        return array(200, $respuesta);
    }
    /* ===== Helpers ===== */

    private function isErrorPrecio($resp)
    {
        if (!is_array($resp)) return true;
        if (!isset($resp[0]) || !is_array($resp[0])) return true;

        $row = $resp[0];

        if (!isset($row['id']) || !$row['id']) return true;
        if (!isset($row['PrecioVenta']) || $row['PrecioVenta'] === null) return true;
        if ((float)$row['PrecioVenta'] <= 0) return true;

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
        // $Total = $this->cantidad * $precio;
        $Total = $this->tarifaPorCantidad($precio, $this->cantidad);

        $sqlstr = "INSERT INTO `Cotizaciones`(`Fecha`,`RazonSocial`, `NCliente`, `Cantidad`,`Precio`,`Total`,
             `LocalidadDestino`,`Ancho`, `Alto`, `Largo`, `Peso`,`Tarifa`,`EntregaEn`,`Kilometros`,`FechaEntrega`) 
             VALUES ('" . $date . "','" . parent::escapar($nombre) . "','" . parent::escapar($id) . "','" . $this->cantidad . "','" . $precio . "','" . $Total . "',
             '" . parent::escapar($citydestination) . "','" . parent::escapar($width) . "','" . parent::escapar($height) . "','" . parent::escapar($length) . "','" . parent::escapar($weight) . "','" . parent::escapar($price_title) . "',
             'Domicilio','" . parent::escapar($distance) . "','" . parent::escapar($send_date) . "')";
        try {
            $resp = parent::nonQueryId($sqlstr);
        } catch (Throwable $e) {
            $resp = 0;
        }
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
        // IMPORTANTE: dejalo exacto como lo tenés en BD (por tu screenshot es 0000000183)
        $query = "SELECT id,Titulo,PrecioVenta,Kilometros,Seguro,Codigo
                  FROM Productos
                  WHERE Codigo='0000000183'
                  LIMIT 1";
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

        // CP no encontrado / sin KM
        if (!$resp_dist || !isset($resp_dist[0]['Km']) || $resp_dist[0]['Km'] === null) {
            return array('error' => 4, 'motivo' => 'CP_NO_ENCONTRADO', 'cp' => $codigopostal);
        }

        $dist = (float)$resp_dist[0]['Km'];

        // Tu volumen actual (según tu sistema)
        $dim = (float)$length * (float)$width * (float)$height;

        // Control máximo m3
        $max_m3 = $this->obtenerMaxM3Web();
        if ($max_m3 > 0 && $dim > $max_m3) {
            return array(
                'error'  => 4,
                'motivo' => 'VOLUMEN_EXCEDE_MAXIMO',
                'dim'    => $dim,
                'max_m3' => $max_m3
            );
        }

        // Buscar tarifa
        $query = "SELECT id, Titulo, PrecioVenta, Kilometros, Seguro, Codigo
              FROM Productos
              WHERE Grupo='Web'
                AND m3 >= '" . $dim . "'
                AND Kilometros >= '" . $dist . "'
              ORDER BY PrecioVenta ASC
              LIMIT 1";
        $resp = parent::obtenerDatos($query);

        if (!$resp) {
            // Volumen ok, pero no encontró tarifa para esa distancia (o combinación)
            return array(
                'error'  => 4,
                'motivo' => 'SIN_TARIFA_PARA_DISTANCIA',
                'dim'    => $dim,
                'dist'   => $dist
            );
        }

        return $resp;
    }

    public function clienteOrigen($usuarioId)
    {
        $q1 = "SELECT NdeCliente FROM usuarios WHERE id = '" . $usuarioId . "'";
        $r1 = parent::obtenerDatos($q1);
        $nde = ($r1 && isset($r1[0]['NdeCliente'])) ? $r1[0]['NdeCliente'] : 0;

        $q2 = "SELECT nombrecliente,id,Direccion,sure FROM Clientes WHERE id = '" . $nde . "'";
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
        $q = "SELECT TokenId,UsuarioId,Estado
              FROM usuarios_token
              WHERE Token = '" . parent::escapar($this->token) . "'
                AND Estado = 'Activo'";
        $resp = parent::obtenerDatos($q);
        return $resp ? $resp : 0;
    }
}
