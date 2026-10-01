<?php
// Prueba de cotización con Andreani (uso interno, protegida con clave).
//   ?clave=...                                   formulario
//   ?clave=...&cp=5900&kg=2&alto=20&ancho=30&largo=40&valor=10000[&formato=json]
// La lógica está en clases/andreani.class.php; las credenciales, en conexion/andreani_config.php (solo servidor).
require_once 'clases/andreani.class.php';
header('Cache-Control: no-store');

// Solo se guarda el hash de la clave
const ANDREANI_PRUEBA_CLAVE_SHA256 = '23cc78463a09f3f2230511c940f58a239e3a205d4a973d5d9c2ccaef0c8b7256';

if (!hash_equals(ANDREANI_PRUEBA_CLAVE_SHA256, hash('sha256', (string) ($_GET['clave'] ?? '')))) {
    http_response_code(403);
    exit('No autorizado');
}

$h = fn($s) => htmlspecialchars((string) $s);
$cp = preg_replace('/\D/', '', (string) ($_GET['cp'] ?? ''));
$resultado = null;
$error = null;

if ($cp !== '') {
    $bulto = [
        'kilos'          => (float) ($_GET['kg'] ?? 0),
        'altoCm'         => (float) ($_GET['alto'] ?? 0),
        'anchoCm'        => (float) ($_GET['ancho'] ?? 0),
        'largoCm'        => (float) ($_GET['largo'] ?? 0),
        'valorDeclarado' => (float) ($_GET['valor'] ?? 0),
    ];
    try {
        $andreani = new Andreani();
        $resultado = $andreani->cotizar($cp, [$bulto]) + ['ambiente' => $andreani->ambiente()];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    if (($_GET['formato'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($error ? ['ok' => false, 'error' => $error] : $resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
$v = fn($k, $def = '') => $h($_GET[$k] ?? $def);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cotizar con Andreani</title>
<style>
  body{font-family:system-ui,sans-serif;max-width:640px;margin:24px auto;padding:0 16px;color:#222}
  label{display:block;margin:8px 0 2px;font-size:14px} input{padding:7px;font-size:14px;width:120px}
  .fila{display:flex;gap:12px;flex-wrap:wrap} .btn{background:#e24f30;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;margin-top:12px}
  .ok{color:#0a7a2f} .err{color:#b3261e} pre{background:#f5f5f5;padding:10px;overflow:auto;font-size:12px}
</style>
</head>
<body>
<h2>Cotizar con Andreani</h2>
<form method="get">
  <input type="hidden" name="clave" value="<?= $v('clave') ?>">
  <div class="fila">
    <div><label>CP destino</label><input name="cp" value="<?= $v('cp') ?>" required></div>
    <div><label>Peso (kg)</label><input name="kg" value="<?= $v('kg', '1') ?>"></div>
    <div><label>Valor declarado ($)</label><input name="valor" value="<?= $v('valor', '10000') ?>"></div>
  </div>
  <div class="fila">
    <div><label>Alto (cm)</label><input name="alto" value="<?= $v('alto', '20') ?>"></div>
    <div><label>Ancho (cm)</label><input name="ancho" value="<?= $v('ancho', '20') ?>"></div>
    <div><label>Largo (cm)</label><input name="largo" value="<?= $v('largo', '20') ?>"></div>
  </div>
  <button class="btn">Cotizar</button>
</form>
<?php if ($error): ?>
  <p class="err"><?= $h($error) ?></p>
<?php elseif ($resultado && $resultado['ok']): ?>
  <p class="ok">Ambiente <?= $h($resultado['ambiente']) ?> · <b>$ <?= number_format((float) $resultado['con_iva'], 2, ',', '.') ?></b> con IVA
    ($ <?= number_format((float) $resultado['sin_iva'], 2, ',', '.') ?> sin IVA)<?= $resultado['peso_aforado'] ? ' · peso aforado ' . $h($resultado['peso_aforado']) . ' kg' : '' ?></p>
  <pre><?= $h(json_encode($resultado['crudo'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
<?php elseif ($resultado): ?>
  <p class="err">Andreani respondió <?= $h($resultado['http']) ?>: <?= $h($resultado['error']) ?></p>
<?php endif; ?>
</body>
</html>
