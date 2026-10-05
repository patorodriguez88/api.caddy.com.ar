<?php
// Copiar como conexion/tiendanube_config.php EN EL SERVIDOR (no se sube al repo, ver .gitignore)
// y completar con el "Client Secret" de la app Caddy Logística (id 1579) del panel de Partners
// de Tienda Nube. Con esto se valida la firma (x-linkedstore-hmac-sha256) de los webhooks.
// Sin este archivo los webhooks se aceptan igual pero sin validar la firma (queda en el log).
return [
    'app_id'     => '1579',
    'app_secret' => '',
];
