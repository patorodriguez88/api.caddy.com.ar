<?php
// Copiar como conexion/andreani_config.php EN EL SERVIDOR (no se sube al repo, ver .gitignore)
// y completar con los datos de la cuenta de Andreani (panel pymes.andreani.com > Integraciones).
return [
    'ambiente'        => 'qa',          // 'qa' (pruebas) o 'produccion'
    'usuario'         => '',            // usuario de la API
    'password'        => '',            // contraseña de la API
    'cliente'         => '',            // número de cliente Andreani
    'contrato'        => '',            // número de contrato (ej. envío a domicilio)
    'sucursal_origen' => '',            // opcional: código de sucursal donde se impone el envío
];
