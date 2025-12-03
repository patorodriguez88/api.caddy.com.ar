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
        $pdf->Cell(0, 5, 'REFERENCIAS: ' . $this->pdfTxt($observaciones), 0, 1, 'L');
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
        $cobranza = $d['Cobranza']         ?? 0;
        $codigo  = $d['CodigoSeguimiento'] ?? '';
        $idProveedor = $d['idProveedor']   ?? '';
        $id = $d['id']                     ?? '';
        $observaciones = $d['Observaciones'] ?? '';
        $recorrido = '';

        // Texto BULTO X/Y (si hay más de 1)
        $textoBulto = '';
        if ($totalBultos > 1) {
            $textoBulto = $nroBulto . "/" . $totalBultos;
        }

        // $zpl = "^XA
        // ^PW600
        // ^CF0,40
        // ^FO40,40^FDCADDY LOGISTICA^FS

        // ^CF0,30
        // ^FO40,100^FDORIGEN:^FS
        // ^FO40,140^FD$origen^FS
        // ^FO40,180^FD$o_dir^FS
        // ^FO40,220^FD$o_loc^FS

        // ^FO40,280^FDDESTINO:^FS
        // ^FO40,320^FD$dest^FS
        // ^FO40,360^FD$d_dir^FS
        // ^FO40,400^FD$d_loc ($cp)^FS
        // ^FO40,440^FDTel: $tel^FS

        // ^FO40,500^FDCant: $cant  VD: $valdec  Cobranza: $cobranza^FS

        // ^BY3,2,120
        // ^FO80,560^BCN,120,Y,N,N
        // ^FD$codigo^FS

        // ^CF0,30
        // ^FO80,700^FDCOD: $codigo^FS";

        // if (!empty($textoBulto)) {
        //     $zpl .= "
        // ^CF0,35
        // ^FO80,740^FD$textoBulto^FS";
        // }

        // $zpl .= "
        // ^XZ";

        $zpl = '^XA' +
            '^CI28' +
            '^LH0,10' +
            '^FX  Is Product  ^FS' +
            '^FX  Quantity  ^FS' +
            '^FO30,120^A0N,70,70^FB160,1,0,C^FD' + $textoBulto + '^FS' +
            '^FX Logo Caddy^FS^' +
            '^FO15,5^ILE:LOGOCADD.GRF^FS' +
            '^FO35,200^A0N,25,25^FB150,1,0,C^FH^FDCantidad^FS' +
            '^FX  Product title  ^FS' +
            '^FO250,25^A0N,50,50^FB570,1,-1^FH^FD' + $d_loc + '^FS' +
            '^FO700,25^A0N,50,50^FB570,1,-1^FH^FD' + 'Córdoba' + '^FS' +
            '^FX  Variations  ^FS' +
            '^FO190,70^A0N,20,20^FB570,1,-1^FDDestino^FS' +
            '^FO190,95^A0N,24,24^FB570,1,-1^FDNombre: ' + $dest + '^FS' +
            '^FO190,120^A0N,20,20^FB570,1,-1^FDDireccion: ' + $d_dir + '^FS' +
            '^FO190,145^A0N,24,24^FB570,1,-1^FDRecorrido: ' + $recorrido + '^FS' +
            '^FX SKU ^FS' +
            '^FO200,190^A0N,30,30^FDSKU: ^FS' +
            '^FO265,192^A0N,25,25^FB510,1,-1^FH^FD' + $id + '^FS' +
            '^FO0,225^GB850,2,1^FS' +
            '^FX Order id ^FS' +
            '^FO40,245^A0N,28,28^FDN.Venta: ^FS' +
            '^FO41,245^A0N,28,28^FDN.Venta: ^FS' +
            '^FO130,249^A0N,25,25^FD^FS' +
            '^FO192,245^A0N,30,30^FD' + $id + '^FS' +
            '^FO193,245^A0N,30,30^FD' + $id + '^FS' +
            '^FX Tracking number ^FS' +
            '^FO299,245^A0N,28,28^FDTracking: ^FS' +
            '^FO300,245^A0N,28,28^FDTracking: ^FS' +
            '^FO410,246^A0N,26,26^FD' + $codigo + '^FS' +
            '^FO0,300^GB850,1,1^FS' +
            '^LH0,320^FX  HEADER  ^FS' +
            '^FO120,0^A0N,20,20^FH^FDOrigen^FS' +
            '^FO120,22^A0N,24,24^FH^FDNombre: #' + $origen + ' ' + $idProveedor + '^FS' +
            '^FO120,65^A0N,24,24^FB660,2,0,L^FH^FDDireccion Destino: ' + $o_dir + '^FS' +
            '^FO120,100^A0N,24,24^FB660,2,0,L^FH^FD  CP 1437^FS' +
            '^FO120,135^A0N,24,24^FDN.Venta: ^FS' +
            '^FO255,132^A0N,27,27^FD' + $id + '^FS' +
            '^FO500,135^A0N,24,24^FDSKU TC: ^FS' +
            '^FO652,132^A0N,27,27^FD' + $id + '^FS^FX 1 Horizontal Line ^FS^FO0,170^GB850,0,2^FS' +
            '^FO0,185^A0N,48,48^FB800,1,0,C^FDCaddy Yo lo llevo!^FS' +
            '^FX 2 Horizontal Line ^FS' +
            '^FO0,235^GB850,0,2^FS' +
            '^FX QR Code ^FS' +
            '^FO10,250^A0N,16,18^FDPodes seguir tu envío en nuestra web con el Código ' + $codigo + ' o escaneando con tu teléfono el QR.^FS' +
            '^FO260,270^BY4,4,0^BQN,2,4^FDLA,{\"id\":\"https://www.caddy.com.ar/seguimiento.html?codigo=' + $codigo + '\",\"sender_id\":3987654312,\"hash_code\":\"fyePAxtasdOM/kZgZZDSAH+h1JBckgknsg2R3754ERKI=\",\"security_digit\":\"0\"}^FS' +
            '^FO10,290^A0N,20,20^FDCódigo Wepoint:^FS' +
            '^FO20,310^BY3,2,0^BQN,2,4^FDLA,' + $codigo + '^FS' +
            '^FO10,440^A0N,20,20^FDwww.caddy.com.ar^FS' +
            '^FO500,440^A0N,20,20^FDUsuario: API CADDY ^FS' +
            '^XZ';
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
