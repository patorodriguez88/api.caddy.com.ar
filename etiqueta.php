<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conexion/conexion.php';
require_once 'clases/respuestas.class.php';
// Para PDF: asegurate de tener FPDF en esta ruta (ajustá si lo tenés en otro lado)
require_once 'libs/fpdf182/fpdf.php';

$_respuestas = new respuestas;

/**
 * Servicio de etiquetas
 */
class EtiquetaService extends conexion
{
    private $token;

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

        // Etiqueta 100x150 mm
        $pdf = new FPDF('P', 'mm', array(100, 150));
        $pdf->AddPage();

        // Header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 8, 'CADDY LOGISTICA', 0, 1, 'C');
        $pdf->Ln(2);

        // Código de seguimiento
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, 'Codigo de seguimiento: ' . $codigo, 0, 1, 'C');
        $pdf->Ln(3);

        // Origen
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, 'ORIGEN', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, $origen, 0, 1, 'L');
        $pdf->Cell(0, 5, $o_dir, 0, 1, 'L');
        $pdf->Cell(0, 5, $o_loc, 0, 1, 'L');
        $pdf->Ln(3);

        // Destino
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, 'DESTINO', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, $dest, 0, 1, 'L');
        $pdf->Cell(0, 5, $d_dir, 0, 1, 'L');
        $pdf->Cell(0, 5, $d_loc . ' (' . $cp . ')', 0, 1, 'L');
        if (!empty($tel)) {
            $pdf->Cell(0, 5, 'Tel: ' . $tel, 0, 1, 'L');
        }
        $pdf->Ln(3);

        // Datos adicionales
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, 'Cantidad: ' . $cant, 0, 1, 'L');
        $pdf->Cell(0, 5, 'Valor declarado: $ ' . number_format($valdec, 2, ',', '.'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Cobranza: $ ' . number_format($cobranza, 2, ',', '.'), 0, 1, 'L');
        $pdf->Ln(8);

        // (Opcional) Podrías agregar un código de barras con otra lib
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 6, 'COD: ' . $codigo, 0, 1, 'C');

        // Enviar al navegador
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
