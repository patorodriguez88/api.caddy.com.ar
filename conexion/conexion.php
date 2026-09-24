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

    // Cliente dueño del token del pedido (lo setea Token::validar), para el log de protocolo
    public static $clienteLog = 0;
    private static $logProtocoloRegistrado = false;


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
        $this->conexion->set_charset("utf8");

        // TEMPORAL (desde 2026-09-24): registrar si los clientes entran por http o https,
        // para saber si se puede activar Force HTTPS sin romper a nadie. Sacar al decidir.
        if (php_sapi_name() !== 'cli' && !self::$logProtocoloRegistrado) {
            self::$logProtocoloRegistrado = true;
            register_shutdown_function([$this, 'logProtocolo']);
        }
    }

    /**
     * Suma 1 al contador diario de api_protocolo_log por (protocolo, endpoint, ip, UA, cliente).
     * Guarda también las variables crudas del server: detrás del proxy nginx no está
     * claro cuál indica el protocolo original. Nunca debe romper la respuesta.
     */
    public function logProtocolo(): void
    {
        try {
            $https = $_SERVER['HTTPS'] ?? '';
            $xfp   = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            $port  = $_SERVER['SERVER_PORT'] ?? '';
            $crudo = substr("https=$https;xfp=$xfp;port=$port", 0, 60);

            $esHttps = ($xfp !== '') ? (strtolower($xfp) === 'https')
                : (($https !== '' && strtolower($https) !== 'off') || $port === '443');
            $protocolo = $esHttps ? 'https' : 'http';

            $endpoint = substr(strtok($_SERVER['REQUEST_URI'] ?? '', '?'), 0, 80);
            $metodo   = substr($_SERVER['REQUEST_METHOD'] ?? '', 0, 8);
            $ip       = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
            $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120);
            $cliente  = (int) self::$clienteLog;
            $dia      = date('Y-m-d');
            $clave    = md5("$dia|$protocolo|$crudo|$endpoint|$metodo|$ip|$ua|$cliente");

            $v = array_map([$this, 'escapar'], [$clave, $dia, $protocolo, $crudo, $endpoint, $metodo, $ip, $ua]);
            $sql = "INSERT INTO api_protocolo_log
                        (clave, dia, protocolo, crudo, endpoint, metodo, ip, ua, cliente, n, ultimo)
                    VALUES ('$v[0]','$v[1]','$v[2]','$v[3]','$v[4]','$v[5]','$v[6]','$v[7]',$cliente,1,NOW())
                    ON DUPLICATE KEY UPDATE n = n + 1, ultimo = NOW()";

            if (!$this->conexion->query($sql) && $this->conexion->errno === 1146) {
                $this->conexion->query("CREATE TABLE IF NOT EXISTS api_protocolo_log (
                    clave     CHAR(32)     NOT NULL PRIMARY KEY,
                    dia       DATE         NOT NULL,
                    protocolo VARCHAR(5)   NOT NULL,
                    crudo     VARCHAR(60)  NOT NULL,
                    endpoint  VARCHAR(80)  NOT NULL,
                    metodo    VARCHAR(8)   NOT NULL,
                    ip        VARCHAR(45)  NOT NULL,
                    ua        VARCHAR(120) NOT NULL,
                    cliente   INT          NOT NULL DEFAULT 0,
                    n         INT          NOT NULL DEFAULT 1,
                    ultimo    DATETIME     NOT NULL,
                    KEY idx_dia_protocolo (dia, protocolo)
                )");
                $this->conexion->query($sql);
            }
        } catch (\Throwable $e) {
            // el log es accesorio: nunca afectar la respuesta
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
        if (!is_array($array)) {
            return $array;
        }

        array_walk_recursive($array, function (&$item) {
            // Solo tocamos strings
            if (!is_string($item)) {
                return;
            }

            // Si ya es UTF-8, no tocamos
            if (mb_detect_encoding($item, 'UTF-8', true)) {
                return;
            }

            // Convertimos desde ISO-8859-1 (o latin1) a UTF-8
            $item = mb_convert_encoding($item, 'UTF-8', 'ISO-8859-1');
        });

        return $array;
    }
    public function obtenerDatos($sqlstr)
    {
        $results = $this->conexion->query($sqlstr);
        $resultArray = array();

        if (!$results) {
            return $resultArray;
        }

        foreach ($results as $key) {
            $resultArray[] = $key;
        }

        return $this->convertirUTF8($resultArray);
    }
    public function obtenerDatosLimpios($sqlstr)
    {
        $results = $this->conexion->query($sqlstr);
        $resultArray = array();

        if (!$results) {
            return $resultArray;
        }

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
    public function escapeString($str)
    {
        return $this->conexion->real_escape_string($str);
    }
    public function escapar($valor)
    {
        return $this->conexion->real_escape_string((string)$valor);
    }


    public function logMeli($mensaje, $data = null)
    {
        $archivo = __DIR__ . '/log_webhook_ml.log';
        $maxSize = 5 * 1024 * 1024; // 5 MB

        // 🔁 Rotación si el archivo supera el tamaño máximo
        if (file_exists($archivo)) {
            $size = filesize($archivo);

            if ($size !== false && $size >= $maxSize) {
                $nuevoNombre = __DIR__ . '/log_webhook_ml_' . date('Ymd_His') . '.log';
                @rename($archivo, $nuevoNombre);
            }
        }

        // 🧾 Armar log
        $log = array(
            'fecha'   => date('Y-m-d H:i:s'),
            'mensaje' => $mensaje,
            'data'    => $data
        );

        // 📝 Escribir log
        @file_put_contents(
            $archivo,
            json_encode($log, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }
}
