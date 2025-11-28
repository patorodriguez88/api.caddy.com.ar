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
                    CodigoSeguimiento
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
     * Generar PDF de etiqueta usando FPDF
     */
    public function generarPDF(array $d)
    {
        $origen   = $d['OrigenNombre']      ?? '';
        $o_dir    = $d['OrigenDireccion']   ?? '';
        $o_loc    = $d['OrigenLocalidad']   ?? '';
        $dest     = $d['ClienteDestino']    ?? '';
        $d_dir    = $d['DomicilioDestino']  ?? '';
        $d_loc    = $d['LocalidadDestino']  ?? '';
        $cp       = $d['cpdestino']         ?? '';
        $tel      = $d['Telefono']          ?? '';
        $cant     = $d['Cantidad']          ?? 1;
        $valdec   = $d['ValorDeclarado']    ?? 0;
        $cobranza = $d['Cobranza']          ?? 0;
        $codigo   = $d['CodigoSeguimiento'] ?? 'SIN-CODIGO';

        // Datos de impresión (podés cambiarlos si querés pasarlos por $d)
        $usuarioImp = $d['Usuario'] ?? 'API';
        $fechaImp   = date('d/m/Y');
        $horaImp    = date('H:i');

        // Etiqueta 100x150 mm
        $pdf = new FPDF('P', 'mm', array(100, 150));
        $pdf->SetAutoPageBreak(false); // 👈 importantísimo
        $pdf->AddPage();

        $pageWidth  = $pdf->GetPageWidth();
        $pageHeight = $pdf->GetPageHeight();

        // ---- LOGO CADDY CENTRADO (un poco más chico) ----
        $logoPath = __DIR__ . '/assets/LogoCaddy.png';
        if (file_exists($logoPath)) {
            $logoWidth  = 40;  // antes 50
            $xLogo      = ($pageWidth - $logoWidth) / 2;
            $yLogo      = 5;   // más arriba

            $pdf->Image($logoPath, $xLogo, $yLogo, $logoWidth, 0);
        } else {
            error_log("⚠️ LOGO NO ENCONTRADO: " . $logoPath);
            $yLogo = 5; // fallback
        }

        // Cursor justo debajo del logo + pequeño margen
        $pdf->SetY($yLogo + 25);


        // Header (un puntito más chico)
        $pdf->SetFont('Arial', 'B', 14); // antes 16
        $pdf->Cell(0, 7, 'Caddy Logistica', 0, 1, 'C');
        $pdf->Ln(1);

        // Código de seguimiento
        $pdf->SetFont('Arial', '', 9);   // antes 10
        $pdf->Cell(0, 5, 'Codigo de seguimiento: ' . $codigo, 0, 1, 'C');
        $pdf->Ln(3);

        // Origen
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 5, 'ORIGEN', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 4, $this->pdfTxt($origen), 0, 1, 'L');
        $pdf->Cell(0, 4, $this->pdfTxt($o_dir), 0, 1, 'L');
        $pdf->Cell(0, 4, $this->pdfTxt($o_loc), 0, 1, 'L');
        $pdf->Ln(3);

        // Destino
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 5, 'DESTINO', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 4, $this->pdfTxt($dest), 0, 1, 'L');
        $pdf->Cell(0, 4, $this->pdfTxt($d_dir), 0, 1, 'L');
        $pdf->Cell(0, 4, $this->pdfTxt($d_loc . ' (' . $cp . ')'), 0, 1, 'L');
        if (!empty($tel)) {
            $pdf->Cell(0, 4, $this->pdfTxt('Tel: ' . $tel), 0, 1, 'L');
        }
        $pdf->Ln(3);

        // Datos adicionales
        $pdf->Cell(0, 4, 'Cantidad: ' . $cant, 0, 1, 'L');
        $pdf->Cell(0, 4, 'Valor declarado: $ ' . number_format($valdec, 2, ',', '.'), 0, 1, 'L');
        $pdf->Cell(0, 4, 'Cobranza: $ ' . number_format($cobranza, 2, ',', '.'), 0, 1, 'L');
        $pdf->Ln(4);

        // COD centrado
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 5, 'COD: ' . $codigo, 0, 1, 'C');

        // --- QR ABAJO AL MEDIO, MÁS CHICO ---
        if (!empty($codigo)) {
            $qrSize = 30; // antes 40
            $tmpQR  = sys_get_temp_dir() . '/qr_' . $codigo . '.png';

            QRcode::png($codigo, $tmpQR, QR_ECLEVEL_L, 4);

            if (file_exists($tmpQR)) {
                $xQR = ($pageWidth - $qrSize) / 2;
                $yQR = $pageHeight - ($qrSize + 5); // 5 mm del borde inferior

                $pdf->Image($tmpQR, $xQR, $yQR, $qrSize, $qrSize);
                @unlink($tmpQR);
            } else {
                error_log("⚠️ QR no generado: " . $tmpQR);
            }
        }
        // Línea: Usuario / Fecha / Hora
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(
            0,
            4,
            "Usuario: {$usuarioImp}   Fecha: {$fechaImp}   Hora: {$horaImp}",
            0,
            1,
            'C'
        );
        $pdf->Ln(2);

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="etiqueta-' . $codigo . '.pdf"');
        $pdf->Output('I', 'etiqueta-' . $codigo . '.pdf');
        exit;
    }

    /**
     * Método principal: valida token, busca datos y devuelve ZPL o PDF
     */
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
