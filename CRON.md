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

# Truncar los logs, domingos 4am
0 4 * * 0 : > /home/dinter6/logs/cron_worker.log ; : > /home/dinter6/logs/cron_webhooks.log
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

**`cron_worker.php`** — worker de la cola de webhooks de MercadoLibre
(`MeliWebhookQueue` / `Integraciones/meli_queue/`). Procesa un lote por corrida.

## Incidente 2026-08-28 (resumen)

`cron_webhooks.php` viejo + backlog de ~340k filas + endpoint de un partner
caído → procesos PHP colgados en `curl` que no terminaban → Entry Processes al
límite → **508 en toda la API durante la mañana**. Se resolvió: pausar
cron-job.org, matar los `lsphp`, congelar el backlog
(`UPDATE Webhook_notifications SET Stop=1 WHERE Send<=8 AND Response<>200 AND Stop=0`),
reescribir `cron_webhooks.php` acotado, y mover los crons a cPanel + CLI + flock.
