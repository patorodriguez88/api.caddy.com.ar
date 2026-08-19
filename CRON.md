# Cron externo (cron-job.org)

El cron de Apache no nos funcionaba de forma confiable, así que los procesos periódicos
se disparan desde afuera: un job en **[cron-job.org](https://console.cron-job.org/dashboard)**
le pega por HTTP a una URL protegida por secret, en vez de depender de un cron del servidor.

Cada endpoint acepta el secret por GET o POST (`?secret=...` o `secret=...` en el body) y
devuelve JSON. Van con headers no-cache porque nginx/nuestro proxy llegó a cachear alguna
de estas URLs, lo que hacía que el job pareciera correr sin hacer nada.

## Jobs activos

| Qué dispara | Archivo | Sitio |
|---|---|---|
| Worker de la cola de MercadoLibre | `cron_worker.php` | api.caddy.com.ar |
| Envío de webhooks pendientes (`Webhook_notifications`) | `cron_webhooks.php` | api.caddy.com.ar |

El envío de webhooks vive acá (no en sistema.caddy.com.ar) porque es un evento de API
saliente hacia un partner externo (Wepoint, etc.) — api.caddy.com.ar es el subdominio
para todos los eventos de API. Quien *decide* cuándo avisar (según `Estados.Webhook`) y
encola la fila en `Webhook_notifications` es `cambiarRecorrido()` en sistema.caddy.com.ar
(`Funciones/Funciones.php`) — eso sí queda ahí, porque es lógica operativa interna, no un
evento de API en sí mismo. Las dos apps comparten la misma base (`dinter6_triangular` en
`ftp.dintersa.com.ar`), así que no hace falta duplicar tablas para que esto funcione.

El secret de cada uno está hardcodeado como constante en el propio archivo PHP (mismo
patrón en los dos) — no está en ningún otro lado, no hace falta buscarlo en un vault.

## Por qué existe este archivo

Para no depender de la memoria de nadie sobre cuál era el servicio de cron externo que
usábamos, ni de tener que releer código para acordarse de que este patrón existe. Si se
agrega un job nuevo (otro disparador HTTP con secret), sumarlo a la tabla de arriba.
