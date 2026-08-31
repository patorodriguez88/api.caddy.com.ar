<?php
/**
 * cron_status.php — Chequeo de salud de los endpoints de la API.
 *
 * Corre cada pocos minutos, pega a cada endpoint (prod + sandbox) con un
 * request "vacío" (sin token / sin body), mide latencia y estado, y guarda
 * una fila por chequeo en `api_status_checks`. `status.php` lee esa tabla.
 *
 * Formas de correr:
 *   - CLI (cron de cPanel):   php cron_status.php            (sin secret)
 *   - HTTP (cron externo):    cron_status.php?secret=...     (con secret)
 *
 * Un endpoint se considera OK si:
 *   - respondió (sin error de transporte),
 *   - el body NO contiene un error de PHP (Fatal/Parse/Warning/Stack trace),
 *   - el HTTP code está en el set esperado y el body trae el marcador esperado.
 *
 * Tabla (crear una vez):
 *
 *   CREATE TABLE IF NOT EXISTS api_status_checks (
 *     id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 *     ts DATETIME NOT NULL,
 *     chk VARCHAR(40) NOT NULL,
 *     entorno VARCHAR(10) NOT NULL,
 *     metodo VARCHAR(8) NOT NULL,
 *     url VARCHAR(255) NOT NULL,
 *     http_code SMALLINT NOT NULL,
 *     ok TINYINT(1) NOT NULL,
 *     latency_ms INT NOT NULL,
 *     ttfb_ms INT NOT NULL,
 *     error VARCHAR(200) DEFAULT NULL,
 *     PRIMARY KEY (id),
 *     KEY idx_chk_ts (chk, ts),
 *     KEY idx_ts (ts)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

const CRON_STATUS_SECRET = 'S7pQ2mZx9Lr4Ktb8Ncy3Vha6Fwd1Uje';
const RETENCION_DIAS      = 45;

// UA que NO sea "curl": mod_security del hosting devuelve 406 a curl/UA vacío.
const MONITOR_UA = 'CaddyStatus/1.0 (+https://api.caddy.com.ar/api/status.php)';

$esCli = (php_sapi_name() === 'cli');

if (!$esCli) {
    $secreto = $_GET['secret'] ?? $_POST['secret'] ?? '';
    if (!hash_equals(CRON_STATUS_SECRET, (string) $secreto)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => 0, 'error' => 'No autorizado']);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
}

// La clase conexion mira $_SERVER['REQUEST_URI'] para elegir config vs
// config_prueba. En CLI no existe -> lo fijamos para que use SIEMPRE prod
// (los resultados de todos los entornos se guardan en la BD de prod).
if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/status-cron';
}

require_once __DIR__ . '/conexion/conexion.php';

/* ───────────────────────── Definición de chequeos ───────────────────────── */

$BASE = 'https://api.caddy.com.ar';

// [clave, entorno, método, ruta, [http esperados], marcador_body]
// marcador_body: substring que prueba que la app respondió lo suyo.
$defsApi = [
    ['rates',     'GET',  '/rates',     [401],           'error_id'],
    ['rates_v2',  'GET',  '/rates_v2',  [401],           'error_id'],
    ['rates_v3',  'GET',  '/rates_v3',  [401],           'error_id'],
    ['auth',      'POST', '/auth',      [400, 401],      'error_id'],
    ['servicios', 'POST', '/servicios', [400, 401],      'error_id'],
    ['etiqueta',  'GET',  '/etiqueta',  [200, 400, 401], 'error_id'],
    ['warehouse', 'GET',  '/warehouse', [400, 401],      'error_id'],
];

$CHECKS = [];

// Compartidos (no dependen del entorno)
$CHECKS[] = ['shared:root', 'shared', 'GET', $BASE . '/',                       [301, 302], ''];
$CHECKS[] = ['shared:docs', 'shared', 'GET', $BASE . '/api-docs/openapi.yaml',  [200],      'openapi:'];

foreach (['prod' => '/api', 'sandbox' => '/sandbox'] as $entorno => $prefijo) {
    foreach ($defsApi as [$nombre, $metodo, $ruta, $codigos, $marcador]) {
        $CHECKS[] = ["$entorno:$nombre", $entorno, $metodo, $BASE . $prefijo . $ruta, $codigos, $marcador];
    }
}

/* ───────────────────────────── Ejecutar ────────────────────────────────── */

function chequear(string $url, string $metodo, array $codigos, string $marcador): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url . (strpos($url, '?') === false ? '?' : '&') . 'hc=' . time(),
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => MONITOR_UA,
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/html'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }

    $body      = curl_exec($ch);
    $code      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total     = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $ttfb      = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
    $curlErr   = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    $latency = (int) round($total * 1000);
    $ttfbMs  = (int) round($ttfb * 1000);

    if ($curlErr !== '') {
        return [0, 0, $latency, $ttfbMs, 'transporte: ' . substr($curlErr, 0, 150)];
    }

    $bodyStr = is_string($body) ? $body : '';
    if (preg_match('/Fatal error|Parse error|<b>Warning<\/b>|Stack trace|Uncaught\s+\w+Error/i', $bodyStr)) {
        return [0, $code, $latency, $ttfbMs, 'error PHP en la respuesta'];
    }

    $codeOk   = in_array($code, $codigos, true);
    $markerOk = ($marcador === '') || (stripos($bodyStr, $marcador) !== false);

    if ($codeOk && $markerOk) {
        return [1, $code, $latency, $ttfbMs, null];
    }
    if (!$codeOk) {
        return [0, $code, $latency, $ttfbMs, 'HTTP ' . $code . ' inesperado (esperaba ' . implode('/', $codigos) . ')'];
    }
    return [0, $code, $latency, $ttfbMs, 'respuesta sin el marcador esperado'];
}

$db  = new conexion();
$now = date('Y-m-d H:i:s');

$resultados = [];
$okTotal = 0;

foreach ($CHECKS as [$clave, $entorno, $metodo, $url, $codigos, $marcador]) {
    [$ok, $code, $lat, $ttfb, $err] = chequear($url, $metodo, $codigos, $marcador);
    $okTotal += $ok;

    $sql = sprintf(
        "INSERT INTO api_status_checks (ts, chk, entorno, metodo, url, http_code, ok, latency_ms, ttfb_ms, error)
         VALUES ('%s','%s','%s','%s','%s',%d,%d,%d,%d,%s)",
        $now,
        $db->escapar($clave),
        $db->escapar($entorno),
        $db->escapar($metodo),
        $db->escapar($url),
        $code,
        $ok,
        $lat,
        $ttfb,
        $err === null ? 'NULL' : "'" . $db->escapar($err) . "'"
    );
    $db->nonQuery($sql);

    $resultados[] = ['chk' => $clave, 'ok' => $ok, 'http' => $code, 'ms' => $lat, 'error' => $err];
}

// Limpieza de histórico
$db->nonQuery("DELETE FROM api_status_checks WHERE ts < (NOW() - INTERVAL " . (int) RETENCION_DIAS . " DAY)");

$resumen = [
    'ok'        => 1,
    'via'       => $esCli ? 'cli' : 'http',
    'ts'        => $now,
    'checks'    => count($CHECKS),
    'ok_checks' => $okTotal,
    'ko_checks' => count($CHECKS) - $okTotal,
    'detalle'   => $resultados,
];

echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
