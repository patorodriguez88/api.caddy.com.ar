# Procesos periódicos (cron)

Desde el **2026-08-28** los procesos periódicos corren como **cron de cPanel
ejecutando PHP por CLI**, no por HTTP. Antes se disparaban desde
[cron-job.org](https://console.cron-job.org/dashboard) pegándole a una URL.

## Jobs activos (crontab de cPanel, usuario `dinter6`)

```cron
MAILTO="prodriguez@dintersa.com.ar"
SHELL="/bin/bash"

# Worker de la cola de MercadoLibre — cada 2 min
*/2 * * * * /usr/bin/flock -n /home/dinter6/tmp/cron_worker.lock   /opt/cpanel/ea-php82/root/usr/bin/php /home/dinter6/api.caddy.com.ar/api/cron_worker.php   >> /home/dinter6/logs/cron_worker.log 2>&1

# Envío de webhooks pendientes (Webhook_notifications) — cada 2 min
*/2 * * * * /usr/bin/flock -n /home/dinter6/tmp/cron_webhooks.lock /opt/cpanel/ea-php82/root/usr/bin/php /home/dinter6/api.caddy.com.ar/api/cron_webhooks.php >> /home/dinter6/logs/cron_webhooks.log 2>&1

# Chequeo de salud de los endpoints (llena api_status_checks) — cada 5 min
# El `CRON_STATUS_SECRET=...` adelante exporta el secret solo para ese proceso
# (getenv en cron_status.php). Rotar el valor y borrarlo del código (queda un
# fallback hardcodeado por si el env no está). El CLI no usa el secret; sirve
# para el disparo HTTP y para cron-job.org.
*/5 * * * * CRON_STATUS_SECRET=<secret-rotado> /usr/bin/flock -n /home/dinter6/tmp/cron_status.lock /opt/cpanel/ea-php82/root/usr/bin/php /home/dinter6/api.caddy.com.ar/api/cron_status.php >> /home/dinter6/logs/cron_status.log 2>&1

# Truncar los logs, domingos 4am
0 4 * * 0 : > /home/dinter6/logs/cron_worker.log ; : > /home/dinter6/logs/cron_webhooks.log ; : > /home/dinter6/logs/cron_status.log
```

- **`flock -n`**: si la corrida anterior sigue viva, la nueva se **saltea** en
  vez de apilarse. Es la protección contra el pile-up de procesos.
- **Binario pineado** (`ea-php82`): el wrapper `/usr/local/bin/php` resuelve la
  versión por directorio y desde cron puede caer a la default de la cuenta; los
  scripts necesitan PHP 8.
- **`MAILTO`**: cualquier error del cron llega por mail (visibilidad que con
  cron-job.org no había).
- Logs en `/home/dinter6/logs/`.

## Modo CLI vs HTTP

Ambos scripts (`cron_worker.php`, `cron_webhooks.php`) detectan el SAPI:

```php
$esCli = (php_sapi_name() === 'cli');
if (!$esCli) { /* exige ?secret=... y manda headers no-cache */ }
```

- **CLI** (cron de cPanel): sin secret. No consume Entry Processes, no pasa por
  el WAF, no lo cachea ningún proxy.
- **HTTP** (`https://api.caddy.com.ar/api/cron_*.php?secret=...`): sigue
  funcionando por si hay que dispararlo a mano. El secret está hardcodeado como
  constante en cada archivo.

**Ojo con el `/api/` en la URL HTTP**: el Document Root del dominio es
`/home/dinter6/api.caddy.com.ar`, pero la cuenta FTP `api@api.caddy.com.ar` tiene
su home en `/home/dinter6/api.caddy.com.ar/api`, así que lo deployado queda
público en `https://api.caddy.com.ar/api/<archivo>.php`.

## cron-job.org — fallback deshabilitado

Los dos jobs en cron-job.org quedaron **pausados** (no borrados) como respaldo.
Apuntan a las URLs HTTP con `?secret=...`. Si alguna vez se reactivan, `flock`
del lado del cron de cPanel no aplica al trigger HTTP, así que **no reactivar los
dos a la vez** (HTTP + CLI).

## Qué hace cada uno

**`cron_webhooks.php`** — consume `Webhook_notifications`, que
`sistema.caddy.com.ar` encola en `cambiarRecorrido()`
(`Funciones/Funciones.php`) cuando un cambio de estado amerita avisar a un
cliente (según `Estados.Webhook`). Envía cada notificación pendiente al endpoint
del cliente (`Webhook.Endpoint`).

Acotado a propósito (fix 2026-08-28): `LIMIT 50` por corrida, presupuesto de
~20s, y **UPDATE de la fila** (`Send+1`, `Response`, `Stop`) en vez de INSERT de
una fila nueva por intento. La versión vieja hacía `SELECT` sin LIMIT sobre
cientos de miles de filas y un INSERT por intento → la tabla crecía sin fin, los
procesos quedaban horas en el loop de `curl` (el timer de PHP no cuenta la espera
de red) y saturaban los Entry Processes de la cuenta → **508 en toda la API**.

**Filtro de pendientes**: `Send <= 8 AND Response <> 200 AND (Stop = 0 OR Stop IS
NULL)`. El productor crea las filas con `Stop = NULL`; la versión vieja filtraba
solo `Stop = 0`, así que **nunca las tomaba** (había ~125k notificaciones sin
enviar acumuladas desde 2023). Ese backlog viejo se congeló aparte
(`UPDATE Webhook_notifications SET Stop = 1 WHERE Stop IS NULL AND TimeStamp < ...`)
para no disparar años de webhooks de golpe.

**`cron_worker.php`** — worker de la cola de webhooks de MercadoLibre
(`MeliWebhookQueue` / `Integraciones/meli_queue/`). Procesa un lote por corrida.

**`cron_status.php`** — chequeo de salud. Pega a cada endpoint (prod + sandbox)
con un request vacío (sin token / sin body), mide latencia y estado, y guarda una
fila por chequeo en `api_status_checks`. El panel `status.php`
(`https://api.caddy.com.ar/api/status.php`, o `?format=json` para monitoreo
externo) lee esa tabla y muestra estado actual, latencia y uptime 24h / 7d.

Un endpoint se cuenta OK si respondió, el body no tiene un error de PHP y el HTTP
code + marcador son los esperados (un `401`/`400` limpio ya prueba que PHP corre,
el ruteo anda y la BD responde al validar el token). En cada corrida borra de
`api_status_checks` lo más viejo que `RETENCION_DIAS` (14).

Usa un User-Agent propio (`CaddyStatus/1.0`), no `curl`: el mod_security del
hosting devuelve **406** a los requests con UA `curl` o UA vacío. Si algún
monitor externo pega con `curl` pelado, hay que agregarle `-A "algo"`.

**429 / rate-limit del proxy (fix 2026-09-01).** Los 16 checks salían pegados
desde la IP del propio server y el nginx de adelante empezaba a devolver **429**
a partir del ~request 8; el panel pintaba de "caído" medio sandbox sin que los
endpoints tuvieran nada (uptime 24h en ~22 %). Mitigación en `cron_status.php`:

- `PAUSA_ENTRE_CHECKS_MS` (500) entre cada request y `shuffle()` del orden, para
  no sesgar siempre a los mismos checks.
- Ante un `429`: espera `REINTENTO_429_MS` (2500) y reintenta **una** vez. Si
  igual da 429, la fila se guarda con `http_code = 429` y se trata como **"sin
  medición"** (skipped), no como caída.
- `status.php` **excluye `http_code = 429`** del uptime, de los incidentes y del
  estado global; en la tabla sale un pill gris "SIN MEDICIÓN" y el sparkline lo
  pinta atenuado. Esto arregla también el histórico ya guardado.
- Frecuencia bajada de `*/3` a `*/5` para aflojar la carga saliente.
- `CHECK_RESOLVE` (por defecto `''`): si se setea a `127.0.0.1`, los checks
  resuelven el host a esa IP y saltan el proxy. Probar antes a mano
  (`curl --resolve api.caddy.com.ar:443:127.0.0.1 ...`); contra: deja de testear
  el borde (TLS/proxy).

**Secrets por env.** `CRON_STATUS_SECRET` (disparo HTTP) y `STATUS_VIEW_KEY`
(panel) se leen con `getenv()`. `cron_status.php` deja un fallback hardcodeado;
`status.php` **no** — sin `STATUS_VIEW_KEY` el panel queda abierto pero recortado
(sin strings de error crudos ni detalle de incidentes). Con `?key=…` correcta se
ve todo; con la key configurada y sin/mal `?key=` → 403 (también para el JSON).

**URLs del panel:** la canónica es **`/api/status.php`** (es adonde deploya la
cuenta FTP). `/api-docs/status.php` resuelve al mismo panel (redirect / alias
armado a mano); si algún día deja de andar, apuntar directo a `/api/status.php`.

### Tabla `api_status_checks` (crear una vez)

```sql
CREATE TABLE IF NOT EXISTS api_status_checks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  chk VARCHAR(40) NOT NULL,          -- 'prod:rates', 'sandbox:auth', 'shared:docs'
  entorno VARCHAR(10) NOT NULL,      -- prod | sandbox | shared
  metodo VARCHAR(8) NOT NULL,
  url VARCHAR(255) NOT NULL,
  http_code SMALLINT NOT NULL,       -- 0 = no respondió / timeout
  ok TINYINT(1) NOT NULL,
  latency_ms INT NOT NULL,
  ttfb_ms INT NOT NULL,
  error VARCHAR(200) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_chk_ts (chk, ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Incidente 2026-08-28 (resumen)

`cron_webhooks.php` viejo + backlog de ~340k filas + endpoint de un partner
caído → procesos PHP colgados en `curl` que no terminaban → Entry Processes al
límite → **508 en toda la API durante la mañana**. Se resolvió: pausar
cron-job.org, matar los `lsphp`, congelar el backlog
(`UPDATE Webhook_notifications SET Stop=1 WHERE Send<=8 AND Response<>200 AND Stop=0`),
reescribir `cron_webhooks.php` acotado, y mover los crons a cPanel + CLI + flock.
