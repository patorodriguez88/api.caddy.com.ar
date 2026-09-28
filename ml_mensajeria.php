<?php
// Caddy como mensajería de Envíos Flex en Mercado Libre: pantalla de administración.
//   ?clave=...                     estado: cuenta conectada y últimos reportes
//   ?clave=...&accion=conectar     arranca el OAuth con la cuenta de ML de la mensajería
//   ?code=...&state=...            callback del OAuth (redirect_uri registrada en la app de ML)
//   ?clave=...&accion=probar&shipment_id=N   reporta un solo envío (prueba)
//   ?clave=...&accion=simular      lista los envíos que reportaría el cron, sin llamar a ML
// El reporte automático lo hace cron_ml_mensajeria.php. Ver clases/ml_mensajeria.class.php.

require_once 'clases/ml_mensajeria.class.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

// Solo se guarda el hash de la clave
const ML_MENSAJERIA_CLAVE_SHA256 = '6a97af009f563f2ab268aa75627aa9eedaa043fea951d72b18245df697dd41a3';
const ML_STATE_VIGENCIA = 900; // segundos para completar el OAuth

function claveOk(): bool
{
    return hash_equals(ML_MENSAJERIA_CLAVE_SHA256, hash('sha256', (string)($_GET['clave'] ?? $_POST['clave'] ?? '')));
}

// El state firma la hora de inicio: prueba que el OAuth lo arrancó alguien con la clave
function firmarState(int $ts): string
{
    return $ts . '.' . hash_hmac('sha256', 'ml_mensajeria|' . $ts, WebhookMlReceiver::CLIENT_SECRET);
}

function stateOk(string $state): bool
{
    [$ts, $firma] = array_pad(explode('.', $state, 2), 2, '');
    return ctype_digit($ts) && time() - (int)$ts < ML_STATE_VIGENCIA && hash_equals(firmarState((int)$ts), $state);
}

function pagina(string $titulo, string $cuerpo): void
{
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Mensajería Flex</title><style>body{font-family:system-ui,sans-serif;max-width:760px;margin:24px auto;padding:0 16px;color:#222}'
        . 'table{border-collapse:collapse;width:100%;font-size:14px}td,th{border-bottom:1px solid #ddd;padding:6px;text-align:left}'
        . '.ok{color:#0a7a2f}.err{color:#b3261e}.btn{display:inline-block;background:#3483fa;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;border:0;cursor:pointer}'
        . 'input{padding:7px;font-size:14px}</style></head><body><h2>' . htmlspecialchars($titulo) . '</h2>' . $cuerpo . '</body></html>';
    exit;
}

$h = fn($s) => htmlspecialchars((string)$s);
$ml = new MlMensajeria();

// 1) Callback del OAuth
if (isset($_GET['code']) || isset($_GET['error'])) {
    if (!stateOk((string)($_GET['state'] ?? ''))) {
        http_response_code(403);
        pagina('Autorización rechazada', '<p class="err">El enlace venció o no se inició desde esta pantalla. Volvé a intentarlo.</p>');
    }
    if (isset($_GET['error'])) {
        pagina('Autorización cancelada', '<p class="err">' . $h($_GET['error_description'] ?? $_GET['error']) . '</p>');
    }
    [$ok, $msg] = $ml->conectar((string)$_GET['code'], (string)$_GET['state']);
    pagina($ok ? 'Mensajería conectada' : 'No se pudo conectar', '<p class="' . ($ok ? 'ok' : 'err') . '">' . $h($msg) . '</p>');
}

if (!claveOk()) {
    http_response_code(403);
    pagina('No autorizado', '<p>Falta la clave.</p>');
}
$clave  = urlencode((string)($_GET['clave'] ?? $_POST['clave']));
$accion = $_GET['accion'] ?? '';

// 2) Arrancar el OAuth
if ($accion === 'conectar') {
    header('Location: ' . MlMensajeria::urlAutorizacion(firmarState(time())));
    exit;
}

// 3) Pruebas
$resultado = '';
if ($accion === 'probar' && ctype_digit((string)($_GET['shipment_id'] ?? ''))) {
    $resultado = $ml->reportarPendientes(0, 1, (int)$_GET['shipment_id']);
} elseif ($accion === 'simular') {
    $resultado = $ml->reportarPendientes((int)($_GET['dias'] ?? 2), 500, null, true);
}

// 4) Estado
$cuenta = $ml->cuenta();
$html = $cuenta
    ? '<p class="ok">Cuenta conectada: <b>' . $h($cuenta['nickname']) . '</b> (user_id ' . $h($cuenta['user_id']) . '). Token renovado: ' . $h($cuenta['actualizado']) . '.</p>'
    : '<p class="err">Todavía no hay cuenta de mensajería conectada.</p>';
$html .= '<p><a class="btn" href="?clave=' . $clave . '&accion=conectar">' . ($cuenta ? 'Reconectar' : 'Conectar') . ' cuenta de ML de la mensajería</a></p>'
    . '<p style="font-size:13px">Entrá con la cuenta de Mercado Libre con la que registraste la mensajería (no la de un vendedor).</p>';

if ($resultado !== '') {
    $html .= '<h3>Resultado</h3><pre>' . $h(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}

if ($cuenta) {
    $html .= '<h3>Probar con un envío</h3><form method="get"><input type="hidden" name="clave" value="' . $h(urldecode($clave)) . '">'
        . '<input type="hidden" name="accion" value="probar"><input name="shipment_id" placeholder="shipment_id de ML" required> '
        . '<button class="btn">Reportar</button> <a href="?clave=' . $clave . '&accion=simular">ver pendientes (sin reportar)</a></form>';

    $r = $ml->resumenReportes();
    $html .= '<h3>Últimos 7 días</h3><table><tr><th>Código</th><th>Envíos</th><th>Último</th></tr>';
    foreach ($r['por_codigo'] as $f) {
        $html .= '<tr><td>' . $h($f['http_code']) . '</td><td>' . $h($f['n']) . '</td><td>' . $h($f['ultimo']) . '</td></tr>';
    }
    $html .= '</table><p style="font-size:13px">204 = registrado · 409 = ya asignado a una mensajería · 404 = no existe · 400 = inválido · 401/403 = permiso o token</p>'
        . '<h3>Últimos reportes</h3><table><tr><th>shipment_id</th><th>Código</th><th>Intentos</th><th>Cuándo</th><th>Respuesta</th></tr>';
    foreach ($r['ultimos'] as $f) {
        $html .= '<tr><td>' . $h($f['shipment_id']) . '</td><td>' . $h($f['http_code']) . '</td><td>' . $h($f['intentos'])
            . '</td><td>' . $h($f['ultimo_intento']) . '</td><td>' . $h(substr($f['respuesta'], 0, 80)) . '</td></tr>';
    }
    $html .= '</table>';
}

pagina('Caddy como mensajería Flex (Mercado Libre)', $html);
