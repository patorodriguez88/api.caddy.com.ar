<?php
require_once __DIR__ . '/../conexion/conexion.php';

class Token
{
    /**
     * Lee el token desde:
     *  - Header: Authorization: Bearer xxx
     *  - o query: ?token=xxx
     *  - o body POST: token=xxx (por si lo usás en otros endpoints)
     */
    public static function obtenerToken(): ?string
    {
        $authHeader = null;

        // 1) Intentar con getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $authHeader = $v;
                    break;
                }
            }
        }

        // 2) Fallbacks típicos de Apache / Nginx
        if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!$authHeader && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        // 3) Parsear Bearer
        if ($authHeader && stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '') {
                return $token;
            }
        }

        // 4) Fallback por query: ?token=xxx
        if (!empty($_GET['token'])) {
            return trim($_GET['token']);
        }

        // 5) Fallback por POST (por si lo usás en otros lados)
        if (!empty($_POST['token'])) {
            return trim($_POST['token']);
        }

        return null;
    }

    /**
     * Valida el token contra la BD usando la conexión que ya tiene $db
     */
    public static function validar(string $token, conexion $db): ?array
    {
        // Si tenés un helper para escapar, usalo; si no, dejalo así y
        // más adelante podemos pasarlo a prepared statements.
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
