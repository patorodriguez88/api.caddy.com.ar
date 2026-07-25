<?php

/**
 * Curva de descuento por volumen, unificada para toda la API
 * (GET /rates, POST /rates, POST /rates_v2, POST /servicios).
 *
 * Ordenados por tarifa descendente:
 *   1er bulto (el más caro)  -> 100%
 *   2do bulto                -> 0% (bonificado)
 *   3er bulto en adelante    -> 50% de su propia tarifa
 *
 * No depende de conexion ni de ninguna clase del proyecto: es cálculo puro,
 * así ningún endpoint puede reimplementarlo por su cuenta y desalinearse.
 */
class Pricing
{
    public static function aplicarDescuentoPorBulto(array $bultos, string $campoTarifa = 'tarifa'): array
    {
        if (count($bultos) === 0) {
            return [];
        }

        $indexados = [];
        foreach (array_values($bultos) as $i => $b) {
            $indexados[] = ['idx' => $i, 'bulto' => $b];
        }

        // Orden descendente por tarifa. usort es estable desde PHP 8, así que en
        // caso de empate gana el que vino primero en el array original.
        usort($indexados, function ($a, $b) use ($campoTarifa) {
            return $b['bulto'][$campoTarifa] <=> $a['bulto'][$campoTarifa];
        });

        foreach ($indexados as $pos => &$item) {
            if ($pos === 0) {
                $porcentaje = 1.0;
            } elseif ($pos === 1) {
                $porcentaje = 0.0;
            } else {
                $porcentaje = 0.5;
            }
            $item['porcentaje']     = $porcentaje;
            $item['precioAplicado'] = round(((float)$item['bulto'][$campoTarifa]) * $porcentaje, 2);
        }
        unset($item);

        // Vuelvo al orden de entrada original.
        usort($indexados, fn($a, $b) => $a['idx'] <=> $b['idx']);

        $resultado = [];
        foreach ($indexados as $item) {
            $bulto                       = $item['bulto'];
            $bulto['porcentajeAplicado'] = $item['porcentaje'];
            $bulto['precioAplicado']     = $item['precioAplicado'];
            $resultado[]                 = $bulto;
        }

        return $resultado;
    }

    /**
     * Atajo para el caso de bultos idénticos: N tarifas iguales sueltas.
     * Es un caso particular de aplicarDescuentoPorBulto(), mismo code path.
     *
     * @param float[] $tarifas
     */
    public static function totalConDescuento(array $tarifas): float
    {
        $bultos       = array_map(fn($t) => ['tarifa' => (float)$t], $tarifas);
        $conDescuento = self::aplicarDescuentoPorBulto($bultos, 'tarifa');

        $total = 0.0;
        foreach ($conDescuento as $b) {
            $total += $b['precioAplicado'];
        }

        return round($total, 2);
    }
}
