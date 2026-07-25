<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/conexion/conexion.php';
require_once __DIR__ . '/clases/token.class.php';

class WebhookTestService extends conexion
{
}

// Cuenta de ejemplo publicada en la documentación pública de la API: cualquiera
// que lea la doc puede loguearse con ella, así que no cuenta como cliente
// "vetted" y no puede usar esta herramienta (aunque su token sea válido).
const WEBHOOK_TEST_USUARIO_BLOQUEADO = 'cliente@caddy.com.ar';

function esUsuarioDemoBloqueado(int $usuarioId, WebhookTestService $db): bool
{
    $query  = "SELECT Usuario FROM usuarios WHERE id = '" . (int)$usuarioId . "' LIMIT 1";
    $resp   = $db->obtenerDatos($query);
    $usuario = $resp[0]['Usuario'] ?? '';

    return strcasecmp($usuario, WEBHOOK_TEST_USUARIO_BLOQUEADO) === 0;
}

function logUsoWebhookTest(array $tokenData, string $url, int $httpCode): void
{
    $linea = json_encode([
        'fecha'      => date('Y-m-d H:i:s'),
        'usuarioId'  => $tokenData['UsuarioId'] ?? null,
        'ndeCliente' => $tokenData['NdeCliente'] ?? null,
        'url'        => $url,
        'http_code'  => $httpCode,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;

    @file_put_contents(__DIR__ . '/webhook_test.log', $linea, FILE_APPEND);
}

function esIpPrivadaOLocal(string $ip): bool
{
    if ($ip === '') {
        return true;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function dispararWebhookPrueba(string $url, string $bearer, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    $headers = ['Content-Type: application/json'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $respuestaBody = curl_exec($ch);
    $httpCode      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError     = curl_error($ch);
    curl_close($ch);

    return [
        'enviado' => [
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ],
        'respuesta' => [
            'http_code' => $httpCode,
            'body'      => $respuestaBody,
            'error'     => $curlError !== '' ? $curlError : null,
        ],
    ];
}

$campos = [
    'apiToken'          => '',
    'evento'            => 'recorrido',
    'webhookUrl'        => '',
    'bearerToken'       => '',
    'codigoSeguimiento' => '',
    'recorrido'         => '',
    'resultadoEntrega'  => 'entregado',
    'motivo'            => '',
];

$error     = null;
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($campos as $clave => $valorPorDefecto) {
        $campos[$clave] = trim((string)($_POST[$clave] ?? $valorPorDefecto));
    }

    $db        = new WebhookTestService();
    $tokenData = $campos['apiToken'] !== '' ? Token::validar($campos['apiToken'], $db) : null;

    if (!$tokenData) {
        $error = 'Token de Caddy inválido o vencido. Generá uno con POST /auth y pegalo acá.';
    } elseif (esUsuarioDemoBloqueado((int)($tokenData['UsuarioId'] ?? 0), $db)) {
        $error = 'La cuenta de demostración publicada en la documentación no puede usar esta herramienta. Necesitás un usuario real.';
    } elseif ($campos['codigoSeguimiento'] === '') {
        $error = 'Falta el Código de Seguimiento (nro_serie).';
    } elseif (!preg_match('#^https://#i', $campos['webhookUrl']) || !filter_var($campos['webhookUrl'], FILTER_VALIDATE_URL)) {
        $error = 'La URL del webhook debe ser una URL https:// válida.';
    } else {
        $host = parse_url($campos['webhookUrl'], PHP_URL_HOST);
        $ip   = $host ? gethostbyname($host) : '';

        if (esIpPrivadaOLocal($ip)) {
            $error = 'La URL del webhook resuelve a una dirección de red privada/local. No está permitido por seguridad.';
        } else {
            if ($campos['evento'] === 'entrega') {
                $body = [
                    'nro_serie' => $campos['codigoSeguimiento'],
                    'resultado' => $campos['resultadoEntrega'],
                ];
            } else {
                $body = [
                    'nro_serie' => $campos['codigoSeguimiento'],
                    'recorrido' => $campos['recorrido'],
                ];
            }
            if ($campos['motivo'] !== '') {
                $body['motivo'] = $campos['motivo'];
            }

            $resultado = dispararWebhookPrueba($campos['webhookUrl'], $campos['bearerToken'], $body);
            logUsoWebhookTest($tokenData, $campos['webhookUrl'], (int)$resultado['respuesta']['http_code']);
        }
    }
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prueba de Webhooks Caddy → Wepoint</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #0f1117;
    color: #e2e8f0;
    padding: 32px;
    max-width: 720px;
    margin: 0 auto;
    line-height: 1.5;
  }
  h1 { font-size: 20px; margin-bottom: 4px; }
  p.subtitle { color: #94a3b8; font-size: 13px; margin-bottom: 24px; }
  fieldset { border: 1px solid #334155; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
  legend { padding: 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; }
  label { display: block; font-size: 13px; margin: 10px 0 4px; color: #cbd5e1; }
  input[type=text], input[type=url], select, textarea {
    width: 100%;
    padding: 8px 10px;
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 6px;
    color: #e2e8f0;
    font-size: 13px;
  }
  .hint { font-size: 11px; color: #64748b; margin-top: 3px; }
  button {
    background: #3b82f6;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 8px;
  }
  button:hover { background: #2563eb; }
  .error-box {
    background: #450a0a;
    border: 1px solid #7f1d1d;
    color: #fca5a5;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
  }
  .result-box {
    background: #0c1a0c;
    border: 1px solid #14532d;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
  }
  .result-box h3 { font-size: 12px; text-transform: uppercase; color: #86efac; margin-bottom: 8px; }
  pre {
    background: #060d1a;
    padding: 10px;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 12px;
    color: #cbd5e1;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .http-code { font-weight: 700; }
  .http-2xx { color: #4ade80; }
  .http-otro { color: #f87171; }
</style>
</head>
<body>

<h1>Prueba de Webhooks Caddy → Wepoint</h1>
<p class="subtitle">Simula manualmente el POST que el sistema principal hará en producción, para validar el formato con el equipo de Wepoint antes de implementarlo.</p>

<?php if ($error): ?>
  <div class="error-box"><strong>Error:</strong> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($resultado): ?>
  <div class="result-box">
    <h3>Se envió</h3>
    <pre><?= h(json_encode($resultado['enviado'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    <h3>Respuesta del servidor de Wepoint</h3>
    <p>Código HTTP: <span class="http-code <?= ($resultado['respuesta']['http_code'] >= 200 && $resultado['respuesta']['http_code'] < 300) ? 'http-2xx' : 'http-otro' ?>"><?= (int)$resultado['respuesta']['http_code'] ?></span></p>
    <?php if ($resultado['respuesta']['error']): ?>
      <p style="color:#f87171;">Error de conexión: <?= h($resultado['respuesta']['error']) ?></p>
    <?php endif; ?>
    <pre><?= h((string)$resultado['respuesta']['body']) ?></pre>
  </div>
<?php endif; ?>

<form method="POST">
  <fieldset>
    <legend>Autenticación</legend>
    <label for="apiToken">Tu token de Caddy (obtenido con POST /auth)</label>
    <input type="text" id="apiToken" name="apiToken" value="<?= h($campos['apiToken']) ?>" placeholder="token de tu cuenta">
    <p class="hint">Requerido para usar esta herramienta. No es el token que se envía a Wepoint.</p>
  </fieldset>

  <fieldset>
    <legend>Destino del webhook</legend>
    <label for="webhookUrl">URL del endpoint de Wepoint</label>
    <input type="url" id="webhookUrl" name="webhookUrl" value="<?= h($campos['webhookUrl']) ?>" placeholder="https://dominio-wepoint/api/v2/webhooks/caddy/recorrido">

    <label for="bearerToken">Bearer token de servicio (header Authorization, opcional)</label>
    <input type="text" id="bearerToken" name="bearerToken" value="<?= h($campos['bearerToken']) ?>" placeholder="token-de-servicio">
  </fieldset>

  <fieldset>
    <legend>Evento a simular</legend>
    <label for="evento">Tipo de webhook</label>
    <select id="evento" name="evento" onchange="mostrarCampos()">
      <option value="recorrido" <?= $campos['evento'] === 'recorrido' ? 'selected' : '' ?>>Asignación de recorrido</option>
      <option value="entrega" <?= $campos['evento'] === 'entrega' ? 'selected' : '' ?>>Confirmación de entrega / devolución</option>
    </select>

    <label for="codigoSeguimiento">Código de Seguimiento (nro_serie)</label>
    <input type="text" id="codigoSeguimiento" name="codigoSeguimiento" value="<?= h($campos['codigoSeguimiento']) ?>" placeholder="CAD-XXXXX">

    <div id="campos-recorrido">
      <label for="recorrido">Recorrido</label>
      <input type="text" id="recorrido" name="recorrido" value="<?= h($campos['recorrido']) ?>" placeholder="Norte">
    </div>

    <div id="campos-entrega" style="display:none;">
      <label for="resultadoEntrega">Resultado</label>
      <select id="resultadoEntrega" name="resultadoEntrega">
        <option value="entregado" <?= $campos['resultadoEntrega'] === 'entregado' ? 'selected' : '' ?>>entregado</option>
        <option value="fallido" <?= $campos['resultadoEntrega'] === 'fallido' ? 'selected' : '' ?>>fallido</option>
      </select>
    </div>

    <label for="motivo">Motivo (opcional)</label>
    <input type="text" id="motivo" name="motivo" value="<?= h($campos['motivo']) ?>" placeholder="razón del cambio o del fallo">
  </fieldset>

  <button type="submit">Enviar prueba</button>
</form>

<script>
  function mostrarCampos() {
    var evento = document.getElementById('evento').value;
    document.getElementById('campos-recorrido').style.display = evento === 'recorrido' ? 'block' : 'none';
    document.getElementById('campos-entrega').style.display = evento === 'entrega' ? 'block' : 'none';
  }
  mostrarCampos();
</script>

</body>
</html>
