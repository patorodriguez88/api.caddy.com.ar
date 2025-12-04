<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
//SE AGREGA EMAIL =TRUE EN EL PEDIDO DE CARGAR ENVIO PARA QUE ENVIE MAIL AL CLIENTE
//SI NO VIENE ESE PARAMETRO NO ENVIA MAIL

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}
// require_once "../conexion/conexion.php";
require_once "respuestas.class.php";

class servicios extends conexion
{
    private $table = "Seguimiento";
    private $tableTrans = "TransClientes";
    private $tableClientes = "Clientes";
    private $pacienteid = "";
    private $dni = "";
    private $nombre = "";
    private $direccion = "";
    private $codigoPostal = "";
    private $telefono = "";
    private $token = "";
    private $Observaciones = "";
    private $Observaciones_clean = "";
    private $idproveedor = "";
    private $pato = "";
    private $ClienteOrigen = "";
    private $idClienteOrigen = "";
    private $DireccionClienteOrigen = "";
    private $correo = "";
    private $genero = "";
    private $ciudad = "";
    private $email = "";
    private $cantidad = "";
    private $servicio = "";
    private $valordec = "";
    private $fechaNacimiento = "";
    private $CodigoSeguimiento = "";

    function get_nombre_dia($fecha)
    {
        $fechats = strtotime($fecha); //pasamos a timestamp

        //el parametro w en la funcion date indica que queremos el dia de la semana
        //lo devuelve en numero 0 domingo, 1 lunes,....
        switch (date('w', $fechats)) {
            case 0:
                return "Domingo";
                break;
            case 1:
                return "Lunes";
                break;
            case 2:
                return "Martes";
                break;
            case 3:
                return "Miércoles";
                break;
            case 4:
                return "Jueves";
                break;
            case 5:
                return "Viernes";
                break;
            case 6:
                return "Sábado";
                break;
        }
    }

    public function listaServicios($pagina, $idClienteOrigen, $estado)
    {
        $inicio   = 0;
        $cantidad = 100;

        if ($pagina > 1) {
            $inicio = ($cantidad * ($pagina - 1)) + 1;
        }

        $_respuestas     = new respuestas;
        $idClienteOrigen = (int)$idClienteOrigen;

        if ($idClienteOrigen <= 0) {
            // Si el controlador no pudo obtener NdeCliente desde el token
            return $_respuestas->error_401('Cliente no asociado al token');
        }

        // Busco clientes relacionados que este cliente puede administrar
        $sqlRelaciones = "SELECT id FROM Clientes WHERE Relacion='$idClienteOrigen' AND AdminEnvios='1'";
        $resp          = parent::obtenerDatosLimpios($sqlRelaciones);

        $idsPermitidos = [$idClienteOrigen];

        if ($resp) {
            // obtenerDatosLimpios puede devolver un array de ids o un array de filas; contemplamos ambos casos
            foreach ($resp as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $idsPermitidos[] = $row['id'];
                } else {
                    $idsPermitidos[] = $row;
                }
            }
        }

        if (empty($idsPermitidos)) {
            return $_respuestas->error_500("Error 500");
        }

        $listaIds = implode(',', array_map('intval', $idsPermitidos));

        $query = "SELECT Fecha,CodigoSeguimiento,NumeroComprobante,RazonSocial,ClienteDestino,DomicilioDestino,Estado,CodigoProveedor as idProveedor
                  FROM " . $this->tableTrans . "
                  WHERE IngBrutosOrigen IN($listaIds)
                    AND Entregado = '" . $estado . "'
                    AND Eliminado='0'
                    AND Haber='0'
                  ORDER BY Fecha DESC
                  LIMIT $inicio,$cantidad";

        $datos = parent::obtenerDatos($query);

        return $datos;
    }

    public function obtenerSeguimiento($id, $idClienteOrigen)
    {
        $_respuestas     = new respuestas;
        $idClienteOrigen = (int)$idClienteOrigen;

        if ($idClienteOrigen <= 0) {
            return $_respuestas->error_401('Cliente no asociado al token');
        }

        // Busco el cliente origen real del envío
        $query = "SELECT IngBrutosOrigen FROM " . $this->tableTrans . " WHERE CodigoSeguimiento = '" . $id . "'";
        $datos = parent::obtenerDatos($query);

        if (!$datos || !isset($datos[0]['IngBrutosOrigen'])) {
            return $_respuestas->error_204('No se encontraron datos del envío');
        }

        $id2 = (int)$datos[0]['IngBrutosOrigen'];

        // Si el envío pertenece a otro cliente, verifico relación / permisos
        if ($id2 !== $idClienteOrigen) {
            $query = "SELECT Relacion,AdminEnvios FROM " . $this->tableClientes . " WHERE id = '" . $id2 . "'";
            $datos = parent::obtenerDatos($query);

            if (
                !$datos ||
                (
                    (int)$datos[0]['Relacion'] !== $idClienteOrigen &&
                    (int)$datos[0]['AdminEnvios'] !== 1
                )
            ) {
                return $_respuestas->error_204();
            }
        }

        // Si llegamos acá, el cliente está autorizado a ver el seguimiento
        $query = "SELECT id,Fecha,Hora,Usuario,CodigoSeguimiento,Observaciones,Estado,NombreCompleto,Dni,Destino 
                  FROM " . $this->table . " 
                  WHERE CodigoSeguimiento = '" . $id . "'";

        $datos = parent::obtenerDatos($query);

        if ($datos) {
            return $datos;
        }

        return $_respuestas->error_204();
    }

    // BUSCO EN SEGUIMIENTO DESDE EL ID DEL PROVEEDOR
    public function obtenerSeguimientoProveedor($id, $idClienteOrigen)
    {
        $_respuestas     = new respuestas;
        $idClienteOrigen = (int)$idClienteOrigen;

        if ($idClienteOrigen <= 0) {
            return $_respuestas->error_401('Cliente no asociado al token');
        }

        // Busco el envío por CódigoProveedor
        $query = "SELECT IngBrutosOrigen,CodigoSeguimiento FROM " . $this->tableTrans . " WHERE CodigoProveedor = '" . $id . "'";
        $datos = parent::obtenerDatos($query);

        if (!$datos || !isset($datos[0]['IngBrutosOrigen'])) {
            return $_respuestas->error_204('No se encontraron envíos para ese proveedor');
        }

        $id2   = (int)$datos[0]['IngBrutosOrigen'];
        $id2Cs = $datos[0]['CodigoSeguimiento'];

        // Si el envío pertenece a otro cliente, verifico relación / permisos
        if ($id2 !== $idClienteOrigen) {
            $query = "SELECT Relacion,AdminEnvios FROM " . $this->tableClientes . " WHERE id = '" . $id2 . "'";
            $datos = parent::obtenerDatos($query);

            if (
                !$datos ||
                (
                    (int)$datos[0]['Relacion'] !== $idClienteOrigen &&
                    (int)$datos[0]['AdminEnvios'] !== 1
                )
            ) {
                return $_respuestas->error_204();
            }
        }

        // Si llegamos acá, el cliente está autorizado a ver el seguimiento
        $query = "SELECT " . $this->table . ".id," . $this->table . ".Fecha," . $this->table . ".Hora," . $this->table . ".Usuario,
                  " . $this->table . ".CodigoSeguimiento," . $this->table . ".Observaciones," . $this->table . ".Estado," . $this->table . ".NombreCompleto,
                  " . $this->table . ".Dni," . $this->table . ".Destino," . $this->tableTrans . ".CodigoProveedor 
                  FROM " . $this->table . " 
                  INNER JOIN " . $this->tableTrans . " 
                    ON " . $this->table . ".CodigoSeguimiento = " . $this->tableTrans . ".CodigoSeguimiento 
                  WHERE " . $this->table . ".CodigoSeguimiento =  '" . $id2Cs . "'";

        $datos = parent::obtenerDatos($query);

        if ($datos) {
            return $datos;
        }

        return $_respuestas->error_204();
    }

    public function post($json)
    {
        $_respuestas = new respuestas;
        $datos = json_decode($json, true);

        if (!is_array($datos)) {
            return $_respuestas->error_400('JSON inválido');
        }

        // 1) OBTENER TOKEN
        //    - Preferimos el header (Bearer / X-Api-Token)
        //    - Si no viene, usamos $datos['token'] (compatibilidad)
        $token = Token::obtenerToken();

        if (!$token && !empty($datos['token'])) {
            $token = trim($datos['token']);
        }

        if (!$token) {
            return $_respuestas->error_401('Debe enviar token');
        }

        // 2) VALIDAR TOKEN CONTRA BD (usuarios_token + usuarios)
        //    $this es la propia clase servicios, que extiende conexion, así que
        //    Token::validar puede usar $this->obtenerDatos(...)
        $tokenData = Token::validar($token, $this);

        if (!$tokenData) {
            return $_respuestas->error_401('El token enviado es inválido o ha caducado');
        }

        // Id de usuario (tabla usuarios.id) desde el token
        $idUsuario = (int)($tokenData['UsuarioId'] ?? 0);

        if ($idUsuario <= 0) {
            return $_respuestas->error_401('Token sin usuario asociado');
        }

        // A partir de acá, TODO lo que hacías con $arrayToken y $this->pato
        // lo hacemos con $idUsuario

        // Validación de campos mínimos
        if (!isset($datos['NombreCompleto']) || !isset($datos['Direccion']) || !isset($datos['CodigoPostal'])) {
            return $_respuestas->error_400();
        }

        // --- tus variables actuales, igual que antes ---

        $length    = $datos['Box'][0]['Length'] ?? null;
        $width     = $datos['Box'][0]['Width']  ?? null;
        $height    = $datos['Box'][0]['Height'] ?? null;
        $weight    = $datos['Box'][0]['Weight'] ?? null;
        $cobranza  = $datos['Cobranza'] ?? 0;
        $codigoPostal = $datos['CodigoPostal'];

        // DETERMINO SI EL CLIENTE UTILIZA FLEX
        if (($datos['Servicio'] ?? null) == 3) {

            if (($codigoPostal >= '5000') && ($codigoPostal <= '5023')) {

                $price = $this->rate_flex();
            } else {
                // SI NO ES FLEX CALCULO DIMENSIONES
                $dim = $this->calc_dim($length, $width, $height, $weight);

                if ($dim == 0) {
                    return $_respuestas->error_400('Faltan datos del paquete');
                }

                $price = $this->rate($codigoPostal, $length, $width, $height, $weight);
            }
        } else {
            // NO FLEX
            $dim = $this->calc_dim($length, $width, $height, $weight);

            if ($dim == 0) {
                return $_respuestas->error_400('Faltan datos del paquete');
            }

            $price = $this->rate($codigoPostal, $length, $width, $height, $weight);
        }

        if (!empty($price[0]['id'])) {

            $respuesta_rate["result"] = array(
                "Id"          => $price[0]['id'],
                "Titulo"      => $price[0]['Titulo'],
                "PrecioVenta" => $price[0]['PrecioVenta']
            );
            $titulo_rate = $price[0]['Titulo'];
            $tarifa_rate = $price[0]['PrecioVenta'];
        } else {

            if ($price == 4) {
                return $_respuestas->error_400('Error en localidad');
            } else {
                return $_respuestas->error_400('Error en la obtención de precio');
            }
        }

        // WEBHOOK (si viene)
        if (!empty($datos['WebHook'])) {

            $webhook    = $datos['WebHook'];
            $webhook_id = $this->clienteOrigen($idUsuario);

            $webhook_api = $this->webhook($webhook, $webhook_id[0]['id'] ?? null);
        }

        $respuesta_actualizacion = '';

        // VERIFICO SI ME ESTA ENVIANDO LOS DATOS DEL IDPROVEEDOR DE ORIGEN
        if (empty($datos['Origen'][0]['idProveedor']) || $datos['Origen'][0]['idProveedor'] == "0") {

            // Cliente origen directo del usuario
            $ClienteOrigen = $this->clienteOrigen($idUsuario);
        } else {

            $idProveedor      = $datos['Origen'][0]['idProveedor'];
            $NombreOrigen     = $datos['Origen'][0]['Nombre'];
            $DireccionOrigen  = $datos['Origen'][0]['Direccion'];

            $ClienteOrigen = $this->clienteOrigenRelacion($idUsuario, $idProveedor, $NombreOrigen, $DireccionOrigen);

            if (!empty($ClienteOrigen[0]['nombrecliente'])) {

                $idClienteOrigen = $ClienteOrigen[0]['nombrecliente'];

                if ($ClienteOrigen[0]['nombrecliente'] <> $NombreOrigen) {
                    $respuesta_actualizacion  = 'El Nombre ' . $NombreOrigen . ' no coincide con ' . $ClienteOrigen[0]['nombrecliente'] . ' de nuestra BD. ';
                }
                if ($ClienteOrigen[0]['Direccion'] <> $DireccionOrigen) {
                    $respuesta_actualizacion .= 'La Direccion ' . $DireccionOrigen . ' no coincide con ' . $ClienteOrigen[0]['Direccion'] . ' de nuestra BD';
                }
            } else {

                if ($ClienteOrigen == 1) {
                    return $_respuestas->error_500('No encontramos Cliente Origen Relacionado');
                } else if ($ClienteOrigen == 3) {
                    return $_respuestas->error_500('La Direccion no coincide con el Cliente Relacionado');
                } else if ($ClienteOrigen == 4) {
                    return $_respuestas->error_500('El idProveedor no coincide con el Cliente Relacionado, no pudimos actualizar');
                } else {
                    return $_respuestas->error_400();
                }
            }
        }

        $idClienteOrigen = $ClienteOrigen[0]['nombrecliente'];

        $this->ClienteOrigen          = $idClienteOrigen;
        $this->idClienteOrigen        = $ClienteOrigen[0]['id'];
        $this->DireccionClienteOrigen = $ClienteOrigen[0]['Direccion'];

        $this->nombre    = $datos['NombreCompleto'];
        $this->direccion = $datos['Direccion'];
        $this->ciudad    = $datos['Ciudad'] ?? '';

        if (isset($datos['Dni'])) {
            $this->dni        = $datos['Dni'];
        }
        if (isset($datos['Telefono'])) {
            $this->telefono   = $datos['Telefono'];
        }
        if (isset($datos['Mail'])) {
            $this->email      = $datos['Mail'];
        }
        if (isset($datos['CodigoPostal'])) {
            $this->codigoPostal = $datos['CodigoPostal'];
        }

        // OBSERVACIONES
        if (($datos['Servicio'] ?? null) == 3) {
            $Obs_api = 'API MERCADO LIBRE FLEX';
        } else {
            $Obs_api = 'API WEB CADDY';
        }

        if (isset($datos['Observaciones'])) {

            $this->Observaciones       = $datos['Observaciones'];
            $this->Observaciones_clean = $datos['Observaciones'];
        } else {
            $this->Observaciones = $Obs_api . ' ' . $respuesta_actualizacion;
        }

        // CANTIDAD
        $this->cantidad = isset($datos['Cantidad']) ? (int)$datos['Cantidad'] : 1;

        // SERVICIO
        if (isset($datos['Servicio']) && $datos['Servicio'] == 3) {
            $this->servicio = 0;
        } else {
            $this->servicio = 1;
        }

        // VALOR DECLARADO
        if (!isset($datos['ValorDeclarado']) || $datos['ValorDeclarado'] == 0) {
            $this->valordec = 10000;
        } else {
            $this->valordec = $datos['ValorDeclarado'];
        }

        // ID PROVEEDOR
        if (isset($datos['idProveedor'])) {
            $this->idproveedor = $datos['idProveedor'];
        }

        // ENVIAR MAIL
        $enviar_mail = !empty($datos['EnviarMail']);

        // INSERTAR VENTA
        $venta = $this->insertarVenta($tarifa_rate, $titulo_rate, $length, $width, $height, $weight, $cobranza, $enviar_mail);

        if (!$venta || empty($venta['id_preventa'])) {
            return $_respuestas->error_500();
        }

        $date = $this->date_send($codigoPostal);

        $citydestination = $date[0]['Localidad'] ?? null;
        $send_date       = $date[0]['DiaSalida'] ?? null;

        $Total             = $this->cantidad * floatval($tarifa_rate);
        $tarifa_rate_label = round($tarifa_rate);
        $total_label       = round($Total);

        $respuesta = $_respuestas->response;

        $respuesta["result"] = array(
            "Id_de_Venta"        => $venta['id_preventa'],
            "Codigo_Seguimiento" => $venta['CodigoSeguimiento'],
            "Observaciones"      => $respuesta_actualizacion,
            "Fecha_Entrega"      => $send_date,
            "Localidad"          => $citydestination,
            "Cantidad"           => $this->cantidad,
            "Titulo"             => $titulo_rate,
            "Tarifa"             => $tarifa_rate_label,
            "Total"              => $total_label,

            "Origen" => array(
                "Nombre"          => $this->ClienteOrigen,
                "idClienteOrigen" => $this->idClienteOrigen,
                "Direccion"       => $this->DireccionClienteOrigen,
                "Ciudad"          => "Cordoba",
                "Pais"            => "Argentina"
            ),

            "Destino" => array(
                "Nombre"       => $this->nombre,
                "Direccion"    => $this->direccion,
                "Ciudad"       => $this->ciudad,
                "CodigoPostal" => $this->codigoPostal,
                "Telefono"     => $this->telefono,
                "Mail"         => $this->email,
                "Cantidad"     => $this->cantidad
            ),

            "Box" => array(
                "Length" => $length,
                "Width"  => $width,
                "Height" => $height,
                "Weight" => $weight
            ),

            "Cobranza"       => $cobranza,
            "Servicio"       => $this->servicio,
            "ValorDeclarado" => $this->valordec,
            "EnviarMail"     => $enviar_mail
        );

        return $respuesta;
    }

    // public function post($json)
    // {
    //     $_respuestas = new respuestas;
    //     $datos = json_decode($json, true);

    //     if (!isset($datos['token'])) {

    //         return $_respuestas->error_401('213');
    //     } else {

    //         $this->token = $datos['token'];
    //         $arrayToken = $this->buscarToken();

    //         if ($arrayToken) {

    //             if (!isset($datos['NombreCompleto']) || !isset($datos['Direccion']) || !isset($datos['CodigoPostal'])) {

    //                 return $_respuestas->error_400();
    //             } else {

    //                 $this->pato = $arrayToken[0]['UsuarioId'];

    //                 $idUsuario = $this->pato;

    //                 // if(($datos['Box'][0]['Length']<>"") AND ($datos['Box'][0]['Width']<>"") AND ($datos['Box'][0]['Height']<>"") AND ($datos['Box'][0]['Weight']<>"")) {

    //                 $length = $datos['Box'][0]['Length'];
    //                 $width = $datos['Box'][0]['Width'];
    //                 $height = $datos['Box'][0]['Height'];
    //                 $weight = $datos['Box'][0]['Weight'];
    //                 $cobranza = $datos['Cobranza']; //OBTENGO EL DATO COBRANZA
    //                 //    $citydestination=$datos['Ciudad'];

    //                 $codigoPostal = $datos['CodigoPostal'];


    //                 //DETERMINO SI EL CLIENTE UTILIZA FLEX
    //                 if ($datos['Servicio'] == 3) {

    //                     if (($codigoPostal >= '5000') && ($codigoPostal <= '5023')) {

    //                         $price = $this->rate_flex();
    //                     } else {
    //                         //SI NO ES FLEX CALCULO DIMENSIONES
    //                         $dim = $this->calc_dim($length, $width, $height, $weight);

    //                         if ($dim <> 0) {
    //                             $price = $this->rate($codigoPostal, $length, $width, $height, $weight);
    //                         } else {
    //                             return $_respuestas->error_400('Faltan datos del paquete');
    //                         }

    //                         $price = $this->rate($codigoPostal, $length, $width, $height, $weight);
    //                     }
    //                 } else {
    //                     //SI NO ES FLEX CALCULO DIMENSIONES
    //                     $dim = $this->calc_dim($length, $width, $height, $weight);

    //                     if ($dim <> 0) {
    //                         $price = $this->rate($codigoPostal, $length, $width, $height, $weight);
    //                     } else {
    //                         return $_respuestas->error_400('Faltan datos del paquete');
    //                     }

    //                     $price = $this->rate($codigoPostal, $length, $width, $height, $weight);
    //                 }


    //                 if ($price[0]['id']) {

    //                     // $respuesta = $price->response;

    //                     $respuesta_rate["result"] = array(
    //                         "Id" => $price[0]['id'],
    //                         "Titulo" => $price[0]['Titulo'],
    //                         "PrecioVenta" => $price[0]['PrecioVenta']
    //                     );
    //                     $titulo_rate = $price[0]['Titulo'];
    //                     $tarifa_rate = $price[0]['PrecioVenta'];
    //                 } else {

    //                     if ($price == 4) {

    //                         return $_respuestas->error_400('Error en localidad');
    //                     } else {

    //                         return $_respuestas->error_400('Error en la obtencion de precio');
    //                     }
    //                 }

    //                 //    }else{

    //                 // return $_respuestas->error_400('Faltan datos del paquete');    

    //                 //   } 

    //                 if (!empty($datos['WebHook'])) {

    //                     $webhook = $datos['WebHook'];
    //                     $webhook_id = $this->clienteOrigen($idUsuario);

    //                     $webhook_api = $this->webhook($webhook, $webhook_id[0]['id']);
    //                 }
    //                 $respuesta_actualizacion = '';

    //                 //VERIFICO SI ME ESTA ENVIANDO LOS DATOS DEL IDPROVEEDOR DE ORIGEN
    //                 if (($datos['Origen'][0]['idProveedor'] == "") || ($datos['Origen'][0]['idProveedor'] == "0")) {

    //                     $ClienteOrigen = $this->clienteOrigen($idUsuario);
    //                 } else {

    //                     $idProveedor = $datos['Origen'][0]['idProveedor'];
    //                     $NombreOrigen = $datos['Origen'][0]['Nombre'];
    //                     $DireccionOrigen = $datos['Origen'][0]['Direccion'];


    //                     $ClienteOrigen = $this->clienteOrigenRelacion($idUsuario, $idProveedor, $NombreOrigen, $DireccionOrigen);



    //                     if ($ClienteOrigen[0]['nombrecliente']) {
    //                         $idClienteOrigen = $ClienteOrigen[0]['nombrecliente'];
    //                         if ($ClienteOrigen[0]['nombrecliente'] <> $NombreOrigen) {
    //                             $respuesta_actualizacion = 'El Nombre ' . $NombreOrigen . ' no coincide con ' . $ClienteOrigen[0]['nombrecliente'] . ' de nuestra BD. ';
    //                         }
    //                         if ($ClienteOrigen[0]['Direccion'] <> $DireccionOrigen) {
    //                             $respuesta_actualizacion .= 'La Direccion ' . $DireccionOrigen . ' no coincide con ' . $ClienteOrigen[0]['Direccion'] . ' de nuestra BD';
    //                         }
    //                     } else {

    //                         if ($ClienteOrigen == 1) {

    //                             return $_respuestas->error_500('No encontramos Cliente Origen Relacionado');
    //                         } else if ($ClienteOrigen == 3) {

    //                             return $_respuestas->error_500('La Direccion no coincide con el Cliente Relacionado');
    //                         } else if ($ClienteOrigen == 4) {

    //                             return $_respuestas->error_500('El idProveedor no coincide con el Cliente Relacionado, no pudimos actualizar');
    //                         } else {

    //                             return $_respuestas->error_400();
    //                         }
    //                     }
    //                 }

    //                 $idClienteOrigen = $ClienteOrigen[0]['nombrecliente'];

    //                 $this->ClienteOrigen = $idClienteOrigen;
    //                 $this->idClienteOrigen = $ClienteOrigen[0]['id'];
    //                 $this->DireccionClienteOrigen = $ClienteOrigen[0]['Direccion'];

    //                 $this->nombre = $datos['NombreCompleto']; //NOMBRE CLIENTE
    //                 $this->direccion = $datos['Direccion']; //MAIL
    //                 $this->ciudad = $datos['Ciudad']; //CIUDAD
    //                 if (isset($datos['Dni'])) {
    //                     $this->dni = $datos['Dni'];
    //                 } //CUIT 
    //                 if (isset($datos['Telefono'])) {
    //                     $this->telefono = $datos['Telefono'];
    //                 } //TELEFONO
    //                 if (isset($datos['Mail'])) {
    //                     $this->email = $datos['Mail'];
    //                 } //MAIL
    //                 if (isset($datos['CodigoPostal'])) {
    //                     $this->codigoPostal = $datos['CodigoPostal'];
    //                 } //CODIGO POSTAL

    //                 //OBSERVACIONES

    //                 if ($datos['Servicio'] == 3) { //SI EL SERVICIO ES FLEX

    //                     $Obs_api = 'API MERCADO LIBRE FLEX';
    //                 } else {

    //                     $Obs_api = 'API WEB CADDY';
    //                 }

    //                 if (isset($datos['Observaciones'])) {

    //                     $this->Observaciones = $datos['Observaciones'];
    //                     $this->Observaciones_clean = $datos['Observaciones'];
    //                 } else {

    //                     $this->Observaciones = $Obs_api . ' ' . $respuesta_actualizacion;
    //                 }

    //                 //CANTIDAD POR DEFECTO 1
    //                 if (isset($datos['Cantidad'])) {

    //                     $this->cantidad = $datos['Cantidad'];
    //                 } else {

    //                     $this->cantidad = 1;
    //                 }

    //                 //SERVICIO POR DEFECTO 1
    //                 if (isset($datos['Servicio']) || ($datos['Servicio'] == 3)) {

    //                     $this->servicio = 0;
    //                 } else {

    //                     $this->servicio = 1;
    //                 }

    //                 //VALOR DECLARADO POR DEFECTO $10000
    //                 if (!isset($datos['ValorDeclarado']) || ($datos['ValorDeclarado'] == 0)) {

    //                     $this->valordec = 10000;
    //                 } else {

    //                     $this->valordec = $datos['ValorDeclarado'];
    //                 }

    //                 //ID PROVEEDOR
    //                 if (isset($datos['idProveedor'])) {
    //                     $this->idproveedor = $datos['idProveedor'];
    //                 }
    //                 //ENVIAR MAIL
    //                 if (!empty($datos['EnviarMail'])) {
    //                     $enviar_mail = true;
    //                 } else {
    //                     $enviar_mail = false;
    //                 }

    //                 $venta = $this->insertarVenta($tarifa_rate, $titulo_rate, $length, $width, $height, $weight, $cobranza, $enviar_mail);
    //                 if ($venta['id_preventa']) {

    //                     $date = $this->date_send($codigoPostal);

    //                     // Cuidamos el caso en que date_send no devuelva nada
    //                     $citydestination = $date[0]['Localidad'] ?? null;
    //                     $send_date       = $date[0]['DiaSalida'] ?? null;

    //                     $Total             = $this->cantidad * floatval($tarifa_rate);
    //                     $tarifa_rate_label = round($tarifa_rate);
    //                     $total_label       = round($Total);

    //                     $respuesta = $_respuestas->response;

    //                     $respuesta["result"] = array(
    //                         // Datos generales de la venta
    //                         "Id_de_Venta"        => $venta['id_preventa'],
    //                         "Codigo_Seguimiento" => $venta['CodigoSeguimiento'],
    //                         "Observaciones"      => $respuesta_actualizacion,
    //                         "Fecha_Entrega"      => $send_date,
    //                         "Localidad"          => $citydestination,
    //                         "Cantidad"           => $this->cantidad,
    //                         "Titulo"             => $titulo_rate,
    //                         "Tarifa"             => $tarifa_rate_label,
    //                         "Total"              => $total_label,

    //                         // Datos de origen
    //                         "Origen" => array(
    //                             "Nombre"          => $this->ClienteOrigen,
    //                             "idClienteOrigen" => $this->idClienteOrigen,
    //                             "Direccion"       => $this->DireccionClienteOrigen,
    //                             "Ciudad"          => "Cordoba",            // según lo que grabás en PreVenta
    //                             "Pais"            => "Argentina"
    //                         ),

    //                         // Datos de destino
    //                         "Destino" => array(
    //                             "Nombre"       => $this->nombre,
    //                             "Direccion"    => $this->direccion,
    //                             "Ciudad"       => $this->ciudad,
    //                             "CodigoPostal" => $this->codigoPostal,
    //                             "Telefono"     => $this->telefono,
    //                             "Mail"         => $this->email,
    //                             "Cantidad"     => $this->cantidad
    //                         ),

    //                         // Datos del paquete (opcional, pero queda muy prolijo)
    //                         "Box" => array(
    //                             "Length" => $length,
    //                             "Width"  => $width,
    //                             "Height" => $height,
    //                             "Weight" => $weight
    //                         ),

    //                         // Otros datos útiles de la operación
    //                         "Cobranza"       => $cobranza,
    //                         "Servicio"       => $this->servicio,
    //                         "ValorDeclarado" => $this->valordec,
    //                         "EnviarMail"     => !empty($datos['EnviarMail']) ? true : false
    //                     );

    //                     return $respuesta;
    //                 } else {
    //                     return $_respuestas->error_500();
    //                 }
    //                 // if ($venta['id_preventa']) {

    //                 //     $date = $this->date_send($codigoPostal);

    //                 //     $citydestination = $date[0]['Localidad'];
    //                 //     $send_date = $date[0]['DiaSalida'];

    //                 //     $Total = $this->cantidad * floatval($tarifa_rate);
    //                 //     $tarifa_rate_label = round($tarifa_rate);
    //                 //     $total_label = round($Total);

    //                 //     $respuesta = $_respuestas->response;

    //                 //     $respuesta["result"] = array(
    //                 //         "Id_de_Venta" => $venta['id_preventa'],
    //                 //         "Observaciones" => $respuesta_actualizacion,
    //                 //         "Fecha_Entrega" => $send_date,
    //                 //         "Localidad" => $citydestination,
    //                 //         "Cantidad" => $this->cantidad,
    //                 //         "Titulo" => $titulo_rate,
    //                 //         "Tarifa" => $tarifa_rate_label,
    //                 //         "Total" => $total_label,
    //                 //         "Codigo_Seguimiento" => $venta['CodigoSeguimiento']
    //                 //     );

    //                 //     return $respuesta;
    //                 // } else {
    //                 //     return $_respuestas->error_500();
    //                 // }
    //             }
    //         } else {

    //             return $_respuestas->error_401("El Token que envio es invalido o ha caducado");
    //         }
    //     }
    // }

    //CALCULO DIMENSIONES
    public function calc_dim($length, $width, $height, $weight)
    {

        if (($length <> "") and ($width <> "") and ($height <> "")) {

            $dim = $length * $width * $height;

            return $dim;
        } else {

            return 0;
        }
    }

    //CALCULO TARIFA FLEX    
    public function rate_flex()
    {

        $query = "SELECT id,Titulo,PrecioVenta,Kilometros FROM Productos WHERE Codigo='183'";

        if ($resp = parent::obtenerDatos($query)) {

            return $resp;
        } else {

            return 4;
        }
    }

    //CALCULO TARIFA
    public function rate($codigoPostal, $length, $width, $height, $weight)
    {

        if (is_null($codigoPostal) || $codigoPostal == 0) {

            $codigoPostal = '5000';
        }

        //DISTANCE
        if (($codigoPostal >= '5000') && ($codigoPostal <= '5023')) {

            $codigoPostal = '5000';

            $dist = 1;
        } else {

            $query_dist = "SELECT Km FROM Localidades WHERE Cp='" . $codigoPostal . "'";

            $resp_dist = parent::obtenerDatos($query_dist);

            $dist = $resp_dist[0]['Km'];
        }

        if ($dist <> null) {

            $dim = $length * $width * $height;

            $query = "SELECT id,Titulo,MIN(PrecioVenta)as PrecioVenta,Kilometros FROM Productos WHERE Grupo='Web' AND m3>='" . $dim . "' AND Kilometros>='" . $dist . "'";

            $resp = parent::obtenerDatos($query);

            return $resp;
        } else {

            return 4;
        }
    }

    public function clienteOrigen($id)
    {

        $query = "SELECT  NdeCliente FROM usuarios WHERE id = '" . $id . "'";
        $resp = parent::obtenerDatos($query);
        $query = "SELECT  nombrecliente,id,Direccion FROM Clientes WHERE id = '" . $resp[0]['NdeCliente'] . "'";
        $resp = parent::obtenerDatos($query);
        return $resp;
    }

    public function date_send($codigopostal)
    {

        if (($codigopostal >= '5000') && ($codigopostal <= '5023')) {

            $Localidad = '5000';
        }

        $query = "SELECT DiaSalida,Localidad FROM Localidades WHERE Cp = '" . $Localidad . "'";

        $resp = parent::obtenerDatos($query);

        return $resp;
    }

    //WEBHOOK
    public function webhook($webhook, $webhook_id)
    {

        $query = "SELECT Endpoint FROM Webhook WHERE idCliente = '" . $webhook_id . "'";

        $resp = parent::obtenerDatos($query);

        if ($resp) {

            $query = "UPDATE Webhook SET Endpoint = '" . $webhook . "' WHERE idCliente='" . $webhook_id . "' ";

            $resp = parent::nonQueryId($query);
        } else {

            $query = "INSERT INTO Webhook(idCliente,Endpoint)VALUES( '" . $webhook_id . "','" . $webhook . "' )";

            $resp = parent::nonQueryId($query);
        }
    }

    private function clienteOrigenRelacion($idUsuario, $idProveedor, $NombreOrigen, $DireccionOrigen)
    {

        $query = "SELECT NdeCliente FROM usuarios WHERE id = '" . $idUsuario . "'";
        $resp0 = parent::obtenerDatos($query);
        $DireccionOrigen_utf = mb_convert_encoding($DireccionOrigen, 'ISO-8859-1', 'UTF-8');

        $query = "SELECT nombrecliente,id,Direccion FROM Clientes WHERE Relacion= '" . $resp0[0]['NdeCliente'] . "' AND idProveedor = '" . $idProveedor . "'";
        $resp = parent::obtenerDatos($query);
        //ENCUENTRO EL CLIENTE RELACIONADO CON LA RELACION Y EL IDPROVEEDOR
        if ($resp[0]['id']) {
            //VERIFICAMOS QUE LA DIRECCION Y EL NOMBRE QUE ENVIO EL POST NOS COINCIDA CON EL CLIENTE RELACIONADO
            if ($resp[0]['nombrecliente'] == $NombreOrigen) {
                if ($resp[0]['Direccion'] == $DireccionOrigen_utf) {
                    return $resp;
                } else {
                    return 3;
                }
            } else {
                //EL NOMBRE DE CLIENTE NO COINCIDE PERO CARGO LA VENTA LO MISMO CON EL ID DEL CLIENTE ORIGEN PROPORCIONADO
                return $resp;
            }
        } else {

            //COMO NO ENCONTRE EL CLIENTE RELACIONADO CONSULTO EN LA PRIMERA POSIBILIDAD CON NOMBRE Y DIRECCION...  
            $query = "SELECT nombrecliente,id,Direccion FROM Clientes WHERE nombrecliente = '" . $NombreOrigen . "' AND Direccion like '%" . $DireccionOrigen_utf . "%'"; //utf8_decode
            $resp1 = parent::obtenerDatos($query);
            if ($resp1[0]['id']) {
                $query = "UPDATE Clientes SET idProveedor = '" . $idProveedor . "' WHERE nombrecliente = '" . $NombreOrigen . "' AND Direccion like '%" . $DireccionOrigen_utf . "%' ";
                $resp = parent::nonQuery($query);
                if ($resp >= 1) {
                    return $resp1;
                } else {
                    return 4;
                }
            }
        }
    }



    private function insertarVenta($tarifa_rate, $titulo_rate, $length, $width, $height, $weight, $cobranza, $enviar_mail)
    {

        // LUEGO CARGO LA VENTA
        $Fecha = date('Y-m-d H:i:s');
        $Hora = date("H:i:s");
        $Codigo = '49';
        // $DomicilioDestino=$Calle.' '.$Numero;

        //DATOS DE LA VENTA
        // $Cantidad = $_POST['Cant'];
        $DatoNV = $_POST['NV'] ?? '';
        $Precio = floatval($tarifa_rate);
        $Total = $this->cantidad * $Precio;

        $direccion = mb_convert_encoding($this->direccion, 'ISO-8859-1', 'UTF-8');
        $ciudad = mb_convert_encoding($this->ciudad, 'ISO-8859-1', 'UTF-8');
        $DireccionClienteOrigen = mb_convert_encoding($this->DireccionClienteOrigen, 'ISO-8859-1', 'UTF-8');
        $ClienteDestino = mb_convert_encoding($this->nombre, 'ISO-8859-1', 'UTF-8');

        //BUSCO EL CLIENTE DESTINO
        $query = "SELECT id,Observaciones FROM Clientes WHERE nombrecliente = '" . $ClienteDestino . "' AND Direccion = '" . $direccion . "' LIMIT 1";
        $resp = parent::obtenerDatos($query);

        if ($resp) {

            //SI YA EXISTE EL CLIENTE OBTENEMOS EL ID
            $idClienteDestino = $resp[0]['id'];

            //SI OBSERVACIONES DE QUE NOS ENVIA EL CLIENTE NO SON NULL ACTUALIZAMOS EL CAMPO DE LA BD
            if ($this->Observaciones_clean <> null) {

                //SI LAS OBSERVACIONES QUE ME ENVIA EL CLIENTE DIFIEREN DE LAS QUE TENGO EN LA BD, ACTUALIZO LAS OBSERVACIONES
                if ($resp[0]['Observaciones'] <> $this->Observaciones_clean) {

                    $query = "UPDATE Clientes SET Observaciones = '" . $this->Observaciones_clean . "' WHERE id = '" . $idClienteDestino . "' LIMIT 1";
                    $resp = parent::nonQuery($query);
                }
            }
        } else {

            $query = "SELECT MAX(id)as id FROM Clientes";
            $respmax = parent::obtenerDatos($query);

            $NewidCliente = trim($respmax[0]['id']) + 1;

            $query = "INSERT INTO Clientes (NdeCliente,nombrecliente,Direccion,Ciudad,Telefono,Celular,Celular2,Cuit,Relacion,Pais,Mail,CodigoPostal,Observaciones)VALUES
    ('" . $NewidCliente . "','" . $ClienteDestino . "','" . $direccion . "','" . $ciudad . "','" . $this->telefono . "','" . $this->telefono . "',
    '" . $this->telefono . "','" . $this->dni . "','" . $this->idClienteOrigen . "','Argentina','" . $this->email . "','" . $this->codigoPostal . "','" . $this->Observaciones . "')";

            $respclientes = parent::nonQueryId($query);
            $idClienteDestino = $respclientes;
        }

        //INSERTO LA VENTA EN PREVENTA
        $CodigoSeguimiento = parent::generarCodigo(9);
        $this->CodigoSeguimiento = $CodigoSeguimiento;

        $query_preventa = "INSERT INTO `PreVenta`(`Fecha`, `RazonSocial`, `NCliente`, `TipoDeComprobante`, `NumeroComprobante`, `Cantidad`,`Precio`,`Total`,
        `ClienteDestino`, `idClienteDestino`, `DomicilioDestino`, `LocalidadDestino`,`NumeroVenta`, `DomicilioOrigen`,`LocalidadOrigen`, `Usuario`,
        `EntregaEn`,`Observaciones`,`Hora`,`Telefono`,`Celular`,`Retirado`,`ValorDeclarado`,`idProveedor`,`Length`, `Width`, `Height`, `Weight`,`cpdestino`,`Cobranza`,`CodigoSeguimiento`)VALUES
        ('" . $Fecha . "','" . $this->ClienteOrigen . "','" . $this->idClienteOrigen . "','" . $titulo_rate . "','"  . $Codigo . "','" . $this->cantidad . "','" . $Precio . "','" . $Total . "','" . $ClienteDestino . "',
        '" . $idClienteDestino . "','" . $direccion . "','" . $ciudad . "','" . $DatoNV . "','" . $DireccionClienteOrigen . "','Cordoba','" . $this->token . "',
        'Domicilio','" . $this->Observaciones . "','" . $Hora . "','" . $this->telefono . "','" . $this->telefono . "','" . $this->servicio . "','" . $this->valordec . "','" . $this->idproveedor . "',
        '" . $length . "','" . $width . "','" . $height . "','" . $weight . "','" . $this->codigoPostal . "','" . $cobranza . "','" . $CodigoSeguimiento . "')";

        $resp_preventa = parent::nonQueryId($query_preventa);
        $codigoPostal = $this->codigoPostal;

        if ($resp_preventa) {

            // Enviar mail solo si el caller lo pidió y el email es válido
            if (
                $enviar_mail &&
                !empty($this->email) &&
                filter_var($this->email, FILTER_VALIDATE_EMAIL)
            ) {
                $this->enviar_mail();
            }

            return [
                'id_preventa' => $resp_preventa,
                'CodigoSeguimiento' => $CodigoSeguimiento
            ];
        } else {
            return [
                'id_preventa' => 0,
                'CodigoSeguimiento' => null
            ];
        }
    }

    public function put($json)
    {
        $_respuestas = new respuestas;
        $datos = json_decode($json, true);

        if (!isset($datos['token'])) {
            return $_respuestas->error_401('738');
        } else {
            $this->token = $datos['token'];
            $arrayToken =   $this->buscarToken();
            if ($arrayToken) {
                if (!isset($datos['pacienteId'])) {
                    return $_respuestas->error_400();
                } else {
                    $this->pacienteid = $datos['pacienteId'];
                    if (isset($datos['nombre'])) {
                        $this->nombre = $datos['nombre'];
                    }
                    if (isset($datos['dni'])) {
                        $this->dni = $datos['dni'];
                    }
                    if (isset($datos['correo'])) {
                        $this->correo = $datos['correo'];
                    }
                    if (isset($datos['telefono'])) {
                        $this->telefono = $datos['telefono'];
                    }
                    if (isset($datos['direccion'])) {
                        $this->direccion = $datos['direccion'];
                    }
                    if (isset($datos['CodigoPostal'])) {
                        $this->codigoPostal = $datos['CodigoPostal'];
                    }
                    if (isset($datos['genero'])) {
                        $this->genero = $datos['genero'];
                    }
                    if (isset($datos['fechaNacimiento'])) {
                        $this->fechaNacimiento = $datos['fechaNacimiento'];
                    }
                }
            } else {
                return $_respuestas->error_401("El Token que envio es invalido o ha caducado");
            }
        }
    }



    private function enviar_mail()
    {
        // Varios destinatarios
        $para  = $this->email; // atención a la coma

        // título
        $título = 'Recibimos tu solicitud de envío !';

        // mensaje
        $cliente = $this->nombre;
        $cliente_origen = $this->ClienteOrigen;
        $shtml = file_get_contents('https://www.caddy.com.ar/SistemaTriangular/Mail/plantilla/delivered.html');

        $cp_min = '5000';
        $cp_max = '5023';

        //send date
        if (($cp_min <= $this->codigoPostal) && ($this->codigoPostal <= $cp_max)) {
            // if($this->codigoPostal=='5000'){
            $hora = date("G");
            if ($hora < 11) {
                $send_date = 'Llega Hoy ';
            } else {
                $send_date = 'Llega Mañana ';
            }
        } else {

            $date = $this->date_send($this->codigoPostal);

            if ($date == null) {
                $fecha = date("Y-m-d", strtotime("+ 2 days"));
                $dia = $this->get_nombre_dia($fecha);
                $send_date = 'Llega el ' . $dia;
            } else {

                $send_date = 'Llega el ' . $date[0]['DiaSalida'];
                $citydestination = $date[0]['Localidad'];
            }
        }

        $replace_a = array('<p id="name"></p>', '<p id="message"></p>');
        $replace_b = array('<p id="name"></p>Hola! ' . $cliente . '</a>', '<p id="message"></p> Recibimos tu compra en ' . $cliente_origen . '. </br>' . 'Tu código de seguimiento es ' . $this->CodigoSeguimiento . '</br> ' . $send_date . ' !</a>');

        $mensaje = str_replace($replace_a, $replace_b, $shtml);

        // Para enviar un correo HTML, debe establecerse la cabecera Content-type
        $cabeceras  = 'MIME-Version: 1.0' . "\r\n";
        $cabeceras .= 'Content-type: text/html; charset=UTF-8' . "\r\n";

        // Cabeceras adicionales
        $cabeceras .= 'To: ' . $cliente . ' <' . $para . '>' . "\r\n";
        $cabeceras .= 'From: noreply@caddy.com.ar ' . "\r\n";
        $cabeceras .= 'Cco: prodriguez@caddy.com.ar' . "\r\n";
        // $cabeceras .= 'Bcc: birthdaycheck@example.com' . "\r\n";

        // Enviarlo
        mail($para, $título, $mensaje, $cabeceras);
    }

    private function buscarToken()
    {
        $query = "SELECT TokenId,UsuarioId,Estado from usuarios_token WHERE Token = '" . $this->token . "' AND Estado = 'Activo'";
        $resp = parent::obtenerDatos($query);

        if ($resp) {
            return $resp;
        } else {
            return 0;
        }
    }

    private function actualizarToken($tokenid)
    {
        $date = date("Y-m-d H:i");
        $query = "UPDATE usuarios_token SET Fecha = '$date' WHERE TokenId = '$tokenid' ";
        $resp = parent::nonQuery($query);
        if ($resp >= 1) {
            return $resp;
        } else {
            return 0;
        }
    }
}
//SE AGREGA EMAIL =TRUE EN EL PEDIDO DE CARGAR ENVIO PARA QUE ENVIE MAIL AL CLIENTE
//SI NO VIENE ESE PARAMETRO NO ENVIA MAIL
// SE REEMPLAZO COMPLETAMENTE EL ARRAY DE RESPUESTA PARA QUE SEA MAS CLARO Y ORDENADO