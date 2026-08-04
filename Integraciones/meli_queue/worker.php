<?php
// Worker de la cola de webhooks de Meli. Pensado para correr por cron
// (CLI: `php worker.php`, cada 1 minuto) y drenar MeliWebhookQueue en lotes,
// reusando la lógica de procesamiento existente sin tocarla.

require_once __DIR__ . '/../../conexion/conexion.php';
require_once __DIR__ . '/../../clases/webhook_ml.class.php'; // define class auth (login() sin modificar)

// Cambiar por un valor propio antes de exponer worker.php por HTTP en un cron de cPanel.
const MELI_QUEUE_WORKER_SECRET = '';

class MeliQueueWorker extends conexion
{
    private const LOTE = 20;
    private const MAX_INTENTOS = 5;

    public function procesarLote(auth $handler): array
    {
        $pendientes = parent::obtenerDatos(
            "SELECT id, raw_payload FROM MeliWebhookQueue
             WHERE procesado = 0 AND intentos < " . self::MAX_INTENTOS . "
             ORDER BY id ASC
             LIMIT " . self::LOTE
        );

        $resumen = ['total' => count($pendientes), 'procesados' => 0, 'fallidos' => 0];

        foreach ($pendientes as $fila) {
            if ($this->procesarUno($handler, $fila)) {
                $resumen['procesados']++;
            } else {
                $resumen['fallidos']++;
            }
        }

        return $resumen;
    }

    private function procesarUno(auth $handler, array $fila): bool
    {
        $id = (int)$fila['id'];

        try {
            $resultado = $handler->login($fila['raw_payload']);

            $query = "UPDATE MeliWebhookQueue
                      SET procesado = 1, processed_at = NOW(), resultado = '" . parent::escapar(json_encode($resultado)) . "'
                      WHERE id = '" . parent::escapar($id) . "'
                      LIMIT 1";
            parent::nonQuery($query);

            parent::logMeli('MELI_QUEUE_PROCESADO', ['id' => $id, 'resultado' => $resultado]);
            return true;
        } catch (Throwable $e) {
            $query = "UPDATE MeliWebhookQueue
                      SET intentos = intentos + 1, error = '" . parent::escapar($e->getMessage()) . "'
                      WHERE id = '" . parent::escapar($id) . "'
                      LIMIT 1";
            parent::nonQuery($query);

            parent::logMeli('MELI_QUEUE_ERROR', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }
}

$esCLI = (php_sapi_name() === 'cli');

if (!$esCLI) {
    $secreto = $_GET['secret'] ?? '';
    if (MELI_QUEUE_WORKER_SECRET === '' || $secreto !== MELI_QUEUE_WORKER_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => 0, 'error' => 'No autorizado']);
        exit;
    }
    header('Content-Type: application/json');
}

$handler = new auth();
$worker = new MeliQueueWorker();
$resumen = $worker->procesarLote($handler);

if ($esCLI) {
    echo json_encode($resumen, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($resumen);
}
