<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "SERVICIOS.CLASS REAL: " . __FILE__ . "<br>";

$path = __DIR__ . '/../conexion/conexion.php';
echo "VOY A BUSCAR CONEXION EN: " . $path . "<br>";

var_dump(file_exists($path));
exit;
