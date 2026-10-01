<?php
// Integración con la API de Andreani (cotización, por ahora).
// Doc: developers.andreani.com. Ambientes: QA https://apisqa.andreani.com / producción https://apis.andreani.com
//   - GET /login con Basic auth (usuario:contraseña) -> el token viene en el header x-authorization-token
//   - GET /v1/tarifas?cpDestino=&contrato=&cliente=&bultos[0][kilos]=&bultos[0][volumen]=&bultos[0][valorDeclarado]=
// Las credenciales NO van en el repo: conexion/andreani_config.php existe solo en el servidor
// (ver conexion/andreani_config.example.php). Si falta, la clase lo avisa y no llama a Andreani.
class Andreani
{
    private const URLS = ['qa' => 'https://apisqa.andreani.com', 'produccion' => 'https://apis.andreani.com'];

    private array $conf;
    private string $base;

    public function __construct()
    {
        $archivo = __DIR__ . '/../conexion/andreani_config.php';
        if (!is_file($archivo)) {
            throw new RuntimeException('Falta conexion/andreani_config.php en el servidor (credenciales de Andreani).');
        }
        $conf = require $archivo;
        foreach (['ambiente', 'usuario', 'password', 'cliente', 'contrato'] as $k) {
            if (empty($conf[$k])) {
                throw new RuntimeException("andreani_config.php: falta '$k'.");
            }
        }
        if (!isset(self::URLS[$conf['ambiente']])) {
            throw new RuntimeException("andreani_config.php: ambiente debe ser 'qa' o 'produccion'.");
        }
        $this->conf = $conf;
        $this->base = self::URLS[$conf['ambiente']];
    }

    public function ambiente(): string
    {
        return $this->conf['ambiente'];
    }

    /**
     * Cotiza un envío. $bultos = [['kilos' => 2.5, 'altoCm' => 20, 'anchoCm' => 30, 'largoCm' => 40, 'valorDeclarado' => 10000], ...]
     * Devuelve ['ok' => true, 'con_iva' => float, 'sin_iva' => float, 'peso_aforado' => ?float, 'crudo' => array]
     * o ['ok' => false, 'http' => int, 'error' => string].
     */
    public function cotizar(string $cpDestino, array $bultos, ?string $contrato = null): array
    {
        $params = [
            'cpDestino' => $cpDestino,
            'contrato'  => $contrato ?: $this->conf['contrato'],
            'cliente'   => $this->conf['cliente'],
            'bultos'    => [],
        ];
        if (!empty($this->conf['sucursal_origen'])) {
            $params['sucursalOrigen'] = $this->conf['sucursal_origen'];
        }
        foreach ($bultos as $b) {
            $bulto = ['valorDeclarado' => (int) round((float) ($b['valorDeclarado'] ?? 0))];
            foreach (['kilos', 'altoCm', 'anchoCm', 'largoCm', 'volumen'] as $k) {
                if (isset($b[$k]) && (float) $b[$k] > 0) {
                    $bulto[$k] = (float) $b[$k];
                }
            }
            if (!isset($bulto['volumen']) && isset($bulto['altoCm'], $bulto['anchoCm'], $bulto['largoCm'])) {
                $bulto['volumen'] = $bulto['altoCm'] * $bulto['anchoCm'] * $bulto['largoCm'];
            }
            $params['bultos'][] = $bulto;
        }

        [$http, $cuerpo] = $this->pedir('GET', '/v1/tarifas?' . http_build_query($params));
        $json = json_decode($cuerpo, true);
        if ($http !== 200 || !is_array($json)) {
            return ['ok' => false, 'http' => $http, 'error' => is_array($json) ? ($json['detail'] ?? $json['title'] ?? $json['message'] ?? $cuerpo) : $cuerpo];
        }
        return [
            'ok'           => true,
            'con_iva'      => isset($json['tarifaConIva']['total']) ? (float) $json['tarifaConIva']['total'] : null,
            'sin_iva'      => isset($json['tarifaSinIva']['total']) ? (float) $json['tarifaSinIva']['total'] : null,
            'peso_aforado' => isset($json['pesoAforado']) ? (float) $json['pesoAforado'] : null,
            'crudo'        => $json,
        ];
    }

    // Token de Andreani: dura 24 hs; se guarda en un archivo temporal para no loguearse en cada cotización.
    private function token(bool $renovar = false): string
    {
        $cache = sys_get_temp_dir() . '/andreani_token_' . md5($this->base . '|' . $this->conf['usuario']) . '.json';
        if (!$renovar && is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (!empty($c['token']) && ($c['vence'] ?? 0) > time()) {
                return $c['token'];
            }
        }
        $ch = curl_init($this->base . '/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode($this->conf['usuario'] . ':' . $this->conf['password'])],
        ]);
        $resp = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $largoHeaders = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($http !== 200 || !preg_match('/^x-authorization-token:\s*(\S+)/mi', substr($resp, 0, $largoHeaders), $m)) {
            throw new RuntimeException("Andreani no aceptó el login (HTTP $http). Revisá usuario y contraseña en andreani_config.php.");
        }
        @file_put_contents($cache, json_encode(['token' => $m[1], 'vence' => time() + 23 * 3600]));
        return $m[1];
    }

    private function pedir(string $metodo, string $ruta, bool $reintento = false): array
    {
        $ch = curl_init($this->base . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['x-authorization-token: ' . $this->token($reintento), 'Accept: application/json'],
        ]);
        $cuerpo = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 401 && !$reintento) {
            return $this->pedir($metodo, $ruta, true); // token vencido antes de tiempo: se pide uno nuevo una vez
        }
        return [$http, $cuerpo];
    }
}
