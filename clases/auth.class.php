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
            //error con los campos
            return $_respustas->error_400();
        } else {
            //todo esta bien 
            $usuario = $datos['usuario'];
            $password = $datos['password'];
            $password = parent::encriptar($password);
            $datos = $this->obtenerDatosUsuario($usuario);
            if ($datos) {
                //verificar si la contraseña es igual

                if ($password == $datos[0]['PASSWORD']) {

                    if ($datos[0]['Estado'] == "Activo") {
                        //VERIFICAR TOKEN
                        $verificar = $this->verificartoken($datos[0]['id']);
                        if ($verificar) {
                            $antiguedad = time() - strtotime($verificar[0]['Fecha']);
                            $expiresIn = max(0, self::TOKEN_TTL_SECONDS - $antiguedad);
                            $result = $_respustas->response;
                            $result["result"] = array(
                                "Estado" => $verificar[0]['Estado'],
                                "token" => $verificar[0]['Token'],
                                "expires_in" => $expiresIn,
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

    private function obtenerDatosUsuario($correo)
    {
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
        $ttl = self::TOKEN_TTL_SECONDS;
        $query = "SELECT Estado,Token,Fecha FROM usuarios_token WHERE UsuarioId='$usuarioid' AND Fecha > (NOW() - INTERVAL $ttl SECOND) AND Estado='Activo'";
        $verifica = parent::obtenerDatos($query);
        if ($verifica) {
            return $verifica;
        } else {
            return 0;
        }
    }
}
