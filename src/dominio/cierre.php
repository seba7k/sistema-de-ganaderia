<?php
declare(strict_types=1);

/**
 * Cierre económico de un lote (closeout).
 *
 * El costo por kg producido excluye la adquisición: mide qué costó *engordar*,
 * no qué costó comprar el animal. Mezclarlos hace que un lote comprado caro
 * parezca ineficiente en conversión, que es otro problema distinto.
 */

/**
 * @param array{adquisicion: float, alimentacion: float, sanidad: float, otros: float} $costos
 * @return array<string, float|int|null>
 */
function calcular_cierre(
    array $costos,
    float $ingresos,
    int $cabezas_iniciales,
    int $cabezas_vendidas,
    int $cabezas_muertas,
    float $kg_producidos,
    int $dias_engorda,
): array {
    $costo_total = array_sum($costos);
    $margen      = $ingresos - $costo_total;

    $costo_produccion = $costo_total - $costos['adquisicion'];
    $cabezas_promedio = ($cabezas_iniciales + max($cabezas_iniciales - $cabezas_muertas, 0)) / 2;

    return [
        'costo_total'           => round($costo_total, 0),
        'ingreso_total'         => round($ingresos, 0),
        'margen_bruto'          => round($margen, 0),
        'margen_por_cabeza'     => $cabezas_vendidas > 0
            ? round($margen / $cabezas_vendidas, 0)
            : round($margen / max($cabezas_iniciales, 1), 0),
        'costo_por_kg_producido' => $kg_producidos > 0
            ? round($costo_produccion / $kg_producidos, 0)
            : null,
        'costo_diario_por_cabeza' => $dias_engorda > 0 && $cabezas_promedio > 0
            ? round($costo_produccion / $cabezas_promedio / $dias_engorda, 0)
            : null,
        'mortalidad_porcentaje' => $cabezas_iniciales > 0
            ? round($cabezas_muertas / $cabezas_iniciales * 100, 2)
            : 0.0,
        'rentabilidad_porcentaje' => $ingresos > 0 ? round($margen / $ingresos * 100, 1) : null,
    ];
}
