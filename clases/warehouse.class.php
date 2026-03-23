<?php
require_once __DIR__ . "/../conexion/conexion.php";
require_once __DIR__ . "/respuestas.class.php";
require_once __DIR__ . "/token.class.php";

date_default_timezone_set('America/Argentina/Cordoba');

class warehouse extends conexion
{
    private string $token = "";
    private ?array $tokenData = null;

    private function autenticarRequest(?array $datos = null)
    {
        $_respuestas = new respuestas;

        // Mantener lógica actual: X-Api-Token
        $token = Token::obtenerToken();

        // fallback opcional si algún cliente viejo lo manda en JSON
        if (!$token && is_array($datos) && !empty($datos['token'])) {
            $token = trim((string)$datos['token']);
        }

        if (!$token) {
            return $_respuestas->error_401("Token no enviado");
        }

        $this->token = $token;

        $valido = Token::validar($this->token, $this);
        if (!$valido) {
            return $_respuestas->error_401("El Token que envió es inválido o ha caducado");
        }

        $this->tokenData = $valido;

        return true;
    }

    public function post($json)
    {
        $_respuestas = new respuestas;

        $datos = json_decode($json, true);
        if (!is_array($datos)) {
            return $_respuestas->error_400("JSON inválido");
        }

        $auth = $this->autenticarRequest($datos);
        if ($auth !== true) {
            return $auth;
        }

        if (empty($datos['status'])) {
            return $_respuestas->error_400("Falta el estado del movimiento");
        }

        $status = strtolower(trim((string)$datos['status']));
        $permitidos = ['inn', 'out', 'crossdock', 'send'];

        if (!in_array($status, $permitidos, true)) {
            return $_respuestas->error_400("El estado del movimiento no es correcto");
        }

        $resp = $this->procesarMovimiento($status, $datos);

        if (is_array($resp) && isset($resp["result"]["error_id"])) {
            return $resp;
        }

        return [
            "result" => [
                "success" => 1,
                "message" => "OK",
                "status"  => $status
            ],
            "data" => $resp
        ];
    }

    public function getColectasPorFecha($fecha)
    {
        $auth = $this->autenticarRequest();
        if ($auth !== true) {
            return $auth;
        }

        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            $fecha = date('Y-m-d');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return [
                "result" => [
                    "error_id"  => 400,
                    "error_msg" => "Formato de fecha inválido. Use YYYY-MM-DD"
                ]
            ];
        }

        $desde = $this->escape($fecha . ' 00:00:00');
        $hasta = $this->escape(date('Y-m-d', strtotime($fecha . ' +1 day')) . ' 00:00:00');

        $sql = "SELECT 
                TC.id,
                TC.RazonSocial,
                C.Recorrido,
                TC.idColecta,
                TC.CodigoSeguimiento,
                TC.Wepoint_c as CodigoProveedor,
                TC.Cantidad
            FROM TransClientes TC
            INNER JOIN Colecta C 
                ON TC.idColecta = C.id
            WHERE
                TC.Eliminado = 0
                AND C.Fecha >= '$desde'
                AND C.Fecha < '$hasta'
            ORDER BY TC.Recorrido, TC.id ASC";

        $rows = parent::obtenerDatos($sql);

        if (!is_array($rows)) {
            return [
                "result" => [
                    "error_id"  => 500,
                    "error_msg" => "Error al consultar colectas"
                ]
            ];
        }

        $agrupado = [];

        foreach ($rows as $row) {
            $codigoSeguimiento = trim((string)$row['CodigoSeguimiento']);
            $codigoProveedor   = trim((string)$row['CodigoProveedor']);
            $cantidad          = isset($row['Cantidad']) ? (int)$row['Cantidad'] : 1;
            $id                = isset($row['id']) ? (int)$row['id'] : null;

            // Filtrar código padre / cabecera de colecta
            if ($codigoSeguimiento !== '' && $codigoSeguimiento === $codigoProveedor && $cantidad > 1) {
                continue;
            }

            $key = $row['Recorrido'] . '_' . $row['idColecta'];

            if (!isset($agrupado[$key])) {
                $agrupado[$key] = [
                    "Origen"    => $row['RazonSocial'],
                    "Recorrido" => $row['Recorrido'],
                    "idColecta" => $row['idColecta'],
                    "codigos"   => []
                ];
            }

            $agrupado[$key]["codigos"][] = [
                "idCaddy"   => $id,
                "CodigoSeguimiento" => $codigoSeguimiento,
                "CodigoProveedor"   => $codigoProveedor,
                "Cantidad"          => $cantidad
            ];
        }
        foreach ($agrupado as $k => $colecta) {
            if (empty($colecta['codigos'])) {
                unset($agrupado[$k]);
            }
        }
        $colectas = array_values($agrupado);

        return [
            "result" => [
                "error_id"  => 0,
                "error_msg" => "OK",
                "fecha"     => $fecha,
                "total"     => count($colectas),
                "usuario"   => $this->tokenData['UsuarioId'] ?? null,
                "cliente"   => $this->tokenData['NdeCliente'] ?? null,
                "colectas"  => $colectas
            ]
        ];
    }

    private function procesarMovimiento(string $status, array $datos)
    {
        $_respuestas = new respuestas;

        $Fecha = date('Y-m-d H:i:s');
        $Fecha_alone = date('Y-m-d');

        $id_caddy = $datos['id_caddy'] ?? [];
        $codigos  = $datos['line_items'] ?? [];
        $id_wp    = $datos['id_wp'] ?? [];

        if (!is_array($codigos) || count($codigos) === 0) {
            return $_respuestas->error_400("line_items vacío");
        }

        if (!is_array($id_wp) || count($id_wp) !== count($codigos)) {
            return $_respuestas->error_400("id_wp debe tener la misma cantidad de elementos que line_items");
        }

        if (!is_array($id_caddy)) {
            $id_caddy = [];
        }

        $pr = (int)($datos['pr'] ?? 0);
        $so = (int)($datos['so'] ?? 0);
        $oc = (int)($datos['oc'] ?? 0);

        $resultado = [
            "procesados"     => 0,
            "insertados"     => 0,
            "duplicados"     => 0,
            "no_encontrados" => [],
            "errores"        => []
        ];

        for ($i = 0; $i < count($codigos); $i++) {

            $resultado["procesados"]++;

            $raw = (string)$codigos[$i];
            $new_codigos = explode('_', $raw);
            $new_codigo  = trim((string)$new_codigos[0]);
            $idCaddyActual = isset($id_caddy[$i]) ? (int)$id_caddy[$i] : 0;

            if ($new_codigo === '' && $idCaddyActual <= 0) {
                $resultado["errores"][] = ["codigo" => $raw, "error" => "Código vacío"];
                continue;
            }

            if ($idCaddyActual > 0) {
                $query_tc = "SELECT id, Recorrido, CodigoSeguimiento, Wepoint_c
                         FROM TransClientes
                         WHERE id='" . $idCaddyActual . "'
                         AND Eliminado=0
                         AND Entregado=0
                         AND Devuelto=0
                         AND Haber=0
                         LIMIT 1";
            } else {
                $query_tc = "SELECT id, Recorrido, CodigoSeguimiento, Wepoint_c
                         FROM TransClientes
                         WHERE Wepoint_c='" . $this->escape($new_codigo) . "'
                         AND Eliminado=0
                         AND Entregado=0
                         AND Devuelto=0
                         AND Haber=0
                         LIMIT 1";
            }

            $recorrido = parent::obtenerDatos($query_tc);

            if (!$recorrido || empty($recorrido[0])) {
                $resultado["no_encontrados"][] = $idCaddyActual > 0 ? $idCaddyActual : $new_codigo;
                continue;
            }

            $CodigoProveedorReal = $recorrido[0]['Wepoint_c'] ?? $new_codigo;
            $Recorrido = $recorrido[0]['Recorrido'] ?? '';
            $CodigoSeguimientoCaddy = $recorrido[0]['CodigoSeguimiento'] ?? '';

            try {

                if ($status === 'inn') {
                    $ok = $this->registrarMovimientoWepoint([
                        "tipo" => "Ingreso",
                        "flagCol" => "Ingreso",
                        "flagVal" => 1,
                        "new_codigo" => $CodigoProveedorReal,
                        "pr" => $pr,
                        "so" => $so,
                        "oc" => $oc,
                        "Recorrido" => $Recorrido,
                        "Fecha" => $Fecha,
                        "Fecha_alone" => $Fecha_alone,
                        "CodigoSeguimientoCaddy" => $CodigoSeguimientoCaddy,
                        "id_wp" => (string)$id_wp[$i],
                    ]);

                    if ($ok === "DUP") {
                        $resultado["duplicados"]++;
                    } elseif ($ok === true) {
                        $resultado["insertados"]++;
                        $this->updateTransClientesWepointStatus($CodigoProveedorReal, "Ingreso");
                    } else {
                        $resultado["errores"][] = [
                            "codigo" => $idCaddyActual > 0 ? $idCaddyActual : $CodigoProveedorReal,
                            "error" => "No se pudo insertar INN"
                        ];
                    }
                }

                if ($status === 'out') {
                    $ok = $this->registrarMovimientoWepoint([
                        "tipo" => "Egreso",
                        "flagCol" => "Egreso",
                        "flagVal" => 1,
                        "new_codigo" => $CodigoProveedorReal,
                        "pr" => $pr,
                        "so" => $so,
                        "oc" => $oc,
                        "Recorrido" => $Recorrido,
                        "Fecha" => $Fecha,
                        "Fecha_alone" => $Fecha_alone,
                        "CodigoSeguimientoCaddy" => $CodigoSeguimientoCaddy,
                        "id_wp" => (string)$id_wp[$i],
                    ]);

                    if ($ok === "DUP") {
                        $resultado["duplicados"]++;
                    } elseif ($ok === true) {
                        $resultado["insertados"]++;
                        $this->updateTransClientesWepointStatus($CodigoProveedorReal, "Egreso");
                    } else {
                        $resultado["errores"][] = [
                            "codigo" => $idCaddyActual > 0 ? $idCaddyActual : $CodigoProveedorReal,
                            "error" => "No se pudo insertar OUT"
                        ];
                    }
                }

                if ($status === 'crossdock') {
                    $ok = $this->registrarCrossdock([
                        "new_codigo" => $CodigoProveedorReal,
                        "pr" => $pr,
                        "so" => $so,
                        "oc" => $oc,
                        "Recorrido" => $Recorrido,
                        "Fecha" => $Fecha,
                        "Fecha_alone" => $Fecha_alone,
                        "CodigoSeguimientoCaddy" => $CodigoSeguimientoCaddy,
                        "id_wp" => (string)$id_wp[$i],
                    ]);

                    if ($ok === "DUP") {
                        $resultado["duplicados"]++;
                    } elseif ($ok === true) {
                        $resultado["insertados"]++;
                        $this->updateTransClientesWepointStatus($CodigoProveedorReal, "Crossdock");
                    } else {
                        $resultado["errores"][] = [
                            "codigo" => $idCaddyActual > 0 ? $idCaddyActual : $CodigoProveedorReal,
                            "error" => "No se pudo insertar CROSSDOCK"
                        ];
                    }
                }

                if ($status === 'send') {
                    $updated = $this->updateTransClientesWepointStatus($CodigoProveedorReal, "Enviado");

                    if (!$updated) {
                        $resultado["errores"][] = [
                            "codigo" => $idCaddyActual > 0 ? $idCaddyActual : $CodigoProveedorReal,
                            "error" => "No se pudo actualizar status SEND"
                        ];
                    } else {
                        $resultado["insertados"]++;
                    }
                }
            } catch (\Throwable $e) {
                $resultado["errores"][] = [
                    "codigo" => $idCaddyActual > 0 ? $idCaddyActual : $CodigoProveedorReal,
                    "error" => $e->getMessage()
                ];
            }
        }

        return $resultado;
    }
    private function registrarMovimientoWepoint(array $p)
    {
        $query_control = "SELECT id
                          FROM wepoint
                          WHERE " . $p["flagCol"] . "='" . (int)$p["flagVal"] . "'
                          AND so='" . (int)$p["so"] . "'
                          AND oc='" . (int)$p["oc"] . "'
                          AND CodigoSeguimiento='" . $this->escape($p["new_codigo"]) . "'
                          LIMIT 1";

        $existe = parent::obtenerDatos($query_control);
        if ($existe && !empty($existe[0])) {
            return "DUP";
        }

        $colsFlags = "`" . $p["flagCol"] . "`";
        $valsFlags = "'" . (int)$p["flagVal"] . "'";

        $query = "INSERT INTO wepoint
            (`CodigoSeguimiento`, $colsFlags, `pr`, `so`, `oc`, `Recorrido`, `Time`, `CodigoSeguimiento_caddy`, `Fecha`, `id_wp`)
            VALUES
            ('" . $this->escape($p["new_codigo"]) . "', $valsFlags, '" . (int)$p["pr"] . "', '" . (int)$p["so"] . "', '" . (int)$p["oc"] . "',
             '" . $this->escape((string)$p["Recorrido"]) . "', '" . $this->escape((string)$p["Fecha"]) . "',
             '" . $this->escape((string)$p["CodigoSeguimientoCaddy"]) . "', '" . $this->escape((string)$p["Fecha_alone"]) . "',
             '" . $this->escape((string)$p["id_wp"]) . "')";

        $id = parent::nonQueryId($query);
        return $id ? true : false;
    }

    private function registrarCrossdock(array $p)
    {
        $query_control = "SELECT id
                          FROM wepoint
                          WHERE Crossdock='1'
                          AND so='" . (int)$p["so"] . "'
                          AND oc='" . (int)$p["oc"] . "'
                          AND CodigoSeguimiento='" . $this->escape($p["new_codigo"]) . "'
                          LIMIT 1";

        $existe = parent::obtenerDatos($query_control);
        if ($existe && !empty($existe[0])) {
            return "DUP";
        }

        $Ingreso = 1;
        $Egreso = 1;
        $Crossdock = 1;

        $query = "INSERT INTO wepoint
            (`CodigoSeguimiento`, `Ingreso`, `Crossdock`, `Egreso`, `pr`, `so`, `oc`, `Recorrido`, `Time`, `CodigoSeguimiento_caddy`, `Fecha`, `id_wp`)
            VALUES
            ('" . $this->escape($p["new_codigo"]) . "', '" . $Ingreso . "', '" . $Crossdock . "', '" . $Egreso . "',
             '" . (int)$p["pr"] . "', '" . (int)$p["so"] . "', '" . (int)$p["oc"] . "',
             '" . $this->escape((string)$p["Recorrido"]) . "', '" . $this->escape((string)$p["Fecha"]) . "',
             '" . $this->escape((string)$p["CodigoSeguimientoCaddy"]) . "', '" . $this->escape((string)$p["Fecha_alone"]) . "',
             '" . $this->escape((string)$p["id_wp"]) . "')";

        $id = parent::nonQueryId($query);
        return $id ? true : false;
    }

    private function updateTransClientesWepointStatus(string $wepoint_c, string $status): bool
    {
        $fecha = date('Y-m-d');
        $hora  = date('H:i:s');

        $query_update = "UPDATE TransClientes
                         SET Wepoint_f='" . $this->escape($fecha) . "',
                             Wepoint_h='" . $this->escape($hora) . "',
                             Wepoint_status='" . $this->escape($status) . "'
                         WHERE Wepoint_c='" . $this->escape($wepoint_c) . "'
                         AND Entregado=0
                         AND Eliminado=0
                         AND Devuelto=0
                         LIMIT 1";

        $aff = parent::nonQuery($query_update);
        return $aff > 0;
    }

    private function escape(string $s): string
    {
        return parent::escapeString($s);
    }
}
