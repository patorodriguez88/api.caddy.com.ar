<?php
require_once __DIR__ . '/../conexion/conexion.php';

class Token
{
    public static function obtenerToken(): ?string
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        // Normalizar claves a minúsculas para buscar fácil
        $norm = [];
        foreach ($headers as $k => $v) {
            $norm[strtolower($k)] = $v;
        }

        // 1) Intentar Authorization: Bearer (por si algún día funciona)
        if (isset($norm['authorization'])) {
            $authHeader = $norm['authorization'];
            if (stripos($authHeader, 'Bearer ') === 0) {
                $token = trim(substr($authHeader, 7));
                if ($token !== '') {
                    return $token;
                }
            }
        }

        // 2) Header alternativo: X-Api-Token
        if (isset($norm['x-api-token']) && trim($norm['x-api-token']) !== '') {
            return trim($norm['x-api-token']);
        }

        // 3) Fallback query ?token=
        if (!empty($_GET['token'])) {
            return trim($_GET['token']);
        }

        // 4) Fallback body (por si en algún endpoint lo mandás por POST)
        if (!empty($_POST['token'])) {
            return trim($_POST['token']);
        }

        return null;
    }

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
