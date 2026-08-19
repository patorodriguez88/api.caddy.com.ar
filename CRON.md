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
| Envío de webhooks pendientes (`Webhook_notifications` → `SendWebhooks`) | `SistemaTriangular/cron_webhooks.php` | sistema.caddy.com.ar |

El secret de cada uno está hardcodeado como constante en el propio archivo PHP (mismo
patrón en los dos) — no está en ningún otro lado, no hace falta buscarlo en un vault.

## Por qué existe este archivo

Para no depender de la memoria de nadie sobre cuál era el servicio de cron externo que
usábamos, ni de tener que releer código para acordarse de que este patrón existe. Si se
agrega un job nuevo (otro disparador HTTP con secret), sumarlo a la tabla de arriba.
