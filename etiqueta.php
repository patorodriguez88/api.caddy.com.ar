<?php
require_once 'conexion/conexion.php';
require_once 'clases/token.class.php';
require_once 'clases/respuestas.class.php';
require_once 'libs/fpdf182/fpdf.php';
require_once 'libs/phpqrcode/qrlib.php';

$_respuestas = new respuestas;


class EtiquetaService extends conexion
{
    // private $token;
    private function pdfTxt(string $txt): string
    {
        // Convertir de UTF-8 a ISO-8859-1 para FPDF
        return mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
    }

    private function dashedLine($pdf, $x1, $y1, $x2, $y2, $dash = 1, $gap = 1)
    {
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(0, 0, 0);

        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $dist = sqrt($dx * $dx + $dy * $dy);
        $dashGapCount = $dist / ($dash + $gap);
        $dashX = $dx / $dashGapCount;
        $dashY = $dy / $dashGapCount;

        for ($i = 0; $i < $dashGapCount; $i += 2) {
            $pdf->Line(
                $x1 + ($dashX * $i),
                $y1 + ($dashY * $i),
                $x1 + ($dashX * ($i + 1)),
                $y1 + ($dashY * ($i + 1))
            );
        }
    }

    /**
     * Datos de la venta / envío a partir del Código de Seguimiento
     */

    public function obtenerDatosEnvio(string $codigoSeguimiento, int $idOrigen)
    {
        // 1) PRIMERO BUSCO EN TRANSCLIENTES
        $idOrigen = (int)$idOrigen; // por las dudas

        $query_transclientes = "
        SELECT 
            tc.id,
            tc.Fecha,
            tc.RazonSocial       AS OrigenNombre,
            tc.DomicilioOrigen   AS OrigenDireccion,
            tc.LocalidadOrigen   AS OrigenLocalidad,
            tc.ClienteDestino,
            tc.DomicilioDestino,
            tc.LocalidadDestino,
            c.CodigoPostal       AS cpdestino,      -- mismo nombre que en PreVenta
            tc.TelefonoDestino   AS Telefono,       -- mismo nombre que en PreVenta
            tc.Cantidad,
            tc.ValorDeclarado,
            tc.CobrarEnvio       AS Cobranza,       -- mismo nombre que en PreVenta
            tc.CodigoSeguimiento,
            tc.CodigoProveedor   AS idProveedor,    -- mismo nombre que en PreVenta
            tc.Observaciones
        FROM TransClientes AS tc
        JOIN Clientes AS c ON tc.idClienteDestino = c.id
        WHERE tc.CodigoSeguimiento = '" . $codigoSeguimiento . "'
          AND tc.Eliminado = '0'
          AND tc.IngBrutosOrigen = '" . $idOrigen . "'
        LIMIT 1
    ";

        $datos = $this->obtenerDatos($query_transclientes);

        if ($datos && isset($datos[0])) {
            return $datos[0];   // 👈 el paquete pertenece al cliente del token
        }

        // 2) SI NO HAY DATOS EN TRANSCLIENTES, BUSCO EN PREVENTA
        $query_preventa = "
        SELECT 
            id,
            Fecha,
            RazonSocial       AS OrigenNombre,
            DomicilioOrigen   AS OrigenDireccion,
            LocalidadOrigen   AS OrigenLocalidad,
            ClienteDestino,
            DomicilioDestino,
            LocalidadDestino,
            cpdestino,
            Telefono,
            Cantidad,
            ValorDeclarado,
            Cobranza,
            CodigoSeguimiento,
            idProveedor,
            Observaciones
        FROM PreVenta
        WHERE CodigoSeguimiento = '" . $codigoSeguimiento . "'
          AND Eliminado = '0'
          AND NCliente = '" . $idOrigen . "'
        LIMIT 1
    ";

        $datos = $this->obtenerDatos($query_preventa);

        if ($datos && isset($datos[0])) {
            return $datos[0];
        }

        // 3) SI NO SE ENCONTRÓ NI EN TRANSCLIENTES NI EN PREVENTA PARA ESE CLIENTE
        return null;
    }

    private function dibujarEtiquetaPDF(FPDF $pdf, array $d, int $nroBulto = 1, int $totalBultos = 1): void
    {
        $margin = 5;
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetXY($margin, $margin);

        $origen      = $d['OrigenNombre']      ?? '';
        $o_dir       = $d['OrigenDireccion']   ?? '';
        $o_loc       = $d['OrigenLocalidad']   ?? '';
        $dest        = $d['ClienteDestino']    ?? '';
        $d_dir       = $d['DomicilioDestino']  ?? '';
        $d_loc       = $d['LocalidadDestino']  ?? '';
        $cp          = $d['cpdestino']         ?? '';
        $provDest    = $d['ProvinciaDestino']  ?? '';
        $recorrido   = $d['Recorrido']         ?? '';
        $tel         = $d['Telefono']          ?? '';
        $cant        = $d['Cantidad']          ?? 1;
        $valdec      = $d['ValorDeclarado']    ?? 0;
        $cobranza    = $d['Cobranza']          ?? 0;
        $codigoBase  = $d['CodigoSeguimiento'] ?? 'SIN-CODIGO';
        $usuario     = $d['Usuario']           ?? '';
        $fechaImp    = date('d/m/Y H:i');
        $idProveedor = $d['idProveedor']       ?? '';
        $id          = $d['id']                ?? '';
        $observaciones = $d['Observaciones']   ?? '';

        // código que se muestra / imprime por bulto
        $codigoEtiqueta = $d['CodigoSeguimiento'] ?? 'SIN-CODIGO';

        $pageWidth  = $pdf->GetPageWidth();
        $pageHeight = $pdf->GetPageHeight();
        $usableW    = $pageWidth - 2 * $margin;

        /* ========= BLOQUE SUPERIOR: LOGO + ORIGEN ========= */

        $logoPath   = __DIR__ . '/assets/LogoCaddy.png';
        $logoWidth  = 28;
        $logoHeight = 22;

        $yTop  = $pdf->GetY();
        $xLogo = $margin;

        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, $xLogo, $yTop, $logoWidth, 0);
        }

        // ORIGEN a la derecha del logo
        $xOrigen = $xLogo + $logoWidth + 3;
        $wOrigen = $usableW - ($logoWidth + 3);

        $pdf->SetXY($xOrigen, $yTop);

        // Nombre en negrita
        $nombre = $this->pdfTxt($origen);
        $pdf->SetFont('Arial', 'B', 11);
        $wNombre = $pdf->GetStringWidth($nombre) + 1;

        $pdf->Cell($wNombre, 5, $nombre, 0, 0, 'L');

        // #idProveedor normal, pegado
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 5, ' #' . $idProveedor, 0, 1, 'L');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetX($xOrigen);
        $pdf->Cell($wOrigen, 4, $this->pdfTxt($o_dir), 0, 1, 'L');
        $pdf->SetX($xOrigen);
        $pdf->Cell($wOrigen, 4, $this->pdfTxt($o_loc), 0, 1, 'L');
        $pdf->SetX($xOrigen);
        $pdf->Cell($wOrigen, 4, 'Venta: ' . $this->pdfTxt($id), 0, 1, 'L');

        // bajar cursor según lo más alto (logo o texto)
        $yAfterTop = max($yTop + $logoHeight, $pdf->GetY());

        /* ========= BULTO X/Y DEBAJO DEL LOGO (AJUSTADO) ========= */
        if ($totalBultos > 1) {

            $pdf->SetFont('Arial', 'B', 20); // 👈 MÁS GRANDE

            // Subimos un poco: antes era +1, ahora -2 para pegarlo más al logo
            $alturaFraccion = $yTop + $logoHeight - 2;

            // Posición: debajo del logo, más arriba y más visible
            $pdf->SetXY($margin, $alturaFraccion);

            // Texto tipo “1/3”
            $pdf->Cell(0, 10, $nroBulto . '/' . $totalBultos, 0, 1, 'L');

            // Actualizamos el Y final del bloque superior para continuar sin pisar
            $yAfterTop = max($alturaFraccion + 10, $pdf->GetY());
        }
        // ahora sí bajamos un poco y dibujamos la línea
        $pdf->SetY($yAfterTop + 3);
        $y = $pdf->GetY();
        $pdf->Line($margin, $y, $pageWidth - $margin, $y);
        $pdf->Ln(2);

        /* ========= CÓDIGO GRANDE (centrado) ========= */
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 5, $codigoEtiqueta, 0, 1, 'C');
        $pdf->Ln(3);

        // línea bajo el código
        $y = $pdf->GetY();
        $pdf->Line($margin, $y, $pageWidth - $margin, $y);
        $pdf->Ln(3);

        /* ========= BLOQUE QR + DATOS ========= */

        $qrSize = 30;
        $qrX    = $margin;
        $qrY    = $pdf->GetY();

        if (!empty($codigoEtiqueta)) {
            $tmpQR = sys_get_temp_dir() . '/qr_' . $codigoEtiqueta . '.png';
            QRcode::png($codigoEtiqueta, $tmpQR, QR_ECLEVEL_L, 4);

            if (file_exists($tmpQR)) {
                $pdf->Image($tmpQR, $qrX, $qrY, $qrSize, $qrSize);
                @unlink($tmpQR);
            }
        }

        $xDatosQR = $qrX + $qrSize + 4;
        $wDatosQR = $usableW - ($qrSize + 4);

        $pdf->SetXY($xDatosQR, $qrY);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($wDatosQR, 5, $codigoEtiqueta, 0, 1, 'L');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetX($xDatosQR);
        $pdf->Cell($wDatosQR, 5, 'CP: ' . $cp, 0, 1, 'L');
        $pdf->SetX($xDatosQR);
        $pdf->Cell($wDatosQR, 5, $this->pdfTxt($d_loc), 0, 1, 'L');

        if (!empty($provDest)) {
            $pdf->SetX($xDatosQR);
            $pdf->Cell($wDatosQR, 5, 'Prov: ' . $this->pdfTxt($provDest), 0, 1, 'L');
        }
        if (!empty($recorrido)) {
            $pdf->SetX($xDatosQR);
            $pdf->Cell($wDatosQR, 5, 'Recorrido: ' . $recorrido, 0, 1, 'L');
        }

        $yAfterQR = max($qrY + $qrSize, $pdf->GetY());
        $pdf->SetY($yAfterQR + 3);

        // línea
        $y = $pdf->GetY();
        $pdf->Line($margin, $y, $pageWidth - $margin, $y);
        $pdf->Ln(3);

        /* ========= DESTINO ========= */

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 5, 'DESTINO', 0, 1, 'L');

        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 4, $this->pdfTxt($dest), 0, 1, 'L');
        $pdf->Cell(0, 4, $this->pdfTxt($d_dir), 0, 1, 'L');
        $pdf->Cell(0, 4, $this->pdfTxt($d_loc . ' (' . $cp . ')'), 0, 1, 'L');
        if (!empty($tel)) {
            // $pdf->Cell(0, 4, 'Tel: ' . $tel, 0, 1, 'L');
        }
        // $pdf->Cell(0, 5, 'REFERENCIAS: ' . $this->pdfTxt($observaciones), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 4, 'REFERENCIAS: ' . $this->pdfTxt($observaciones), 0, 'L');
        $pdf->Ln(2);
        $pdf->Ln(2);

        /* ========= PIE ========= */

        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(0, 4, 'Usuario: ' . $usuario . '  |  Fecha: ' . $fechaImp, 0, 1, 'R');

        // Marco punteado
        $x = 2;
        $y = 2;
        $w = 96;
        $h = 146;
        $this->dashedLine($pdf, $x, $y, $x + $w, $y);
        $this->dashedLine($pdf, $x, $y + $h, $x + $w, $y + $h);
        $this->dashedLine($pdf, $x, $y, $x, $y + $h);
        $this->dashedLine($pdf, $x + $w, $y, $x + $w, $y + $h);
    }

    // Helper opcional para normalizar texto a ZPL (ISO-8859-1)
    private function zplTxt(string $txt): string
    {
        return mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Construir string ZPL para impresora Zebra
     * emulando la estructura de la etiqueta PDF.
     */
    public function construirZPL(array $d, int $nroBulto = 1, int $totalBultos = 1): string
    {
        $origen        = $this->zplTxt($d['OrigenNombre']      ?? '');
        $o_dir         = $this->zplTxt($d['OrigenDireccion']   ?? '');
        $o_loc         = $this->zplTxt($d['OrigenLocalidad']   ?? '');
        $dest          = $this->zplTxt($d['ClienteDestino']    ?? '');
        $d_dir         = $this->zplTxt($d['DomicilioDestino']  ?? '');
        $d_loc         = $this->zplTxt($d['LocalidadDestino']  ?? '');
        $cp            = $this->zplTxt($d['cpdestino']         ?? '');
        $tel           = $this->zplTxt($d['Telefono']          ?? '');
        $cant          = (int)($d['Cantidad']                  ?? 1);
        $valdec        = $d['ValorDeclarado']                  ?? 0;
        $cobranza      = $d['Cobranza']                        ?? 0;
        $codigo        = $this->zplTxt($d['CodigoSeguimiento'] ?? '');
        $idProveedor   = $this->zplTxt($d['idProveedor']       ?? '');
        $id            = $this->zplTxt($d['id']                ?? '');
        $observaciones = $this->zplTxt($d['Observaciones']     ?? '');
        $recorrido     = ''; // si lo tenés en la BD podés mapearlo acá

        $textoBulto = '';
        if ($totalBultos >= 1) {
            $textoBulto = $nroBulto . '/' . $totalBultos;
        }

        $zpl  = "^XA\n";
        $zpl .= "^CI28\n";
        $zpl .= "^LH0,10\n";

        // BULTO X/Y
        if ($textoBulto !== '') {
            $zpl .= "^FO30,120^A0N,70,70^FB160,1,0,C^FD" . $textoBulto . "^FS\n";
        }

        // Logo (asumiendo que ya cargaste LOGOCADD.GRF en la impresora)
        $zpl .= "^FO15,5^ILE:LOGOCADD.GRF^FS\n";

        // Destino grande arriba
        $zpl .= "^FO250,25^A0N,50,50^FB570,1,-1^FH^FD" . $d_loc . "^FS\n";
        $zpl .= "^FO700,25^A0N,50,50^FB570,1,-1^FH^FDCórdoba^FS\n";

        // Datos destino
        $zpl .= "^FO190,70^A0N,20,20^FB570,1,-1^FDDestino^FS\n";
        $zpl .= "^FO190,95^A0N,24,24^FB570,1,-1^FDNombre: " . $dest . "^FS\n";
        $zpl .= "^FO190,120^A0N,20,20^FB570,1,-1^FDDireccion: " . $d_dir . "^FS\n";
        $zpl .= "^FO190,145^A0N,24,24^FB570,1,-1^FDRecorrido: " . $recorrido . "^FS\n";

        // SKU / id
        $zpl .= "^FO200,190^A0N,30,30^FDSKU: ^FS\n";
        $zpl .= "^FO265,192^A0N,25,25^FB510,1,-1^FH^FD" . $id . "^FS\n";

        // línea horizontal
        $zpl .= "^FO0,225^GB850,2,1^FS\n";

        // N° de Venta / Tracking
        $zpl .= "^FO40,245^A0N,28,28^FDN.Venta: ^FS\n";
        $zpl .= "^FO192,245^A0N,30,30^FD" . $id . "^FS\n";

        $zpl .= "^FO299,245^A0N,28,28^FDTracking: ^FS\n";
        $zpl .= "^FO410,246^A0N,26,26^FD" . $codigo . "^FS\n";

        $zpl .= "^FO0,300^GB850,1,1^FS\n";

        // Origen
        $zpl .= "^LH0,320\n";
        $zpl .= "^FO120,0^A0N,20,20^FH^FDOrigen^FS\n";
        $zpl .= "^FO120,22^A0N,24,24^FH^FDNombre: #" . $origen . " " . $idProveedor . "^FS\n";
        $zpl .= "^FO120,65^A0N,24,24^FB660,2,0,L^FH^FDDireccion Origen: " . $o_dir . "^FS\n";

        // Línea
        $zpl .= "^FO0,170^GB850,0,2^FS\n";
        $zpl .= "^FO0,185^A0N,48,48^FB800,1,0,C^FDCaddy Yo lo llevo!^FS\n";
        $zpl .= "^FO0,235^GB850,0,2^FS\n";

        // Texto instrucciones + QR seguimiento
        $zpl .= "^FO10,250^A0N,16,18^FDPodes seguir tu envío en nuestra web con el Código " . $codigo . " o escaneando con tu teléfono el QR.^FS\n";
        $zpl .= "^FO260,270^BY4,4,0^BQN,2,4^FDLA,{\"id\":\"https://www.caddy.com.ar/seguimiento.html?codigo=" . $codigo . "\",\"sender_id\":3987654312,\"hash_code\":\"fyePAxtasdOM/kZgZZDSAH+h1JBckgknsg2R3754ERKI=\",\"security_digit\":\"0\"}^FS\n";

        // Código Wepoint (en código de barras 2D también)
        $zpl .= "^FO10,290^A0N,20,20^FDCódigo Wepoint:^FS\n";
        $zpl .= "^FO20,310^BY3,2,0^BQN,2,4^FDLA," . $codigo . "^FS\n";

        // Footer
        $zpl .= "^FO10,440^A0N,20,20^FDwww.caddy.com.ar^FS\n";
        $zpl .= "^FO500,440^A0N,20,20^FDUsuario: API CADDY^FS\n";

        $zpl .= "^XZ\n";

        return $zpl;
    }

    /**
     * Generar PDF de la etiqueta
     */
    public function generarPDF(array $d)
    {
        $codigo      = $d['CodigoSeguimiento'] ?? 'SIN-CODIGO';
        $totalBultos = (int)($d['Cantidad'] ?? 1);

        if (ob_get_length()) {
            ob_end_clean();
        }

        $pdf = new FPDF('P', 'mm', [100, 150]);
        // como es una sola etiqueta, es BULTO 1/total
        $this->dibujarEtiquetaPDF($pdf, $d, 1, $totalBultos);

        header('Content-Type: application/pdf');
        $filename = $codigo . '.pdf';
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header("X-Robots-Tag: noindex");
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $pdf->Output('I', $filename);
        exit;
    }


    public function procesar(string $codigo, string $idOrigen, string $formato)
    {

        // 👉 Ahora SÍ filtramos por dueño del paquete
        $datos = $this->obtenerDatosEnvio($codigo, $idOrigen);

        if (!$datos) {
            return [
                'error'  => true,
                'tipo'   => 'no_encontrado',
                'detail' => 'No se encontro ningun envio con ese CodigoSeguimiento para este cliente'
            ];
        }

        $formato    = strtolower($formato);
        $cantidad   = (int)($datos['Cantidad'] ?? 1);
        $codigoBase = $datos['CodigoSeguimiento'] ?? $codigo;

        /* ====== ZPL ====== */
        if ($formato === 'zpl') {

            $zplTotal = '';

            if ($cantidad > 1) {
                for ($i = 1; $i <= $cantidad; $i++) {
                    $datosEtiqueta = $datos;
                    $datosEtiqueta['CodigoSeguimiento'] = $codigoBase . '_' . $i;
                    $zplTotal .= $this->construirZPL($datosEtiqueta, $i, $cantidad);
                }
            } else {
                $zplTotal = $this->construirZPL($datos, 1, 1);
            }

            header('Content-Type: text/plain; charset=UTF-8');
            echo $zplTotal;
            exit;
        }

        /* ====== PDF ====== */
        if ($formato === 'pdf') {

            if ($cantidad > 1) {
                if (ob_get_length()) {
                    ob_end_clean();
                }

                $pdf = new FPDF('P', 'mm', [100, 150]);

                for ($i = 1; $i <= $cantidad; $i++) {
                    $datosEtiqueta = $datos;
                    $datosEtiqueta['CodigoSeguimiento'] = $codigoBase . '_' . $i;
                    $this->dibujarEtiquetaPDF($pdf, $datosEtiqueta, $i, $cantidad);
                }

                header('Content-Type: application/pdf');
                $filename = $codigoBase . '.pdf';
                header('Content-Disposition: inline; filename="' . $filename . '"');
                header("X-Robots-Tag: noindex");

                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');

                $pdf->Output('I', $filename);
                exit;
            }

            // cantidad = 1 → uso el flujo normal
            $this->generarPDF($datos);
        }
        // Si llegó acá, formato no soportado
        return [
            'error'  => true,
            'tipo'   => 'formato',
            'detail' => 'Formato de etiqueta no soportado'
        ];
    }
}

// ----------------------
//  CONTROLADOR HTTP
// ----------------------

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    header('Content-Type: application/json');
    $datos = $_respuestas->error_405();
    echo json_encode($datos);
    http_response_code(405);
    exit;
}

// 1) Obtener token (Bearer o ?token=)
$token = Token::obtenerToken();

if (!$token) {
    header('Content-Type: application/json');
    $resp = $_respuestas->error_400("Debe enviar token (Bearer o query)");
    echo json_encode($resp);
    http_response_code(400);
    exit;
}
// 2) Instanciar servicio (tiene la conexión a BD)
$svc = new EtiquetaService();

// 3) Validar token usando la BD del servicio
$tokenData = Token::validar($token, $svc);

if (!$tokenData) {
    header('Content-Type: application/json');
    $resp = $_respuestas->error_401("Token inválido o vencido");
    echo json_encode($resp);
    http_response_code(401);
    exit;
}
// 4) Tomar NdeCliente como idOrigen
$idOrigen = $tokenData['NdeCliente'] ?? null;

$codigo  = $_GET['codigo']  ?? null;
$formato = $_GET['formato'] ?? 'pdf';

if (!$codigo) {
    header('Content-Type: application/json');
    $resp = $_respuestas->error_400('Falta parámetro: codigo');
    echo json_encode($resp);
    http_response_code(400);
    exit;
}
// 5) Procesar etiqueta (PDF o ZPL)
$resultado = $svc->procesar($codigo, (string)$idOrigen, $formato);

// Si llegó acá, es porque procesar NO hizo exit, entonces hubo error lógico
header('Content-Type: application/json');

if (!empty($resultado['error'])) {
    if ($resultado['tipo'] === 'no_encontrado') {
        $resp = $_respuestas->error_204($resultado['detail']);
        http_response_code(204);
    } elseif ($resultado['tipo'] === 'formato') {
        $resp = $_respuestas->error_400($resultado['detail']);
        http_response_code(400);
    } else {
        $resp = $_respuestas->error_500($resultado['detail'] ?? 'Error generando etiqueta');
        http_response_code(500);
    }
    echo json_encode($resp);
    exit;
}

// Por las dudas
$resp = $_respuestas->error_500('Error inesperado en etiqueta.php');
http_response_code(500);
echo json_encode($resp);
