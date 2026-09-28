<?php

/**
 * Caddy como mensajería de Envíos Flex en Mercado Libre.
 *
 * ML mide a la mensajería (volumen, SLA, % same day, entregas post 21 hs) sobre
 * los envíos que tiene asociados. Esta clase:
 *  - guarda la cuenta de ML de la mensajería (OAuth, una sola vez: ver ml_mensajeria.php)
 *    y renueva su token solo;
 *  - le reporta a ML cada envío Flex que maneja Caddy:
 *    POST /flex/sites/MLA/users/{user_id mensajería}/courier-shipment/v1 {"shipment_id": N}
 *
 * Los estados (retirado, entregado) NO se reportan acá: los siguen marcando los
 * repartidores en la app Envíos Flex de ML.
 *
 * Usa la misma app de ML que los vendedores (WebhookMlReceiver::CLIENT_ID), con el
 * permiso "Ventas y envíos de un producto" en lectura y escritura.
 *
 * Tablas (crear una vez, en dinter6_triangular y en dinter6_triangularcopia para sandbox):
 *
 *   CREATE TABLE ml_mensajeria (
 *     id            TINYINT      NOT NULL PRIMARY KEY,
 *     user_id       BIGINT       NOT NULL,
 *     nickname      VARCHAR(100) NOT NULL DEFAULT '',
 *     access_token  VARCHAR(255) NOT NULL,
 *     refresh_token VARCHAR(255) NOT NULL,
 *     expira        DATETIME     NOT NULL,
 *     actualizado   DATETIME     NOT NULL
 *   );
 *
 *   CREATE TABLE ml_mensajeria_envios (
 *     shipment_id     BIGINT       NOT NULL PRIMARY KEY,
 *     idTransClientes INT          NOT NULL DEFAULT 0,
 *     http_code       SMALLINT     NOT NULL DEFAULT 0,
 *     respuesta       VARCHAR(255) NOT NULL DEFAULT '',
 *     intentos        TINYINT      NOT NULL DEFAULT 0,
 *     primer_intento  DATETIME     NOT NULL,
 *     ultimo_intento  DATETIME     NOT NULL,
 *     KEY idx_code (http_code)
 *   );
 */

require_once __DIR__ . "/../conexion/conexion.php";
require_once __DIR__ . "/webhook_ml_receiver.class.php";

class MlMensajeria extends conexion
{
    public const REDIRECT_URI = 'https://api.caddy.com.ar/api/ml_mensajeria';
    private const SITE = 'MLA';

    // Respuestas definitivas: no se reintentan.
    // 204 registrado, 409 ya asignado a una mensajería, 404 no existe, 400 inválido (ej. no es Flex)
    private const DEFINITIVOS = [204, 400, 404, 409];
    private const MAX_INTENTOS = 5;

    /** URL de autorización de ML para la cuenta de la mensajería */
    public static function urlAutorizacion(string $state): string
    {
        return 'https://auth.mercadolibre.com.ar/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => WebhookMlReceiver::CLIENT_ID,
            'redirect_uri'  => self::REDIRECT_URI,
            'state'         => $state,
        ]);
    }

    /** Canjea el code del OAuth y guarda la cuenta. Devuelve [ok, mensaje] */
    public function conectar(string $code): array
    {
        [$http, $r] = self::oauthToken([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::REDIRECT_URI,
        ]);
        if ($http !== 200 || empty($r['access_token']) || empty($r['user_id'])) {
            return [false, 'ML rechazó la autorización: ' . substr(($r['error'] ?? '') . ' ' . ($r['message'] ?? ''), 0, 150)];
        }

        [, $me] = self::http('GET', 'https://api.mercadolibre.com/users/me', $r['access_token']);
        $this->guardarCuenta((int)$r['user_id'], (string)($me['nickname'] ?? ''), $r);
        return [true, 'Cuenta conectada: ' . ($me['nickname'] ?? '') . ' (user_id ' . $r['user_id'] . ')'];
    }

    /** Cuenta conectada (sin tokens), o null */
    public function cuenta(): ?array
    {
        $c = parent::obtenerDatos("SELECT user_id, nickname, expira, actualizado FROM ml_mensajeria WHERE id = 1");
        return $c[0] ?? null;
    }

    /** [user_id, access_token] vigentes, renovando el token si vence en menos de 10 min */
    private function credenciales(): ?array
    {
        $c = parent::obtenerDatos("SELECT user_id, nickname, access_token, refresh_token, expira FROM ml_mensajeria WHERE id = 1");
        $c = $c[0] ?? null;
        if (!$c) {
            return null;
        }
        if (strtotime($c['expira']) > time() + 600) {
            return [(int)$c['user_id'], $c['access_token']];
        }

        [$http, $r] = self::oauthToken(['grant_type' => 'refresh_token', 'refresh_token' => $c['refresh_token']]);
        if ($http !== 200 || empty($r['access_token'])) {
            parent::logMeli('ML_MENSAJERIA_REFRESH_FALLIDO', ['http' => $http, 'error' => $r['error'] ?? '', 'message' => $r['message'] ?? '']);
            return null;
        }
        // ML rota el refresh_token en cada uso: si no se guarda el nuevo, se pierde el acceso
        $this->guardarCuenta((int)$c['user_id'], (string)$c['nickname'], $r + ['refresh_token' => $c['refresh_token']]);
        return [(int)$c['user_id'], $r['access_token']];
    }

    private function guardarCuenta(int $userId, string $nickname, array $tok): void
    {
        $expira = date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 21600));
        parent::nonQuery(
            "REPLACE INTO ml_mensajeria (id, user_id, nickname, access_token, refresh_token, expira, actualizado)
             VALUES (1, " . $userId . ", '" . $this->escapar($nickname) . "', '" . $this->escapar($tok['access_token']) . "',
                     '" . $this->escapar($tok['refresh_token']) . "', '" . $expira . "', NOW())"
        );
    }

    /**
     * Reporta los envíos de ML que maneja Caddy y todavía no quedaron registrados.
     * @param int      $dias   antigüedad máxima (por TransClientes.Fecha)
     * @param int      $limite máximo de envíos por corrida
     * @param int|null $solo   reportar solo este shipment_id (prueba)
     * @param bool     $simular listar sin llamar a ML
     */
    public function reportarPendientes(int $dias = 2, int $limite = 100, ?int $solo = null, bool $simular = false): array
    {
        // Simular no necesita la cuenta: sirve para ver el volumen antes de conectarla
        $cred = $simular ? [0, ''] : $this->credenciales();
        if (!$cred) {
            return ['ok' => 0, 'error' => 'Sin cuenta de mensajería conectada o token inválido'];
        }
        [$userId, $token] = $cred;

        $filtro = $solo
            ? "t.shipments_id = '" . $solo . "'"
            : "t.Fecha >= CURDATE() - INTERVAL " . max(0, $dias) . " DAY
               AND (e.shipment_id IS NULL
                    OR (e.http_code NOT IN (" . implode(',', self::DEFINITIVOS) . ")
                        AND e.intentos < " . self::MAX_INTENTOS . "
                        AND e.ultimo_intento < NOW() - INTERVAL 10 MINUTE))";

        // Un envío de ML puede tener varias filas (bultos): se reporta una vez.
        // shipments_id también guarda pedidos de Tienda Nube (10 dígitos, empiezan con 2):
        // los envíos de ML tienen 11+ dígitos y empiezan con 4 o más.
        $envios = parent::obtenerDatos(
            "SELECT t.shipments_id AS shipment_id, MIN(t.id) AS idTransClientes
             FROM TransClientes t
             LEFT JOIN ml_mensajeria_envios e ON e.shipment_id = t.shipments_id
             WHERE t.Eliminado = 0 AND t.shipments_id REGEXP '^[4-9][0-9]{10,}$' AND $filtro
             GROUP BY t.shipments_id
             ORDER BY MIN(t.id)
             LIMIT " . max(1, $limite)
        );

        $resumen = ['ok' => 1, 'mensajeria' => $userId, 'candidatos' => count($envios), 'codigos' => []];
        if ($simular) {
            $resumen['simulado'] = array_column($envios, 'shipment_id');
            return $resumen;
        }

        $url = 'https://api.mercadolibre.com/flex/sites/' . self::SITE . '/users/' . $userId . '/courier-shipment/v1';
        foreach ($envios as $e) {
            [$http, $r, $raw] = self::http('POST', $url, $token, ['shipment_id' => (int)$e['shipment_id']]);
            $this->registrar((int)$e['shipment_id'], (int)$e['idTransClientes'], $http, $raw);
            $resumen['codigos'][$http] = ($resumen['codigos'][$http] ?? 0) + 1;

            // Token o permiso inválido: cortar, van a fallar todos igual
            if ($http === 401 || $http === 403) {
                $resumen['ok'] = 0;
                $resumen['error'] = "ML respondió $http: revisar permiso 'Ventas y envíos' (lectura y escritura) o reconectar la cuenta";
                break;
            }
        }
        return $resumen;
    }

    /** Últimos resultados, para la pantalla de estado */
    public function resumenReportes(): array
    {
        return [
            'por_codigo' => parent::obtenerDatos(
                "SELECT http_code, COUNT(*) n, MAX(ultimo_intento) ultimo FROM ml_mensajeria_envios
                 WHERE primer_intento >= NOW() - INTERVAL 7 DAY GROUP BY http_code ORDER BY n DESC"
            ),
            'ultimos' => parent::obtenerDatos(
                "SELECT shipment_id, idTransClientes, http_code, respuesta, intentos, ultimo_intento
                 FROM ml_mensajeria_envios ORDER BY ultimo_intento DESC LIMIT 15"
            ),
        ];
    }

    private function registrar(int $shipmentId, int $idTc, int $http, string $raw): void
    {
        $resp = $this->escapar(substr(trim($raw), 0, 255));
        parent::nonQuery(
            "INSERT INTO ml_mensajeria_envios (shipment_id, idTransClientes, http_code, respuesta, intentos, primer_intento, ultimo_intento)
             VALUES ($shipmentId, $idTc, $http, '$resp', 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE http_code = $http, respuesta = '$resp', intentos = intentos + 1, ultimo_intento = NOW()"
        );
    }

    private static function oauthToken(array $campos): array
    {
        $ch = curl_init('https://api.mercadolibre.com/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($campos + [
                'client_id'     => WebhookMlReceiver::CLIENT_ID,
                'client_secret' => WebhookMlReceiver::CLIENT_SECRET,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $raw  = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http, json_decode($raw, true) ?: []];
    }

    /** @return array [http_code, json decodificado, cuerpo crudo] */
    private static function http(string $metodo, string $url, string $token, ?array $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $raw  = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http === 0) {
            $raw = 'curl: ' . curl_error($ch);
        }
        curl_close($ch);
        return [$http, json_decode($raw, true) ?: [], $raw];
    }
}
