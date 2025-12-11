<?php
    /**
    * Nuevo método unificado de cotización.
    *
    * - Si recibe 'bultos' => cotiza múltiples bultos (dimensión y valor declarado agregados).
    * - Si NO recibe 'bultos' => delega en cotizarGet() (modo legacy GET).
    *
    * Formato esperado con bultos:
    * [
    * 'cp' => '5023',
    * 'localidad' => 'Villa Allende',
    * 'servicio' => 0,
    * 'flex' => 0,
    * 'bultos' => [
    * ['length'=>0.4,'width'=>0.3,'height'=>0.2,'weight'=>2.5,'valorDeclarado'=>10000],
    * ['length'=>0.5,'width'=>0.3,'height'=>0.25,'weight'=>3.2,'valorDeclarado'=>15000]
    * ]
    * ]
    */
    
public function cotizar(array $p): array
    {
    $_resp = new respuestas();

    // Si no hay bultos, usamos el flujo clásico (GET compat)
    if (!isset($p['bultos']) || !is_array($p['bultos']) || count($p['bultos']) === 0) {
    return $this->cotizarGet($p);
    }

    /* ==========================
    * 1) TOKEN (Bearer o ?token=)
    * ========================== */
    $token = Token::obtenerToken();

    // Backwards compatible: si viene en $p['token'], lo usamos como último recurso
    if (!$token && !empty($p['token'])) {
    $token = trim((string)$p['token']);
    }

    if (!$token) {
    return [
    401,
    $_resp->error_401('Debe enviar token (Bearer o query "token")')
    ];
    }

    // Validar token contra la BD (usa la conexión de esta clase)
    $tokenInfo = Token::validar($token, $this);
    if (!$tokenInfo) {
    return [
    401,
    $_resp->error_401('El Token que envió es inválido o ha caducado')
    ];
    }

    /* ==========================
    * 2) Validar parámetros básicos
    * ========================== */
    if (!isset($p['cp']) || $p['cp'] === '') {
    return [
    400,
    $_resp->error_400('Falta parámetro: cp')
    ];
    }

    $bultos = $p['bultos'];
    if (!is_array($bultos) || count($bultos) === 0) {
    return [
    400,
    $_resp->error_400('Debe enviar al menos un bulto')
    ];
    }

    // Validar campos por bulto
    $totalVolumen = 0.0;
    $totalPeso = 0.0;
    $totalValorDec = 0.0;

    foreach ($bultos as $idx => $b) {
    $idxMsg = ' (bulto índice ' . $idx . ')';

    foreach (['length', 'width', 'height', 'weight'] as $k) {
    if (!isset($b[$k]) || $b[$k] === '') {
    return [
    400,
    $_resp->error_400('Falta parámetro: ' . $k . $idxMsg)
    ];
    }
    }

    $l = (float)$b['length'];
    $w = (float)$b['width'];
    $h = (float)$b['height'];
    $peso = (float)$b['weight'];

    $totalVolumen += $this->calc_dim($l, $w, $h, $peso);
    $totalPeso += $peso;
    $totalValorDec += isset($b['valorDeclarado']) ? (float)$b['valorDeclarado'] : 0.0;
    }

    /* ==========================
    * 3) Normalizar entrada agregada
    * ========================== */
    $this->cp = trim((string)$p['cp']);
    $this->localidad = isset($p['localidad']) ? (string)$p['localidad'] : '';
    $this->servicio = isset($p['servicio']) ? (int)$p['servicio'] : 1;
    $this->flex = isset($p['flex']) ? (int)$p['flex'] : 0;

    // Cantidad = cantidad de bultos enviados
    $this->cantidad = count($bultos);
    $this->valorDeclarado = $totalValorDec;

    // Representamos volumen total como largo = volumen, ancho=1, alto=1
    // (la tarifa usa m3, así que lo importante es el producto)
    $this->length = $totalVolumen;
    $this->width = 1.0;
    $this->height = 1.0;
    $this->weight = $totalPeso;

    // Etiqueta de servicio según código (igual que en cotizarGet)
    if ($this->servicio === 1) {
    $this->servicio_label = 'Retiro y Entrega';
    } elseif ($this->servicio === 3) {
    $this->servicio_label = 'Retiro y Entrega (Flex)';
    } else {
    $this->servicio_label = 'Solo Entrega';
    }

    /* ==========================
    * 4) Seguro mínimo
    * ========================== */
    $seguroMin = $this->sure();
    if (!$seguroMin) {
    return [
    400,
    $_resp->error_400('No se pudo obtener monto mínimo de seguro')
    ];
    }
    $this->valorDeclaradoMinimo = (int)$seguroMin[0]['Valor'];

    $esFlex = ($this->servicio === 3) || ($this->flex === 1);

    // Normalización de CP capital
    $cpEval = $this->cp;
    if ($this->cp >= '5000' && $this->cp <= '5023' ) {
        $cpEval='5000' ;
        }

        /*==========================* 5) FLEX en capital -> tarifa fija
        * ========================== */
        if ($esFlex && ($this->cp >= '5000' && $this->cp <= '5023' )) {

            $precio=$this->rate_flex();

            if ($precio === 4 || $this->isErrorPrecio($precio)) {
            return [
            400,
            $_resp->error_400('Error en la obtención de precio FLEX')
            ];
            }

            return $this->armarRespuestaOk($precio, $tokenInfo, true);
            }

            /* ==========================
            * 6) NO FLEX (o FLEX fuera capital) → validar volumen total
            * ========================== */
            $dim = $this->calc_dim($this->length, $this->width, $this->height, $this->weight);
            if ($dim == 0) {
            return [
            400,
            $_resp->error_400('Faltan datos del paquete (volumen total 0)')
            ];
            }

            // Tarifa general usando volumen total
            $precio = $this->rate($cpEval, $this->length, $this->width, $this->height, $this->weight);

            if ($precio === 4) {
            return [
            400,
            $_resp->error_400('Código postal no encontrado o sin tarifa configurada')
            ];
            }

            if ($this->isErrorPrecio($precio)) {
            return [
            400,
            $_resp->error_400('Error en la obtención de precio')
            ];
            }

            $esCapital = ($this->cp >= '5000' && $this->cp <= '5023' );
                return $this->armarRespuestaOk($precio, $tokenInfo, $esCapital);
                }