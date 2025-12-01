<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
// Opcional: iniciar buffer de salida para poder limpiar antes del PDF
if (ob_get_length() === false) {
    ob_start();
}
require_once 'conexion/conexion.php';
require_once 'clases/respuestas.class.php';
// Para PDF: asegurate de tener FPDF en esta ruta (ajustá si lo tenés en otro lado)
require_once 'libs/fpdf182/fpdf.php';
require_once 'libs/phpqrcode/qrlib.php';   // 👈 NUEVO

$_respuestas = new respuestas;

/**
 * Servicio de etiquetas
 */
class EtiquetaService extends conexion
{
    private $token;
    private function pdfTxt(string $txt): string
    {
        // Convertir de UTF-8 a ISO-8859-1 para FPDF
        return mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
    }

    private function validarToken(string $token)
    {
        $this->token = $token;
        $query = "SELECT TokenId,UsuarioId,Estado 
                  FROM usuarios_token 
                  WHERE Token = '" . $this->token . "' 
                    AND Estado = 'Activo'";

        $resp = $this->obtenerDatos($query);
        return $resp ? $resp[0] : null;
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
    public function obtenerDatosEnvio(string $codigoSeguimiento)
    {
        $query = "SELECT 
                    id,Fecha,
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
                  LIMIT 1";

        $datos = $this->obtenerDatos($query);
        return $datos ? $datos[0] : null;
    }

    /**
     * Construir string ZPL para impresora Zebra
     */
    public function construirZPL(array $d): string
    {
        // Valores de fallback por si faltara algo
        $origen  = $d['OrigenNombre']      ?? '';
        $o_dir   = $d['OrigenDireccion']   ?? '';
        $o_loc   = $d['OrigenLocalidad']   ?? '';
        $dest    = $d['ClienteDestino']    ?? '';
        $d_dir   = $d['DomicilioDestino']  ?? '';
        $d_loc   = $d['LocalidadDestino']  ?? '';
        $cp      = $d['cpdestino']         ?? '';
        $tel     = $d['Telefono']          ?? '';
        $cant    = $d['Cantidad']          ?? 1;
        $valdec  = $d['ValorDeclarado']    ?? 0;
        $cobranza = $d['Cobranza']          ?? 0;
        $codigo  = $d['CodigoSeguimiento'] ?? '';
        $idProveedor = $d['idProveedor'] ?? '';
        $id = $d['id'] ?? '';
        $observaciones = $d['Observaciones'] ?? '';

        $zpl = "^XA
        ^PW600
        ^CF0,40
        ^FO40,40^FDCADDY LOGISTICA^FS

        ^CF0,30
        ^FO40,100^FDORIGEN:^FS
        ^FO40,140^FD$origen^FS
        ^FO40,180^FD$o_dir^FS
        ^FO40,220^FD$o_loc^FS

        ^FO40,280^FDDESTINO:^FS
        ^FO40,320^FD$dest^FS
        ^FO40,360^FD$d_dir^FS
        ^FO40,400^FD$d_loc ($cp)^FS
        ^FO40,440^FDTel: $tel^FS

        ^FO40,500^FDCant: $cant  VD: $valdec  Cobranza: $cobranza^FS

        ^BY3,2,120
        ^FO80,560^BCN,120,Y,N,N
        ^FD$codigo^FS

        ^CF0,30
        ^FO80,700^FDCOD: $codigo^FS

        ^XZ";

        return $zpl;
    }
    /**
     * Generar PDF de la etiqueta
     */
    public function generarPDF(array $d)
    {
        // --- Tamaño real 10 x 15 cm ---
        $pdf = new FPDF('P', 'mm', [100, 150]);
        $pdf->SetAutoPageBreak(false);
        $margin = 5;
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->AddPage();
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
        $codigo      = $d['CodigoSeguimiento'] ?? 'SIN-CODIGO';
        $usuario     = $d['Usuario']           ?? '';
        $fechaImp    = date('d/m/Y H:i');
        $idProveedor = $d['idProveedor']       ?? '';
        $id          = $d['id']                ?? '';
        $observaciones = $d['Observaciones']   ?? '';

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
        $pdf->SetY($yAfterTop + 3);

        // línea
        $y = $pdf->GetY();
        $pdf->Line($margin, $y, $pageWidth - $margin, $y);
        $pdf->Ln(1);

        /* ========= LÍNEA CÓDIGO ========= */
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 5, $codigo, 0, 1, 'C');
        $pdf->Ln(1);

        $y = $pdf->GetY();
        $pdf->Line($margin, $y, $pageWidth - $margin, $y);
        $pdf->Ln(3);

        /* ========= BLOQUE QR + DATOS ========= */

        $qrSize = 30;
        $qrX    = $margin;
        $qrY    = $pdf->GetY();

        if (!empty($codigo)) {
            $tmpQR = sys_get_temp_dir() . '/qr_' . $codigo . '.png';
            QRcode::png($codigo, $tmpQR, QR_ECLEVEL_L, 4);

            if (file_exists($tmpQR)) {
                $pdf->Image($tmpQR, $qrX, $qrY, $qrSize, $qrSize);
                @unlink($tmpQR);
            }
        }

        $xDatosQR = $qrX + $qrSize + 4;
        $wDatosQR = $usableW - ($qrSize + 4);

        $pdf->SetXY($xDatosQR, $qrY);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($wDatosQR, 5, $codigo, 0, 1, 'L');

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
            $pdf->Cell(0, 4, 'Tel: ' . $tel, 0, 1, 'L');
        }
        $pdf->Cell(0, 5, 'REFERENCIAS: ' . $this->pdfTxt($observaciones), 0, 1, 'L');
        $pdf->Ln(2);

        /* ========= PIE ========= */

        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(0, 4, 'Usuario: ' . $usuario . '  |  Fecha: ' . $fechaImp, 0, 1, 'R');

        // limpiar buffer y enviar
        if (ob_get_length()) {
            ob_end_clean();
        }

        // Límites
        $x = 2;
        $y = 2;
        $w = 96;
        $h = 146;

        // Marco punteado
        $this->dashedLine($pdf, $x, $y, $x + $w, $y);           // arriba
        $this->dashedLine($pdf, $x, $y + $h, $x + $w, $y + $h); // abajo
        $this->dashedLine($pdf, $x, $y, $x, $y + $h);           // izquierda
        $this->dashedLine($pdf, $x + $w, $y, $x + $w, $y + $h); // derecha

        header('Content-Type: application/pdf');
        // nombre SOLO el código
        $filename = $codigo . '.pdf';
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header("X-Robots-Tag: noindex");
        $pdf->Output('I', $filename);
        exit;
    }



    public function procesar(string $codigo, string $token, string $formato)
    {
        $tokenData = $this->validarToken($token);
        if (!$tokenData) {
            return [
                'error'  => true,
                'tipo'   => 'token',
                'detail' => 'Token invalido o caducado'
            ];
        }

        $datos = $this->obtenerDatosEnvio($codigo);
        if (!$datos) {
            return [
                'error'  => true,
                'tipo'   => 'no_encontrado',
                'detail' => 'No se encontro ningun envio con ese CodigoSeguimiento'
            ];
        }

        $formato = strtolower($formato);

        if ($formato === 'zpl') {
            $zpl = $this->construirZPL($datos);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $zpl;
            exit;
        }

        // --- SI HAY MÁS DE 1 CANTIDAD, GENERAR MULTI-ETIQUETAS ---
        // Default = PDF

        $cantidad = $datos['Cantidad'] ?? 1;

        if ($cantidad > 1 && strtolower($formato) === 'pdf') {

            for ($i = 1; $i <= $cantidad; $i++) {

                // Clonamos los datos de la etiqueta
                $datosEtiqueta = $datos;

                // Agregamos sufijo al código
                $datosEtiqueta['CodigoSeguimiento'] = $datos['CodigoSeguimiento'] . "_" . $i;

                // Generamos cada PDF individual
                $this->generarPDF($datosEtiqueta);
            }

            exit;
        }
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

$codigo  = $_GET['codigo']  ?? null;
$formato = $_GET['formato'] ?? 'pdf';
$token   = $_GET['token']   ?? null;

if (!$codigo || !$token) {
    header('Content-Type: application/json');
    $datos = $_respuestas->error_400('Faltan parametros: codigo y/o token');
    echo json_encode($datos);
    http_response_code($datos['result']['error_id'] ?? 400);
    exit;
}

$svc = new EtiquetaService();
$resultado = $svc->procesar($codigo, $token, $formato);

// Si llegó acá, es porque hubo error (procesar hace exit cuando todo sale bien)
header('Content-Type: application/json');

if (!empty($resultado['error'])) {
    if ($resultado['tipo'] === 'token') {
        $resp = $_respuestas->error_401($resultado['detail']);
        http_response_code(401);
    } elseif ($resultado['tipo'] === 'no_encontrado') {
        $resp = $_respuestas->error_204($resultado['detail']);
        http_response_code(204);
    } else {
        $resp = $_respuestas->error_500($resultado['detail'] ?? 'Error generando etiqueta');
        http_response_code(500);
    }
    echo json_encode($resp);
    exit;
}

// Por las dudas, si no hay error pero tampoco se generó nada:
$resp = $_respuestas->error_500('Error inesperado en etiqueta.php');
http_response_code(500);
echo json_encode($resp);
