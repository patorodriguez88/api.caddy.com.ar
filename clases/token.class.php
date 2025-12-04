<?php
require_once __DIR__ . '/../conexion/conexion.php';

class Token
{
    /**
     * Obtiene el token desde:
     *  - Authorization: Bearer xxx
     *  - X-Api-Token: xxx   (o "Bearer xxx")
     *  - ?token=xxx
     *  - $_POST['token']
     */
    public static function obtenerToken(): ?string
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        // Normalizamos las claves a minúsculas
        $norm = [];
        foreach ($headers as $k => $v) {
            $norm[strtolower($k)] = $v;
        }

        // 1) Authorization: Bearer xxx  (si algún día llega)
        if (isset($norm['authorization'])) {
            $authHeader = trim($norm['authorization']);

            if (stripos($authHeader, 'Bearer ') === 0) {
                $token = trim(substr($authHeader, 7));
                if ($token !== '') {
                    return $token;
                }
            }
        }

        // 2) X-Api-Token: xxx  (lo que vemos en el log)
        if (isset($norm['x-api-token'])) {
            $value = trim($norm['x-api-token']);

            // Si viene como "Bearer xxx", le saco el prefijo
            if (stripos($value, 'Bearer ') === 0) {
                $value = trim(substr($value, 7));
            }

            if ($value !== '') {
                return $value;
            }
        }

        // 3) ?token=xxx en query
        if (!empty($_GET['token'])) {
            return trim($_GET['token']);
        }

        // 4) token en body (por si en algún endpoint lo mandás por POST)
        if (!empty($_POST['token'])) {
            return trim($_POST['token']);
        }

        return null;
    }

    /**
     * Valida el token contra la BD.
     */
    public static function validar(string $token, conexion $db): ?array
    {
        $query = "
            SELECT 
                ut.TokenId,
                ut.UsuarioId,
                ut.Estado,
                u.NdeCliente
            FROM usuarios_token AS ut
            JOIN usuarios AS u ON ut.UsuarioId = u.id
            WHERE ut.Token = '" . $token . "'
              AND ut.Estado = 'Activo'
            LIMIT 1
        ";

        $resp = $db->obtenerDatos($query);

        return ($resp && isset($resp[0])) ? $resp[0] : null;
    }
}
