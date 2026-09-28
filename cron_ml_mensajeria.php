<?php
// Reporta a Mercado Libre los envíos Flex que maneja Caddy como mensajería.
// Solo CLI (cron de cPanel). Ver clases/ml_mensajeria.class.php.
//
//   php cron_ml_mensajeria.php                 envíos de los últimos 2 días no reportados
//   php cron_ml_mensajeria.php --dias=7        carga inicial de la última semana
//   php cron_ml_mensajeria.php --simular       lista sin llamar a ML
//
// Crontab sugerido (cada 5 min):
// */5 * * * * /usr/bin/flock -n /home/dinter6/tmp/cron_ml_mensajeria.lock /opt/cpanel/ea-php82/root/usr/bin/php /home/dinter6/api.caddy.com.ar/api/cron_ml_mensajeria.php >> /home/dinter6/logs/cron_ml_mensajeria.log 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/clases/ml_mensajeria.class.php';

$opt  = getopt('', ['dias:', 'simular', 'limite:']);
$dias = isset($opt['dias']) ? (int)$opt['dias'] : 2;

$resumen = (new MlMensajeria())->reportarPendientes($dias, isset($opt['limite']) ? (int)$opt['limite'] : 200, null, isset($opt['simular']));

echo date('Y-m-d H:i:s') . ' ' . json_encode($resumen, JSON_UNESCAPED_UNICODE) . PHP_EOL;
