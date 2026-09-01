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
 * Un 429 (nos throttleó el proxy de adelante, no es el endpoint) se reintenta
 * una vez y, si persiste, se guarda como "sin medición" (skipped): no cuenta
 * como caído ni entra en el uptime. `status.php` ignora esas filas.
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

// Secret del disparo HTTP. Prod: exportarlo en el crontab
// (`CRON_STATUS_SECRET=... /opt/cpanel/.../php cron_status.php`) y rotar este
// fallback. Queda hardcodeado solo para no romper el trigger de cron-job.org si
// el env no está seteado.
const CRON_STATUS_SECRET_FALLBACK = 'S7pQ2mZx9Lr4Ktb8Ncy3Vha6Fwd1Uje';

function cron_status_secret(): string
{
    $env = getenv('CRON_STATUS_SECRET');
    return ($env !== false && $env !== '') ? $env : CRON_STATUS_SECRET_FALLBACK;
}

// Cada corrida borra las filas mas viejas que esto. Con el cron cada 5 min la
// tabla se estabiliza en ~RETENCION_DIAS * 288 corridas/dia * 16 checks filas
// (14d => ~65k). El panel solo usa datos recientes (sparkline ~3h, uptime
// 24h/7d), asi que no hace falta guardar mas.
const RETENCION_DIAS      = 14;

// UA que NO sea "curl": mod_security del hosting devuelve 406 a curl/UA vacío.
const MONITOR_UA = 'CaddyStatus/1.0 (+https://api.caddy.com.ar/api/status.php)';

// El proxy de adelante (nginx) throttlea las ráfagas que salen de la propia IP
// del server. Si los 16 checks salen pegados, los del final comen 429 y el
// panel los pinta de "caído" sin que el endpoint tenga nada. Mitigación:
//   - separar cada request PAUSA_ENTRE_CHECKS_MS,
//   - ante un 429, esperar REINTENTO_429_MS y reintentar una vez,
//   - si igual da 429, marcarlo "sin medición" (no cuenta como caído).
const PAUSA_ENTRE_CHECKS_MS = 500;
const REINTENTO_429_MS      = 2500;

// Opcional: si se setea (p.ej. '127.0.0.1'), los checks resuelven el host a esa
// IP y saltan el proxy de adelante. Probar antes a mano que Apache atienda ahí:
//   curl -sI --resolve api.caddy.com.ar:443:127.0.0.1 https://api.caddy.com.ar/api/rates
// Contra: se deja de testear el borde (TLS/proxy). '' = pegar por la URL pública.
const CHECK_RESOLVE = '';

$esCli = (php_sapi_name() === 'cli');

// Con la pausa entre checks + reintentos de 429, una corrida puede pasar los
// 30s del max_execution_time por defecto en HTTP.
@set_time_limit(120);

if (!$esCli) {
    $secreto = $_GET['secret'] ?? $_POST['secret'] ?? '';
    if (!hash_equals(cron_status_secret(), (string) $secreto)) {
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

/**
 * Devuelve [ok, http_code, latency_ms, ttfb_ms, error|null, skipped(bool)].
 * skipped = true  -> no se pudo medir (el proxy tiró 429 dos veces). No es
 * "caído": el panel lo ignora para el uptime y para el estado global.
 */
function chequear(string $url, string $metodo, array $codigos, string $marcador, bool $permitirReintento = true): array
{
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url . (strpos($url, '?') === false ? '?' : '&') . 'hc=' . time(),
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => MONITOR_UA,
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/html'],
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if (CHECK_RESOLVE !== '') {
        $opts[CURLOPT_RESOLVE] = [
            'api.caddy.com.ar:443:' . CHECK_RESOLVE,
            'api.caddy.com.ar:80:'  . CHECK_RESOLVE,
        ];
    }
    curl_setopt_array($ch, $opts);
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
        return [0, 0, $latency, $ttfbMs, 'transporte: ' . substr($curlErr, 0, 150), false];
    }

    // 429 = nos throttleó el proxy de adelante, no el endpoint. Reintentar una
    // vez tras una pausa; si sigue, marcar "sin medición".
    if ($code === 429) {
        if ($permitirReintento) {
            usleep(REINTENTO_429_MS * 1000);
            return chequear($url, $metodo, $codigos, $marcador, false);
        }
        return [0, 429, $latency, $ttfbMs, 'SKIP: throttled por el proxy (HTTP 429)', true];
    }

    $bodyStr = is_string($body) ? $body : '';
    if (preg_match('/Fatal error|Parse error|<b>Warning<\/b>|Stack trace|Uncaught\s+\w+Error/i', $bodyStr)) {
        return [0, $code, $latency, $ttfbMs, 'error PHP en la respuesta', false];
    }

    $codeOk   = in_array($code, $codigos, true);
    $markerOk = ($marcador === '') || (stripos($bodyStr, $marcador) !== false);

    if ($codeOk && $markerOk) {
        return [1, $code, $latency, $ttfbMs, null, false];
    }
    if (!$codeOk) {
        return [0, $code, $latency, $ttfbMs, 'HTTP ' . $code . ' inesperado (esperaba ' . implode('/', $codigos) . ')', false];
    }
    return [0, $code, $latency, $ttfbMs, 'respuesta sin el marcador esperado', false];
}

$db  = new conexion();
$now = date('Y-m-d H:i:s');

$resultados = [];
$okTotal   = 0;
$skipTotal = 0;

// Orden al azar: que un 429 residual no caiga siempre sobre los mismos checks
// (los del final del loop) y les baje el uptime de forma sesgada.
shuffle($CHECKS);

foreach ($CHECKS as $i => [$clave, $entorno, $metodo, $url, $codigos, $marcador]) {
    if ($i > 0) {
        usleep(PAUSA_ENTRE_CHECKS_MS * 1000);
    }
    [$ok, $code, $lat, $ttfb, $err, $skip] = chequear($url, $metodo, $codigos, $marcador);
    $okTotal   += $ok;
    $skipTotal += $skip ? 1 : 0;

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

    $resultados[] = ['chk' => $clave, 'ok' => $ok, 'http' => $code, 'ms' => $lat, 'error' => $err, 'skip' => $skip];
}

// Limpieza de histórico
$db->nonQuery("DELETE FROM api_status_checks WHERE ts < (NOW() - INTERVAL " . (int) RETENCION_DIAS . " DAY)");

$ko    = count($CHECKS) - $okTotal - $skipTotal;
$fails = array_values(array_filter($resultados, fn($r) => !$r['ok'] && !$r['skip']));

if ($esCli) {
    // Una sola línea por corrida para que cron_status.log no crezca de golpe.
    // Si todo OK: ~90 chars. Si hay fallas: agrega qué falló.
    $linea = sprintf('%s ok=%d/%d', $now, $okTotal, count($CHECKS));
    if ($skipTotal) {
        $linea .= sprintf(' skip=%d', $skipTotal);
    }
    foreach ($fails as $f) {
        $linea .= sprintf(' | %s HTTP=%d %s', $f['chk'], $f['http'], $f['error']);
    }
    echo $linea . PHP_EOL;
    exit;
}

// HTTP (disparo manual): devolvemos el detalle completo.
echo json_encode([
    'ok'        => 1,
    'via'       => 'http',
    'ts'        => $now,
    'checks'      => count($CHECKS),
    'ok_checks'   => $okTotal,
    'ko_checks'   => $ko,
    'skip_checks' => $skipTotal,
    'detalle'     => $resultados,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
