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

    /**
     * Datos de la venta / envío a partir del Código de Seguimiento
     */
    public function obtenerDatosEnvio(string $codigoSeguimiento)
    {
        $query = "SELECT 
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
                    idProveedor
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
        $origen    = $d['OrigenNombre']      ?? '';
        $o_dir     = $d['OrigenDireccion']   ?? '';
        $o_loc     = $d['OrigenLocalidad']   ?? '';
        $dest      = $d['ClienteDestino']    ?? '';
        $d_dir     = $d['DomicilioDestino']  ?? '';
        $d_loc     = $d['LocalidadDestino']  ?? '';
        $cp        = $d['cpdestino']         ?? '';
        $provDest  = $d['ProvinciaDestino']  ?? '';   // opcional
        $recorrido = $d['Recorrido']         ?? '';   // opcional
        $tel       = $d['Telefono']          ?? '';
        $cant      = $d['Cantidad']          ?? 1;
        $valdec    = $d['ValorDeclarado']    ?? 0;
        $cobranza  = $d['Cobranza']          ?? 0;
        $codigo    = $d['CodigoSeguimiento'] ?? 'SIN-CODIGO';
        $usuario   = $d['Usuario']           ?? '';
        $fechaImp  = date('d/m/Y H:i');
        $idProveedor = $d['idProveedor'] ?? '';

        // Etiqueta 100x150 mm
        $pdf = new FPDF('P', 'mm', array(100, 150));
        $margin = 5;
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->AddPage();

        $pageWidth  = $pdf->GetPageWidth();
        $pageHeight = $pdf->GetPageHeight();
        $usableW    = $pageWidth - 2 * $margin;

        /* ========= BLOQUE SUPERIOR: LOGO + ORIGEN ========= */

        $logoPath   = __DIR__ . '/assets/LogoCaddy.png';
        $logoWidth  = 28;              // un poco más chico
        $logoHeight = 22;              // alto estimado

        $yTop = $pdf->GetY();
        $xLogo = $margin;
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, $xLogo, $yTop, $logoWidth, 0);
        }

        // ORIGEN a la derecha del logo
        $xOrigen = $xLogo + $logoWidth + 3;
        $wOrigen = $usableW - ($logoWidth + 3);

        $pdf->SetXY($xOrigen, $yTop);
        $pdf->SetFont('Arial', 'B', 10);

        //ORIGEN
        $pdf->SetFont('Arial', 'B', 10); // NEGRITA
        $pdf->Cell($wOrigen, 5, $this->pdfTxt($origen . ' '), 0, 0, 'L');

        $pdf->SetFont('Arial', '', 9);   // NORMAL
        $pdf->Cell(0, 5, '#' . $idProveedor, 0, 1, 'L');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetX($xOrigen);
        // $pdf->Cell($wOrigen, 4, $this->pdfTxt($origen), 0, 1, 'L');
        $pdf->SetX($xOrigen);
        $pdf->Cell($wOrigen, 4, $this->pdfTxt($o_dir), 0, 1, 'L');
        $pdf->SetX($xOrigen);
        $pdf->Cell($wOrigen, 4, $this->pdfTxt($o_loc), 0, 1, 'L');

        // bajar el cursor a lo máximo entre logo y texto
        $yAfterTop = max($yTop + $logoHeight, $pdf->GetY());
        $pdf->SetY($yAfterTop + 3);

        // línea horizontal
        $y = $pdf->GetY();
        $pdf->Line($margin, $y, $pageWidth - $margin, $y);
        $pdf->Ln(3);

        /* ========= LÍNEA "ENVÍO FLEX" ========= */
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 5, $codigo, 0, 1, 'C');
        $pdf->Ln(1);

        $y = $pdf->GetY();
        $pdf->Line($margin, $y, $pageWidth - $margin, $y);
        $pdf->Ln(3);

        /* ========= BLOQUE QR + DATOS (CÓDIGO / CP / CIUDAD / PROV / RECORRIDO) ========= */

        $qrSize = 30; // mm
        $qrX    = $margin;
        $qrY    = $pdf->GetY(); // arranca donde estamos ahora

        // Generar QR si hay código
        if (!empty($codigo)) {
            $tmpQR = sys_get_temp_dir() . '/qr_' . $codigo . '.png';
            QRcode::png($codigo, $tmpQR, QR_ECLEVEL_L, 4);

            if (file_exists($tmpQR)) {
                $pdf->Image($tmpQR, $qrX, $qrY, $qrSize, $qrSize);
                @unlink($tmpQR);
            } else {
                error_log("⚠️ QR no generado: " . $tmpQR);
            }
        }

        // Datos a la derecha del QR
        $xDatosQR = $qrX + $qrSize + 4;
        $wDatosQR = $usableW - ($qrSize + 4);

        $pdf->SetXY($xDatosQR, $qrY);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($wDatosQR, 5, 'Codigo: ' . $codigo, 0, 1, 'L');
        $pdf->SetX($xDatosQR);
        $pdf->Cell($wDatosQR, 5, 'CP: ' . $cp, 0, 1, 'L');
        $pdf->SetX($xDatosQR);
        $pdf->Cell($wDatosQR, 5, 'Ciudad: ' . $this->pdfTxt($d_loc), 0, 1, 'L');

        if (!empty($provDest)) {
            $pdf->SetX($xDatosQR);
            $pdf->Cell($wDatosQR, 5, 'Provincia: ' . $this->pdfTxt($provDest), 0, 1, 'L');
        }
        if (!empty($recorrido)) {
            $pdf->SetX($xDatosQR);
            $pdf->Cell($wDatosQR, 5, 'Recorrido: ' . $recorrido, 0, 1, 'L');
        }

        // bajar a donde termina el QR
        $yAfterQR = max($qrY + $qrSize, $pdf->GetY());
        $pdf->SetY($yAfterQR + 3);

        // línea horizontal
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
        $pdf->Ln(2);

        // Datos adicionales (cantidad / valor declarado / cobranza)
        // $pdf->Cell(0, 4, 'Cantidad: ' . $cant, 0, 1, 'L');
        // $pdf->Cell(0, 4, 'Valor declarado: $ ' . number_format($valdec, 2, ',', '.'), 0, 1, 'L');
        // $pdf->Cell(0, 4, 'Cobranza: $ ' . number_format($cobranza, 2, ',', '.'), 0, 1, 'L');
        // $pdf->Ln(2);

        /* ========= PIE: USUARIO / FECHA ========= */
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(0, 4, 'Usuario: ' . $usuario . '  |  Fecha: ' . $fechaImp, 0, 1, 'R');

        // asegurar que no haya 2da página
        // (con estos tamaños no debería ocurrir; si alguna vez se dispara, podés bajar aún más las fuentes)

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="etiqueta-' . $codigo . '.pdf"');
        $pdf->Output('I', 'etiqueta-' . $codigo . '.pdf');
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

        // Default = PDF
        $this->generarPDF($datos);
        // generarPDF ya hace exit()
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
