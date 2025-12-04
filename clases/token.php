<?php

class Token
{
    /**
     * Lee el token desde Authorization: Bearer o desde ?token=
     */
    public static function obtenerToken(): ?string
    {
        // Headers
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) === 'HTTP_') {
                    $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$key] = $value;
                }
            }
        }

        // Authorization: Bearer XXX
        if (isset($headers['Authorization']) && stripos($headers['Authorization'], 'Bearer ') === 0) {
            $t = trim(substr($headers['Authorization'], 7));
            if ($t !== '') return $t;
        }

        // Query string
        if (!empty($_GET['token'])) {
            return $_GET['token'];
        }

        return null;
    }

    /**
     * Valida el token contra la BD
     */
    public static function validar(string $token, $db): ?array
    {
        $query = "
            SELECT ut.TokenId, ut.UsuarioId, ut.Estado, u.NdeCliente
            FROM usuarios_token ut
            JOIN usuarios u ON ut.UsuarioId = u.id
            WHERE ut.Token = '$token'
            AND ut.Estado = 'Activo'
        ";

        $resp = $db->obtenerDatos($query);
        return $resp ? $resp[0] : null;
    }
}
