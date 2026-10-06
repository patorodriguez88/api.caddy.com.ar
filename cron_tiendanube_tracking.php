<?php
// Informa a Tienda Nube los cambios de estado de los envíos de sus pedidos (tracking events del
// fulfillment order). Ver clases/tiendanube_tracking.class.php. Solo por CLI (cron de cPanel):
//   */5 * * * * /usr/bin/flock -n /home/dinter6/tmp/cron_tn_tracking.lock /opt/cpanel/ea-php82/root/usr/bin/php /home/dinter6/api.caddy.com.ar/api/cron_tiendanube_tracking.php >> /home/dinter6/logs/cron_tn_tracking.log 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/clases/tiendanube_tracking.class.php';

$r = (new TiendanubeTracking())->procesar();
if ($r['enviados'] || $r['omitidos'] || $r['errores'] || $r['pendientes']) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode($r) . PHP_EOL;
}
