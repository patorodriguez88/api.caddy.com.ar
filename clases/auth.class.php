<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// require_once '../conexion/conexion.php';
require_once 'respuestas.class.php';


class auth extends conexion
{
    private const TOKEN_TTL_SECONDS = 86400;

    public function login($json)
    {
        $_respustas = new respuestas;
        $datos = json_decode($json, true);

        if (!isset($datos['usuario']) || !isset($datos["password"])) {
            // Se dice el motivo concreto (sin mostrar la clave): con un 400 genérico el cliente no
            // sabe si es la clave, la codificación o cómo arma el pedido (caso VENEX 11/11).
            $motivo = $this->motivoCuerpoInvalido((string) $json, $datos);
            $usuarioLog = is_array($datos) ? ($datos['usuario'] ?? null) : null;
            if ($usuarioLog === null && preg_match('/"usuario"\s*:\s*"([^"]{1,100})"|usuario=([^&]{1,100})/', (string) $json, $m)) {
                $usuarioLog = urldecode($m[2] ?? '') ?: $m[1]; // solo el usuario, para saber de qué cliente es
            }
            $this->logAuth($motivo, $usuarioLog, strlen((string) $json));
            return $_respustas->error_400($motivo);
        } else {
            //todo esta bien 
            $usuario = $datos['usuario'];
            $password = (string)$datos['password'];
            $datos = $this->obtenerDatosUsuario($usuario);
            if ($datos) {
                //verificar si la contraseña es igual

                if ($this->passwordValida($password, (string)$datos[0]['PASSWORD'])) {

                    if ($datos[0]['Estado'] == "Activo") {
                        //VERIFICAR TOKEN
                        $verificar = $this->verificartoken($datos[0]['id']);
                        if ($verificar) {
                            $result = $_respustas->response;
                            $result["result"] = array(
                                "Estado" => $verificar[0]['Estado'],
                                "token" => $verificar[0]['Token'],
                                // Informativo / compatibilidad: el token NO caduca
                                // por tiempo, vale mientras Estado='Activo'.
                                "expires_in" => self::TOKEN_TTL_SECONDS,
                            );
                            return $result;
                        } else {
                            //CREAR TOKEN (no desactivamos los tokens vigentes de otros
                            //procesos del mismo usuario: pueden convivir varios activos
                            //a la vez, cada uno expira solo por TTL)
                            $verificar1  = $this->insertarToken($datos[0]['id']);
                            $this->insertarTokenCliente($datos[0]['NdeCliente'], $verificar1);
                        }

                        if ($verificar1) {
                            // si se guardo
                            $result = $_respustas->response;
                            $result["result"] = array(
                                "token" => $verificar1,
                                "expires_in" => self::TOKEN_TTL_SECONDS,
                            );
                            return $result;
                        } else {
                            //error al guardar
                            return $_respustas->error_500("Error interno, No hemos podido guardar");
                        }
                    } else {
                        //el usuario esta inactivo
                        return $_respustas->error_200("El usuario esta inactivo");
                    }
                } else {
                    //la contraseña no es igual
                    return $_respustas->error_200("El password es inválido");
                }
            } else {
                //no existe el usuario
                return $_respustas->error_200("El usuaro $usuario  no existe ");
            }
        }
    }

    private function motivoCuerpoInvalido(string $cuerpo, $datos): string
    {
        if (trim($cuerpo) === '') {
            return 'El pedido llegó sin cuerpo: se espera JSON {"usuario":"...","password":"..."}';
        }
        if (!is_array($datos)) {
            if (!mb_check_encoding($cuerpo, 'UTF-8')) {
                return 'El cuerpo no está en UTF-8: si la clave o el usuario tienen ñ o acentos, hay que mandarlos en UTF-8';
            }
            $tipo = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            parse_str($cuerpo, $form);
            if (str_contains($tipo, 'form') || isset($form['usuario']) || isset($form['password'])) {
                return 'Llegó como formulario (usuario=...&password=...); se espera JSON {"usuario":"...","password":"..."} con Content-Type: application/json';
            }
            return 'El cuerpo no es un JSON válido (' . json_last_error_msg() . '): si la clave tiene comillas (") o barras (\\), hay que escaparlas; conviene armar el JSON con json_encode o equivalente';
        }
        $faltan = array_filter(['usuario', 'password'], fn($c) => !isset($datos[$c]));
        return 'Falta el campo ' . implode(' y ', $faltan) . ' en el JSON';
    }

    // Registro de logins rechazados por formato, para diagnosticar sin pedirle la clave a nadie.
    // Nunca se guarda el cuerpo. conexion/ y *.log están bloqueados desde afuera por .htaccess.
    private function logAuth(string $motivo, $usuario, int $largo): void
    {
        $archivo = __DIR__ . '/../conexion/log_auth.log';
        if (@filesize($archivo) > 2 * 1024 * 1024) {
            @rename($archivo, __DIR__ . '/../conexion/log_auth_' . date('Ymd_His') . '.log');
        }
        $linea = [
            'fecha' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            'largo' => $largo,
            'usuario' => is_scalar($usuario) ? (string) $usuario : null,
            'motivo' => $motivo,
        ];
        @file_put_contents($archivo, json_encode($linea, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL, FILE_APPEND);
    }

    // Las cuentas nuevas (registro y recupero de la plataforma) guardan password_hash(); las viejas, md5.
    private function passwordValida(string $ingresada, string $guardada): bool
    {
        if ($guardada === '') return false;
        if (str_starts_with($guardada, '$2y$') || str_starts_with($guardada, '$argon')) {
            return password_verify($ingresada, $guardada);
        }
        return hash_equals(strtolower($guardada), parent::encriptar($ingresada));
    }

    private function obtenerDatosUsuario($correo)
    {
        $correo = parent::escapeString((string)$correo);
        $query = "SELECT id,PASSWORD,Estado,NdeCliente FROM usuarios WHERE Usuario = '$correo' AND Nivel=4 AND ACTIVO=1 AND Estado='Activo'";
        $datos = parent::obtenerDatos($query);
        if (isset($datos[0]["id"])) {
            return $datos;
        } else {
            return 0;
        }
    }


    private function insertarToken($usuarioid)
    {
        $val = true;
        $token = bin2hex(openssl_random_pseudo_bytes(16, $val));
        $date = date("Y-m-d H:i");
        $estado = "Activo";
        $query = "INSERT INTO usuarios_token (UsuarioId,Token,Estado,Fecha)VALUES('$usuarioid','$token','$estado','$date')";
        $verifica = parent::nonQuery($query);

        if ($verifica) {
            return $token;
        } else {
            return 0;
        }
    }
    private function insertarTokenCliente($id, $token)
    {

        $query = "UPDATE Clientes SET Token='$token' WHERE id='$id' AND Eliminado=0";
        $verificaCliente = parent::nonQuery($query);

        if ($verificaCliente) {
            return $verificaCliente;
        } else {
            return 0;
        }
    }

    private function verificartoken($usuarioid)
    {
        // Sin filtro por antigüedad: reusamos el token activo que ya tenga el
        // usuario en vez de emitir uno nuevo cada 24h. El token no caduca por
        // tiempo (ver Token::validar()); esto evita que usuarios_token acumule
        // una fila nueva por cliente por día.
        $usuarioid = parent::escapar($usuarioid);
        $query = "SELECT Estado,Token,Fecha FROM usuarios_token
                  WHERE UsuarioId='$usuarioid' AND Estado='Activo'
                  ORDER BY TokenId DESC LIMIT 1";
        $verifica = parent::obtenerDatos($query);
        if ($verifica) {
            return $verifica;
        } else {
            return 0;
        }
    }
}
