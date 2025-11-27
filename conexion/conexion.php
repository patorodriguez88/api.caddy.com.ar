<?php

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}
date_default_timezone_set('America/Argentina/Cordoba');

class conexion
{

    private $server;
    private $user;
    private $password;
    private $database;
    private $port;
    private $conexion;


    function __construct()
    {
        $listadatos = $this->datosConexion();
        foreach ($listadatos as $key => $value) {
            $this->server = $value['server'];
            $this->user = $value['user'];
            $this->password = $value['password'];
            $this->database = $value['database'];
            $this->port = $value['port'];
        }
        $this->conexion = new mysqli($this->server, $this->user, $this->password, $this->database, $this->port);
        if ($this->conexion->connect_errno) {
            echo "Algo va mal con la conexion";
            die();
        }
    }

    private function datosConexion()
    {
        $direccion = dirname(__FILE__);
        $uri = $_SERVER['REQUEST_URI'];

        // Verificar si la carpeta "sandbox" está en la URI
        if (strpos($uri, 'sandbox') !== false) {

            $jsondata = file_get_contents($direccion . "/" . "config_prueba");
        } else {

            $jsondata = file_get_contents($direccion . "/" . "config");
        }

        // $jsondata = file_get_contents($direccion . "/" . "config");
        return json_decode($jsondata, true);
    }

    private function convertirUTF8($array)
    {
        array_walk_recursive($array, function (&$item, $key) {
            if (!mb_detect_encoding($item, 'utf-8', true)) {
                $item = utf8_encode($item);
            }
        });
        return $array;
    }
    public function obtenerDatos($sqlstr)
    {
        $results = $this->conexion->query($sqlstr);
        $resultArray = array();
        foreach ($results as $key) {
            $resultArray[] = $key;
        }
        return $this->convertirUTF8($resultArray);
    }

    public function obtenerDatosLimpios($sqlstr)
    {
        $results = $this->conexion->query($sqlstr);
        $resultArray = array();
        foreach ($results as $key) {
            $resultArray[] = $key['id'];
        }
        return $this->convertirUTF8($resultArray);
    }


    public function nonQuery($sqlstr)
    {
        $results = $this->conexion->query($sqlstr);
        return $this->conexion->affected_rows;
    }

    //INSERT 
    public function nonQueryId($sqlstr)
    {
        $results = $this->conexion->query($sqlstr);
        $filas = $this->conexion->affected_rows;
        if ($filas >= 1) {
            return $this->conexion->insert_id;
        } else {
            return 0;
        }
    }

    //encriptar
    protected function encriptar($string)
    {
        return md5($string);
    }

    //FUNCION PARA GENERAR CODIGOS ALEATORIOS DE 9 DIGIGITOS
    public function generarCodigo($longitud)
    {
        $key = '';

        $pattern = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $max = strlen($pattern) - 1;

        for ($i = 0; $i < $longitud; $i++) {

            $key .= $pattern[mt_rand(0, $max)];
        }

        return $key;
    }
}
