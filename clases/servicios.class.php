<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
//SE AGREGA EMAIL =TRUE EN EL PEDIDO DE CARGAR ENVIO PARA QUE ENVIE MAIL AL CLIENTE
//SI NO VIENE ESE PARAMETRO NO ENVIA MAIL

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}
// require_once "../conexion/conexion.php";
require_once "respuestas.class.php";
require_once "pricing.class.php";

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
        $estado   = parent::escapar($estado);

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

    // public function obtenerSeguimiento($id, $idClienteOrigen)
    // {
    //     $_respuestas     = new respuestas;
    //     $idClienteOrigen = (int)$idClienteOrigen;

    //     if ($idClienteOrigen <= 0) {
    //         return $_respuestas->error_401('Cliente no asociado al token');
    //     }

    //     // Busco el cliente origen real del envío
    //     $query = "SELECT IngBrutosOrigen FROM " . $this->tableTrans . " WHERE CodigoSeguimiento = '" . $id . "'";
    //     $datos = parent::obtenerDatos($query);

    //     if (!$datos || !isset($datos[0]['IngBrutosOrigen'])) {
    //         return $_respuestas->error_204('No se encontraron datos del envío');
    //     }

    //     $id2 = (int)$datos[0]['IngBrutosOrigen'];

    //     // Si el envío pertenece a otro cliente, verifico relación / permisos
    //     if ($id2 !== $idClienteOrigen) {
    //         $query = "SELECT Relacion,AdminEnvios FROM " . $this->tableClientes . " WHERE id = '" . $id2 . "'";
    //         $datos = parent::obtenerDatos($query);

    //         if (
    //             !$datos ||
    //             (
    //                 (int)$datos[0]['Relacion'] !== $idClienteOrigen &&
    //                 (int)$datos[0]['AdminEnvios'] !== 1
    //             )
    //         ) {
    //             return $_respuestas->error_204();
    //         }
    //     }

    //     // Si llegamos acá, el cliente está autorizado a ver el seguimiento
    //     $query = "SELECT id,Fecha,Hora,Usuario,CodigoSeguimiento,Observaciones,Estado,NombreCompleto,Dni,Destino 
    //               FROM " . $this->table . " 
    //               WHERE CodigoSeguimiento = '" . $id . "'";

    //     $datos = parent::obtenerDatos($query);

    //     if ($datos) {
    //         return $datos;
    //     }

    //     return $_respuestas->error_204();
    // }
    public function obtenerSeguimiento($id, $idClienteOrigen)
    {
        $_respuestas     = new respuestas;
        $idClienteOrigen = (int)$idClienteOrigen;

        if ($idClienteOrigen <= 0) {
            return $_respuestas->error_401('Cliente no asociado al token');
        }

        $id = parent::escapar($id);

        // ==============================
        // 1) Determinar dueño del envío
        //    (TransClientes.IngBrutosOrigen)
        // ==============================
        $queryEnvio = "SELECT IngBrutosOrigen
                   FROM " . $this->tableTrans . " 
                   WHERE CodigoSeguimiento = '" . $id . "'
                   LIMIT 1";
        $datosEnvio = parent::obtenerDatos($queryEnvio);

        $autorizado   = false;
        $idClienteEnv = 0;

        if ($datosEnvio && isset($datosEnvio[0]['IngBrutosOrigen'])) {
            $idClienteEnv = (int)$datosEnvio[0]['IngBrutosOrigen'];

            if ($idClienteEnv === $idClienteOrigen) {
                // Es el mismo cliente dueño del envío
                $autorizado = true;
            } else {
                // Verifico si el cliente del envío está relacionado
                $queryCli = "SELECT Relacion,AdminEnvios 
                         FROM " . $this->tableClientes . " 
                         WHERE id = '" . $idClienteEnv . "' 
                         LIMIT 1";
                $datosCli = parent::obtenerDatos($queryCli);

                if (
                    $datosCli &&
                    (
                        (int)$datosCli[0]['Relacion'] === $idClienteOrigen ||
                        (int)$datosCli[0]['AdminEnvios'] === 1
                    )
                ) {
                    $autorizado = true;
                }
            }
        } else {
            // No está en TransClientes → puede estar solo en PreVenta
            // Chequeo dueño por NCliente
            $sqlPreOwner = "SELECT NCliente 
                        FROM PreVenta 
                        WHERE CodigoSeguimiento = '" . $id . "'
                          AND Eliminado = '0'
                        ORDER BY id DESC
                        LIMIT 1";
            $datosOwner = parent::obtenerDatos($sqlPreOwner);

            if ($datosOwner && isset($datosOwner[0]['NCliente'])) {
                $idClienteEnv = (int)$datosOwner[0]['NCliente'];

                if ($idClienteEnv === $idClienteOrigen) {
                    $autorizado = true;
                } else {
                    $queryCli = "SELECT Relacion,AdminEnvios 
                             FROM " . $this->tableClientes . " 
                             WHERE id = '" . $idClienteEnv . "' 
                             LIMIT 1";
                    $datosCli = parent::obtenerDatos($queryCli);

                    if (
                        $datosCli &&
                        (
                            (int)$datosCli[0]['Relacion'] === $idClienteOrigen ||
                            (int)$datosCli[0]['AdminEnvios'] === 1
                        )
                    ) {
                        $autorizado = true;
                    }
                }
            }
        }

        if (!$autorizado) {
            // Para no revelar si el código existe o no, devolvemos 204 “sin datos”
            return $_respuestas->error_204('No se encontraron datos del envío');
        }

        // ==============================
        // 2) Buscar movimientos en Seguimiento
        // ==============================
        $querySeg = "SELECT id,Fecha,Hora,Usuario,CodigoSeguimiento,Observaciones,Estado,NombreCompleto,Dni,Destino
                 FROM " . $this->table . "
                 WHERE CodigoSeguimiento = '" . $id . "'
                 ORDER BY id ASC";
        $datosSeg = parent::obtenerDatos($querySeg);

        if ($datosSeg && count($datosSeg) > 0) {
            $respuesta           = $_respuestas->response;
            $respuesta["result"] = $datosSeg;
            return $respuesta;
        }

        // ==============================
        // 3) Si no hay Seguimiento, revisar PreVenta pendiente
        // ==============================
        $sqlPreVenta = "SELECT id, Fecha, Hora, ClienteDestino, DomicilioDestino, LocalidadDestino, Cargado
                    FROM PreVenta
                    WHERE CodigoSeguimiento = '" . $id . "'
                      AND Eliminado='0'
                    ORDER BY id DESC
                    LIMIT 1";
        $datosPre = parent::obtenerDatos($sqlPreVenta);

        if ($datosPre && isset($datosPre[0]['Cargado']) && (int)$datosPre[0]['Cargado'] === 0) {

            // Armamos una fila con la misma estructura que Seguimiento
            $fila = array(
                "id"                => $datosPre[0]['id'],
                "Fecha"             => isset($datosPre[0]['Fecha']) ? $datosPre[0]['Fecha'] : "",
                "Hora"              => isset($datosPre[0]['Hora']) ? $datosPre[0]['Hora'] : "",
                "Usuario"           => "API",
                "CodigoSeguimiento" => $id,
                "Observaciones"     => "La venta aun no fue cargada al sistema de seguimiento",
                "Estado"            => "Pendiente",
                "NombreCompleto"    => isset($datosPre[0]['ClienteDestino']) ? $datosPre[0]['ClienteDestino'] : "",
                "Dni"               => "",
                "Destino"           => (isset($datosPre[0]['DomicilioDestino']) ? $datosPre[0]['DomicilioDestino'] : "")
                    . (isset($datosPre[0]['LocalidadDestino']) ? " - " . $datosPre[0]['LocalidadDestino'] : "")
            );

            $respuesta           = $_respuestas->response;
            $respuesta["result"] = array($fila);

            return $respuesta;
        }

        // ==============================
        // 4) No hay info
        // ==============================
        return $_respuestas->error_204("Seguimiento no encontrado");
    }

    // BUSCO EN SEGUIMIENTO DESDE EL ID DEL PROVEEDOR
    public function obtenerSeguimientoProveedor($id, $idClienteOrigen)
    {
        $_respuestas     = new respuestas;
        $idClienteOrigen = (int)$idClienteOrigen;

        if ($idClienteOrigen <= 0) {
            return $_respuestas->error_401('Cliente no asociado al token');
        }

        $id = parent::escapar($id);

        // Busco el envío por CódigoProveedor
        $query = "SELECT IngBrutosOrigen,CodigoSeguimiento FROM " . $this->tableTrans . " WHERE CodigoProveedor = '" . $id . "'";
        $datos = parent::obtenerDatos($query);

        if (!$datos || !isset($datos[0]['IngBrutosOrigen'])) {
            return $_respuestas->error_204('No se encontraron envíos para ese proveedor');
        }

        $id2   = (int)$datos[0]['IngBrutosOrigen'];
        $id2Cs = parent::escapar($datos[0]['CodigoSeguimiento']);

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

        // --- BOX[] / CANTIDAD: contrato de compatibilidad ---
        // - Box con 1 elemento + Cantidad=N -> modo legacy, se replica ese bulto N veces
        //   (igual que el comportamiento de siempre, ningún cliente actual necesita cambiar nada).
        // - Box con exactamente N elementos (N=Cantidad, N>1) -> modo nuevo, bultos heterogéneos.
        // - Cualquier otra combinación -> error.

        $this->cantidad = isset($datos['Cantidad']) ? (int)$datos['Cantidad'] : 1;
        if ($this->cantidad < 1) {
            $this->cantidad = 1;
        }

        $boxIn = $datos['Box'] ?? [];
        if (!is_array($boxIn) || count($boxIn) === 0) {
            return $_respuestas->error_400('Debe enviar al menos un bulto en Box');
        }

        $esModoHeterogeneo = count($boxIn) > 1;

        if (count($boxIn) === 1) {
            $bultosDim = array_fill(0, $this->cantidad, $boxIn[0]);
        } elseif (count($boxIn) === $this->cantidad) {
            $bultosDim = array_values($boxIn);
        } else {
            return $_respuestas->error_400('La cantidad de bultos enviados en Box no coincide con Cantidad');
        }

        $cobranza     = $datos['Cobranza'] ?? 0;
        $codigoPostal = $datos['CodigoPostal'];
        $servicioFlex = (($datos['Servicio'] ?? null) == 3);

        // Valor declarado a nivel envío: fallback para bultos que no traigan el propio.
        $valorDeclaradoEnvio = (!isset($datos['ValorDeclarado']) || $datos['ValorDeclarado'] == 0)
            ? 20000
            : (float)$datos['ValorDeclarado'];

        $bultosResueltos = [];
        foreach ($bultosDim as $i => $box) {
            $length = $box['Length'] ?? null;
            $width  = $box['Width']  ?? null;
            $height = $box['Height'] ?? null;
            $weight = $box['Weight'] ?? null;

            $price = $this->resolverTarifaBulto($servicioFlex, $codigoPostal, $length, $width, $height, $weight);

            if ($price === 'faltan_datos') {
                return $_respuestas->error_400('Faltan datos del paquete en el bulto ' . ($i + 1));
            }
            if ($price === 4) {
                return $_respuestas->error_400('Error en localidad');
            }
            if (empty($price[0]['id'])) {
                return $_respuestas->error_400('Error en la obtención de precio para el bulto ' . ($i + 1));
            }

            $bultosResueltos[] = [
                'tarifa'         => (float)$price[0]['PrecioVenta'],
                'titulo'         => $price[0]['Titulo'],
                'length'         => $length,
                'width'          => $width,
                'height'         => $height,
                'weight'         => $weight,
                'valorDeclarado' => isset($box['ValorDeclarado']) ? (float)$box['ValorDeclarado'] : $valorDeclaradoEnvio,
            ];
        }

        // Curva de descuento universal (clases/pricing.class.php): 1º bulto (el más
        // caro) 100%, 2º 0%, 3º en adelante 50% c/u. Misma función que usan /rates,
        // /rates_v2 y /rates_v3, para que cotización y cobro nunca se desalineen.
        $bultosConDescuento = Pricing::aplicarDescuentoPorBulto($bultosResueltos, 'tarifa');

        // Bulto representante = el más caro (el mismo que queda en 100% en la curva).
        // Alimenta los campos legacy de un solo bulto (Titulo/Tarifa/Length/Width/Height).
        // En modo legacy (bultos idénticos) da exactamente el resultado de siempre.
        $indiceRepresentante = 0;
        $tarifaMax = -INF;
        foreach ($bultosResueltos as $i => $b) {
            if ($b['tarifa'] > $tarifaMax) {
                $tarifaMax = $b['tarifa'];
                $indiceRepresentante = $i;
            }
        }
        $representante = $bultosResueltos[$indiceRepresentante];
        $titulo_rate   = $representante['titulo'];
        $tarifa_rate   = $representante['tarifa'];
        $length        = $representante['length'];
        $width         = $representante['width'];
        $height        = $representante['height'];
        $weight        = $representante['weight'];

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
            // VALIDAR QUE EL USUARIO TENGA NdeCliente
            if (
                !$ClienteOrigen ||
                empty($ClienteOrigen[0]['id']) ||
                empty($ClienteOrigen[0]['nombrecliente'])
            ) {
                return $_respuestas->error_401(
                    'El usuario no tiene un cliente asignado (NdeCliente). No es posible crear envíos.'
                );
            }
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

        // SERVICIO
        if (isset($datos['Servicio']) && $datos['Servicio'] == 3) {
            $this->servicio = 0;
        } else {
            $this->servicio = 1;
        }

        // VALOR DECLARADO: suma de los declarados efectivos de cada bulto
        // (en modo legacy con 1 solo bulto real, coincide con el valor de siempre).
        $this->valordec = array_sum(array_column($bultosConDescuento, 'valorDeclarado'));

        // ID PROVEEDOR
        if (isset($datos['idProveedor'])) {
            $this->idproveedor = $datos['idProveedor'];
        }

        // ENVIAR MAIL
        $enviar_mail = !empty($datos['EnviarMail']);

        // INSERTAR VENTA
        $venta = $this->insertarVenta($bultosConDescuento, $titulo_rate, $tarifa_rate, $cobranza, $enviar_mail);

        if (!$venta || empty($venta['id_preventa'])) {
            return $_respuestas->error_500();
        }

        $date = $this->date_send($codigoPostal);

        $citydestination = isset($date[0]['Localidad']) ? $date[0]['Localidad'] : '';
        $send_date       = isset($date[0]['DiaSalida']) ? $date[0]['DiaSalida'] : '';
        $Total             = array_sum(array_column($bultosConDescuento, 'precioAplicado'));
        $tarifa_rate_label = round($tarifa_rate);
        $total_label       = round($Total);

        // "Box" en la respuesta: en modo legacy (1 solo bulto real) se mantiene
        // como objeto único, igual que siempre. En modo heterogéneo se devuelve
        // el detalle de cada bulto.
        if ($esModoHeterogeneo) {
            $boxRespuesta = array_map(function ($b) {
                return array(
                    "Length"         => $b['length'],
                    "Width"          => $b['width'],
                    "Height"         => $b['height'],
                    "Weight"         => $b['weight'],
                    "ValorDeclarado" => $b['valorDeclarado'],
                    "Tarifa_Lista"   => round($b['tarifa']),
                    "Tarifa"         => round($b['precioAplicado']),
                );
            }, $bultosConDescuento);
        } else {
            $boxRespuesta = array(
                "Length" => $length,
                "Width"  => $width,
                "Height" => $height,
                "Weight" => $weight
            );
        }

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

            "Box" => $boxRespuesta,

            "Cobranza"       => $cobranza,
            "Servicio"       => $this->servicio,
            "ValorDeclarado" => $this->valordec,
            "EnviarMail"     => $enviar_mail
        );

        return $respuesta;
    }

    // Resuelve la tarifa de UN bulto (extraído de la lógica que antes vivía
    // inline en post() para un solo Box[0]; ahora se llama una vez por bulto).
    private function resolverTarifaBulto($esFlex, $codigoPostal, $length, $width, $height, $weight)
    {
        if ($esFlex && ($codigoPostal >= '5000') && ($codigoPostal <= '5023')) {
            return $this->rate_flex();
        }

        $dim = $this->calc_dim($length, $width, $height, $weight);
        if ($dim == 0) {
            return 'faltan_datos';
        }

        return $this->rate($codigoPostal, $length, $width, $height, $weight);
    }

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

            $query_dist = "SELECT Km FROM Localidades WHERE Cp='" . parent::escapar($codigoPostal) . "'";

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
        $Localidad = trim((string)$codigopostal);

        if ($Localidad === '' || $Localidad === '0') {
            $Localidad = '5000';
        }

        if ($Localidad >= '5000' && $Localidad <= '5023') {
            $Localidad = '5000';
        }

        $query = "SELECT DiaSalida, Localidad
              FROM Localidades
              WHERE Cp = '" . parent::escapar($Localidad) . "'
              LIMIT 1";

        $resp = parent::obtenerDatos($query);

        if (!$resp) {
            return array();
        }

        return $resp;
    }

    //WEBHOOK
    public function webhook($webhook, $webhook_id)
    {
        $webhook    = parent::escapar($webhook);
        $webhook_id = parent::escapar($webhook_id);

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
        // Copias escapadas solo para armar SQL; $NombreOrigen/$DireccionOrigen
        // se mantienen intactas para las comparaciones contra los datos de la BD.
        $idProveedorSql  = parent::escapar($idProveedor);
        $NombreOrigenSql = parent::escapar($NombreOrigen);

        $query = "SELECT NdeCliente FROM usuarios WHERE id = '" . $idUsuario . "'";
        $resp0 = parent::obtenerDatos($query);
        $DireccionOrigen_utf = $DireccionOrigen;
        $DireccionOrigenSql  = parent::escapar($DireccionOrigen);

        $query = "SELECT nombrecliente,id,Direccion FROM Clientes WHERE Relacion= '" . $resp0[0]['NdeCliente'] . "' AND idProveedor = '" . $idProveedorSql . "'";
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
            $query = "SELECT nombrecliente,id,Direccion FROM Clientes WHERE nombrecliente = '" . $NombreOrigenSql . "' AND Direccion like '%" . $DireccionOrigenSql . "%'"; //utf8_decode
            $resp1 = parent::obtenerDatos($query);
            if ($resp1[0]['id']) {
                $query = "UPDATE Clientes SET idProveedor = '" . $idProveedorSql . "' WHERE nombrecliente = '" . $NombreOrigenSql . "' AND Direccion like '%" . $DireccionOrigenSql . "%' ";
                $resp = parent::nonQuery($query);
                if ($resp >= 1) {
                    return $resp1;
                } else {
                    return 4;
                }
            }
        }
    }



    private function insertarVenta(array $bultos, $titulo_rate, $tarifa_rate, $cobranza, $enviar_mail)
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
        $Total  = array_sum(array_column($bultos, 'precioAplicado'));
        $WeightTotal = array_sum(array_column($bultos, 'weight'));

        // Bulto representante = el que quedó al 100% en la curva de descuento
        // (el más caro) — alimenta las columnas Length/Width/Height, pensadas
        // para un solo bulto. En modo legacy (bultos idénticos) da lo mismo de siempre.
        $representante = $bultos[0];
        foreach ($bultos as $b) {
            if (abs(($b['porcentajeAplicado'] ?? 0) - 1.0) < 0.0001) {
                $representante = $b;
                break;
            }
        }
        $length = $representante['length'];
        $width  = $representante['width'];
        $height = $representante['height'];

        $direccion = $this->direccion;
        $ciudad = $this->ciudad;
        $DireccionClienteOrigen = (string)($this->DireccionClienteOrigen ?? '');
        $ClienteDestino = $this->nombre;

        // Copias escapadas para armar SQL. Las variables/propiedades originales
        // de arriba se mantienen sin escapar para lógica, comparaciones y la
        // respuesta JSON al cliente.
        $direccionSql               = parent::escapar($direccion);
        $ciudadSql                  = parent::escapar($ciudad);
        $DireccionClienteOrigenSql  = parent::escapar($DireccionClienteOrigen);
        $ClienteDestinoSql          = parent::escapar($ClienteDestino);

        //BUSCO EL CLIENTE DESTINO
        $query = "SELECT id,Observaciones FROM Clientes WHERE nombrecliente = '" . $ClienteDestinoSql . "' AND Direccion = '" . $direccionSql . "' LIMIT 1";
        $resp = parent::obtenerDatos($query);

        if ($resp) {

            //SI YA EXISTE EL CLIENTE OBTENEMOS EL ID
            $idClienteDestino = $resp[0]['id'];

            //SI OBSERVACIONES DE QUE NOS ENVIA EL CLIENTE NO SON NULL ACTUALIZAMOS EL CAMPO DE LA BD
            if ($this->Observaciones_clean <> null) {

                //SI LAS OBSERVACIONES QUE ME ENVIA EL CLIENTE DIFIEREN DE LAS QUE TENGO EN LA BD, ACTUALIZO LAS OBSERVACIONES
                if ($resp[0]['Observaciones'] <> $this->Observaciones_clean) {

                    $query = "UPDATE Clientes SET Observaciones = '" . parent::escapar($this->Observaciones_clean) . "' WHERE id = '" . parent::escapar($idClienteDestino) . "' LIMIT 1";
                    $resp = parent::nonQuery($query);
                }
            }
        } else {

            $query = "SELECT MAX(id)as id FROM Clientes";
            $respmax = parent::obtenerDatos($query);

            $NewidCliente = trim($respmax[0]['id']) + 1;

            $query = "INSERT INTO Clientes (NdeCliente,nombrecliente,Direccion,Ciudad,Telefono,Celular,Celular2,Cuit,Relacion,Pais,Mail,CodigoPostal,Observaciones)VALUES
    ('" . $NewidCliente . "','" . $ClienteDestinoSql . "','" . $direccionSql . "','" . $ciudadSql . "','" . parent::escapar($this->telefono) . "','" . parent::escapar($this->telefono) . "',
    '" . parent::escapar($this->telefono) . "','" . parent::escapar($this->dni) . "','" . parent::escapar($this->idClienteOrigen) . "','Argentina','" . parent::escapar($this->email) . "','" . parent::escapar($this->codigoPostal) . "','" . parent::escapar($this->Observaciones) . "')";

            $respclientes = parent::nonQueryId($query);
            $idClienteDestino = $respclientes;
        }

        //INSERTO LA VENTA EN PREVENTA
        $CodigoSeguimiento = parent::generarCodigo(9);
        $this->CodigoSeguimiento = $CodigoSeguimiento;

        // Salvaguarda para producción: crea la tabla de detalle de bultos si
        // todavía no existe (operación barata cuando ya existe). El DDL
        // "oficial" se corre a mano por phpMyAdmin antes del deploy; esto es
        // solo para que nunca falle si alguien se olvida de ese paso.
        parent::nonQuery(
            "CREATE TABLE IF NOT EXISTS `PreVentaBultos` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `CodigoSeguimiento` VARCHAR(9) NOT NULL,
                `Indice` TINYINT UNSIGNED NOT NULL,
                `Length` DECIMAL(10,4) NOT NULL,
                `Width` DECIMAL(10,4) NOT NULL,
                `Height` DECIMAL(10,4) NOT NULL,
                `Weight` DECIMAL(10,3) NOT NULL,
                `ValorDeclarado` DECIMAL(12,2) NOT NULL DEFAULT 0,
                `PorcentajeAplicado` DECIMAL(4,3) NOT NULL,
                `PrecioAplicado` DECIMAL(12,2) NOT NULL,
                `Fecha` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_codigoseguimiento` (`CodigoSeguimiento`),
                UNIQUE KEY `uq_codigo_indice` (`CodigoSeguimiento`, `Indice`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

        foreach ($bultos as $i => $b) {
            $query_bulto = "INSERT INTO `PreVentaBultos`
                (`CodigoSeguimiento`,`Indice`,`Length`,`Width`,`Height`,`Weight`,
                 `ValorDeclarado`,`PorcentajeAplicado`,`PrecioAplicado`,`Fecha`)
                VALUES ('" . $CodigoSeguimiento . "','" . ($i + 1) . "',
                '" . parent::escapar($b['length']) . "','" . parent::escapar($b['width']) . "',
                '" . parent::escapar($b['height']) . "','" . parent::escapar($b['weight']) . "',
                '" . parent::escapar($b['valorDeclarado']) . "','" . parent::escapar($b['porcentajeAplicado']) . "',
                '" . parent::escapar($b['precioAplicado']) . "','" . $Fecha . "')";
            parent::nonQuery($query_bulto);
        }

        $query_preventa = "INSERT INTO `PreVenta`(`Fecha`, `RazonSocial`, `NCliente`, `TipoDeComprobante`, `NumeroComprobante`, `Cantidad`,`Precio`,`Total`,
        `ClienteDestino`, `idClienteDestino`, `DomicilioDestino`, `LocalidadDestino`,`NumeroVenta`, `DomicilioOrigen`,`LocalidadOrigen`, `Usuario`,
        `EntregaEn`,`Observaciones`,`Hora`,`Telefono`,`Celular`,`Retirado`,`ValorDeclarado`,`idProveedor`,`Length`, `Width`, `Height`, `Weight`,`cpdestino`,`Cobranza`,`CodigoSeguimiento`)VALUES
        ('" . $Fecha . "','" . parent::escapar($this->ClienteOrigen) . "','" . parent::escapar($this->idClienteOrigen) . "','" . parent::escapar($titulo_rate) . "','"  . $Codigo . "','" . $this->cantidad . "','" . $Precio . "','" . $Total . "','" . $ClienteDestinoSql . "',
        '" . parent::escapar($idClienteDestino) . "','" . $direccionSql . "','" . $ciudadSql . "','" . parent::escapar($DatoNV) . "','" . $DireccionClienteOrigenSql . "','Cordoba','" . parent::escapar($this->token) . "',
        'Domicilio','" . parent::escapar($this->Observaciones) . "','" . $Hora . "','" . parent::escapar($this->telefono) . "','" . parent::escapar($this->telefono) . "','" . $this->servicio . "','" . parent::escapar($this->valordec) . "','" . parent::escapar($this->idproveedor) . "',
        '" . parent::escapar($length) . "','" . parent::escapar($width) . "','" . parent::escapar($height) . "','" . parent::escapar($WeightTotal) . "','" . parent::escapar($this->codigoPostal) . "','" . parent::escapar($cobranza) . "','" . $CodigoSeguimiento . "')";

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
        $query = "SELECT TokenId,UsuarioId,Estado from usuarios_token WHERE Token = '" . parent::escapar($this->token) . "' AND Estado = 'Activo'";
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