<?php
/**
 * status.php — Panel de estado de los endpoints de la API.
 *
 * Lee `api_status_checks` (que llena cron_status.php) y muestra, por endpoint:
 * estado actual, latencia, uptime 24h / 7d y un sparkline de latencia.
 *
 *   /api/status.php               -> panel HTML (auto-refresh 60s)
 *   /api/status.php?format=json   -> resumen JSON (para monitoreo externo)
 *
 * Acceso:
 *   - Con STATUS_VIEW_KEY seteada (constante o env del mismo nombre): exige
 *     ?key=... para CUALQUIER formato; sin key -> 403.
 *   - Sin key configurada: el panel queda abierto pero RECORTADO — sin los
 *     strings de error crudos ni el detalle de incidentes (que pueden filtrar
 *     rutas o códigos internos). Pasá ?key=... para ver todo.
 */

const STATUS_VIEW_KEY_FALLBACK = '';  // '' = sin key. Mejor exportar STATUS_VIEW_KEY en el env.
const VENTANA_SPARK   = 60;           // puntos del sparkline
const DEG_UPTIME      = 99.5;         // % 24h por debajo del cual el check está "degradado"
const WARN_UPTIME     = 95.0;

function status_view_key(): string
{
    $env = getenv('STATUS_VIEW_KEY');
    return ($env !== false && $env !== '') ? $env : STATUS_VIEW_KEY_FALLBACK;
}

require_once __DIR__ . '/conexion/conexion.php';

$formato = ($_GET['format'] ?? 'html') === 'json' ? 'json' : 'html';

$viewKey = status_view_key();
$keyOk   = $viewKey !== '' && hash_equals($viewKey, (string) ($_GET['key'] ?? ''));

if ($viewKey !== '' && !$keyOk) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No autorizado.';
    exit;
}

// Detalle completo solo si hay key y coincide. Sin key configurada => recortado.
$detalleCompleto = $keyOk;

$db = new conexion();

// obtenerDatos() no atrapa las excepciones de mysqli (modo estricto por
// defecto en PHP 8.1+), así que si la tabla todavía no existe o la BD tose,
// esto envuelve la consulta y devuelve [] + marca el motivo.
$dbError = null;
function q(conexion $db, string $sql): array
{
    global $dbError;
    try {
        return $db->obtenerDatos($sql);
    } catch (\Throwable $e) {
        if (stripos($e->getMessage(), "api_status_checks") !== false
            && stripos($e->getMessage(), "exist") !== false) {
            $dbError = 'tabla_faltante';
        } elseif ($dbError === null) {
            $dbError = 'db_error: ' . $e->getMessage();
        }
        return [];
    }
}

/* ─────────────────────────────── Datos ─────────────────────────────────── */

// Última fila por chequeo (estado actual, robusto aunque el cron esté frenado).
$ultimas = [];
foreach (q($db,
    "SELECT c.chk,c.entorno,c.metodo,c.url,c.http_code,c.ok,c.latency_ms,c.ttfb_ms,c.error,c.ts
     FROM api_status_checks c
     JOIN (SELECT chk, MAX(id) mid FROM api_status_checks GROUP BY chk) m ON m.mid = c.id"
) as $r) {
    $ultimas[$r['chk']] = $r;
}

// Agregados 24h / 7d. Las filas con http_code = 429 son "sin medición" (nos
// throttleó el proxy, no es el endpoint) y NO entran en el uptime.
$agg = [];
foreach (q($db,
    "SELECT chk,
            SUM(ts >= NOW() - INTERVAL 24 HOUR)                         AS n24,
            SUM(ok = 1 AND ts >= NOW() - INTERVAL 24 HOUR)              AS ok24,
            SUM(ts >= NOW() - INTERVAL 7 DAY)                           AS n7,
            SUM(ok = 1 AND ts >= NOW() - INTERVAL 7 DAY)               AS ok7,
            ROUND(AVG(CASE WHEN ts >= NOW() - INTERVAL 24 HOUR THEN latency_ms END)) AS lat_avg24,
            MAX(CASE WHEN ts >= NOW() - INTERVAL 24 HOUR THEN latency_ms END)        AS lat_max24
     FROM api_status_checks
     WHERE ts >= NOW() - INTERVAL 7 DAY AND http_code <> 429
     GROUP BY chk"
) as $r) {
    $agg[$r['chk']] = $r;
}

// Puntos recientes para el sparkline (últimas ~3h).
$puntos = [];
foreach (q($db,
    "SELECT chk, ok, http_code, latency_ms
     FROM api_status_checks
     WHERE ts >= NOW() - INTERVAL 3 HOUR
     ORDER BY id"
) as $r) {
    $puntos[$r['chk']][] = [
        'ok'   => (int) $r['ok'],
        'skip' => ((int) $r['http_code'] === 429) ? 1 : 0,
        'ms'   => (int) $r['latency_ms'],
    ];
}

// Incidentes (rachas de ok=0) en 7 días. Excluye 429 (sin medición).
$fallas = q($db,
    "SELECT chk, ts, http_code, error
     FROM api_status_checks
     WHERE ok = 0 AND http_code <> 429 AND ts >= NOW() - INTERVAL 7 DAY
     ORDER BY chk, id"
);
$incidentes = [];
$cur = null;
foreach ($fallas as $f) {
    if ($cur && $cur['chk'] === $f['chk']
        && (strtotime($f['ts']) - strtotime($cur['fin'])) <= 15 * 60) {
        $cur['fin']   = $f['ts'];
        $cur['veces']++;
        $cur['error'] = $f['error'] ?: $cur['error'];
    } else {
        if ($cur) $incidentes[] = $cur;
        $cur = ['chk' => $f['chk'], 'ini' => $f['ts'], 'fin' => $f['ts'],
                'veces' => 1, 'error' => $f['error'] ?: ('HTTP ' . $f['http_code'])];
    }
}
if ($cur) $incidentes[] = $cur;
usort($incidentes, fn($a, $b) => strcmp($b['ini'], $a['ini']));

// Último chequeo global.
$ultimoTs = null;
foreach ($ultimas as $u) {
    if ($ultimoTs === null || $u['ts'] > $ultimoTs) $ultimoTs = $u['ts'];
}
$cronVivo   = $ultimoTs && (time() - strtotime($ultimoTs)) < 15 * 60;
$totalKo    = 0;   // caídos de verdad (ok=0 y no es un 429 de throttling)
$totalSkip  = 0;   // sin medición ahora mismo (último chequeo dio 429)
$totalMedidos = 0; // checks con medición válida en el último chequeo
foreach ($ultimas as $u) {
    if ((int) $u['http_code'] === 429) { $totalSkip++; continue; }
    $totalMedidos++;
    if (!$u['ok']) $totalKo++;
}

/* ─────────────────────────────── Helpers ──────────────────────────────── */

function pct($ok, $n): ?float { return $n > 0 ? round($ok / $n * 100, 2) : null; }

function claseUptime(?float $p): string
{
    if ($p === null) return 'na';
    if ($p >= DEG_UPTIME) return 'ok';
    if ($p >= WARN_UPTIME) return 'warn';
    return 'bad';
}

function hace(string $ts): string
{
    $s = time() - strtotime($ts);
    if ($s < 60)   return "hace {$s}s";
    if ($s < 3600) return 'hace ' . floor($s / 60) . 'm';
    if ($s < 86400) return 'hace ' . floor($s / 3600) . 'h';
    return 'hace ' . floor($s / 86400) . 'd';
}

function sparkline(array $pts): string
{
    $pts = array_slice($pts, -VENTANA_SPARK);
    if (!$pts) return '<span class="spark-empty">sin datos</span>';
    $w = 2; $h = 28; $gap = 1;
    $max = 1;
    foreach ($pts as $p) $max = max($max, $p['ms']);
    $max = min($max, 4000);                       // cap para que un pico no aplaste todo
    $svgW = count($pts) * ($w + $gap);
    $out  = '<svg class="spark" width="' . $svgW . '" height="' . $h . '" viewBox="0 0 ' . $svgW . ' ' . $h . '">';
    foreach ($pts as $i => $p) {
        $skip = !empty($p['skip']);
        $bh = max(1, round(min($p['ms'], $max) / $max * $h));
        $x  = $i * ($w + $gap);
        $y  = $h - $bh;
        $cls = $skip ? 'sp-skip' : ($p['ok'] ? 'sp-ok' : 'sp-ko');
        $rot = $skip ? ' · sin medición (429)' : ($p['ok'] ? '' : ' · caído');
        $out .= '<rect class="' . $cls . '" x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $bh . '"><title>' . $p['ms'] . ' ms' . $rot . '</title></rect>';
    }
    return $out . '</svg>';
}

function nombreLindo(string $chk): array
{
    [$env, $ep] = array_pad(explode(':', $chk, 2), 2, '');
    return [$env, $ep];
}

/* ─────────────────────────────── JSON ─────────────────────────────────── */

if ($formato === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $checks = [];
    foreach ($ultimas as $chk => $u) {
        $a    = $agg[$chk] ?? [];
        $skip = (int) $u['http_code'] === 429;
        $checks[$chk] = [
            'entorno'     => $u['entorno'],
            'ok'          => $skip ? null : (int) $u['ok'],
            'sin_medicion' => $skip,
            'http_code'   => (int) $u['http_code'],
            'latency_ms'  => (int) $u['latency_ms'],
            // Sin key: no exponemos el string de error crudo (puede filtrar rutas
            // o códigos internos), solo si está operativo o no.
            'error'       => $skip
                                ? 'sin medición: el proxy devolvió 429'
                                : ($detalleCompleto
                                    ? $u['error']
                                    : ($u['ok'] ? null : 'no operativo')),
            'ts'          => $u['ts'],
            'uptime_24h'  => pct($a['ok24'] ?? 0, $a['n24'] ?? 0),
            'uptime_7d'   => pct($a['ok7'] ?? 0,  $a['n7']  ?? 0),
            'lat_avg_24h' => isset($a['lat_avg24']) ? (int) $a['lat_avg24'] : null,
        ];
    }

    if ($dbError !== null || !$cronVivo) {
        $estado = 'sin_datos';
    } elseif ($totalMedidos === 0) {
        $estado = 'sin_medicion';                 // todo 429: no sabemos
    } elseif ($totalKo === 0) {
        $estado = 'operativo';
    } elseif ($totalKo >= $totalMedidos) {
        $estado = 'caido';
    } else {
        $estado = 'degradado';
    }
    http_response_code(in_array($estado, ['operativo', 'degradado'], true) ? 200 : 503);

    echo json_encode([
        'estado'        => $estado,
        'ultimo_chequeo' => $ultimoTs,
        'cron_vivo'     => $cronVivo,
        'db_error'      => $dbError,
        'checks_ko'     => $totalKo,
        'checks_sin_medicion' => $totalSkip,
        'checks_total'  => count($ultimas),
        'checks'        => $checks,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/* ─────────────────────────────── HTML ─────────────────────────────────── */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$sufijoSkip = $totalSkip ? " · $totalSkip sin medición (429)" : '';
$estadoTxt = !$cronVivo
    ? ['sin datos recientes', 'warn']
    : ($totalMedidos === 0
        ? ['sin medición: el proxy está devolviendo 429', 'warn']
        : ($totalKo === 0
            ? ['todos los endpoints operativos' . $sufijoSkip, 'ok']
            : ["$totalKo endpoint(s) con problemas" . $sufijoSkip, $totalKo >= $totalMedidos ? 'bad' : 'warn']));

// Orden: prod, sandbox, shared; dentro por nombre.
$orden = ['prod' => 0, 'sandbox' => 1, 'shared' => 2];
$claves = array_keys($ultimas);
usort($claves, function ($a, $b) use ($orden, $ultimas) {
    $ea = $orden[$ultimas[$a]['entorno']] ?? 9;
    $eb = $orden[$ultimas[$b]['entorno']] ?? 9;
    return $ea === $eb ? strcmp($a, $b) : $ea - $eb;
});
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Estado API · Caddy</title>
<style>
  :root{
    --bg:#f6f7f9; --card:#fff; --tx:#1c2530; --muted:#6b7785; --line:#e6e9ee;
    --ok:#1a9c54; --okbg:#e7f6ee; --warn:#b7791f; --warnbg:#fdf4e3; --bad:#c8372d; --badbg:#fbe9e7;
  }
  @media (prefers-color-scheme:dark){
    :root{ --bg:#0f1319; --card:#161b23; --tx:#e7ebf0; --muted:#8b97a5; --line:#252c37;
      --ok:#37c976; --okbg:#12301f; --warn:#e0a13a; --warnbg:#332711; --bad:#f2685c; --badbg:#331815; }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--tx);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:1000px;margin:0 auto;padding:28px 20px 60px}
  h1{font-size:20px;margin:0 0 4px}
  .sub{color:var(--muted);font-size:13px;margin-bottom:20px}
  .banner{display:flex;align-items:center;gap:10px;padding:14px 16px;border-radius:10px;font-weight:600;margin-bottom:22px;border:1px solid var(--line)}
  .banner.ok{background:var(--okbg);color:var(--ok)}
  .banner.warn{background:var(--warnbg);color:var(--warn)}
  .banner.bad{background:var(--badbg);color:var(--bad)}
  .dot{width:10px;height:10px;border-radius:50%;flex:0 0 auto;background:currentColor}
  .grp{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:22px 0 8px}
  table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:10px;overflow:hidden}
  th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--line);white-space:nowrap}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600}
  tr:last-child td{border-bottom:0}
  td.ep{font-weight:600}
  td.num{text-align:right;font-variant-numeric:tabular-nums}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600}
  .pill.ok{background:var(--okbg);color:var(--ok)} .pill.warn{background:var(--warnbg);color:var(--warn)}
  .pill.bad{background:var(--badbg);color:var(--bad)} .pill.na{background:var(--line);color:var(--muted)}
  .u-ok{color:var(--ok)} .u-warn{color:var(--warn)} .u-bad{color:var(--bad)} .u-na{color:var(--muted)}
  .spark rect.sp-ok{fill:var(--ok)} .spark rect.sp-ko{fill:var(--bad)} .spark rect.sp-skip{fill:var(--muted);opacity:.45}
  .spark-empty{color:var(--muted);font-size:12px}
  .err{color:var(--bad);font-size:12px;white-space:normal}
  .inc{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:4px 0;margin-top:8px}
  .inc div{padding:9px 14px;border-bottom:1px solid var(--line);font-size:13px}
  .inc div:last-child{border-bottom:0}
  .muted{color:var(--muted)}
  code{background:var(--line);padding:1px 5px;border-radius:4px;font-size:12px}
  a{color:inherit}
  @media(max-width:640px){ th.hide,td.hide{display:none} }
</style>
</head>
<body>
<div class="wrap">
  <h1>Estado de la API</h1>
  <div class="sub">
    <?= $ultimoTs ? 'Último chequeo ' . htmlspecialchars(hace($ultimoTs)) . ' (' . htmlspecialchars($ultimoTs) . ')' : 'Todavía sin chequeos registrados.' ?>
    · se refresca solo cada 60&nbsp;s · <a href="?format=json">JSON</a>
  </div>

  <div class="banner <?= $dbError === 'tabla_faltante' ? 'warn' : $estadoTxt[1] ?>"><span class="dot"></span><?=
    $dbError === 'tabla_faltante' ? 'falta crear la tabla api_status_checks' : htmlspecialchars($estadoTxt[0]) ?></div>

  <?php if ($dbError === 'tabla_faltante'): ?>
    <p class="err">La tabla <code>api_status_checks</code> todavía no existe. Corré el <code>CREATE TABLE</code> de <code>CRON.md</code> y agregá la línea de cron (<code>*/5</code>). En cuanto <code>cron_status.php</code> corra una vez, este panel se llena solo.</p>
  <?php elseif ($dbError !== null): ?>
    <p class="err">Error de base de datos leyendo el histórico: <?= htmlspecialchars($dbError) ?></p>
  <?php elseif (!$cronVivo && $ultimoTs): ?>
    <p class="err">⚠ El cron <code>cron_status.php</code> no registra chequeos hace más de 15&nbsp;min. Los datos de abajo pueden estar viejos.</p>
  <?php elseif (!$ultimas): ?>
    <p class="muted">Cuando <code>cron_status.php</code> corra por primera vez van a aparecer acá los endpoints. Ver instrucciones en <code>CRON.md</code>.</p>
  <?php endif; ?>

  <?php
  $grpActual = null;
  foreach ($claves as $chk):
      $u = $ultimas[$chk];
      $a = $agg[$chk] ?? [];
      [$env, $ep] = nombreLindo($chk);
      $up24 = pct($a['ok24'] ?? 0, $a['n24'] ?? 0);
      $up7  = pct($a['ok7'] ?? 0,  $a['n7']  ?? 0);
      if ($env !== $grpActual):
          if ($grpActual !== null) echo "</tbody></table>";
          $grpActual = $env;
          $etq = ['prod' => 'Producción · /api/', 'sandbox' => 'Sandbox · /sandbox/', 'shared' => 'Generales'][$env] ?? $env;
          echo '<div class="grp">' . htmlspecialchars($etq) . '</div>';
          echo '<table><thead><tr><th>Endpoint</th><th>Estado</th><th class="num">Latencia</th><th class="num hide">Prom 24h</th><th class="num">Uptime 24h</th><th class="num hide">Uptime 7d</th><th class="hide">Últimas 3h</th></tr></thead><tbody>';
      endif;
  ?>
    <tr>
      <td class="ep"><?= htmlspecialchars($ep) ?> <span class="muted" style="font-weight:400"><?= htmlspecialchars($u['metodo']) ?></span></td>
      <?php $skip = (int) $u['http_code'] === 429; ?>
      <td>
        <?php if ($skip): ?>
          <span class="pill na" title="El proxy devolvió 429 en el último chequeo; no se pudo medir.">SIN MEDICIÓN</span>
        <?php elseif ($u['ok']): ?>
          <span class="pill ok">OK <?= (int) $u['http_code'] ?></span>
        <?php else: ?>
          <span class="pill bad">CAÍDO <?= (int) $u['http_code'] ?: '—' ?></span>
        <?php endif; ?>
        <div class="muted" style="font-size:11px"><?= htmlspecialchars(hace($u['ts'])) ?></div>
        <?php if (!$u['ok'] && !$skip && $u['error']): ?>
          <div class="err"><?= $detalleCompleto ? htmlspecialchars($u['error']) : 'no operativo' ?></div>
        <?php endif; ?>
      </td>
      <td class="num"><?= (int) $u['latency_ms'] ?> ms</td>
      <td class="num hide"><?= isset($a['lat_avg24']) ? (int) $a['lat_avg24'] . ' ms' : '—' ?></td>
      <td class="num u-<?= claseUptime($up24) ?>"><?= $up24 === null ? '—' : number_format($up24, 2) . '%' ?></td>
      <td class="num hide u-<?= claseUptime($up7) ?>"><?= $up7 === null ? '—' : number_format($up7, 2) . '%' ?></td>
      <td class="hide"><?= sparkline($puntos[$chk] ?? []) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($grpActual !== null) echo "</tbody></table>"; ?>

  <?php if ($incidentes): ?>
    <div class="grp">Incidentes · últimos 7 días</div>
    <div class="inc">
      <?php foreach (array_slice($incidentes, 0, 25) as $i):
        $dur = strtotime($i['fin']) - strtotime($i['ini']);
        $enCurso = (time() - strtotime($i['fin'])) < 15 * 60;
      ?>
        <div>
          <strong><?= htmlspecialchars($i['chk']) ?></strong>
          — <?= htmlspecialchars(substr($i['ini'], 5, 11)) ?>
          <?php if ($dur >= 60): ?>· <?= floor($dur / 60) ?> min<?php endif; ?>
          · <?= (int) $i['veces'] ?> fallo(s)
          <?php if ($enCurso): ?><span class="pill bad">en curso</span><?php endif; ?>
          <?php if ($detalleCompleto): ?><div class="err"><?= htmlspecialchars($i['error']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($ultimas): ?>
    <div class="grp">Incidentes · últimos 7 días</div>
    <p class="muted">Sin incidentes registrados. 🎉</p>
  <?php endif; ?>

  <p class="sub" style="margin-top:30px">
    Chequeos sin token / sin body: un <code>401</code>/<code>400</code> limpio = el endpoint está sano
    (PHP corre, ruteo OK, la BD responde al validar el token). Un error PHP en el body marca el check como caído.
    Un <code>429</code> es el proxy throttleando el chequeo, no el endpoint: se muestra como <em>sin medición</em> y no cuenta para el uptime.
    <?php if (!$detalleCompleto): ?><br>Vista recortada. Con <code>?key=…</code> se ven los mensajes de error y el detalle de incidentes.<?php endif; ?>
  </p>
</div>
</body>
</html>
